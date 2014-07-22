#!/usr/bin/env bash

################################################################################
# Conductor Install Bootstrapper                                               #
# Written by Bobby Allen <ballen@bobbyallen.me>, 22/07/2014                    #
################################################################################

# We'll make all of the installer scripts executable.
sudo chmod +x scripts/*.sh

# We detect what version of OS they are using and then send them off to the
# correct install script!
VER=$(lsb_release -sr)
sudo wget install_ubuntu_$VER.sh
sudo install_ubuntu_$VER.sh