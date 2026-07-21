#!/usr/bin/env bash

################################################################################
# Conductor Installation Script for Debian 12 (Bookworm)                       #
# Written by Bobby Allen <ballen@bobbyallen.me>, 22/08/2023                    #
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
            echo "Usage: sudo bash install_debian_12.sh [--proxy-only]"
            echo ""
            echo "Options:"
            echo "  --proxy-only    Install only Nginx and required PHP 8.5, skipping optional local services."
            exit 0
            ;;
        *)
            echo "Unknown option: ${arg}"
            echo "Usage: sudo bash install_debian_12.sh [--proxy-only]"
            exit 1
            ;;
    esac
done

# A random password generation function to generate MySQL passwords.
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

    echo ""
    echo "Conductor uses this email address when requesting LetsEncrypt certificates."
    echo "Certbot may use it for important certificate expiry, renewal, and account notices."

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

prompt_fail2ban_support() {
    echo ""
    if prompt_yes_no "Install optional Fail2Ban/nftables support now?" "n"; then
        sudo bash /etc/conductor/utils/install_fail2ban_nftables.sh
    else
        echo "Skipping Fail2Ban/nftables support installation."
    fi
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

    if prompt_yes_no "Install additional PHP versions (7.4, 8.0, 8.1, 8.2) alongside required PHP 8.5?" "y"; then
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

# Ask the user if they would like us to auto-configure a firewall with suggested
# ports being opened by default (80, 443, 22).
# @todo

sudo apt-get update
sudo apt-get -y install software-properties-common debconf-i18n bash-completion curl logrotate

# We now install Nginx
sudo apt-get -y install nginx libnginx-mod-http-geoip2

if [ "$INSTALL_MYSQL" -eq 1 ]; then
    # Now we'll install MariaDB Server and set a default 'root' password, in future we'll generate a random one!
    export DEBIAN_FRONTEND="noninteractive"
    sudo debconf-set-selections <<< "mariadb-server-10.3 mysql-server/root_password password root"
    sudo debconf-set-selections <<< "mariadb-server-10.3 mysql-server/root_password_again password root"

    sudo apt update
    sudo apt-get -y install mariadb-server

    # Set the new random password and do some system clean-up of the default MySQL tables.
    MYSQL_ROOT_PASSWORD=$(passwordgen)

    # Set a random MariaDB root password...
    mysqladmin -u root -proot password "$MYSQL_ROOT_PASSWORD"
    mysql -u root -p"$MYSQL_ROOT_PASSWORD" -e "DELETE FROM mysql.user WHERE User=''";
    mysql -u root -p"$MYSQL_ROOT_PASSWORD" -e "DELETE FROM mysql.user WHERE User='root' AND Host NOT IN ('localhost', '127.0.0.1', '::1');"
    mysql -u root -p"$MYSQL_ROOT_PASSWORD" -e "DELETE FROM mysql.user WHERE User=''";
    mysql -u root -p"$MYSQL_ROOT_PASSWORD" -e "DROP DATABASE IF EXISTS test";
    mysql -u root -p"$MYSQL_ROOT_PASSWORD" -e "DELETE FROM mysql.db WHERE Db='test' OR Db='test\\_%';"
    mysql -u root -p"$MYSQL_ROOT_PASSWORD" -e "FLUSH PRIVILEGES";
else
    echo "Skipping local MySQL installation."
fi

# Debian packages are installed from the configured Debian and third-party repositories above.

# Install some Zip libraries required by some PHP modules.
sudo apt-get install -y zip unzip

# Add the sury/php APT repository (so we get the latest PHP versions...)
sudo curl -sSLo /usr/share/keyrings/deb.sury.org-php.gpg https://packages.sury.org/php/apt.gpg
sudo sh -c 'echo "deb [signed-by=/usr/share/keyrings/deb.sury.org-php.gpg] https://packages.sury.org/php/ $(lsb_release -sc) main" > /etc/apt/sources.list.d/php.list'

sudo apt-get update

PHP_DEFAULT_VERSION="8.5"
PHP_VERSIONS=("8.5")
if [ "$INSTALL_EXTRA_PHP" -eq 1 ]; then
    PHP_VERSIONS=("7.4" "8.0" "8.1" "8.2" "8.5")
fi

echo "Installing PHP versions: ${PHP_VERSIONS[*]}"

for v in "${PHP_VERSIONS[@]}"; do
    sudo apt-get -y install \
        php${v}-common php${v}-cli php${v}-fpm php${v}-curl php${v}-gd php${v}-intl \
        php${v}-mbstring php${v}-sqlite3 php${v}-mysql php${v}-bcmath php${v}-xml \
        php${v}-zip php${v}-apcu

    sudo apt-get -y install php${v}-memcache || true
done

echo "Setting PHP ${PHP_DEFAULT_VERSION} as the default 'php' CLI binary..."
sudo update-alternatives --set php /usr/bin/php${PHP_DEFAULT_VERSION}

# We install the Git Client to enable auto deployments etc.
sudo apt-get -y install git

# We now install the LetsEncrypt client
sudo apt-get -y install letsencrypt

# Create a strong Diffie-Hellman Group
sudo openssl dhparam -out /etc/ssl/certs/dhparam.pem 4096 # Increased from 2048 in previous version!

# Lets now create a default folder structure to hold all of our applications.
# Now we need to pull 'conductor' from GitHub and we'll now deploy the application ready for it to be used.
echo "Installer requested branch checkout: ${BRANCH_INSTALL}"
export CURRENTDIR=`pwd`
sudo git clone https://github.com/allebb/conductor.git /etc/conductor
cd /etc/conductor
sudo git checkout ${BRANCH_INSTALL}
cd $CURRENTDIR

# Create some required directories
sudo mkdir /var/conductor # We'll create a folder structure here to store all of the apps.
sudo mkdir /var/conductor/applications
sudo mkdir /var/conductor/certificates
sudo mkdir /var/conductor/logs
sudo mkdir /var/conductor/seclogs
sudo mkdir /var/conductor/backups
sudo mkdir /var/conductor/tmp
sudo mkdir /var/conductor/geoip
sudo mkdir /var/conductor/error-pages
sudo mkdir /var/conductor/cache
sudo mkdir /var/conductor/cache/nginx-proxy
sudo chown -R www-data:www-data /var/conductor/cache
sudo mkdir /etc/conductor/pwdbs
sudo chmod 755 /etc/conductor/pwdbs
sudo mkdir /etc/conductor/wafs
sudo chmod 755 /etc/conductor/wafs

# Create the composer cache directory and set the required ownership
sudo mkdir /var/www/.cache
sudo chown -R www-data:www-data /var/www/.cache

# Create a SSH key directory for the www-data user
sudo mkdir /var/www/.ssh

# Disable Host Key checking (will look at improving in future!)
echo "Host *" > /var/www/.ssh/config
echo "    StrictHostKeyChecking no" >> /var/www/.ssh/config
echo "    #UserKnownHostsFile /dev/null" >> /var/www/.ssh/config

# Set the ownership for /var/www/.ssh
sudo chown -R www-data:www-data /var/www/.ssh

# Create a blank default file.
sudo cp /etc/conductor/templates/index.html /var/www/html
sudo chown www-data:www-data /var/www/html/index.html
sudo cp /etc/conductor/configs/common/templates/401.html.tpl /var/conductor/error-pages/401.html
sudo cp /etc/conductor/configs/common/templates/403.html.tpl /var/conductor/error-pages/403.html
sudo cp /etc/conductor/configs/common/templates/404.html.tpl /var/conductor/error-pages/404.html
sudo cp /etc/conductor/configs/common/templates/406.html.tpl /var/conductor/error-pages/406.html
sudo cp /etc/conductor/configs/common/templates/500.html.tpl /var/conductor/error-pages/500.html
sudo cp /etc/conductor/configs/common/templates/502.html.tpl /var/conductor/error-pages/502.html
sudo cp /etc/conductor/configs/common/templates/503.html.tpl /var/conductor/error-pages/503.html
sudo cp /etc/conductor/configs/common/templates/504.html.tpl /var/conductor/error-pages/504.html
sudo chown www-data:www-data /var/conductor/error-pages/401.html
sudo chown www-data:www-data /var/conductor/error-pages/403.html
sudo chown www-data:www-data /var/conductor/error-pages/404.html
sudo chown www-data:www-data /var/conductor/error-pages/406.html
sudo chown www-data:www-data /var/conductor/error-pages/500.html
sudo chown www-data:www-data /var/conductor/error-pages/502.html
sudo chown www-data:www-data /var/conductor/error-pages/503.html
sudo chown www-data:www-data /var/conductor/error-pages/504.html

# Copy the SSL params settings to the server...
sudo cp /etc/conductor/templates/ssl-params.conf /etc/nginx/snippets;

# Install Composer
sudo curl -sS https://getcomposer.org/installer | sudo php -- --install-dir=/etc/conductor/bin/composer
sudo ln -s /etc/conductor/bin/composer/composer.phar /usr/bin/composer
# Lets set the new symlink as executable
sudo chmod +x /usr/bin/composer

# Lets now set some permissions...
sudo chmod +x /etc/conductor/bin/*
sudo chmod +x /etc/conductor/utils/*
sudo /etc/conductor/utils/install_nginx_streams.sh
sudo install -m 0644 /etc/conductor/configs/common/completion/conductor.bash /etc/bash_completion.d/conductor
sudo install -d -m 0755 /etc/logrotate.d
sudo install -m 0644 /etc/conductor/configs/common/logrotate/* /etc/logrotate.d/

# Lets symlink the main conductor script...
sudo ln -s /etc/conductor/bin/conductor.php /usr/bin/conductor
# Lets set the new symlink as executable
sudo chmod +x /usr/bin/conductor

# We'll set the 'upgrade.sh' file as executable too (will save time for when the user next chooses to run it)
sudo chmod +x /etc/conductor/upgrade.sh

# We now need to make some changes to the default nginx.conf file...
echo "Configuring Nginx..."
sudo sed -i "s/include \/etc\/nginx\/sites-enabled\/\*/include \/etc\/conductor\/configs\/common\/conductor_nginx\.conf/g" /etc/nginx/nginx.conf
sudo sed -i "s/# server_tokens off\;/server_tokens off\;/g" /etc/nginx/nginx.conf

# Lets copy the configuration file template to /etc/conductor.conf for simplified administration.
sudo cp /etc/conductor/bin/conf/conductor.debian.template.json /etc/conductor.conf

# Set the root password on our configuration script.
sudo sed -i "s|ROOT_PASSWORD_HERE|$MYSQL_ROOT_PASSWORD|" /etc/conductor.conf;
if [ "$INSTALL_MYSQL" -eq 0 ]; then
    sudo sed -i '0,/"enabled": true/s//"enabled": false/' /etc/conductor.conf
fi
if [ "$PROXY_ONLY" -eq 1 ]; then
    sudo sed -i 's|"default_template": "laravel"|"default_template": "proxy"|' /etc/conductor.conf
fi

echo "Downloading GeoIP country database..."
sudo conductor geoipdb --update

echo "Configuring PHP-FPM for Nginx..."
# Change cgi.fix_pathinfo=1 to cgi.fix_pathinfo=0

echo "Securing cgi.fix_pathinfo..."
for v in "${PHP_VERSIONS[@]}"; do
    sudo sed -i "s/;cgi.fix_pathinfo=1/cgi.fix_pathinfo=0/" /etc/php/${v}/fpm/php.ini
done

if [ "$INSTALL_REDIS" -eq 1 ]; then
    # From the official Redis APT repository (ensure we get the latest version)
    sudo curl -fsSL https://packages.redis.io/gpg | sudo gpg --dearmor -o /usr/share/keyrings/redis-archive-keyring.gpg
    echo "deb [signed-by=/usr/share/keyrings/redis-archive-keyring.gpg] https://packages.redis.io/deb $(lsb_release -cs) main" | sudo tee /etc/apt/sources.list.d/redis.list
    sudo apt-get update
    sudo apt-get install redis-server -y
    sudo systemctl enable redis-server.service
    sudo /etc/init.d/redis-server restart
else
    echo "Skipping Redis installation."
fi

if [ "$INSTALL_SUPERVISOR" -eq 1 ]; then
    # Install Supervisord (if requested at installation)
    sudo apt-get -y install supervisor
    sudo systemctl enable supervisor.service
    sudo /etc/init.d/supervisor start
else
    echo "Skipping SupervisorD installation."
fi

#Lets now restart PHP-FPM and Nginx!
for v in "${PHP_VERSIONS[@]}"; do
    sudo /etc/init.d/php${v}-fpm restart
done
sudo /etc/init.d/nginx restart
prompt_letsencrypt_email
echo ""
if [ "$INSTALL_MYSQL" -eq 1 ]; then
    echo "MySQL server root password has been set to: ${MYSQL_ROOT_PASSWORD}"
else
    echo "MySQL was not installed; Conductor MySQL management has been disabled."
fi
echo ""
echo "Congratulations! Conductor is now successfully installed you are running: "
sudo conductor -v
echo ""
echo "Bash completion for conductor has been installed."
echo "Open a new shell to use it, or enable it immediately with:"
echo "  source /etc/bash_completion.d/conductor"
echo ""
prompt_fail2ban_support
