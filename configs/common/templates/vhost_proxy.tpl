# Conductor managed Nginx Virtual Host File
#
# Auto-created by Conductor (v@@VERSION@@) at: @@CREATED_AT@@
#
# IMPORTANT:
# If you manually edit this file you must ensure that the list of managed domains below are updated if you
# add or remove domains to this virtualhost configuration as Conductor will use this list to provision and
# manage certificates with LetsEncrypt certificates on your behalf. You must ensure that each domain or sub-
# domain is separated with a single space.
#
# DO NOT REMOVE ANY OF THE "# -- C:*" prefixed comments, these are used by the Conductor CLI to automate feature
# enablement!
#
# To generate or manually renew LetsEncrypt certificates you should use the `conductor letsencrypt {name}`
# command in your terminal.
#
#:: Application name: [@@APPNAME@@]
#:: Managed domains: [@@DOMAIN@@]
#

# Upstream backends
upstream conductor_@@UPSTREAM@@ {
    # Load balancing defaults to round-robin when no method is specified.
    # Uncomment one method below if it better matches your backend application.
    #least_conn;
    #ip_hash;
    #random two least_conn;

    server @@TARGET_HOST@@ max_fails=3 fail_timeout=30s;

    # Example: add two or three backend instances for load balancing.
    #server 127.0.0.1:9001 max_fails=3 fail_timeout=30s;
    #server 127.0.0.1:9002 max_fails=3 fail_timeout=30s;
    #server 127.0.0.1:9003 backup;

    # Optional backend tuning examples.
    #keepalive 32;
    #zone conductor_@@UPSTREAM@@ 64k;
}

# Optional secondary upstream for routing a specific path, such as an API
# gateway or admin backend. Uncomment this block and the matching location
# example below, then update the target host.
#upstream conductor_@@UPSTREAM@@_api {
#    server 127.0.0.1:9100;
#}

# Enable this configuration block if you wish to configure SSL and force all HTTP traffic over SSL (https).
# -- C:Start Default HTTP to HTTPS Redirect Block -- #
#server {
#       listen         80;
#       listen         [::]:80;
#       server_name    @@DOMAIN@@;
#       include        /etc/conductor/configs/common/wellknown.conf;
#       return         301 https://$server_name$request_uri;
#}
# -- C:End Default HTTP to HTTPS Redirect Block -- #

# If you wish to redirect HTTPS traffic too, such as from a `www.` sub-domain to your TLD, you should enable this configuration block.
# Be sure to replace the two occurrences of `{yourdomain}` in this configuration block with your TLD.
#server {
#        listen                  443 ssl;
#        listen                  [::]:443 ssl;
#        ssl_certificate         /etc/letsencrypt/live/@@APPNAME@@/fullchain.pem;
#        ssl_certificate_key     /etc/letsencrypt/live/@@APPNAME@@/privkey.pem;
#        ssl_trusted_certificate /etc/letsencrypt/live/@@APPNAME@@/chain.pem;
#        include                /etc/nginx/snippets/ssl-params.conf;
#        server_name             www.{yourdomain}; # Replace with your domain name.
#        return                  301 https://{yourdomain}$request_uri; # Replace with your domain name.
#}

