#!/usr/bin/env bash
set -e

event="${1:-}"
jail="${2:-}"
ip="${3:-}"
bantime="${4:-}"
application="${5:-}"
# Update the URL below to your own webhook URL if you want to use this script directly.
url="${6:-http://127.0.0.1}"

json_escape() {
    printf '%s' "$1" | sed 's/\\/\\\\/g; s/"/\\"/g'
}

payload='{"event":"'"$(json_escape "${event}")"'","jail":"'"$(json_escape "${jail}")"'","ip":"'"$(json_escape "${ip}")"'"'

if [ "${event}" = "ban" ]; then
    payload="${payload}"',"application":"'"$(json_escape "${application}")"'","bantime":"'"$(json_escape "${bantime}")"'"'
fi

payload="${payload}"'}'

curl -fsS --max-time 5 -H "Content-Type: application/json" -X POST --data "${payload}" "${url}" >/dev/null
