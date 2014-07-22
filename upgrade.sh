#!/usr/bin/env bash

# Change into the main 'conductor' directory.
sudo cd /etc/conductor

# Pull the latest code changes from GitHub.
sudo git fetch --all
sudo git reset --hard origin/master

# Reset file permissions on the all required executable files.
sudo chmod +x /usr/bin/conductor
sudo chmod +x /etc/conductor/bin/*

# We'll also set this script as executable too (as we need to overwrite the last change by Git)
sudo chmod +x upgrade.sh

# All done!
echo "The upgrade is finished, you are now running: "
sudo conductor --version
echo ""