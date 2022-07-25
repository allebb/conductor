#!/usr/bin/env bash

#############################################################
# Conductor Certbot Auto-renew Script                       #
# https://github.com/allebb/conductor                       #
# Created by: Bobby Allen (ballen@bobbyallen.me) 16/09/2020 #
#############################################################

# We need to briefly stop Nginx (as we use the simple "standalone" authentication and will require binding to port 80!)
service nginx stop

# No we attempt to renew all SSL certs on this server (we will not attempt to force any, you could however use '--force-renewal' or '--renew-by-default' in place of '--keep-until-expiring')
certbot renew -n --keep-until-expiring --agree-tos --no-eff-email

# Finally restart Nginx!
service nginx start