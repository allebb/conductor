#!/usr/bin/env bash
set -e

event="${1:-deploy}"
app="${2:-}"
config="${CONDUCTOR_LETSENCRYPT_WEBHOOK_CONFIG:-/etc/conductor/configs/common/letsencrypt-webhook.conf}"
url="http://127.0.0.1"

if [ -f "${config}" ]; then
    configured_url="$(awk -F= '/^[[:space:]]*url[[:space:]]*=/{print $2; exit}' "${config}" | sed 's/^[[:space:]]*//; s/[[:space:]]*$//')"
    if [ -n "${configured_url}" ]; then
        url="${configured_url}"
    fi
fi

json_escape() {
    printf '%s' "$1" | sed 's/\\/\\\\/g; s/"/\\"/g'
}

payload='{"event":"'"$(json_escape "${event}")"'","app":"'"$(json_escape "${app}")"'","lineage":"'"$(json_escape "${RENEWED_LINEAGE:-}")"'","domains":"'"$(json_escape "${RENEWED_DOMAINS:-}")"'"}'

curl -fsS --max-time 5 -H "Content-Type: application/json" -X POST --data "${payload}" "${url}" >/dev/null || true
