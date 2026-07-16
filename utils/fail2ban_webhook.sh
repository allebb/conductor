#!/usr/bin/env bash
set -e

event="${1:-}"
jail="${2:-}"
ip="${3:-}"
bantime="${4:-}"
# Update the URL below to your own webhook URL if you want to use this script. The default is a public webhook for testing purposes.
url="${5:-https://bin.hallinet.com/z7jw38z7}"

json_escape() {
    printf '%s' "$1" | sed 's/\\/\\\\/g; s/"/\\"/g'
}

payload='{"event":"'"$(json_escape "${event}")"'","jail":"'"$(json_escape "${jail}")"'","ip":"'"$(json_escape "${ip}")"'"'

if [ "${event}" = "ban" ]; then
    payload="${payload}"',"bantime":"'"$(json_escape "${bantime}")"'"'
fi

payload="${payload}"'}'

curl -fsS --max-time 5 -H "Content-Type: application/json" -X POST --data "${payload}" "${url}" >/dev/null
