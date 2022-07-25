#!/usr/bin/env bash

#############################################################
# Automatic Composer Updater Script                         #
# https://github.com/allebb/conductor                       #
# Created by: Bobby Allen (ballen@bobbyallen.me) 26/03/2015 #
#############################################################

COMPOSERBIN=$(which composer)

# Execute the composer update process.
$COMPOSERBIN self-update
