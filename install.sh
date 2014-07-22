#!/usr/bin/env bash

################################################################################
# Conductor Install Bootstrapper                                               #
# Written by Bobby Allen <ballen@bobbyallen.me>, 22/07/2014                    #
################################################################################

VER=$(lsb_release -sr)

# We detect what version of OS they are using and then send them off to the correct install script!
sudo wget https://raw.github.com/bobsta63/conductor/master/scripts/install_ubuntu_$VER.sh
sudo chmod +x install_ubuntu_$VER.sh
sudo bash install_ubuntu_$VER.sh