# Conductor managed (Xcaler) WAF configured for @@APPNAME@@

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
#     # To enable Fail2Ban protection, for this vhost run: conductor waf @@APPNAME@@ --enable
#     return 444;
# }
#
# In the below example, we are explicitly allowing only users from England/UK.
# Country-level GeoIP databases return GB for the United Kingdom.
# if ($conductor_geoip_country_code !~ ^GB$) {
#     return 444;
# }
#
# In the below example, we are explicitly allowing only users from GB, US and DE.
# if ($conductor_geoip_country_code !~ ^(GB|US|DE)$) {
#     return 444;
# }
##########################################################################


##########################################################################
# Default "shared" rulesets.                                             #
#========================================================================#
# Blocks common web search engines (search indexing)
#include /etc/conductor/configs/common/xcaler_community_search_engines.conf;
# Blocks common AI-bots (eg. AI-training/reasearch)
include /etc/conductor/configs/common/xcaler_community_ai_bots.conf;
# Blocks common SQL-like injection attacks
include /etc/conductor/configs/common/xcaler_community_sql_injection.conf;
# Blocks attempts to traverse the web filesystem.
include /etc/conductor/configs/common/xcaler_community_path_traversal.conf;
# Blocks access to common files (eg. wp-config.php, /node_modules/ etc.)
include /etc/conductor/configs/common/xcaler_community_common_paths.conf;
##########################################################################


##########################################################################
# Custom (user/application-specific) rulesets.                           #
#========================================================================#
# -- C:Start Custom WAF Rules Block -- #



# -- C:END Custom WAF Rules Block -- #
##########################################################################


##########################################################################
# Laravel-specific (recommended) rulesets.                             #
#========================================================================#
# Uncomment if this vhost root points at a Laravel project root instead of its public directory.
#location ~ ^/(?:app|bootstrap|config|database|resources|routes|storage|tests|vendor)/ {
#    deny all;
#    return 404;
#}
##########################################################################


##########################################################################
# Fancy "error" (WAF-denied) error pages                                 #
#========================================================================#
include /etc/conductor/configs/common/conductor_waf_error_pages.conf;
##########################################################################
