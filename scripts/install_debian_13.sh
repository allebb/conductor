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
sudo apt-get -y install curl gnupg ca-certificates lsb-release zip unzip git

################################################################################
# NGINX
################################################################################
sudo apt-get -y install nginx

################################################################################
# MariaDB 10.11 (Debian 13 default)
################################################################################
echo "Installing MariaDB..."
sudo apt-get -y install mariadb-server mariadb-client

echo "Configuring MariaDB root user..."
randpassword=$(passwordgen)

sudo mysql <<EOF
ALTER USER 'root'@'localhost' IDENTIFIED VIA mysql_native_password USING PASSWORD('${randpassword}');
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

# Supported PHP versions on Debian 13 via Sury
PHP_VERSIONS=("8.1" "8.2" "8.3" "8.4")

echo "Installing PHP versions: ${PHP_VERSIONS[*]}"

for v in "${PHP_VERSIONS[@]}"; do
    sudo apt-get -y install \
        php${v}-common php${v}-cli php${v}-fpm php${v}-curl php${v}-gd php${v}-intl \
        php${v}-mbstring php${v}-sqlite3 php${v}-mysql php${v}-bcmath php${v}-xml \
        php${v}-zip php${v}-apcu php${v}-memcache || true
done

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
