#!/usr/bin/env bash
set -e

################################################################################
# Conductor Installation Script for Debian 13 (Trixie)
################################################################################

passwordgen() {
    l=$1
    [ "$l" == "" ] && l=16
    tr -dc A-Za-z0-9 < /dev/urandom | head -c ${l} | xargs
}

echo "Updating system..."
sudo apt-get update
sudo apt-get -y install curl wget gnupg ca-certificates lsb-release zip unzip git

################################################################################
# NGINX
################################################################################
sudo apt-get -y install nginx

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
randpassword=$(passwordgen)

# A blank root password during install leaves root@localhost on the auth_socket
# plugin (passwordless, OS-user-matched login) - switch it to a real password here.
sudo mysql <<EOF
ALTER USER 'root'@'localhost' IDENTIFIED WITH caching_sha2_password BY '${randpassword}';
DELETE FROM mysql.user WHERE User='';
DELETE FROM mysql.user WHERE User='root' AND Host NOT IN ('localhost', '127.0.0.1', '::1');
DROP DATABASE IF EXISTS test;
DELETE FROM mysql.db WHERE Db='test' OR Db LIKE 'test\_%';
FLUSH PRIVILEGES;
EOF

################################################################################
# PHP (Sury repo)
################################################################################
echo "Adding Sury PHP repository..."
sudo curl -sSLo /usr/share/keyrings/deb.sury.org-php.gpg https://packages.sury.org/php/apt.gpg
echo "deb [signed-by=/usr/share/keyrings/deb.sury.org-php.gpg] https://packages.sury.org/php/ $(lsb_release -sc) main" \
    | sudo tee /etc/apt/sources.list.d/php.list

sudo apt-get update

# Supported PHP versions on Debian 13 via Sury. PHP 8.5 is the default/active version (see below).
PHP_VERSIONS=("7.4" "8.1" "8.4" "8.5")
PHP_DEFAULT_VERSION="8.5"

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

sudo mkdir -p /var/conductor/{applications,certificates,logs,backups,tmp}

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

sudo ln -sf /etc/conductor/bin/conductor.php /usr/bin/conductor
sudo chmod +x /usr/bin/conductor

sudo chmod +x /etc/conductor/upgrade.sh

################################################################################
# Nginx Configuration
################################################################################
sudo sed -i "s|include /etc/nginx/sites-enabled/\*|include /etc/conductor/configs/common/conductor_nginx.conf|g" /etc/nginx/nginx.conf
sudo sed -i "s/# server_tokens off;/server_tokens off;/g" /etc/nginx/nginx.conf

################################################################################
# PHP-FPM Security Fix
################################################################################
for v in "${PHP_VERSIONS[@]}"; do
    sudo sed -i "s/;cgi.fix_pathinfo=1/cgi.fix_pathinfo=0/" /etc/php/${v}/fpm/php.ini
done

################################################################################
# Redis
################################################################################
sudo curl -fsSL https://packages.redis.io/gpg | sudo gpg --dearmor -o /usr/share/keyrings/redis-archive-keyring.gpg
echo "deb [signed-by=/usr/share/keyrings/redis-archive-keyring.gpg] https://packages.redis.io/deb $(lsb_release -cs) main" \
    | sudo tee /etc/apt/sources.list.d/redis.list

sudo apt-get update
sudo apt-get install -y redis-server
sudo systemctl enable --now redis-server

################################################################################
# Supervisor
################################################################################
sudo apt-get -y install supervisor
sudo systemctl enable --now supervisor

################################################################################
# Restart services
################################################################################
for v in "${PHP_VERSIONS[@]}"; do
    sudo systemctl restart php${v}-fpm
done

sudo systemctl restart nginx

################################################################################
# Conductor Config
################################################################################
sudo cp /etc/conductor/bin/conf/conductor.ubuntu.template.json /etc/conductor.conf
sudo sed -i "s|ROOT_PASSWORD_HERE|$randpassword|" /etc/conductor.conf

echo ""
echo "MySQL server root password has been set to: ${randpassword}"
echo ""
sudo conductor -v
echo ""
echo "Edit /etc/conductor.conf and set admin.email for Certbot."
echo ""
