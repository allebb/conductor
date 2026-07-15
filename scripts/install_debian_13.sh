#!/usr/bin/env bash

################################################################################
# Conductor Installation Script for Debian 13 (Trixie)                       #
# Written by Bobby Allen <ballen@bobbyallen.me>, 07/04/2026                    #
################################################################################

# Exit early if there was an issue with the installation.
set -e

PROXY_ONLY=0
for arg in "$@"; do
    case "$arg" in
        --proxy-only)
            PROXY_ONLY=1
            ;;
        -h|--help)
            echo "Usage: sudo bash install_debian_13.sh [--proxy-only]"
            echo ""
            echo "Options:"
            echo "  --proxy-only    Install only Nginx and required PHP 8.5, skipping optional local services."
            exit 0
            ;;
        *)
            echo "Unknown option: ${arg}"
            echo "Usage: sudo bash install_debian_13.sh [--proxy-only]"
            exit 1
            ;;
    esac
done

passwordgen() {
    l=$1
    [ "$l" == "" ] && l=16
    tr -dc A-Za-z0-9 < /dev/urandom | head -c ${l} | xargs
}

print_conductor_banner() {
    cat <<'EOF'
   ______                __           __
  / ____/___  ____  ____/ /_  _______/ /_____  _____
 / /   / __ \/ __ \/ __  / / / / ___/ __/ __ \/ ___/
/ /___/ /_/ / / / / /_/ / /_/ / /__/ /_/ /_/ / /
\____/\____/_/ /_/\__,_/\__,_/\___/\__/\____/_/

EOF
}

prompt_letsencrypt_email() {
    local email=""
    local email_regex='^[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}$'

    while true; do
        read -r -p "LetsEncrypt notification email address: " email
        if [[ "$email" =~ $email_regex ]]; then
            sudo sed -i "s|update_this_now@localhost.com|$email|" /etc/conductor.conf
            echo "LetsEncrypt email address set to: ${email}"
            break
        fi
        echo "Please enter a valid email address (eg. admin@example.com)."
    done
}

prompt_yes_no() {
    local prompt="$1"
    local default="${2:-y}"
    local answer=""
    local suffix="[Y/n]"

    if [ "$default" = "n" ]; then
        suffix="[y/N]"
    fi

    while true; do
        read -r -p "${prompt} ${suffix} " answer
        answer="${answer:-$default}"
        case "${answer,,}" in
            y|yes) return 0 ;;
            n|no) return 1 ;;
            *) echo "Please answer yes or no." ;;
        esac
    done
}

required_tcp_port_label() {
    case "$1" in
        80) echo "HTTP web server" ;;
        443) echo "HTTPS web server" ;;
        3306) echo "MySQL/MariaDB database" ;;
        6379) echo "Redis" ;;
        *) echo "unknown service" ;;
    esac
}

is_tcp_port_listening() {
    local port_hex
    port_hex=$(printf '%04X' "$1")

    awk -v port="$port_hex" '
        $4 == "0A" {
            split($2, local_address, ":")
            if (toupper(local_address[2]) == port) {
                found = 1
            }
        }
        END { exit found ? 0 : 1 }
    ' /proc/net/tcp /proc/net/tcp6 2>/dev/null
}

check_required_tcp_ports() {
    local port
    local busy=0

    echo "Checking required TCP ports..."
    for port in "$@"; do
        if is_tcp_port_listening "$port"; then
            echo "Port ${port} ($(required_tcp_port_label "$port")) is already in use."
            busy=1
        fi
    done

    if [ "$busy" -ne 0 ]; then
        echo "Please stop (and REMOVE) the conflicting service(s) and run the installer again."
        exit 1
    fi
}

INSTALL_MYSQL=0
INSTALL_REDIS=0
INSTALL_SUPERVISOR=0
INSTALL_EXTRA_PHP=0
MYSQL_ROOT_PASSWORD="NOT_INSTALLED"

print_conductor_banner

if [ "$PROXY_ONLY" -eq 1 ]; then
    echo "Proxy-only install requested; skipping MySQL, Redis, SupervisorD, and additional PHP versions."
else
    if prompt_yes_no "Install MySQL locally?" "y"; then
        INSTALL_MYSQL=1
    fi

    if prompt_yes_no "Install Redis?" "y"; then
        INSTALL_REDIS=1
    fi

    if prompt_yes_no "Install SupervisorD?" "y"; then
        INSTALL_SUPERVISOR=1
    fi

    if prompt_yes_no "Install additional PHP versions (7.4, 8.1, 8.4) alongside required PHP 8.5?" "y"; then
        INSTALL_EXTRA_PHP=1
    fi
fi

REQUIRED_PORTS=(80 443)
if [ "$INSTALL_MYSQL" -eq 1 ]; then
    REQUIRED_PORTS+=(3306)
