#!/usr/bin/env bash
set -e

event="${1:-deploy}"
app="${2:-}"
config="${CONDUCTOR_LETSENCRYPT_WEBHOOK_CONFIG:-/etc/conductor/configs/common/letsencrypt-webhook.conf}"
url=""

if [ -f "${config}" ]; then
    configured_url="$(sed -n 's/^[[:space:]]*url[[:space:]]*=[[:space:]]*//p' "${config}" | head -n 1 | sed 's/[[:space:]]*$//')"
    if [ -n "${configured_url}" ]; then
        url="${configured_url}"
    fi
fi

# An unconfigured webhook is disabled. The loopback URL was the historical
# default, so continue treating it as disabled on upgraded installations.
case "${url}" in
    ""|"http://127.0.0.1") exit 0 ;;
esac

json_escape() {
    printf '%s' "$1" | sed 's/\\/\\\\/g; s/"/\\"/g'
}

payload='{"event":"'"$(json_escape "${event}")"'","app":"'"$(json_escape "${app}")"'","lineage":"'"$(json_escape "${RENEWED_LINEAGE:-}")"'","domains":"'"$(json_escape "${RENEWED_DOMAINS:-}")"'"}'

curl -fsS --max-time 5 -H "Content-Type: application/json" -X POST --data "${payload}" "${url}" >/dev/null || true
