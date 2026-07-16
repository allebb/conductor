# Conductor managed WAF include for @@APPNAME@@

##########################################################################
# Explicit Access (IP) Access Controls                                   #
#========================================================================#
# Optional (explicit) per-vhost access controls.
#allow 203.0.113.0/24;
#allow 2001:db8::/32;
#deny all;
##########################################################################


##########################################################################
# Geolocation (Country Code/ISO 3166-1) IP-base Blocking                 #
#========================================================================#
# In the below example, we are blocking China (CN) and Russian (RU) IP addresses.
# if ($conductor_geoip_country_code ~ ^(CN|RU)$) {
#     # We return 444 response (an a blank page) but consistent attempts to access the site/application
#     # will trigger an IP ban (by Fail2Ban) for this user if Fail2Ban is enabled for this virtual host!
#     # To enable Fail2Ban protection, for this Vhost run: conductor protect @@APPNAME@@ --enable
#     return 444;
# }
##########################################################################


##########################################################################
# Default "shared" rulesets.                                             #
#========================================================================#
# Blocks common web search engines (search indexing)
#include /etc/conductor/configs/common/block_common_crawlers.conf;
# Blocks common AI-bots (eg. AI-training/reasearch)
include /etc/conductor/configs/common/block_common_bots.conf;
# Blocks common SQL-like injection attacks
include /etc/conductor/configs/common/block_common_sql_injection.conf;
# Blocks attempts to traverse the web filesystem.
include /etc/conductor/configs/common/block_common_path_traversal.conf;
# Blocks access to common files (eg. wp-config.php, /node_modules/ etc.)
include /etc/conductor/configs/common/block_common_files.conf;
##########################################################################


##########################################################################
# Custom (user/application-specific) rulesets.                           #
#========================================================================#
# -- C:Start Custom WAF Rules Block -- #



# -- C:END Custom WAF Rules Block -- #
##########################################################################


##########################################################################
# Wordpress-specific (recommended) rulesets.                             #
#========================================================================#
# Uncomment to disable XML-RPC if the site does not need Jetpack, pingbacks, or remot
e publishing.
#location = /xmlrpc.php {
#    deny all;
#    access_log off;
#    log_not_found off;
#    return 404;
#}

# Deny access to any files with a .php extension in the uploads directory.
# Works in subdirectory installs and also in multisite networks.
# Keep logging the requests to parse later (or to pass to firewall utilities such as fail2ban).
location ~* /(?:uploads|files)/.*\.php$ {
    deny all;
}

# Deny direct PHP execution in wp-includes, which is not needed for normal requests.
location ~* /wp-includes/.*\.php$ {
    deny all;
}
##########################################################################


##########################################################################
# Fancy "error" (WAF-denied) error pages                                 #
#========================================================================#
# Local (styled/informative) page for requests rejected by Conductor WAF rules.
# Disable (comment out) if you don't want to expose that the WAF intercepted!
error_page 406 /.406.html;
location = /.406.html {
    internal;
    add_header Cache-Control "no-store";
    add_header X-Application-Id $conductor_application always;
}
##########################################################################
