#!/usr/bin/env bash
set -e

################################################################################
# Optional Conductor Fail2Ban + nftables installer
################################################################################

if [ "$(id -u)" -ne 0 ]; then
    echo "Please run this script as root, for example: sudo bash /etc/conductor/utils/install_fail2ban_nftables.sh"
    exit 1
fi

CONDUCTOR_DIR="${CONDUCTOR_DIR:-/etc/conductor}"
FAIL2BAN_SOURCE="${CONDUCTOR_DIR}/configs/common/fail2ban"

if [ ! -d "${FAIL2BAN_SOURCE}" ]; then
    echo "Fail2Ban templates were not found at: ${FAIL2BAN_SOURCE}"
    exit 1
fi

if command -v apt-get >/dev/null 2>&1; then
    apt-get update
    DEBIAN_FRONTEND=noninteractive apt-get install -y fail2ban nftables curl
elif command -v apt >/dev/null 2>&1; then
    apt update
    DEBIAN_FRONTEND=noninteractive apt install -y fail2ban nftables curl
else
    echo "This installer currently supports Debian/Ubuntu systems with apt-get."
    exit 1
fi

install -d -m 0755 /etc/fail2ban/action.d /etc/fail2ban/filter.d /etc/fail2ban/jail.d
touch /var/log/conductor-fail2ban-manual.log
touch /tmp/conductor_fail2ban_seed.seclog
chmod 0644 /var/log/conductor-fail2ban-manual.log /tmp/conductor_fail2ban_seed.seclog
install -m 0644 "${FAIL2BAN_SOURCE}/action.d/"*.conf /etc/fail2ban/action.d/
install -m 0644 "${FAIL2BAN_SOURCE}/filter.d/"*.conf /etc/fail2ban/filter.d/
install -m 0644 "${FAIL2BAN_SOURCE}/jail.d/conductor-nginx.conf" /etc/fail2ban/jail.d/conductor-nginx.conf

if [ -f "${CONDUCTOR_DIR}/utils/fail2ban_webhook.sh" ]; then
    chmod 0755 "${CONDUCTOR_DIR}/utils/fail2ban_webhook.sh"
fi

if command -v systemctl >/dev/null 2>&1; then
    systemctl enable --now nftables
    systemctl enable --now fail2ban
    systemctl restart fail2ban
else
    service nftables start || true
    service fail2ban restart
fi

cat <<'EOF'

Conductor Fail2Ban support has been installed using nftables.

To enable it for an application:

    conductor protect {appname} --enable --auto-reload

To disable it for an application:

    conductor protect {appname} --disable --auto-reload

The installed automatic jails watch /tmp/conductor_*.seclog for scanner probes,
excessive 4xx responses, repeated 401/403 responses, WAF rejections, GeoIP
blocks, burst request rates, and DoS-style sustained request rates. The
conductor-manual jail is available for manual bans added with
conductor ban {ip_address}.

Fail2Ban ban/unban webhook notifications are enabled by default using and example
webhook URL. You can change the webhook URL in /etc/fail2ban/action.d/conductor-webhook.conf:

    https://bin.hallinet.com/z7jw38z7

The webhook sends a JSON POST when an IP is banned and another JSON POST when it
is unbanned. Edit /etc/fail2ban/action.d/conductor-webhook.conf if you need to
change or disable the endpoint.
EOF
