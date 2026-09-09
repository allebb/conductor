#!/usr/bin/env bash

# List, install, and remove the PHP versions supported by Conductor on Debian 13.

set -euo pipefail

PHP_SUPPORTED_VERSIONS=("8.5" "8.4" "8.3" "8.2" "8.1" "8.0" "7.4")
PHP_PACKAGES=(common cli fpm curl gd intl mbstring sqlite3 mysql bcmath xml zip apcu)

if [ "${EUID}" -eq 0 ]; then
    SUDO=()
else
    SUDO=(sudo)
fi

usage() {
    echo "Usage: $(basename "$0") [--list | --install VERSION | --uninstall VERSION]"
}

is_supported_version() {
    local wanted="$1"
    local version
    for version in "${PHP_SUPPORTED_VERSIONS[@]}"; do
        [ "$version" = "$wanted" ] && return 0
    done
    return 1
}

is_installed() {
    dpkg-query -W -f='${db:Status-Status}' "php${1}-cli" 2>/dev/null | grep -qx installed
}

is_available() {
    local candidate
    candidate=$(apt-cache policy "php${1}-cli" 2>/dev/null | awk '/Candidate:/ { print $2; exit }')
    [ -n "$candidate" ] && [ "$candidate" != "(none)" ]
}

php_binary() {
    local binary="/usr/bin/php${1}"
    if [ -x "$binary" ]; then
        printf '%s\n' "$binary"
    else
        printf '%s\n' "-"
    fi
}

show_versions() {
    local version status
    printf '%-9s %-13s %s\n' "VERSION" "STATUS" "PHP BINARY"
    printf '%-9s %-13s %s\n' "-------" "------" "----------"
    for version in "${PHP_SUPPORTED_VERSIONS[@]}"; do
        if is_installed "$version"; then
            status="installed"
        elif is_available "$version"; then
            status="available"
        else
            status="unavailable"
        fi
        printf '%-9s %-13s %s\n' "$version" "$status" "$(php_binary "$version")"
    done
}

require_root_access() {
    if [ "${EUID}" -ne 0 ] && ! command -v sudo >/dev/null 2>&1; then
        echo "This action must be run as root (or with sudo installed)." >&2
        exit 1
    fi
}

configure_php() {
    local version="$1"
    local php_ini
    local found=0

    while IFS= read -r php_ini; do
        found=1
        "${SUDO[@]}" sed -i -E \
            -e 's/^[[:space:]]*;?[[:space:]]*post_max_size[[:space:]]*=.*/post_max_size = 20M/' \
            -e 's/^[[:space:]]*;?[[:space:]]*upload_max_filesize[[:space:]]*=.*/upload_max_filesize = 20M/' \
            "$php_ini"

        if ! grep -Eq '^post_max_size[[:space:]]*=[[:space:]]*20M$' "$php_ini" ||
           ! grep -Eq '^upload_max_filesize[[:space:]]*=[[:space:]]*20M$' "$php_ini"; then
            echo "Failed to configure 20M upload limits in ${php_ini}." >&2
            exit 1
        fi
    done < <(find "/etc/php/${version}" -mindepth 2 -maxdepth 2 -name php.ini -type f | sort)

    if [ "$found" -eq 0 ]; then
        echo "No php.ini files were found for PHP ${version}." >&2
        exit 1
    fi

    if [ -f "/etc/php/${version}/fpm/php.ini" ]; then
        "${SUDO[@]}" sed -i -E \
            's/^[[:space:]]*;?[[:space:]]*cgi\.fix_pathinfo[[:space:]]*=.*/cgi.fix_pathinfo=0/' \
            "/etc/php/${version}/fpm/php.ini"
    fi
}

newest_installed_version() {
    local version excluded="${1:-}"
    for version in "${PHP_SUPPORTED_VERSIONS[@]}"; do
        if [ "$version" != "$excluded" ] && is_installed "$version"; then
            printf '%s\n' "$version"
            return 0
        fi
    done
    return 1
}

has_conductor_compatible_version() {
    local excluded="${1:-}"
    local version
    for version in 8.5; do
        if [ "$version" != "$excluded" ] && is_installed "$version"; then
            return 0
        fi
    done
    return 1
}

set_conductor_default() {
    local version="$1"

    if [ -x "/usr/bin/php${version}" ]; then
        "${SUDO[@]}" update-alternatives --set php "/usr/bin/php${version}"
    fi

    if [ -f /etc/conductor.conf ]; then
        "${SUDO[@]}" sed -i -E \
            -e "s|/var/run/php/php[0-9]+\.[0-9]+-fpm\.sock|/var/run/php/php${version}-fpm.sock|g" \
            -e "s|/etc/init\.d/php[0-9]+\.[0-9]+-fpm|/etc/init.d/php${version}-fpm|g" \
            /etc/conductor.conf
    fi
}

