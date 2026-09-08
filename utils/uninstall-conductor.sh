#!/usr/bin/env bash

################################################################################
# Complete Conductor server reset                                               #
################################################################################

set -uo pipefail

ASSUME_YES=0
FAILED=0

usage() {
    cat <<'EOF'
Usage: sudo bash /etc/conductor/utils/uninstall-conductor.sh [--yes]

Completely removes Conductor and the web/application stack installed by its
Debian installer. This permanently deletes all applications, backups, logs,
database data, TLS certificates, credentials, and service configuration.

Options:
  --yes       Skip the interactive confirmation (for automated server resets).
  -h, --help  Show this help.
EOF
}

for arg in "$@"; do
    case "${arg}" in
        --yes)
            ASSUME_YES=1
            ;;
        -h|--help)
            usage
            exit 0
            ;;
        *)
            echo "Unknown option: ${arg}" >&2
            usage >&2
            exit 1
            ;;
    esac
done

if [ "$(id -u)" -ne 0 ]; then
    echo "Please run this script as root:" >&2
    echo "  sudo bash /etc/conductor/utils/uninstall-conductor.sh" >&2
    exit 1
fi

if ! command -v apt-get >/dev/null 2>&1 || ! command -v dpkg-query >/dev/null 2>&1; then
    echo "This uninstaller supports Debian/Ubuntu systems managed by apt/dpkg." >&2
    exit 1
fi

cat <<'EOF'

WARNING: COMPLETE CONDUCTOR SERVER RESET

This will permanently delete:
  * every application, backup, log, deploy key, and stored DB credential
  * all MySQL/MariaDB databases, Redis data, and LetsEncrypt certificates
  * Conductor Nginx, PHP, Supervisor, cron, Fail2Ban, and CrowdSec configuration
  * the Nginx, PHP, database, Redis, Supervisor, Certbot, Fail2Ban, nftables,
    CrowdSec, and Conductor installations

This operation cannot be undone. Copy any data you need off this server first.
EOF

if [ "${ASSUME_YES}" -ne 1 ]; then
    printf '\nType RESET CONDUCTOR to continue: '
    confirmation=""
    read -r confirmation || true
    if [ "${confirmation}" != "RESET CONDUCTOR" ]; then
        echo "Reset cancelled. Nothing was changed."
        exit 1
    fi
fi

run_best_effort() {
    local description="$1"
    shift

    echo "${description}..."
    if ! "$@"; then
        echo "WARNING: ${description} failed; continuing the remaining cleanup." >&2
        FAILED=1
    fi
}

stop_and_disable_service() {
    local service_name="$1"

    if command -v systemctl >/dev/null 2>&1; then
        systemctl disable --now "${service_name}" >/dev/null 2>&1 || true
    elif command -v service >/dev/null 2>&1; then
        service "${service_name}" stop >/dev/null 2>&1 || true
    fi
}

remove_file() {
    local path="$1"
    if [ -e "${path}" ] || [ -L "${path}" ]; then
        if ! rm -f -- "${path}"; then
            echo "WARNING: Could not remove ${path}." >&2
            FAILED=1
        fi
    fi
}

remove_tree() {
    local path="$1"

    # Only this explicit allow-list may ever be recursively removed.
    case "${path}" in
        /etc/conductor|/var/conductor|/etc/nginx|/var/lib/nginx|/var/cache/nginx|/var/log/nginx|\
        /etc/letsencrypt|/var/lib/letsencrypt|/var/log/letsencrypt|\
        /etc/mysql|/var/lib/mysql|/var/log/mysql|/var/lib/mysql-files|/var/lib/mysql-keyring|\
        /etc/redis|/var/lib/redis|/var/log/redis|\
        /etc/supervisor|/var/log/supervisor|\
        /etc/fail2ban|/var/lib/fail2ban|/var/log/fail2ban|\
        /etc/crowdsec|/var/lib/crowdsec|/var/log/crowdsec|\
        /var/www/.cache|/var/www/.ssh)
            if ! rm -rf -- "${path}"; then
                echo "WARNING: Could not remove ${path}." >&2
                FAILED=1
            fi
            ;;
        *)
            echo "Refusing unexpected recursive removal target: ${path}" >&2
            FAILED=1
            ;;
    esac
}

echo "Stopping Conductor-managed services..."
for service_name in \
    nginx mysql mariadb redis-server supervisor fail2ban \
    crowdsec crowdsec-firewall-bouncer nftables; do
    stop_and_disable_service "${service_name}"
done

while IFS= read -r php_service; do
    [ -n "${php_service}" ] && stop_and_disable_service "${php_service}"
done < <(
    if command -v systemctl >/dev/null 2>&1; then
        systemctl list-unit-files 'php*-fpm.service' --no-legend 2>/dev/null \
            | awk '{ sub(/\.service$/, "", $1); print $1 }'
    fi
)

