#!/usr/bin/env bash

################################################################################
# Conductor Install Bootstrapper                                               #
# Written by Bobby Allen <ballen@bobbyallen.me>, 22/07/2014                    #
################################################################################

# Check to see if there are is a "BRANCH_INSTALL" environment variable set.
if [[ -z "${BRANCH_INSTALL}" ]]; then
  BRANCH_INSTALL="stable"
else
  BRANCH_INSTALL="${BRANCH_INSTALL}"
fi

# The installation script repository
SITE="https://raw.github.com/allebb/conductor/${BRANCH_INSTALL}/scripts"

# Detect what version of OS they are using and then send them off to the correct install script!
DISTRO=$(lsb_release -si| tr '[:upper:]' '[:lower:]')
VER=$(lsb_release -sr)
INSTALLER='install_'$DISTRO'_'$VER'.sh'

# Check and remove any installers that already exist on the local machine.
if [ -e /tmp/$INSTALLER ]
then
  rm /tmp/$INSTALLER -f
fi
sudo wget $SITE'/'$INSTALLER -P /tmp
sudo chmod +x /tmp/$INSTALLER
sudo bash /tmp/$INSTALLER