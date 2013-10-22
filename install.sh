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
sudo apt-get -y install php5-common php5-cli php5-fpm php-apc php5-curl php5-gd php5-mcrypt php5-sqlite

# We install the Git Client to enable auto deployments etc.
sudo apt-get -y install git

# We now install Nginx
sudo apt-get -y install nginx

# Now we'll install a few other bits, namely MySQL, Beanstalkd


# Lets now create a default folder structure to hold all of our applications.
# Now we need to pull 'conductor' from GitHub and we'll now deploy the application ready for it to be used.
sudo git clone https://github.com/bobsta63/conductor.git /etc/conductor
sudo mkdir /etc/conductor/configs # Stores the user application configs in here.
sudo mkdir /var/conductor # We'll create a folder structure here to store all of the apps.
sudo mkdir /var/conductor/applications
sudo mkdir /var/conductor/certificates
sudo mkdir /var/conductor/logs
sudo mkdir /var/conductor/backups

# We now need to make some changes to the default nginx.conf file...
echo "Configuring Nginx..."
#sed -i "s/include \/etc\/nginx\/sites-enabled\/\*/include \/etc\/conductor\/config\/conductor_nginx\.conf/g" /etc/nginx/nginx.conf
sudo sed -i "s/# server_tokens off\;/server_tokens off\;/g" /etc/nginx/nginx.conf

echo "Configuring PHP-FPM for Nginx..."
# Lets now configure PHP-FPM...
sudo sed -i "s/\listen = 127\.0\.0\.1\:9000/listen = \/tmp\/php5-fpm\.sock/g" /etc/php5/fpm/pool.d/www.conf

# Now we link the Nginx config...
sudo ln -s /etc/conductor/configs/conductor_nginx.conf /etc/nginx/sites-enabled/conductor

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

# Now we register the CLI handlers...
#   conductor list - Lists all hosted applications
#   conductor restart - Restarts ALL dependent services.
#   conductor reload - Reload Nginx config (for new applications to become 'live')
#
#   conductor composer:update - Upgrades the version of Composer running on the serve.
#   conductor app:deploy {app_name} - Creates a new application and will attempt to pull a Git repo.
#   conductor app:destory {app_name} - Deletes an application and stops all hosting, deletes the DB associated too!
#   conductor app:upgrade {app_name} - Pulls the latest code from Git, runs migrations, clears application cache, dumps autoload etc.
#   conductor app:rollback {app_name} - Rolls the application upgrade back (from the recent upgrade)
#   conductor app:cupdate {app_name} - Runs 'composer update' on the named application.
#   conductor app:backup {app_name} {file and path to backup too} - Back's up the application and the DB dump (from MySQL if one exists)
#   conductor app:restore {app_name} {file and path to restore from} - A specific file to restore from, unzip and does import etc.