install_version() {
    local version="$1"
    local suffix
    local packages=()

    is_supported_version "$version" || { echo "Unsupported PHP version: ${version}" >&2; exit 1; }
    is_available "$version" || { echo "PHP ${version} is not available from the configured APT repositories." >&2; exit 1; }
    require_root_access

    for suffix in "${PHP_PACKAGES[@]}"; do
        packages+=("php${version}-${suffix}")
    done

    "${SUDO[@]}" apt-get update
    "${SUDO[@]}" apt-get -y install "${packages[@]}"
    "${SUDO[@]}" apt-get -y install "php${version}-memcache" || true
    configure_php "$version"
    "${SUDO[@]}" systemctl enable --now "php${version}-fpm"

    set_conductor_default "$(newest_installed_version)"
    echo "PHP ${version} installed with 20M post and upload limits."
}

uninstall_version() {
    local version="$1"
    local replacement
    local package
    local packages=()

    is_supported_version "$version" || { echo "Unsupported PHP version: ${version}" >&2; exit 1; }
    is_installed "$version" || { echo "PHP ${version} is not installed." >&2; exit 1; }
    require_root_access

    if ! replacement=$(newest_installed_version "$version"); then
        echo "Cannot remove the only installed PHP version. Install another version first." >&2
        exit 1
    fi
    if ! has_conductor_compatible_version "$version"; then
        echo "Cannot remove PHP ${version}: Conductor needs another installed PHP 8.5 or newer runtime." >&2
        exit 1
    fi

    while IFS= read -r package; do
        [ -n "$package" ] && packages+=("$package")
    done < <(dpkg-query -W -f='${binary:Package}\n' 2>/dev/null | awk -v version="$version" '
        {
            package = $0
            sub(/:.*/, "", package)
        }
        package == "php" version ||
        index(package, "php" version "-") == 1 ||
        package == "libapache2-mod-php" version
    ')

    "${SUDO[@]}" systemctl disable --now "php${version}-fpm" 2>/dev/null || true
    if [ "${#packages[@]}" -gt 0 ]; then
        "${SUDO[@]}" apt-get -y purge "${packages[@]}"
    fi
    "${SUDO[@]}" apt-get -y autoremove --purge

    # Purged packages can leave locally-created configuration behind.
    if [ -d "/etc/php/${version}" ]; then
        "${SUDO[@]}" rm -rf -- "/etc/php/${version}"
    fi

    set_conductor_default "$replacement"

    # Keep existing Conductor-generated virtual hosts usable when they referred
    # to the FPM version that was just removed.
    if [ -d /etc/conductor/configs ]; then
        while IFS= read -r config; do
            "${SUDO[@]}" sed -i \
                "s|/var/run/php/php${version}-fpm.sock|/var/run/php/php${replacement}-fpm.sock|g" \
                "$config"
        done < <(grep -rl --include='*.conf' "/var/run/php/php${version}-fpm.sock" /etc/conductor/configs 2>/dev/null || true)
    fi

    if command -v nginx >/dev/null 2>&1 && "${SUDO[@]}" nginx -t; then
        "${SUDO[@]}" systemctl reload nginx
    fi
    echo "PHP ${version} removed; PHP ${replacement} is now the Conductor default."
}

choose_version() {
    local action="$1"
    local version
    read -r -p "PHP version to ${action}: " version
    printf '%s\n' "$version"
}

case "${1:---interactive}" in
    --list)
        [ "$#" -eq 1 ] || { usage >&2; exit 1; }
        show_versions
        ;;
    --install)
        [ "$#" -eq 2 ] || { usage >&2; exit 1; }
        install_version "$2"
        show_versions
        ;;
    --uninstall)
        [ "$#" -eq 2 ] || { usage >&2; exit 1; }
        uninstall_version "$2"
        show_versions
        ;;
    --interactive)
        [ "$#" -eq 0 ] || { usage >&2; exit 1; }
        show_versions
        echo ""
        read -r -p "Choose [i]nstall, [u]ninstall, or [q]uit: " action
        case "${action,,}" in
            i|install) install_version "$(choose_version install)" ;;
            u|uninstall|remove) uninstall_version "$(choose_version uninstall)" ;;
            q|quit|"") exit 0 ;;
            *) echo "Unknown action: ${action}" >&2; exit 1 ;;
        esac
        echo ""
        show_versions
        ;;
    -h|--help)
        usage
        ;;
    *)
        usage >&2
        exit 1
        ;;
esac
