Conductor
=========

Conductor (as in a 'bus' or 'train' conductor) is utiliy (set of scripts) to automate the installation Laravel 4 specific application servers complete with some scripts and CLI commands to help deploy and manage multiple web applications on the server with ease.

You may be thinking what the hell has a bus or train conductor have to do with anything? - Well I like to think of it as this set of tools assist you getting your apps on and off of the hosting server, it also 'services' your apps by keeping them backed up and handles composer updates too!

Requirements
------------
The script is developed and tested on Ubuntu Server 12.04 LTS in future I may support other distributions too and I always welcome pull requests from other members of the community too if they wish to contribute config changes/updates to the existing project source code!

Installation
------------

Installation can be done effortlessly by simply running this command from the console!

```shell
curl -sS https://raw.github.com/bobsta63/conductor/master/install.sh | sh
```

What does this install
----------------------

Out of the box this script will install and configure the following packages using aptitude:-

* Nginx
* PHP 5.5 (CLI and FPM)
* Git Client
* APC
* MySQL
* Redis
* Beanstalkd

How to use it
-------------
It's pretty streight forward to use, I'll go over breifly the main features (CLI options) and what they do.

###List of avaliable commands and what they do

####```conductor list```

A simple command that displays the names of the currently deployed applications on the server.

####```conductor new {app name}```

When you first want to deploy a new instance of a Laravel 4 application on to your server, you are required to SSH in (or you could right a web-based application to speak to the executable behind if you wanted too) to your server and then execute the following command:-

```sudo conductor new {app name}```

This command will prompt you for the 'FQDN' (or you can add multiples address of which the Virtualhost will server requests for, these should be seperated by spaces!). After entering the FQDN(s) for the new application you'll then be asked if your application requires a MySQL database and as you would expect if you decide you do need a MySQL database Conductor will automatically create a database and MySQL user with permissions to only that database (to keep things secure!). The last part of the deployment you are asked how you would like to deploy your application, you have three options of which are as follows:-

* Git - Keep things automated and use Git to clone and keep your application up to date, this is highly recommended as it's so simple to do... This is what Conductor does best ;-)
* Restore from a backup - You can restore from an application back-up taken from either on your current server of if you're migrating from another server; when restoring from a backup Conductor will automatically extract the contents of the specified backup archive and will also automatically import any MySQL databases if found in the backup archive.
* Manual - You are responsible to SSH/FTP to the server and manually upload the files to the ```/var/conductor/applicaitons/{app name}/``` directory. 

That it, once you've passed through all the prompts your application will then be live and accessible!

####```conductor destroy {app name}```

Will remove the application from the server, removes the Nginx configuration for this application and will also drop the MySQL database and MySQL user (if present), this command basically removes the named application and immediately stops serving the content.

####```conductor upgrade {app name}```
The upgrade command does three things, firstly it gives you the option of putting your application into 'offline mode' of which is up to you (you're prompted for your decission here), before it upgrades anything an automatic 'snapshot' is taken and stored seperately to enable you to 'rollback' later if required.. So next if Conductor finds that the application was previously deployed by Git or has a ```.git``` directory it will attempt to do a ```git reset --hard``` and then ```git pull``` the latest changes. If no git directory is found, Conductor assumes you're doing a 'manual upgrade' and prompts you at this point to upload the new files into your application's root directory... once this is complete you should confirm that the files have all been uploaded... Next Conductor will now execute any database migrations and then clear the application cache as well as dump the auotloader and finally (if you choose to 'take the application offline' during the upgrade process) it will now be automatically put back on-line!

####```conductor rollback {app name}```
This is basically the opposite of ```conductor upgrade {app name}```, this uses that last snapshot that was automatically taken the last time that you preformed an ```conductor upgrade {app name}``` on your application.

You'll be prompted to confirm that you wish to revert to the last database snapshot, it is recommended in most situations that you do this, if however you choose 'no' you will be given the option to run Laravel's migrate:rollback function instead!

####```conductor depupgrade {app name}```

```depupgrade``` basically is shortened version of 'Dependency Upgrade', this command will bascially snapshot your application (including the database) to enable you to 'rollback' if you need too before running ```composer update``` on your application.

####```conductor backup {app name}```

This command provides an extremely easy way to backup both your application files, dependencies, access and error logs including your MySQL database (if you're using one for your application) have them compressed, timestamped and then stored under /var/conductor/backups for you to either 'restore' from in future or to manually SSH/FTP them to a remote machine or even set-up an automated process to RSYNC them out to a remote backup server etc... the choices are endless!

####```conductor restore {app name}```

Yep, you got it... it's the reverse of ```conductor backup {app name}``` but just to be clear, this command will prompt for a backup file to be used before then deleting the contents of your application's hosting and logs directory and then restoring the application files and logs from the archive and will lastly drop and restore your MySQL database too (again, if your application uses one!)

####```conductor --start```

A very simple and quick method to start ALL dependent/bundled Conductor managed daemons  in the recommended order. 

####```conductor --stop```

A very simple and quick method to stop ALL dependent/bundled Conductor managed daemons in the recommended order. 

####```conductor --status```

A very simple and quick method to display the current status of ALL dependent/bundled Conductor managed daemons. 

####```conductor --restart```

A very simple and quick method to restart ALL dependent/bundled Conductor managed daemons in the recommended order. 

####```conductor --reload```

When manually changing configuration of one or more of the dependent/bundled daemons this command will attempt to safely 'reload' the configuration of the daemons without the need to disconnect existing sessions. - Please not this is NOT required when using the ```conductor``` command to manage configuration files but is recommended if you make manual changes!

####```conductor --version```

Displays the current version of the Conductor application that you are currently running on your server, this should help determine whether you are running the latest version by comparing with our latest release's page on our website.

Help and support
----------------
You can drop me an email at [ballen@bobbyallen.me](mailto:ballen@bobbyallen.me) I'll do my best to respond ASAP!