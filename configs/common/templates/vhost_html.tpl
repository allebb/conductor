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
#        include /etc/nginx/snippets/ssl-params.conf;
#        server_name   www.{yourdomain};
#        return        301 https://{yourdomain}$request_uri;
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
    #include /etc/nginx/snippets/ssl-params.conf;
    # -- C:End Auto-LetsEncrypt Main Block -- #

    server_name     @@DOMAIN@@;
    server_tokens   off;

    # Application path and index file settings.
    root            /var/conductor/applications/@@APPPATH@@;
    index           index.html index.htm conductor.html;

    # Logging settings
    access_log      @@HLOGS@@access.log;
    error_log       @@HLOGS@@error.log;
    rewrite_log     off;

    # Fail2Ban (optional) protection managed by `conductor protect`.
    # -- C:Start Fail2Ban Protection Block -- #
    #access_log     /tmp/conductor_@@APPNAME@@.seclog conductor_security;
    # -- C:End Fail2Ban Protection Block -- #

    # Additional per-application optimisations.
    charset utf-8;
    client_max_body_size 2m;
    client_body_timeout 30s;
    client_header_timeout 30s;

    # Optional HTTP Basic authentication managed by `conductor auth`.
    # -- C:Start HTTP Basic Auth Block -- #
    #auth_basic           "Restricted";
    #auth_basic_user_file /etc/conductor/pwdbs/.htpasswd_@@APPNAME@@;
    # -- C:End HTTP Basic Auth Block -- #

    # Enable GZip by default for common files.
    include /etc/conductor/configs/common/gzip.conf;

    # Optional but sensible defaults for caching assets (eg. images, CSS) files etc.
    location ~* \.(?:png|jpg|jpeg|gif|webp|avif|svg|js|css|ico|woff|woff2)$ {
        expires 30d;
        add_header Cache-Control "public";
        log_not_found off;
    }

    # Uncomment to prevent browsers from caching HTML documents while still caching static assets above.
    #location ~* \.(?:html|htm)$ {
    #    expires -1;
    #    add_header Cache-Control "no-store";
    #}

    # LetsEncrypt verification block
    include /etc/conductor/configs/common/wellknown.conf;

    # Optional WAF configuration managed by `conductor waf`.
    # -- C:Start WAF Include Block -- #
    include /etc/conductor/wafs/@@APPNAME@@.conf;
    # -- C:End WAF Include Block -- #

    # Root location handler configuration.
    location / {
        try_files $uri $uri/ =404;
    }

    # Optional custom error pages. Create these files before enabling.
    #error_page 404 /404.html;
    #error_page 500 502 503 504 /50x.html;

}
