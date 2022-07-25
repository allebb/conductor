#!/usr/bin/env bash

#############################################################
# Conductor Automated Backup Script                         #
# https://github.com/allebb/conductor                       #
# Created by: Bobby Allen (ballen@bobbyallen.me) 20/01/2014 #
#############################################################

# Number of days to retain backups for (after this they will be deleted!)
DAYS=7

##############################################################################
#                                                                            #
#     Do not edit below this line unless you know what you are doing!        #
#                                                                            #
##############################################################################

# Conductor binary
CONDUCTORBIN=$(which conductor)

# The path to the Conductor applications directory.
CONAPPSDIR='/var/conductor/applications/'

# The location to where the application backups are stored.
BACKUPDIR='/var/conductor/backups/'

# Lets iterate through each of the applications generating a backup archive for each...
for APPLICATION in $(find $CONAPPSDIR* -maxdepth 0 -type d );
do
    APPNAME=$(basename $APPLICATION);
    echo "Backing up '"$APPNAME"' application..."
    $CONDUCTORBIN backup $APPNAME
done

# Lets remove backups older than the number of days as per the retention rules.
find $BACKUPDIR* -mtime +$DAYS -exec rm -rf {} \;