# Resolve the package list from installed dpkg packages. Quoting the array when
# it is passed to apt means none of these names are treated as shell globs.
mapfile -t conductor_packages < <(
    dpkg-query -W -f='${binary:Package}\n' 2>/dev/null \
        | awk -F: '
            $1 ~ /^(nginx|nginx-common|nginx-core|nginx-full|nginx-light|libnginx-mod-.*|php[0-9]+\.[0-9]+-.*|certbot|letsencrypt|python3-certbot-nginx|mysql-server.*|mysql-client.*|mysql-community-.*|mysql-common|mysql-apt-config|mariadb-server.*|mariadb-client.*|mariadb-common|redis-server|redis-tools|supervisor|fail2ban|nftables|crowdsec.*)$/ { print $0 }
        '
)

if [ "${#conductor_packages[@]}" -gt 0 ]; then
    echo "Purging Conductor service packages:"
    printf '  %s\n' "${conductor_packages[@]}"
    run_best_effort \
        "Purging Conductor service packages" \
        env DEBIAN_FRONTEND=noninteractive apt-get purge -y "${conductor_packages[@]}"
else
    echo "No installed Conductor service packages were found."
fi

run_best_effort \
    "Removing packages that are no longer required" \
    env DEBIAN_FRONTEND=noninteractive apt-get autoremove --purge -y

echo "Removing Conductor system integration files..."
remove_file /usr/bin/conductor
remove_file /usr/bin/composer
remove_file /etc/conductor.conf
remove_file /etc/bash_completion.d/conductor
remove_file /etc/logrotate.d/conductor-seclog
remove_file /etc/logrotate.d/conductor-vhost-logs
remove_file /etc/nginx/snippets/ssl-params.conf
remove_file /etc/nginx/_default.crt
remove_file /etc/nginx/_default.key
remove_file /etc/ssl/certs/dhparam.pem
remove_file /var/www/html/index.html
remove_file /var/log/conductor-fail2ban-manual.log

# Application cron files and optional integration files can remain after their
# owning package has been purged, so remove the Conductor-specific names.
find /etc/cron.d -maxdepth 1 -type f -name 'conductor_*' -delete 2>/dev/null || true
find /etc/fail2ban/action.d -maxdepth 1 -type f -name 'conductor-*.conf' -delete 2>/dev/null || true
find /etc/fail2ban/filter.d -maxdepth 1 -type f -name 'conductor-*.conf' -delete 2>/dev/null || true
remove_file /etc/fail2ban/jail.d/conductor-nginx.conf
remove_file /etc/fail2ban/jail.d/zz-conductor-crowdsec.conf
remove_file /etc/crowdsec/acquis.d/conductor-nginx.yaml
remove_file /etc/crowdsec/parsers/s01-parse/conductor-nginx-seclog.yaml

echo "Removing external package repositories added by Conductor..."
remove_file /etc/apt/sources.list.d/php.list
remove_file /usr/share/keyrings/deb.sury.org-php.gpg
remove_file /etc/apt/sources.list.d/redis.list
remove_file /usr/share/keyrings/redis-archive-keyring.gpg
remove_file /etc/apt/sources.list.d/mysql.list
remove_file /usr/share/keyrings/mysql-apt-config.gpg

# The official CrowdSec bootstrapper has used more than one repository/key file
# name over time. Restrict cleanup to files whose basename contains crowdsec.
find /etc/apt/sources.list.d /etc/apt/keyrings /usr/share/keyrings \
    -maxdepth 1 -type f -iname '*crowdsec*' -delete 2>/dev/null || true

echo "Deleting all Conductor and managed-service data..."
for data_path in \
    /var/conductor \
    /etc/letsencrypt /var/lib/letsencrypt /var/log/letsencrypt \
    /etc/mysql /var/lib/mysql /var/log/mysql /var/lib/mysql-files /var/lib/mysql-keyring \
    /etc/redis /var/lib/redis /var/log/redis \
    /etc/supervisor /var/log/supervisor \
    /etc/fail2ban /var/lib/fail2ban /var/log/fail2ban \
    /etc/crowdsec /var/lib/crowdsec /var/log/crowdsec \
    /etc/nginx /var/lib/nginx /var/cache/nginx /var/log/nginx \
    /var/www/.cache /var/www/.ssh; do
    remove_tree "${data_path}"
done

# Delete the cloned repository last because this running script normally lives
# inside it. Bash has already loaded the script, so the remaining steps continue.
remove_tree /etc/conductor

if command -v systemctl >/dev/null 2>&1; then
    systemctl daemon-reload || true
    systemctl reset-failed || true
fi

run_best_effort "Refreshing apt package metadata" apt-get update

echo ""
if [ "${FAILED}" -eq 0 ]; then
    echo "Conductor and all of its managed data and services have been removed."
    echo "The server is ready for a clean installation."
    exit 0
fi

echo "Conductor cleanup finished with warnings. Review the messages above before reinstalling." >&2
exit 1
