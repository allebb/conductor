#!/usr/bin/env bash

################################################################################
# Conductor Installation Script for Ubuntu Server 14.04 LTS                    #
# Written by Bobby Allen <ballen@bobbyallen.me>, 22/07/2014                    # 
################################################################################

# We'll just run these for best practice!
sudo apt-get update
sudo apt-get -y install python-software-properties debconf-utils

# Apache is installed by default on Ubuntu Server 14.04 LTS, we'll remove it...
sudo service apache2 stop
sudo update-rc.d -f apache2 remove
sudo apt-get -y remove apache2

# We now install Nginx
sudo apt-get -y install nginx

# Now we'll install MySQL Server and set a default 'root' password, in future we'll generate a random one!
sudo apt-get -y install mysql-server-5.5

# We specifically specify 'php5-common' as we don't want Apache etc installed too!
sudo apt-get -y install php5-common php5-cli php5-fpm php-apc php5-curl php5-gd php5-mcrypt php5-sqlite php5-mysql php5-json

# We now need to enable the Mcrypt extension (as this isn't done by default)
php5enmod mcrypt
service nginx restart
service php5-fpm restart

# We install the Git Client to enable auto deployments etc.
sudo apt-get -y install git

# Lets now create a default folder structure to hold all of our applications.
# Now we need to pull 'conductor' from GitHub and we'll now deploy the application ready for it to be used.
sudo git clone https://github.com/bobsta63/conductor.git /etc/conductor
sudo mkdir /var/conductor # We'll create a folder structure here to store all of the apps.
sudo mkdir /var/conductor/applications
sudo mkdir /var/conductor/certificates
sudo mkdir /var/conductor/logs
sudo mkdir /var/conductor/backups
sudo mkdir /var/conductor/tmp

# Now we'll install Composer
curl -sS https://getcomposer.org/installer | sudo php -- --install-dir=/etc/conductor/bin/composer
sudo ln -s /etc/conductor/bin/composer/composer.phar /usr/bin/composer
# Lets set the new symlink as executable
sudo chmod +x /usr/bin/composer

# Lets now set some permissions...
sudo chmod +x /etc/conductor/bin/*

# Lets symlink the main conductor script...
sudo ln -s /etc/conductor/bin/conductor /usr/bin/conductor
# Lets set the new symlink as executable
sudo chmod +x /usr/bin/conductor

# We'll set the 'upgrade.sh' file as executable too (will save time for when the user next chooses to run it)
sudo chmod +x /etc/conductor/upgrade.sh

# We now need to make some changes to the default nginx.conf file...
echo "Configuring Nginx..."
sudo sed -i "s/include \/etc\/nginx\/sites-enabled\/\*/include \/etc\/conductor\/configs\/common\/conductor_nginx\.conf/g" /etc/nginx/nginx.conf
sudo sed -i "s/# server_tokens off\;/server_tokens off\;/g" /etc/nginx/nginx.conf
# Now we link the Nginx config... (decided against this in the end, a proper switch of the sites-available is probably best practice!)
#sudo ln -s /etc/conductor/configs/common/conductor_nginx.conf /etc/nginx/sites-enabled/conductor

echo "Configuring PHP-FPM for Nginx..."
# On Ubuntu 14.04 the following is already listening on a socket so this can be ignored!
#sudo sed -i "s/\listen = 127\.0\.0\.1\:9000/listen = \/tmp\/php5-fpm\.sock/g" /etc/php5/fpm/pool.d/www.conf
# Change cgi.fix_pathinfo=1 to cgi.fix_pathinfo=0

# We'll now install Redis Server
sudo apt-get -y install redis-server
sudo /etc/init.d/redis-server restart

# Now we'll install Beanstalkd
sudo apt-get -y install beanstalkd
sudo sed -i "s/\#START=yes/START=yes/g" /etc/default/beanstalkd
sudo /etc/init.d/beanstalkd start

# A good idea that we get Supervisord installed here too!

#Lets now start PHP5-FPM and Nginx!
sudo /etc/init.d/php5-fpm restart
sudo /etc/init.d/nginx restart

# Lets copy the configuration file template to /etc/conductor.conf for simplified administration.
sudo cp /etc/conductor/bin/conf/conductor.template.conf /etc/conductor.conf

echo "Congratulations! Conductor is now successfully installed you are running: "
sudo conductor --version

echo ""
echo "**************************************************************************"
echo "* INSTALLATION IS NOW COMPLETE BUT YOU NOW NEED TO GO AND SET THE MYSQL  *"
echo "* ROOT ACCOUNT PASSWORD THAT YOU SPECIFIED DURING INSTALLATION, THIS     *"
echo "* PASSWORD MUST BE SET IN THE CONDUCTOR CONFIG FILE FOUND IN:            *"
echo "*                                                                        *"
echo "*     /etc/conductor.conf                                                *"
echo "*                                                                        *"
echo "* IF THIS IS NOT SET BEFORE YOU DEPLOY YOUR FIRST APPLICATION THE MYSQL  *"
echo "* USER AND DATABASE CREATION WILL FAIL!                                  *"
echo "**************************************************************************"
echo ""