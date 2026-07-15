# Conductor managed WAF include for @@APPNAME@@
#
# This file is included inside the application's Nginx server{} block.
# Add per-application WAF, access-control, and file-protection rules here.

# Optional per-vhost access controls.
#allow 203.0.113.0/24;
#allow 2001:db8::/32;
#deny all;

# Example GeoIP country block. Enable the geoip2 lookup in
# /etc/conductor/configs/common/conductor_nginx.conf, then add ISO 3166-1
# alpha-2 country codes to the regex for this vhost.
#
# if ($conductor_geoip_country_code ~ ^(CN|RU)$) {
#     return 444;
# }

# Recommended security headers.
add_header      X-Frame-Options         "SAMEORIGIN";
add_header      X-XSS-Protection        "1; mode=block";
add_header      X-Content-Type-Options  "nosniff";
add_header      Referrer-Policy         "strict-origin-when-cross-origin";

# Optional security headers. Enable after confirming they do not block required plugins, themes, or browser APIs.
#add_header     Permissions-Policy      "camera=(), microphone=(), geolocation=()";
#add_header     Content-Security-Policy "default-src 'self'; img-src 'self' data: https:; script-src 'self' 'unsafe-inline' 'unsafe-eval'; style-src 'self' 'unsafe-inline';";

# Disable access and error logs for requests to these common files.
location = /favicon.ico { allow all; access_log off; log_not_found off; }
location = /robots.txt  { allow all; access_log off; log_not_found off; }

# Deny access to .htaccess, .git and other hidden files by default.
# LetsEncrypt ACME challenges are handled by Conductor; other /.well-known/ paths may be served by the app.
location ~ /\.(?!well-known(?:/|$)).* {
    deny all;
    access_log off;
    log_not_found off;
    return 404;
}

# Deny access to common WordPress and backup files that should never be served directly.
location = /wp-config.php {
    deny all;
    access_log off;
    log_not_found off;
    return 404;
}

location ~* (^|/)readme(?:\.(?:txt|md|markdown|html?))?$ {
    deny all;
    access_log off;
    log_not_found off;
    return 404;
}

location = /license.txt {
    deny all;
    access_log off;
    log_not_found off;
    return 404;
}

location ~* \.(?:sql|sqlite|bak|old|orig|save|swp|dist|conf|ini)$ {
    deny all;
    access_log off;
    log_not_found off;
    return 404;
}

# Uncomment to disable XML-RPC if the site does not need Jetpack, pingbacks, or remote publishing.
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