server {

    # Comment this line out if you wish to switch to HTTPS (but then enable the next code block!).
    # -- C:Start Default (HTTP) Main Block -- #
    listen                   80;
    listen                   [::]:80;
    # -- C:End Default (HTTP) Main Block -- #

    # -- C:Start Auto-LetsEncrypt Main Block -- #
    #listen                  443 ssl;
    #listen                  [::]:443 ssl;
    #ssl_certificate         /etc/letsencrypt/live/@@APPNAME@@/fullchain.pem;
    #ssl_certificate_key     /etc/letsencrypt/live/@@APPNAME@@/privkey.pem;
    #ssl_trusted_certificate /etc/letsencrypt/live/@@APPNAME@@/chain.pem;
    #include                 /etc/nginx/snippets/ssl-params.conf;
    # -- C:End Auto-LetsEncrypt Main Block -- #

    server_name     @@DOMAIN@@;
    server_tokens   off;

    # Application path and index file settings.
    root            /var/conductor/applications/@@APPPATH@@;
    index           index.php index.html;

    # Logging settings
    access_log      @@HLOGS@@access.log;
    error_log       @@HLOGS@@error.log;
    rewrite_log     off;

    # Fail2Ban (optional) protection managed by `conductor protect`.
    # -- C:Start Fail2Ban Protection Block -- #
    #access_log     /tmp/conductor_@@APPNAME@@.seclog conductor_security;
    # -- C:End Fail2Ban Protection Block -- #

    # Additional per-application optimisations.
    charset                 utf-8;
    client_max_body_size    32m;
    client_body_timeout     60s;
    client_header_timeout   30s;

    # Optional HTTP Basic authentication managed by `conductor auth`.
    # -- C:Start HTTP Basic Auth Block -- #
    #auth_basic           "Restricted";
    #auth_basic_user_file /etc/conductor/pwdbs/.htpasswd_@@APPNAME@@;
    # -- C:End HTTP Basic Auth Block -- #

    # Request rate/connection limiting requires matching limit_req_zone or
    # limit_conn_zone directives at http{} scope, for example in nginx.conf.
    #limit_req zone=conductor_@@APPNAME@@ burst=20 nodelay;
    #limit_conn conductor_@@APPNAME@@ 20;

    # Enable GZip by default for common files.
    #include /etc/conductor/configs/common/gzip.conf;

    # Optional but sensible defaults for caching assets (eg. images, CSS) files etc.
    # location ~* \.(png|jpg|jpeg|gif|js|css|ico)$ {
    #    expires 30d;
    #    log_not_found off;
    # }

    # LetsEncrypt verification block
    include /etc/conductor/configs/common/wellknown.conf;

    # Optional WAF configuration (app and security-related protection live in here) managed by `conductor waf {appname}`.
    # -- C:Start WAF Include Block -- #
    include /etc/conductor/wafs/@@APPNAME@@.conf;
    # -- C:End WAF Include Block -- #

    # Serve local, branded error pages when the upstream backend is unavailable.
    error_page 502 /.502.html;
    error_page 503 /.503.html;
    error_page 504 /.504.html;
    location ~ ^/\.(?:502|503|504)\.html$ {
        internal;
        add_header Cache-Control "no-store";
    }

    location / {
        proxy_pass         @@TARGET_SCHEME@@://conductor_@@UPSTREAM@@;
        proxy_intercept_errors on;
        proxy_http_version 1.1;
        proxy_redirect     off;
        proxy_next_upstream error timeout invalid_header http_502 http_503 http_504;
        proxy_next_upstream_tries 3;
        proxy_set_header   Host              $host;
        proxy_set_header   X-Real-IP         $remote_addr;
        proxy_set_header   X-Forwarded-For   $proxy_add_x_forwarded_for;
        proxy_set_header   X-Forwarded-Proto $scheme;
        proxy_set_header   X-Forwarded-Host  $host;
        proxy_set_header   X-Forwarded-Port  $server_port;

        # If proxying to an HTTPS backend with a self-signed certificate, you can
        # bypass upstream certificate verification. Prefer installing/trusting the
        # backend CA when possible.
        #proxy_ssl_verify off;
        #proxy_ssl_server_name on;
        #proxy_ssl_name $proxy_host;

        # Enable these two lines for WebSocket or HTTP upgrade support.
        #proxy_set_header  Upgrade           $http_upgrade;
        #proxy_set_header  Connection        "upgrade";

        # Increase these for long-running requests or large upstream responses.
        #proxy_connect_timeout 60s;
        #proxy_send_timeout    60s;
        #proxy_read_timeout    60s;

        # Tune proxy buffers for large response headers or chatty upstreams.
        #proxy_buffer_size 16k;
        #proxy_buffers 8 16k;
        #proxy_busy_buffers_size 32k;

        # Disable buffering for streaming APIs or server-sent events.
        #proxy_buffering off;

        # Hide or rewrite upstream headers that can leak backend details.
        #proxy_hide_header X-Powered-By;
        #proxy_hide_header Server;

        # Add cookie flags when the upstream app cannot set them itself.
        #proxy_cookie_flags ~ secure httponly samesite=lax;

        # Rewrite backend cookie domains/paths when proxying a legacy app.
        #proxy_cookie_domain backend.internal @@DOMAIN_FIRST@@;
        #proxy_cookie_path / /;

        # Optional bandwidth/rate throttling examples.
        #limit_rate 512k;
        #limit_rate_after 5m;

        # Optional proxy cache example. Requires proxy_cache_path at http{} scope.
        #proxy_cache conductor_@@APPNAME@@;
        #proxy_cache_valid 200 301 302 10m;
        #proxy_cache_valid 404 1m;
        #add_header X-Proxy-Cache $upstream_cache_status always;
    }

    # Optional path-based proxy example. Useful for routing /api/ to a separate
    # backend while the rest of the site uses the main upstream above.
    #location /api/ {
    #    proxy_pass         @@TARGET_SCHEME@@://conductor_@@UPSTREAM@@_api;
    #    proxy_http_version 1.1;
    #    proxy_redirect     off;
    #    proxy_set_header   Host              $host;
    #    proxy_set_header   X-Real-IP         $remote_addr;
    #    proxy_set_header   X-Forwarded-For   $proxy_add_x_forwarded_for;
    #    proxy_set_header   X-Forwarded-Proto $scheme;
    #    proxy_set_header   X-Forwarded-Host  $host;
    #    proxy_set_header   X-Forwarded-Port  $server_port;
    #
    #    # If your backend expects paths without /api/, use this rewrite before proxy_pass.
    #    #rewrite ^/api/?(.*)$ /$1 break;
    #}

}
