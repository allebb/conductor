Conductor
=========

Conductor is CLI utility to automate the installation of Laravel 4.x and 5.x specific application servers complete with some scripts and CLI commands to help deploy and manage multiple web applications on the server with ease.

Requirements
------------
Conductor is developed, tested and supported on the Ubuntu Server LTS releases (18.04, 16.04, 14.04 and 12.04) in future I may support other distributions too and I always welcome PR's from other members of the community too if they wish to contribute configuration changes/updates to the existing project source code!

FreeBSD 10.1 support is currently in BETA! (Requires ``bash``, ``sudo`` and ``wget`` to be installed if you wish to use the automated installer script!)

Installation
------------

Installation on Ubuntu servers running 16.04 can be done effortlessly by simply running this command from the console!

```shell
wget https://raw.github.com/allebb/conductor/master/install.sh
sudo bash install.sh
```

If you wish to install on Ubuntu Server 16.04, 14.04 or 12.04 please use the instructions found on the PHP 5.6 branch instead: https://github.com/allebb/conductor/tree/ubuntu1404_php56

If installing on FreeBSD, a slightly different approach is required at present (given that it needs some initial packages installed and OpenSSL needs some attention). If you wish to install on FreeBSD please follow the [FreeBSD Installation](INSTALL-FREEBSD.md) instructions.

Upgrading Conductor
-------------------

Upgrade instructions can be found in the [UPGRADE](UPGRADE.md) section.

What does this install
----------------------

Out of the box this script will install and configure the following packages using aptitude:-

* Nginx
* PHP 7.0
* Git Client
* MariaDB
* Redis
* Beanstalkd
* Supervisor
* LetsEncrypt

How to use it
-------------
It's pretty straight forward to use, I'll go over briefly the main features (CLI options) and what they do.

### List of avaliable commands and what they do

#### ```conductor list```

A simple command that displays the names of the currently deployed applications on the server.

#### ```conductor new {app name}```

