#!/usr/bin/env bash

################################################################################
# Conductor Install Bootstrapper                                               #
# Written by Bobby Allen <ballen@bobbyallen.me>, 22/07/2014                    #
################################################################################

# We define our mirror site
SITE='https://raw.github.com/bobsta63/conductor/master/scripts'

# We detect what version of OS they are using and then send them off to the correct install script!
DISTRO=$(lsb_release -si| tr '[:upper:]' '[:lower:]')
VER=$(lsb_release -sr)
INSTALLER='install_'$DISTRO'_'$VER'.sh'

# Check the installer doesn't already exist else we'll end up with install_ubuntu_14.04.sh.1 etc
if [ -e $INSTALLER ]
then
  rm $INSTALLER -f
fi
sudo wget $SITE'/'$INSTALLER
sudo chmod +x $INSTALLER
sudo bash $INSTALLER