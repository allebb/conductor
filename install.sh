#!/usr/bin/env bash

################################################################################
# Conductor Install Bootstrapper                                               #
# Written by Bobby Allen <ballen@bobbyallen.me>, 22/07/2014                    #
################################################################################

# The installation script repository
SITE='https://raw.github.com/allebb/conductor/master/scripts'

# Detect what version of OS they are using and then send them off to the correct install script!
DISTRO=$(lsb_release -si| tr '[:upper:]' '[:lower:]')
VER=$(lsb_release -sr)
INSTALLER='install_'$DISTRO'_'$VER'.sh'

# Check and remove any installers that already exist on the local machine.
if [ -e $INSTALLER ]
then
  rm $INSTALLER -f
fi
sudo wget $SITE'/'$INSTALLER
sudo chmod +x $INSTALLER
sudo bash $INSTALLER