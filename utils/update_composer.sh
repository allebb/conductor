#!/usr/bin/env bash

#############################################################
# Automatic Composer Updater Script                         #
# https://github.com/bobsta63/conductor                     #
# Created by: Bobby Allen (ballen@bobbyallen.me) 26/03/2015 #
#############################################################

COMPOSERBIN=$(which composer)

# Excecute the composer update process.
$COMPOSERBIN self-update