When you first want to deploy a new instance of a Laravel 4.x/5.x application on to your server, you are required to SSH in (or you could write a web-based application to speak to the **conductor(( behind if you wanted too) to your server and then execute the following command:-

 ```sudo conductor new {app name}```

This command will prompt you for the 'FQDN' (or you can add multiple addresses of which the Virtualhost will serve requests for, these should be separated by spaces!). After entering the FQDN(s) for the new application you will then be asked for your application's environment type (this basically sets the ``APP_ENV`` environment variable for Nginx of which can then be used by your PHP application like so ``$_SERVER['APP_ENV']``), if your application requires a MySQL database and as you would expect if you decide you do need a MySQL database Conductor will automatically create a database and MySQL user with permissions to only that database (to keep things secure!). The last part of the deployment you are asked how you would like to deploy your application, you have three options of which are as follows:-

* Git - Keep things automated and use Git to clone and keep your application up to date, this is highly recommended as it's so simple to do... This is what Conductor does best ;-)
* Restore from a backup - You can restore from an application back-up taken from either on your current server of if you're migrating from another server; when restoring from a backup Conductor will automatically extract the contents of the specified backup archive and will also automatically import any MySQL databases if found in the backup archive.
* Manual - You are responsible to SSH/FTP to the server and manually upload the files to the ```/var/conductor/applications/{app name}/``` directory.

That it, once you have passed through all the prompts your application will then be live and accessible!

#### ```conductor destroy {app name}```

Will remove the application from the server, removes the Nginx configuration for this application and will also drop the MySQL database and MySQL user (if present), this command basically removes the named application and immediately stops serving the content.

#### ```conductor update {app name}```
The upgrade command does three things, firstly it gives you the option of putting your application into 'offline mode' of which is up to you (you're prompted for your decision here), before it upgrades anything an automatic 'snapshot' is taken and stored separately to enable you to 'roll-back' later if required.. So next if Conductor finds that the application was previously deployed by Git or has a ```.git``` directory it will attempt to do a ```git fetch --all``` and then a ```git reset --hard origin/master``` to pull in the latest changes. If no git directory is found, Conductor assumes you're doing a 'manual upgrade' and prompts you at this point to upload the new files into your application's root directory... once this is complete you should confirm that the files have all been uploaded... Next Conductor will now execute any database migrations and then clear the application cache as well as dump the autoloader and finally (if you choose to 'take the application offline' during the upgrade process) it will now be automatically put back on-line!

#### ```conductor rollback {app name}```
This is basically the opposite of ```conductor upgrade {app name}```, this uses that last snapshot that was automatically taken the last time that you preformed an ```conductor upgrade {app name}``` on your application.

You will be prompted to confirm that you wish to revert to the last database snapshot, it is recommended in most situations that you do this, if however you choose 'no' you will be given the option to run Laravel's ``migrate:rollback`` function instead!

#### ```conductor backup {app name}```

This command provides an extremely easy way to backup both your application files, dependencies, access and error logs including your MySQL database (if you're using one for your application) have them compressed, timestamped and then stored under /var/conductor/backups for you to either 'restore' from in future or to manually SSH/FTP them to a remote machine or even set-up an automated process to Rsync them out to a remote backup server etc... the choices are endless!

*If you wish to automate backups of ALL applications on the server please check out the 'Automated backups' section below.*

#### ```conductor restore {app name}```

Yep, you got it... it's the reverse of ```conductor backup {app name}``` but just to be clear, this command will prompt for a backup file to be used before then deleting the contents of your application's hosting and logs directory and then restoring the application files and logs from the archive and will lastly drop and restore your MySQL database too (again, if your application uses one!)

#### ```conductor start {app name}```

This command enables you to start serving your Laravel 4.x application, this invokes the 'php artistan start' command. You only need to run this if you've recently 'stopped' your application as all newly provisioned applications will be in the 'started' state by default.

#### ```conductor stop {app name}```

This command enables you to stop serving your Laravel 4.x application, this invokes the 'php artistan stop' command.

#### ```conductor services start```

A very simple and quick method to start ALL dependent/bundled Conductor managed daemons  in the recommended order.

#### ```conductor services stop```

A very simple and quick method to stop ALL dependent/bundled Conductor managed daemons in the recommended order.

#### ```conductor services status```

A very simple and quick method to display the current status of ALL dependent/bundled Conductor managed daemons.

#### ```conductor services restart```

A very simple and quick method to restart ALL dependent/bundled Conductor managed daemons in the recommended order.

#### ```conductor services reload```

When manually changing configuration of one or more of the dependent/bundled daemons this command will attempt to safely 'reload' the configuration of the daemons without the need to disconnect existing sessions. - Please not this is NOT required when using the ```conductor``` command to manage configuration files but is recommended if you make manual changes!

#### ```conductor --version```

Displays the current version of the Conductor application that you are currently running on your server, this should help determine whether you are running the latest version by comparing with our latest release's page on our website.

CLI options for non-interactive operation
-----------------------------------------
In the default "interactive" mode, Conductor prompts for various options and questions, if however you wish to script this for unattended (non-interactive mode) etc. since v3, you can now use the [CLI Options document](CLI-OPTIONS.md) document to see the available CLI options.

Generating and updating LetsEncrypt Certificates
------------------------------------------------
You can generate and update LetsEncrypt certificates by running the following command:

```shell
sudo service nginx stop
sudo letsencrypt certonly --standalone -d {domain} -d {sub-domain} --agree-tos --email={your_email_address}
sudo service nginx start
```

Automating application backups
------------------------------
An automation script specifically designed to be used with CRON jobs etc. can be found in the ``utils/`` directory, this shell script will automatically backup all applications on the server and will also remove older backups (as configured in the script), the default backup retention is 7 days!

To configure this task, place the ```utils/scheduled_backups.sh``` script in a directory of your choice on the server (or used directly from the default installation path!) and then set-up a CRON task as follows:-

```shell
0 0 * * * /etc/conductor/utils/scheduled_backups.sh
```

The above example will execute the task daily at midnight, the default configuration will ensure that backups older than 7 days are also deleted (to ensure that your disks don't fill up!) this setting is configurable by editing the ```DAYS``` constant inside the script.

You may wish to then have a remote server 'pull' and 'archive' these backups of which will be located in ``/var/conductor/backups/``.

Automating composer updates
---------------------------
As conductor is designed to be a 'set and forget' system, we've now implemented an script that you can add as a CRON job (to get rid of those nasty '30 days out of date' errors), by adding this script to the CRONtab you can be sure that Composer is automatically updated on the first day of every month at 03:00.

```shell
0 3 1 * * /etc/conductor/utils/update_composer.sh
```

Help and support
----------------
You can drop me an email at [ballen@bobbyallen.me](mailto:ballen@bobbyallen.me) I'll do my best to respond ASAP!