fi
if [ "$INSTALL_REDIS" -eq 1 ]; then
    REQUIRED_PORTS+=(6379)
fi

check_required_tcp_ports "${REQUIRED_PORTS[@]}"

echo "Updating system..."
sudo apt-get update
sudo apt-get -y install bash-completion curl wget gnupg ca-certificates lsb-release zip unzip git

################################################################################
# NGINX
################################################################################
sudo apt-get -y install nginx libnginx-mod-http-geoip2

if [ "$INSTALL_MYSQL" -eq 1 ]; then
    ################################################################################
    # MySQL (official Oracle MySQL APT repository)
    ################################################################################
    # Change this to eg. "mysql-8.4-lts" if you need the previous LTS series instead.
    MYSQL_SERVER_SERIES="mysql-9.7-lts"

    echo "Installing the official MySQL APT repository (${MYSQL_SERVER_SERIES})..."
    curl -fsSL https://repo.mysql.com/mysql-apt-config.deb -o /tmp/mysql-apt-config.deb

    # mysql-apt-config's own postinst reads these environment variables when
    # DEBIAN_FRONTEND=noninteractive, so this configures + installs the repo (and its
    # signing key, to /usr/share/keyrings/mysql-apt-config.gpg) without any prompts.
    sudo env DEBIAN_FRONTEND=noninteractive MYSQL_SERVER_VERSION="${MYSQL_SERVER_SERIES}" \
        dpkg -i /tmp/mysql-apt-config.deb
    rm -f /tmp/mysql-apt-config.deb

    sudo apt-get update
    sudo DEBIAN_FRONTEND=noninteractive apt-get -y install mysql-server mysql-client

    echo "Configuring MySQL root user..."
    MYSQL_ROOT_PASSWORD=$(passwordgen)

    # A blank root password during install leaves root@localhost on the auth_socket
    # plugin (passwordless, OS-user-matched login) - switch it to a real password here.
    sudo mysql <<EOF
ALTER USER 'root'@'localhost' IDENTIFIED WITH caching_sha2_password BY '${MYSQL_ROOT_PASSWORD}';
DELETE FROM mysql.user WHERE User='';
DELETE FROM mysql.user WHERE User='root' AND Host NOT IN ('localhost', '127.0.0.1', '::1');
DROP DATABASE IF EXISTS test;
DELETE FROM mysql.db WHERE Db='test' OR Db LIKE 'test\_%';
FLUSH PRIVILEGES;
EOF
else
    echo "Skipping local MySQL installation."
fi

################################################################################
# PHP (Sury repo)
################################################################################
echo "Adding Sury PHP repository..."
sudo curl -sSLo /usr/share/keyrings/deb.sury.org-php.gpg https://packages.sury.org/php/apt.gpg
echo "deb [signed-by=/usr/share/keyrings/deb.sury.org-php.gpg] https://packages.sury.org/php/ $(lsb_release -sc) main" \
    | sudo tee /etc/apt/sources.list.d/php.list

sudo apt-get update

# Supported PHP versions on Debian 13 via Sury. PHP 8.5 is required and always installed.
PHP_DEFAULT_VERSION="8.5"
PHP_VERSIONS=("8.5")
if [ "$INSTALL_EXTRA_PHP" -eq 1 ]; then
    PHP_VERSIONS=("7.4" "8.1" "8.4" "8.5")
fi

echo "Installing PHP versions: ${PHP_VERSIONS[*]}"

for v in "${PHP_VERSIONS[@]}"; do
    sudo apt-get -y install \
        php${v}-common php${v}-cli php${v}-fpm php${v}-curl php${v}-gd php${v}-intl \
        php${v}-mbstring php${v}-sqlite3 php${v}-mysql php${v}-bcmath php${v}-xml \
        php${v}-zip php${v}-apcu

    # The legacy `memcache` PECL extension isn't packaged for every PHP release
    # (eg. missing for 8.5 at the time of writing), so install it best-effort and
    # on its own, rather than letting a missing package block the whole set above.
    sudo apt-get -y install php${v}-memcache || true
done

echo "Setting PHP ${PHP_DEFAULT_VERSION} as the default 'php' CLI binary..."
sudo update-alternatives --set php /usr/bin/php${PHP_DEFAULT_VERSION}

################################################################################
# Certbot (replaces deprecated letsencrypt)
################################################################################
sudo apt-get -y install certbot python3-certbot-nginx

################################################################################
# DH Params
################################################################################
sudo openssl dhparam -out /etc/ssl/certs/dhparam.pem 4096

################################################################################
# Conductor Deployment
################################################################################
BRANCH_INSTALL="${BRANCH_INSTALL:-stable}"
echo "Installer requested branch checkout: ${BRANCH_INSTALL}"

