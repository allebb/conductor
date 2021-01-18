# Conductor managed Nginx Virtual Host File
#
# IMPORTANT:
# If you manually edit this file you must ensure that the list of managed domains below are updated if you
# add or remove domains to this virtualhost configuration as Conductor will use this list to provision and
# manage certificates with LetsEncrypt certificates on your behalf. You must ensure that each domain or sub-
# domain is separated with a single space.
#
# To generate or manually renew LetsEncrypt certificates you should use the `conductor letsencrypt {name}`
# command in your terminal.
#
#:: Application name: [@@APPNAME@@]
#:: Managed domains: [@@DOMAIN@@]
#

# Enable this configuration block if you wish to configure SSL and force all HTTP traffic over SSL (https).
#server {
#       listen         80;
#       server_name    @@DOMAIN@@;
#       include        /etc/conductor/configs/common/wellknown.conf;
#       return         301 https://$server_name$request_uri;
#}

# If you wish to redirect HTTPS traffic too, such as from a `www.` sub-domain to your TLD, you should enable this configuration block.
# Be sure to replace the two occurrences of `{yourdomain}` in this configuration block with your TLD.
#server {
#        listen                  443 ssl;
#        ssl_certificate         /etc/letsencrypt/live/@@APPNAME@@/fullchain.pem;
#        ssl_certificate_key     /etc/letsencrypt/live/@@APPNAME@@/privkey.pem;
#        ssl_trusted_certificate /etc/letsencrypt/live/@@APPNAME@@/chain.pem;
#        include /etc/nginx/snippets/ssl-params.conf;
#        server_name   www.{yourdomain};
#        return        301 https://{yourdomain}$request_uri;
#}

server {

}