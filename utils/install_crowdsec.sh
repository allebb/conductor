#!/usr/bin/env bash
set -e

################################################################################
# Optional Conductor CrowdSec installer
################################################################################

usage() {
    cat <<'EOF'
Usage:
  sudo bash /etc/conductor/utils/install_crowdsec.sh [options]

Options:
  --bouncer=auto|nftables|none
      Firewall bouncer to install. Default: auto. The auto mode installs the
      nftables firewall bouncer.

  --skip-repo-bootstrap
      Do not add the official CrowdSec package repository if crowdsec is not
      already available from apt.

  -h, --help
      Show this help.

This optional installer installs CrowdSec, adds a firewall bouncer, installs
common http collections, and configures CrowdSec to read Conductor's optional
/var/conductor/seclogs/conductor_*.seclog security logs.
EOF
}

BOUNCER="auto"
BOOTSTRAP_REPO="yes"
OFFICIAL_REPO_BOOTSTRAPPED="no"

for arg in "$@"; do
    case "$arg" in
        --bouncer=*)
            BOUNCER="${arg#*=}"
            ;;
        --skip-repo-bootstrap)
            BOOTSTRAP_REPO="no"
            ;;
        -h|--help)
            usage
            exit 0
            ;;
        *)
            echo "Unknown option: ${arg}"
            usage
            exit 1
            ;;
    esac
done

case "${BOUNCER}" in
    auto|nftables|none)
        ;;
    *)
        echo "Unsupported bouncer: ${BOUNCER}"
        usage
        exit 1
        ;;
esac

if [ "$(id -u)" -ne 0 ]; then
    echo "Please run this script as root, for example: sudo bash /etc/conductor/utils/install_crowdsec.sh"
    exit 1
fi

if ! command -v apt-get >/dev/null 2>&1; then
    echo "This installer currently supports Debian/Ubuntu systems with apt-get."
    exit 1
fi

if ! command -v nft >/dev/null 2>&1; then
    cat <<'EOF'
nftables is not installed.

Please run the optional Fail2Ban+nftables installer first:

    sudo bash /etc/conductor/utils/install_fail2ban_nftables.sh

Then re-run this CrowdSec installer.
EOF
    exit 1
fi

apt_package_available() {
    apt-cache show "$1" >/dev/null 2>&1
}

install_official_repo() {
    echo "CrowdSec packages were not found in apt; adding the official CrowdSec package repository..."
    DEBIAN_FRONTEND=noninteractive apt-get install -y curl ca-certificates gnupg
    tmp_script="$(mktemp)"
    trap 'rm -f "${tmp_script}"' EXIT
    curl -fsSL https://install.crowdsec.net -o "${tmp_script}"
    sh "${tmp_script}"
    rm -f "${tmp_script}"
    trap - EXIT
    apt-get update
    OFFICIAL_REPO_BOOTSTRAPPED="yes"
}

choose_bouncer_package() {
    case "${BOUNCER}" in
        none)
            echo ""
            return
            ;;
    esac

    for package in crowdsec-firewall-bouncer-nftables crowdsec-firewall-bouncer; do
        if apt_package_available "${package}"; then
            echo "${package}"
            return
        fi
    done

    echo ""
}

disable_conductor_automatic_fail2ban_jails() {
    cat >/etc/fail2ban/jail.d/zz-conductor-crowdsec.conf <<'EOF'
# Auto-created by the Conductor CrowdSec installer.
# CrowdSec handles automatic bans from /var/conductor/seclogs/conductor_*.seclog.
# Fail2Ban keeps conductor-manual enabled for conductor ban/unban commands.

[conductor-nginx-scanner]
enabled = false

[conductor-nginx-4xx]
enabled = false

[conductor-nginx-401]
enabled = false

[conductor-nginx-403]
enabled = false

[conductor-nginx-waf-violation]
enabled = false

[conductor-nginx-geoip-block]
enabled = false

[conductor-nginx-burst]
enabled = false

[conductor-nginx-dos]
enabled = false
EOF

    if command -v systemctl >/dev/null 2>&1; then
        systemctl restart fail2ban
    else
        service fail2ban restart
    fi
}

apt-get update

if ! apt_package_available crowdsec; then
    if [ "${BOOTSTRAP_REPO}" = "yes" ]; then
        install_official_repo
    else
        echo "The crowdsec package is not available from apt. Re-run without --skip-repo-bootstrap or add the CrowdSec repository manually."
        exit 1
    fi
fi

BOUNCER_PACKAGE="$(choose_bouncer_package)"
PACKAGES="crowdsec"

