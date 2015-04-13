#!/usr/bin/env bash

################################################################################
# Conductor Installation Script for FreeBSD 10.1                               #
# Written by Bobby Allen <ballen@bobbyallen.me>, 08/04/2015                    # 
################################################################################

# A random password generation function to generate MySQL passwords.
passwordgen() {
    l=$1
    [ "$l" == "" ] && l=16
    tr -dc A-Za-z0-9 < /dev/urandom | head -c ${l} | xargs
}

# Lets grab the latest software packages that are available.
echo "Updating pkg package repository..."
pkg update
echo ""
echo "Installing some required tools..."
pkg install -y git gsed curl readline
echo ""
echo "Installing Nginx..."
pkg install -y nginx
echo ""
echo "Installing MySQL..."
pkg install -y mysql56-server mysql56-client
sh -c 'echo mysql_enable=\"YES\" >> /etc/rc.conf'
service mysql-server start
# Generate a random password that we'll set...
echo ""
echo "Setting new MySQL root password..."
randpassword=$(passwordgen);
echo "Securing MySQL configuration..."
# Configure MySQL with the new password and do a quick clean-up of the default MySQL installation stuff!
mysqladmin -u root password "$randpassword"
mysql -u root -p"$randpassword" -e "DELETE FROM mysql.user WHERE User='root' AND Host != 'localhost'";
mysql -u root -p"$randpassword" -e "DELETE FROM mysql.user WHERE User=''";
mysql -u root -p"$randpassword" -e "FLUSH PRIVILEGES";
mysql -u root -p"$randpassword" -e "DROP DATABASE IF EXISTS test";

# Lets now install PHP and the required PHP extenions
echo ""
echo "Installing PHP and PHP extensions..."
pkg install -y php56 php56-gd php56-hash php56-phar php56-ctype php56-filter php56-openssl php56-iconv php56-json php56-mbstring php56-mcrypt php56-curl php56-tokenizer php56-session php56-xml php56-simplexml sqlite3 php56-zip php56-zlib php56-readline php56-mysql php56-mysqli php56-sqlite3 php56-pdo php56-pdo_mysql php56-pdo_sqlite php56-posix

echo ""
echo "Installing APCu..."
pkg install -y pecl-APCu
#sh -c 'echo extension=apcu.so >> /usr/local/etc/php/extensions.ini'

# Lets enable the remaining services that we've just installed
echo ""
echo "Starting NginX and PHP-FPM..."
sh -c 'echo nginx_enable=\"YES\" >> /etc/rc.conf'
sh -c 'echo php_fpm_enable=\"YES\" >> /etc/rc.conf'

# Lets now create a default folder structure to hold all of our applications.
# Now we need to pull 'conductor' from GitHub and we'll now deploy the application ready for it to be used.
echo ""
echo "Downloading and installing Conductor..."
git clone https://github.com/bobsta63/conductor.git /etc/conductor
mkdir /var/conductor # We'll create a folder structure here to store all of the apps.
mkdir /var/conductor/applications
mkdir /var/conductor/certificates
mkdir /var/conductor/logs
mkdir /var/conductor/backups
mkdir /var/conductor/tmp

echo ""
echo "Downloading and installing Composer..."
curl -sS https://getcomposer.org/installer | php -- --install-dir=/etc/conductor/bin/composer
ln -s /etc/conductor/bin/composer/composer.phar /usr/bin/composer
chmod +x /usr/bin/composer

# Lets now set some permissions...
chmod +x /etc/conductor/bin/*
chmod +x /etc/conductor/utils/*

# Lets symlink the main conductor script...
ln -s /etc/conductor/bin/conductor.php /usr/bin/conductor
# Lets set the new symlink as executable
chmod +x /usr/bin/conductor

# We'll set the 'upgrade.sh' file as executable too (will save time for when the user next chooses to run it)
chmod +x /etc/conductor/upgrade.sh

# We now need to make some changes to the default nginx.conf file...
echo ""
echo "Configuring Nginx..."
sed -i -f "s/# HTTPS server/include \/etc\/conductor\/configs\/common\/conductor_nginx\.conf;/g" /usr/local/etc/nginx/nginx.conf

echo ""
echo "Configuring PHP-FPM for Nginx..."
sed -i -f "s/\listen = 127\.0\.0\.1\:9000/listen = \/var\/run\/php-fpm\.sock/g" /usr/local/etc/php-fpm.conf

# We'll now install Redis Server
echo ""
echo "Installing Redis..."
pkg install -y redis
sh -c 'echo redis_enable=\"YES\" >> /etc/rc.conf'
service redis start

# Now we'll install Beanstalkd
echo ""
echo "Installing Beanstalkd..."
pkg install -y beanstalkd
sh -c 'echo beanstalkd_enable=\"YES\" >> /etc/rc.conf'
service beanstalkd start

# Lets copy the configuration file template to /etc/conductor.conf for simplified administration.
cp /etc/conductor/bin/conf/conductor.freebsd.template.json /etc/conductor.conf

# Set the root password on our configuration script.
sed -i -f "s|ROOT_PASSWORD_HERE|$randpassword|" /etc/conductor.conf;

# Restarting services...
echo ""
echo "Restarting the web application server..."
service php-fpm restart
service nginx restart

echo ""
echo ""
echo "Congratulations! Conductor is now successfully installed you are running: "
conductor -v
echo ""