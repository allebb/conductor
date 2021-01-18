#!/usr/bin/env bash

################################################################################
# Conductor Installation Script for Ubuntu Server 20.04 LTS                    #
# Written by Bobby Allen <ballen@bobbyallen.me>, 16/09/2019                    # 
################################################################################

# A random password generation function to generate MySQL passwords.
passwordgen() {
    l=$1
    [ "$l" == "" ] && l=16
    tr -dc A-Za-z0-9 < /dev/urandom | head -c ${l} | xargs
}

# Ask the user here if they wish to install MySQL locally or not, if they choose
# not we need to prompt the user for their remote DB server and user credentials.

sudo apt-get update
sudo apt-get -y install software-properties-common debconf-i18n

# We now install Nginx
sudo apt-get -y install nginx

# Now we'll install MariaDB Server and set a default 'root' password, in future we'll generate a random one!
export DEBIAN_FRONTEND="noninteractive"
sudo debconf-set-selections <<< "mariadb-server-10.3 mysql-server/root_password password root"
sudo debconf-set-selections <<< "mariadb-server-10.3 mysql-server/root_password_again password root"

sudo apt update
sudo apt-get -y install mariadb-server

# Set the new random password and do some system clean-up of the default MySQL tables.
randpassword=$(passwordgen);

# Set a random MariaDB root password...
mysqladmin -u root -proot password "$randpassword"
mysql -u root -p"$randpassword" -e "DELETE FROM mysql.user WHERE User='root' AND Host != 'localhost'";
mysql -u root -p"$randpassword" -e "DELETE FROM mysql.user WHERE User=''";
mysql -u root -p"$randpassword" -e "FLUSH PRIVILEGES";
mysql -u root -p"$randpassword" -e "DROP DATABASE IF EXISTS test";

# Enable the Universe repository (since Ubuntu 18.04 various packages are supplied in the universe repo eg. libzip4.0, beanstalkd, supervisor and letsencrypt)...
sudo add-apt-repository universe

# Install some Zip libraries required by some PHP modules.
sudo apt-get install -y zip unzip

# Add the ondrej/php PPA (so we get the latest PHP versions...)
sudo add-apt-repository ppa:ondrej/php -y
sudo apt-get update

# Lets add PHP 7.4 (using the default distro packages)
sudo apt-get -y install php7.4-common php7.4-cli php7.4-fpm php7.4-curl php7.4-gd php7.4-intl php7.4-mbstring php7.4-sqlite3 php7.4-mysql php7.4-json php7.4-bcmath php7.4-xml php7.4-memcache php7.4-apcu

# Add PHP 8.0 (using the ondrej/php PPA)
sudo apt-get -y install php8.0-common php8.0-cli php8.0-fpm php8.0-curl php8.0-gd php8.0-intl php8.0-mbstring php8.0-sqlite3 php8.0-mysql php8.0-bcmath php8.0-xml php8.0-memcache php8.0-apcu

# Now we will install the ZIP extension for PHP...
sudo apt-get install -y php7.4-zip php8.0-zip

# We install the Git Client to enable auto deployments etc.
sudo apt-get -y install git

# We now install the LetsEncrypt client
sudo apt-get -y install letsencrypt

# Create a strong Diffie-Hellman Group
sudo openssl dhparam -out /etc/ssl/certs/dhparam.pem 2048

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
sudo mkdir /var/conductor/backups
sudo mkdir /var/conductor/tmp

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

echo "Configuring PHP-FPM for Nginx..."
# On Ubuntu 14.04 the following is already listening on a socket so this can be ignored!
#sudo sed -i "s/\listen = 127\.0\.0\.1\:9000/listen = \/tmp\/php5-fpm\.sock/g" /etc/php/7.0/fpm/pool.d/www.conf
# Change cgi.fix_pathinfo=1 to cgi.fix_pathinfo=0

echo "Securing cgi.fix_pathinfo..."
sudo sed -i "s/;cgi.fix_pathinfo=1/cgi.fix_pathinfo=0/" /etc/php/7.4/fpm/php.ini
sudo sed -i "s/;cgi.fix_pathinfo=1/cgi.fix_pathinfo=0/" /etc/php/8.0/fpm/php.ini

# We'll now install Redis Server
sudo apt-get -y install redis-server
sudo /etc/init.d/redis-server restart

# Now we'll install Beanstalkd
sudo apt-get -y install beanstalkd
sudo /etc/init.d/beanstalkd start

# Install Supervisord (if requested at installation)
sudo apt-get -y install supervisor
sudo systemctl enable supervisor.service
sudo /etc/init.d/supervisor start

#Lets now restart PHP-FPM and Nginx!
sudo /etc/init.d/php7.4-fpm restart
sudo /etc/init.d/php8.0-fpm restart
sudo /etc/init.d/nginx restart

# Lets copy the configuration file template to /etc/conductor.conf for simplified administration.
sudo cp /etc/conductor/bin/conf/conductor.ubuntu.template.json /etc/conductor.conf

# Ubuntu 16.04 specific replacements in the Ubuntu Server configuration.
sudo sed -i "s/\/etc\/init.d\/php5-pfm/\/etc\/init.d\/php7.4-pfm/g" /etc/php/7.4/fpm/pool.d/www.conf
sudo sed -i "s/\/etc\/init.d\/php5-pfm/\/etc\/init.d\/php8.0-pfm/g" /etc/php/8.0/fpm/pool.d/www.conf

# Set the root password on our configuration script.
sudo sed -i "s|ROOT_PASSWORD_HERE|$randpassword|" /etc/conductor.conf;
echo ""
echo "MySQL server root password has been set to: ${randpassword}"
echo ""
echo "Congratulations! Conductor is now successfully installed you are running: "
sudo conductor -v
echo ""
