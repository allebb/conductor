#!/usr/bin/env bash

################################################################################
# Conductor Installation Script for Ubuntu Server 18.04 LTS                    #
# Written by Bobby Allen <ballen@bobbyallen.me>, 25/02/2019                    # 
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

# Now we'll obtain the MariaDB package information
sudo apt-key adv --recv-keys --keyserver hkp://keyserver.ubuntu.com:80 0xF1656F24C74CD1D8
sudo add-apt-repository 'deb [arch=amd64,arm64,ppc64el] http://ftp.utexas.edu/mariadb/repo/10.3/ubuntu bionic main'
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

# We specifically specify 'php7.2-common' as we don't want Apache etc installed too!
#sudo apt-get -y install php7.2-common php7.2-cli php7.2-fpm php7.2-curl php7.2-gd php7.2-mcrypt php7.2-intl php7.2-mbstring php7.2-zip php7.2-sqlite3 php7.2-mysql php7.2-json php7.2-dom php7.2-bcmath php-memcache php-apcu
#sudo service nginx restart
#sudo service php7.2-fpm restart

# Enable the Universe repository (since Ubuntu 18.04 various packages are supplied in the universe repo eg. libzip4.0, beanstalkd, supervisor and letsencrypt)...
sudo add-apt-repository universe

# Install some Zip libraries required by PHP7.3-zip
sudo apt-get install -y zip unzip

# Lets add PHP 7.4
sudo touch /etc/apt/sources.list.d/ondrej-php.list
echo "deb http://ppa.launchpad.net/ondrej/php/ubuntu bionic main" | sudo tee -a /etc/apt/sources.list.d/ondrej-php.list
echo "deb-src http://ppa.launchpad.net/ondrej/php/ubuntu bionic main" | sudo tee -a /etc/apt/sources.list.d/ondrej-php.list
sudo apt-key adv --keyserver keyserver.ubuntu.com --recv-keys 4F4EA0AAE5267A6C
sudo apt-get update
sudo apt-get -y install php7.4-common php7.4-cli php7.4-fpm php7.4-curl php7.4-gd php7.4-intl php7.4-mbstring php7.4-sqlite3 php7.4-mysql php7.4-json php7.4-bcmath php7.4-xml php-memcache php-apcu

# Now we will install the ZIP extension for PHP...
sudo apt-get install -y php7.4-zip

# We install the Git Client to enable auto deployments etc.
sudo apt-get -y install git

# We now install the LetsEncrypt client
sudo apt-get -y install letsencrypt

# Create a strong Diffie-Hellman Group
sudo openssl dhparam -out /etc/ssl/certs/dhparam.pem 2048

# Lets now create a default folder structure to hold all of our applications.
# Now we need to pull 'conductor' from GitHub and we'll now deploy the application ready for it to be used.
sudo git clone https://github.com/bobsta63/conductor.git /etc/conductor

# Create some required directories
sudo mkdir /var/conductor # We'll create a folder structure here to store all of the apps.
sudo mkdir /var/conductor/applications
sudo mkdir /var/conductor/certificates
sudo mkdir /var/conductor/logs
sudo mkdir /var/conductor/backups
sudo mkdir /var/conductor/tmp

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
sudo /etc/init.d/nginx restart

# Lets copy the configuration file template to /etc/conductor.conf for simplified administration.
sudo cp /etc/conductor/bin/conf/conductor.ubuntu.template.json /etc/conductor.conf

# Ubuntu 16.04 specific replacements in the Ubuntu Server configuration.
sudo sed -i "s/\/etc\/init.d\/php5-pfm/\/etc\/init.d\/php7.4-pfm/g" /etc/php/7.4/fpm/pool.d/www.conf

# Set the root password on our configuration script.
sudo sed -i "s|ROOT_PASSWORD_HERE|$randpassword|" /etc/conductor.conf;

echo "Congratulations! Conductor is now successfully installed you are running: "
sudo conductor -v
echo ""
