#!/usr/bin/env bash
set -e

################################################################################
# Optional Nginx stream{} support for Conductor
################################################################################

if [ "$(id -u)" -ne 0 ]; then
    echo "Please run this script as root."
    exit 1
fi

CONDUCTOR_DIR="${CONDUCTOR_DIR:-/etc/conductor}"
NGINX_CONF="${NGINX_CONF:-/etc/nginx/nginx.conf}"
STREAM_INCLUDE="include ${CONDUCTOR_DIR}/streams/*.conf;"
OLD_STREAM_INCLUDE="include ${CONDUCTOR_DIR}/configs/common/conductor_streams.conf;"
STREAM_EXAMPLES="${CONDUCTOR_DIR}/configs/common/streams"
STREAM_TARGET="${CONDUCTOR_DIR}/streams"

if [ -f /usr/local/etc/nginx/nginx.conf ] && [ ! -f "${NGINX_CONF}" ]; then
    NGINX_CONF="/usr/local/etc/nginx/nginx.conf"
fi

if command -v apt-get >/dev/null 2>&1; then
    apt-get -y install libnginx-mod-stream || true
elif command -v apt >/dev/null 2>&1; then
    apt -y install libnginx-mod-stream || true
fi

install -d -m 0755 "${STREAM_TARGET}"

if [ -d "${STREAM_EXAMPLES}" ]; then
    install -m 0644 "${STREAM_EXAMPLES}/"*.conf.example "${STREAM_TARGET}/"
fi

if [ -f "${NGINX_CONF}" ]; then
    tmp_conf="$(mktemp)"
    awk '
        function print_conductor_events(indent) {
            print indent "# Auto-configured by Conductor Installer"
            print indent "worker_connections 20000;"
            print indent "multi_accept on;"
            print indent "# use epoll;"
            configured = 1
        }

        /^[[:space:]]*events[[:space:]]*\{/ {
            in_events = 1
            saw_events = 1
            configured = 0
            print
            next
        }

        in_events && /Auto-configured by Conductor Installer/ {
            next
        }

        in_events && /^[[:space:]]*#?[[:space:]]*(worker_connections|multi_accept|use[[:space:]]+epoll)([[:space:];]|$)/ {
            next
        }

        in_events && /^[[:space:]]*\}/ {
            if (!configured) {
                print_conductor_events("    ")
            }
            in_events = 0
            print
            next
        }

        { print }

        END {
            if (!saw_events) {
                print ""
                print "events {"
                print_conductor_events("    ")
                print "}"
            }
        }
    ' "${NGINX_CONF}" > "${tmp_conf}"

    cat "${tmp_conf}" > "${NGINX_CONF}"
    rm -f "${tmp_conf}"
fi

if ! command -v nginx >/dev/null 2>&1; then
    echo "Nginx binary was not found; skipping stream include configuration."
    exit 0
fi

if ! nginx -V 2>&1 | grep -q -- '--with-stream'; then
    echo "Nginx stream module is not available; examples were copied but stream support was not enabled."
    exit 0
fi

if [ ! -f "${NGINX_CONF}" ]; then
    echo "Nginx config was not found at ${NGINX_CONF}; skipping stream include configuration."
    exit 0
fi

if grep -Fq "${OLD_STREAM_INCLUDE}" "${NGINX_CONF}"; then
    tmp_conf="$(mktemp)"
    awk -v old_line="${OLD_STREAM_INCLUDE}" -v new_line="${STREAM_INCLUDE}" '
        index($0, old_line) { print new_line; next }
        { print }
    ' "${NGINX_CONF}" > "${tmp_conf}"
    cat "${tmp_conf}" > "${NGINX_CONF}"
    rm -f "${tmp_conf}"
fi

if grep -Fq "${STREAM_INCLUDE}" "${NGINX_CONF}"; then
    exit 0
fi

tmp_conf="$(mktemp)"
awk -v include_line="${STREAM_INCLUDE}" '
    !inserted && /^[[:space:]]*http[[:space:]]*\{/ {
        print include_line
        print ""
        inserted = 1
    }
    { print }
    END {
        if (!inserted) {
            print ""
            print include_line
        }
    }
' "${NGINX_CONF}" > "${tmp_conf}"

cat "${tmp_conf}" > "${NGINX_CONF}"
rm -f "${tmp_conf}"
