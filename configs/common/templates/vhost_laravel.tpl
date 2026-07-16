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
#        include                 /etc/nginx/snippets/ssl-params.conf;
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
    index           index.php conductor.html;

    # Logging settings
    access_log      @@HLOGS@@access.log;
    error_log       @@HLOGS@@error.log;
    rewrite_log     on;

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

    # Recommended security headers.
    add_header      X-Frame-Options         "SAMEORIGIN";
    add_header      X-XSS-Protection        "1; mode=block";
    add_header      X-Content-Type-Options  "nosniff";
    add_header      Referrer-Policy         "strict-origin-when-cross-origin";

    # Optional security headers. Enable after confirming they do not block required third-party assets or browser APIs.
    #add_header     Permissions-Policy      "camera=(), microphone=(), geolocation=()";
    #add_header     Content-Security-Policy "default-src 'self'; img-src 'self' data:; script-src 'self'; style-src 'self' 'unsafe-inline';";

    # Disable access and error logs for routine browser discovery requests.
    include /etc/conductor/configs/common/conductor_quiet_common_requests.conf;

    # Enable GZip by default for common files.
    include /etc/conductor/configs/common/gzip.conf;

    # Optional but sensible defaults for caching assets (eg. images, CSS) files etc.
    # location ~* \.(?:png|jpg|jpeg|gif|webp|avif|svg|js|css|ico|woff|woff2)$ {
    #    expires 30d;
    #    add_header Cache-Control "public";
    #    log_not_found off;
    # }

    # LetsEncrypt verification block
    include /etc/conductor/configs/common/wellknown.conf;

    # Optional WAF-like configuration (app and security-related protection) configure/customise with `conductor waf {appname}`.
    # -- C:Start WAF Include Block -- #
    #include /etc/conductor/wafs/@@APPNAME@@.conf;
    # -- C:End WAF Include Block -- #

    # Root location handler configuration.
    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    # Laravel framework specific configuration.
    if (!-d $request_filename) {
        rewrite ^/(.+)/$ /$1 permanent;
    }

    # PHP-FPM handler configuration.
    location ~* \.php$ {
        try_files                       $uri /index.php =404;

        # Defaults to PHP 8.5. If your application requires an older PHP version instead, change the UNIX socket to eg. "unix:/var/run/php/php8.3-fpm.sock;" instead!
        fastcgi_pass                    unix:@@SOCKET@@;
        fastcgi_index                   index.php;
        fastcgi_split_path_info         ^(.+\.php)(.*)$;
        include                         @@FASTCGIPARAMS@@;
        fastcgi_param                   SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_param                   HTTP_PROXY "";
        #fastcgi_read_timeout           120s;
        #fastcgi_buffers                16 16k;
        #fastcgi_buffer_size            32k;

        # Optionally you can override any (default) PHP configuration (php.ini) values per virtual host:
        #fastcgi_param  PHP_VALUE       "upload_max_filesize=30M
        #                               post_max_size=32M
        #                               memory_limit=256M
        #                               max_execution_time=120
        #                               max_input_time=120
        #                               max_input_vars=3000
        #                               max_file_uploads=20";

        # START APPLICATION ENV VARIABLES
        fastcgi_param                   APP_ENV @@ENVIROMENT@@;
        # END APPLICATION ENV VARIABLES
    }

}
