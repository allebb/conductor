# Conductor managed (Xcaler) WAF configured for @@APPNAME@@

##########################################################################
# Explicit Access (IP) Access Controls                                   #
#========================================================================#
#allow 203.0.113.0/24;
#allow 2001:db8::/32;
#deny all;
##########################################################################

##########################################################################
# Geolocation (Country Code/ISO 3166-1) IP-based Blocking                #
#========================================================================#
# if ($conductor_geoip_country_code ~ ^(CN|RU)$) {
#     return 444;
# }
#
# Country-level GeoIP databases return GB for the United Kingdom.
# if ($conductor_geoip_country_code !~ ^GB$) {
#     return 444;
# }
#
# if ($conductor_geoip_country_code !~ ^(GB|US|DE)$) {
#     return 444;
# }
##########################################################################

##########################################################################
# Default "shared" rulesets.                                             #
#========================================================================#
#include /etc/conductor/configs/common/xcaler_community_search_engines.conf;
include /etc/conductor/configs/common/xcaler_community_ai_bots.conf;
include /etc/conductor/configs/common/xcaler_community_sql_injection.conf;
include /etc/conductor/configs/common/xcaler_community_path_traversal.conf;
include /etc/conductor/configs/common/xcaler_community_common_paths.conf;
##########################################################################

##########################################################################
# Custom (user/application-specific) rulesets.                           #
#========================================================================#
# -- C:Start Custom WAF Rules Block -- #



# -- C:END Custom WAF Rules Block -- #
##########################################################################

##########################################################################
# Fancy "error" (WAF-denied) error pages                                 #
#========================================================================#
include /etc/conductor/configs/common/conductor_waf_error_pages.conf;
##########################################################################
