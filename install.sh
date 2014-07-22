#!/usr/bin/env bash

# We'll just run these for best practice!
sudo apt-get update
sudo apt-get -y install python-software-properties

# We now add the PHP5 (latest, at time of writing PHP 5.5.5) PPA like so
sudo add-apt-repository -y ppa:ondrej/php5
sudo apt-get update
# People could use (if they wanted PHP 5.4):
#add-apt-repository -y ppa:ondrej/php5 >> /tmp/ppa_ondrej.txt 2>&1

# Now we can quickly can confirm that we are running the latest version of PHP 5
#php5 -v

# We specifically specify 'php5-common' as we don't want Apache etc installed too!
sudo apt-get -y install php5-common php5-cli php5-fpm php-apc php5-curl php5-gd php5-mcrypt php5-sqlite php5-mysql

# We install the Git Client to enable auto deployments etc.
sudo apt-get -y install git

# We now install Nginx
sudo apt-get -y install nginx

# Now we'll install Composer
curl -sS https://getcomposer.org/installer | sudo php -- --install-dir=/etc/conductor/bin/composer
sudo ln -s /etc/conductor/bin/composer/composer.phar /usr/bin/composer
# Lets set the new symlink as executable
sudo chmod +x /usr/bin/composer

# Lets now create a default folder structure to hold all of our applications.
# Now we need to pull 'conductor' from GitHub and we'll now deploy the application ready for it to be used.
sudo git clone https://github.com/bobsta63/conductor.git /etc/conductor
sudo mkdir /etc/conductor/configs # Stores the user application configs in here.
sudo mkdir /var/conductor # We'll create a folder structure here to store all of the apps.
sudo mkdir /var/conductor/applications
sudo mkdir /var/conductor/certificates
sudo mkdir /var/conductor/logs
sudo mkdir /var/conductor/backups
sudo mkdir /var/conductor/tmp

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
#sed -i "s/include \/etc\/nginx\/sites-enabled\/\*/include \/etc\/conductor\/config\/conductor_nginx\.conf/g" /etc/nginx/nginx.conf
sudo sed -i "s/# server_tokens off\;/server_tokens off\;/g" /etc/nginx/nginx.conf

echo "Configuring PHP-FPM for Nginx..."
# Lets now configure PHP-FPM...
sudo sed -i "s/\listen = 127\.0\.0\.1\:9000/listen = \/tmp\/php5-fpm\.sock/g" /etc/php5/fpm/pool.d/www.conf

# Now we link the Nginx config...
sudo ln -s /etc/conductor/configs/common/conductor_nginx.conf /etc/nginx/sites-enabled/conductor

# Now we'll install MySQL Server and set a default 'root' password, in future we'll generate a random one!
sudo apt-get -y install mysql-server-5.5

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
