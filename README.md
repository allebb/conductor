Conductor
=========

Conductor (as in a 'bus' or 'train' conductor) is utiliy (set of scripts) to automate the installation Laravel 4 specific application servers complete with some scripts and CLI commands to help deploy and manage multiple web applications on the server with ease.

You may be thinking what the hell has a bus or train conductor have to do with anything? - Well I like to think of it as this set of tools assist you getting your apps on and off of the hosting server, it also 'services' your apps by keeping them backed up and handles composer updates too!

Requirements
------------
The script is developed and tested on Ubuntu Server 12.04 LTS in future I may support other distributions too and I always welcome pull requests for other distro installers too!

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

Help and support
----------------
You can drop me an email at [ballen@bobbyallen.me](mailto:ballen@bobbyallen.me) I'll do my best to respond ASAP!