if [ -z "${BOUNCER_PACKAGE}" ] && [ "${BOUNCER}" != "none" ]; then
    if [ "${BOOTSTRAP_REPO}" = "yes" ] && [ "${OFFICIAL_REPO_BOOTSTRAPPED}" = "no" ]; then
        install_official_repo
        BOUNCER_PACKAGE="$(choose_bouncer_package)"
    fi
fi

if [ -n "${BOUNCER_PACKAGE}" ]; then
    PACKAGES="${PACKAGES} ${BOUNCER_PACKAGE}"
elif [ "${BOUNCER}" != "none" ]; then
    echo "No CrowdSec nftables firewall bouncer package was found. Checked crowdsec-firewall-bouncer-nftables and crowdsec-firewall-bouncer. Re-run with --bouncer=none to install CrowdSec without a firewall bouncer."
    exit 1
fi

DEBIAN_FRONTEND=noninteractive apt-get install -y ${PACKAGES}

install -d -o root -g root -m 0755 /var/conductor/seclogs
install -d -m 0755 /etc/crowdsec/acquis.d /etc/crowdsec/parsers/s01-parse
cat >/etc/crowdsec/acquis.d/conductor-nginx.yaml <<'EOF'
filenames:
  - /var/conductor/seclogs/conductor_*.seclog
labels:
  type: conductor-nginx-seclog
EOF

cat >/etc/crowdsec/parsers/s01-parse/conductor-nginx-seclog.yaml <<'EOF'
name: conductor/nginx-seclog
description: Parse Conductor lean Nginx security logs
filter: "evt.Line.Labels.type == 'conductor-nginx-seclog'"
onsuccess: next_stage
grok:
  pattern: '^(?:%{TIMESTAMP_ISO8601:timestamp} )?%{IPORHOST:remote_addr} %{INT:status} "%{WORD:http_verb} %{URIPATHPARAM:http_path}(?: HTTP/%{NUMBER:http_version})?" "%{DATA:http_user_agent}"$'
  apply_on: message
statics:
  - meta: service
    value: http
  - meta: source_ip
    expression: evt.Parsed.remote_addr
  - meta: http_status
    expression: evt.Parsed.status
  - meta: http_verb
    expression: evt.Parsed.http_verb
  - meta: http_path
    expression: evt.Parsed.http_path
  - parsed: time
    expression: evt.Parsed.timestamp
  - parsed: target_fqdn
    value: conductor
EOF

if command -v cscli >/dev/null 2>&1; then
    cscli hub update || true
    cscli collections install crowdsecurity/http-cve || true
    cscli collections install crowdsecurity/base-http-scenarios || true
fi

if command -v fail2ban-client >/dev/null 2>&1; then
    cat <<'EOF'

Fail2Ban appears to be installed. CrowdSec will handle the automatic bans
instead of Fail2Ban. Fail2Ban can keep the conductor-manual jail enabled for
manual conductor ban/unban commands.
EOF

    printf "Disable Conductor's automatic Fail2Ban jails and keep only manual bans? [Y/n] "
    read -r DISABLE_FAIL2BAN_AUTOMATIC_JAILS || DISABLE_FAIL2BAN_AUTOMATIC_JAILS=""
    case "${DISABLE_FAIL2BAN_AUTOMATIC_JAILS}" in
        n|N|no|NO|No)
            echo "Leaving Conductor's automatic Fail2Ban jails enabled."
            ;;
        *)
            disable_conductor_automatic_fail2ban_jails
            echo "Disabled Conductor's automatic Fail2Ban jails. CrowdSec will handle automatic bans instead."
            ;;
    esac
fi

if command -v systemctl >/dev/null 2>&1; then
    systemctl enable --now crowdsec
    systemctl restart crowdsec
    if systemctl list-unit-files | grep -q '^crowdsec-firewall-bouncer'; then
        systemctl enable --now crowdsec-firewall-bouncer || true
        systemctl restart crowdsec-firewall-bouncer || true
    fi
else
    service crowdsec restart
    service crowdsec-firewall-bouncer restart || true
fi

cat <<EOF

Conductor CrowdSec support has been installed.

CrowdSec is monitoring:
  /var/conductor/seclogs/conductor_*.seclog

Installed firewall bouncer:
  ${BOUNCER_PACKAGE:-none}

Useful commands:
  cscli metrics
  cscli alerts list
  cscli decisions list

If you also use Fail2Ban, CrowdSec should handle automatic bans instead of
Fail2Ban. Keep Fail2Ban's conductor-manual jail enabled for conductor ban/unban
commands.
EOF
