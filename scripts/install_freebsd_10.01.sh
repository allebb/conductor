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
pkg update
pkg install --yes git gsed

# We now install Nginx
pkg install --yes nginx

# Lets now install PHP and the required PHP extenions
pkg install --yes php56 php56-gd php56-hash php56-phar php56-ctype php56-filter php56-iconv php56-json php56-mcrypt php56-curl php56-mysql php56-mysqli php56-pdo_mysql php56-sqlite3 php56-pdo_sqlite php56-tokenizer php56-readline php56-session php56-simplexml php56-xml php56-zip php56-zlib php56-openssl openssl

# We now install MySQL and set a random root password...
pkg install --yes mysql
sh -c 'echo mysql_enable=\"YES\" >> /etc/rc.conf'
service mysql-server start
mysqladmin -u root -proot password "$randpassword"
mysql -u root -p"$randpassword" -e "DELETE FROM mysql.user WHERE User='root' AND Host != 'localhost'";
mysql -u root -p"$randpassword" -e "DELETE FROM mysql.user WHERE User=''";
mysql -u root -p"$randpassword" -e "FLUSH PRIVILEGES";
mysql -u root -p"$randpassword" -e "DROP DATABASE IF EXISTS test";

# Lets enable the remaining services that we've just installed
sh -c 'echo nginx_enable=\"YES\" >> /etc/rc.conf'
sh -c 'echo php_fpm_enable=\"YES\" >> /etc/rc.conf'

# Lets now create a default folder structure to hold all of our applications.
# Now we need to pull 'conductor' from GitHub and we'll now deploy the application ready for it to be used.
git clone https://github.com/bobsta63/conductor.git /etc/conductor
mkdir /var/conductor # We'll create a folder structure here to store all of the apps.
mkdir /var/conductor/applications
mkdir /var/conductor/certificates
mkdir /var/conductor/logs
mkdir /var/conductor/backups
mkdir /var/conductor/tmp

# Now we'll install Composer
curl -sS https://getcomposer.org/installer | php -- --install-dir=/etc/conductor/bin/composer
ln -s /etc/conductor/bin/composer/composer.phar /usr/bin/composer
chmod +x /usr/bin/composer

# Lets now set some permissions...
chmod +x /etc/conductor/bin/*
chmod +x /etc/conductor/utils/*

# Lets symlink the main conductor script...
ln -s /etc/conductor/bin/conductor /usr/bin/conductor
# Lets set the new symlink as executable
chmod +x /usr/bin/conductor

# We'll set the 'upgrade.sh' file as executable too (will save time for when the user next chooses to run it)
chmod +x /etc/conductor/upgrade.sh

# We now need to make some changes to the default nginx.conf file...
echo "Configuring Nginx..."
sed -i "s/include \/etc\/nginx\/sites-enabled\/\*/include \/etc\/conductor\/configs\/common\/conductor_nginx\.conf/g" /etc/nginx/nginx.conf

echo "Configuring PHP-FPM for Nginx..."
sed -i "s/\listen = 127\.0\.0\.1\:9000/listen = \/var/run/php-fpm\.sock/g" /usr/local/etc/php-fpm.conf

# We'll now install Redis Server
pkg install redis
sh -c 'echo redis_enable=\"YES\" >> /etc/rc.conf'
service redis start

# Now we'll install Beanstalkd
pkg install beanstalkd
sh -c 'echo beanstalkd_enable=\"YES\" >> /etc/rc.conf'
service beanstalkd start

# Lets copy the configuration file template to /etc/conductor.conf for simplified administration.
cp /etc/conductor/bin/conf/conductor.template.conf /etc/conductor.conf

# Set the root password on our configuration script.
sed -i "s|ROOT_PASSWORD_HERE|$randpassword|" /etc/conductor.conf;

# Restarting services...
service php-fpm restart
service nginx restart

echo "Congratulations! Conductor is now successfully installed you are running: "
conductor --version
echo ""