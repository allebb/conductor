# Conductor managed Nginx Virtual Host File
#
# Auto-created by Conductor (v@@VERSION@@) at: @@CREATED_AT@@
#
#:: Application name: [@@APPNAME@@]
#:: Managed domains: [@@DOMAIN@@]
#

upstream conductor_@@UPSTREAM@@ {
    server 127.0.0.1:@@S3_PORT@@ max_fails=3 fail_timeout=30s;
    keepalive 32;
}

# Enable this block when enabling SSL to redirect HTTP traffic to HTTPS.
# -- C:Start Default HTTP to HTTPS Redirect Block -- #
#server {
#       listen         80;
#       listen         [::]:80;
#       server_name    @@DOMAIN@@;
#       include        /etc/conductor/configs/common/wellknown.conf;
#       return         301 https://$server_name$request_uri;
#}
# -- C:End Default HTTP to HTTPS Redirect Block -- #

server {
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
    set             $conductor_application "@@APPNAME@@";
    root            /var/conductor/applications/@@APPNAME@@;

    # Enable these if normal request logging is required.
    #access_log @@HLOGS@@access.log;
    #error_log  @@HLOGS@@error.log;

    # S3 clients may upload large objects and hold requests open for some time.
    client_max_body_size  0;
    client_body_timeout   3600s;
    client_header_timeout 60s;

    # -- C:Start HTTP Basic Auth Block -- #
    #auth_basic           "Restricted";
    #auth_basic_user_file /etc/conductor/pwdbs/.htpasswd_@@APPNAME@@;
    # -- C:End HTTP Basic Auth Block -- #

    # Recommended security headers. Enable after confirming client compatibility.
    #add_header X-Frame-Options        "SAMEORIGIN";
    #add_header X-Content-Type-Options "nosniff";
    #add_header Referrer-Policy        "strict-origin-when-cross-origin";

    include /etc/conductor/configs/common/conductor_quiet_common_requests.conf;
    include /etc/conductor/configs/common/wellknown.conf;

    # -- C:Start WAF Include Block -- #
    #include /etc/conductor/wafs/@@APPNAME@@.conf;
    # -- C:End WAF Include Block -- #

    # -- C:Start Fail2Ban Protection Block -- #
    #access_log /var/conductor/seclogs/conductor_@@APPNAME@@.seclog conductor_security;
    # -- C:End Fail2Ban Protection Block -- #

    include /etc/conductor/configs/common/conductor_error_pages.conf;
    include /etc/conductor/configs/common/conductor_proxy_error_pages.conf;

    location / {
        proxy_pass              http://conductor_@@UPSTREAM@@;
        proxy_http_version      1.1;
        proxy_request_buffering off;
        proxy_buffering         off;
        # Preserve VersityGW's S3-compatible XML error responses.
        proxy_intercept_errors  off;
        proxy_redirect          off;
        proxy_connect_timeout   60s;
        proxy_send_timeout      3600s;
        proxy_read_timeout      3600s;
        proxy_set_header        Host              $http_host;
        proxy_set_header        X-Real-IP         $remote_addr;
        proxy_set_header        X-Forwarded-For   $proxy_add_x_forwarded_for;
        proxy_set_header        X-Forwarded-Proto $scheme;
        proxy_set_header        Connection        "";
    }

}