sudo git clone https://github.com/allebb/conductor.git /etc/conductor
cd /etc/conductor
sudo git checkout "${BRANCH_INSTALL}"
cd -

sudo mkdir -p /var/conductor/{applications,certificates,logs,backups,tmp,geoip}
sudo mkdir -p /etc/conductor/auth
sudo chmod 755 /etc/conductor/auth

sudo mkdir -p /var/www/.cache
sudo chown -R www-data:www-data /var/www/.cache

sudo mkdir -p /var/www/.ssh
echo "Host *" > /var/www/.ssh/config
echo "    StrictHostKeyChecking no" >> /var/www/.ssh/config
sudo chown -R www-data:www-data /var/www/.ssh

sudo cp /etc/conductor/templates/index.html /var/www/html
sudo chown www-data:www-data /var/www/html/index.html

sudo cp /etc/conductor/templates/ssl-params.conf /etc/nginx/snippets/

################################################################################
# Composer
################################################################################
sudo curl -sS https://getcomposer.org/installer | sudo php -- --install-dir=/etc/conductor/bin/composer
sudo ln -sf /etc/conductor/bin/composer/composer.phar /usr/bin/composer
sudo chmod +x /usr/bin/composer

sudo chmod +x /etc/conductor/bin/*
sudo chmod +x /etc/conductor/utils/*
sudo /etc/conductor/utils/install_nginx_streams.sh
sudo install -m 0644 /etc/conductor/configs/common/completion/conductor.bash /etc/bash_completion.d/conductor

sudo ln -sf /etc/conductor/bin/conductor.php /usr/bin/conductor
sudo chmod +x /usr/bin/conductor

sudo chmod +x /etc/conductor/upgrade.sh

################################################################################
# Nginx Configuration
################################################################################
sudo sed -i "s|include /etc/nginx/sites-enabled/\*|include /etc/conductor/configs/common/conductor_nginx.conf|g" /etc/nginx/nginx.conf
sudo sed -i "s/# server_tokens off;/server_tokens off;/g" /etc/nginx/nginx.conf

################################################################################
# Conductor Config
################################################################################
sudo cp /etc/conductor/bin/conf/conductor.debian.template.json /etc/conductor.conf
sudo sed -i "s|ROOT_PASSWORD_HERE|$MYSQL_ROOT_PASSWORD|" /etc/conductor.conf
if [ "$INSTALL_MYSQL" -eq 0 ]; then
    sudo sed -i '0,/"enabled": true/s//"enabled": false/' /etc/conductor.conf
fi
if [ "$PROXY_ONLY" -eq 1 ]; then
    sudo sed -i 's|"default_template": "laravel"|"default_template": "proxy"|' /etc/conductor.conf
fi

echo "Downloading GeoIP country database..."
sudo conductor geoipdb update

################################################################################
# PHP-FPM Security Fix
################################################################################
for v in "${PHP_VERSIONS[@]}"; do
    sudo sed -i "s/;cgi.fix_pathinfo=1/cgi.fix_pathinfo=0/" /etc/php/${v}/fpm/php.ini
done

if [ "$INSTALL_REDIS" -eq 1 ]; then
    ################################################################################
    # Redis
    ################################################################################
    sudo curl -fsSL https://packages.redis.io/gpg | sudo gpg --dearmor -o /usr/share/keyrings/redis-archive-keyring.gpg
    echo "deb [signed-by=/usr/share/keyrings/redis-archive-keyring.gpg] https://packages.redis.io/deb $(lsb_release -cs) main" \
        | sudo tee /etc/apt/sources.list.d/redis.list

    sudo apt-get update
    sudo apt-get install -y redis-server
    sudo systemctl enable --now redis-server
else
    echo "Skipping Redis installation."
fi

if [ "$INSTALL_SUPERVISOR" -eq 1 ]; then
    ################################################################################
    # Supervisor
    ################################################################################
    sudo apt-get -y install supervisor
    sudo systemctl enable --now supervisor
else
    echo "Skipping SupervisorD installation."
fi

################################################################################
# Restart services
################################################################################
for v in "${PHP_VERSIONS[@]}"; do
    sudo systemctl restart php${v}-fpm
done

sudo systemctl restart nginx
prompt_letsencrypt_email

echo ""
if [ "$INSTALL_MYSQL" -eq 1 ]; then
    echo "MySQL server root password has been set to: ${MYSQL_ROOT_PASSWORD}"
else
    echo "MySQL was not installed; Conductor MySQL management has been disabled."
fi
echo ""
sudo conductor -v
echo ""
echo "Bash completion for conductor has been installed."
echo "Open a new shell to use it, or enable it immediately with:"
echo "  source /etc/bash_completion.d/conductor"
echo ""
