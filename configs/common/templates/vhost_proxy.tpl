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
	index           index.php index.html;

	# Logging settings
	access_log      @@HLOGS@@access.log;
	error_log       @@HLOGS@@error.log;
	rewrite_log     off;

	# Fail2Ban (optional) protection, uncomment to enable!
	#access_log     /tmp/conductor_@@APPNAME@@.seclog conductor_security;

	# Recommended security headers
	#add_header      X-Frame-Options         "SAMEORIGIN";
	#add_header      X-XSS-Protection        "1; mode=block";
	#add_header      X-Content-Type-Options  "nosniff";
	#add_header      Referrer-Policy         "strict-origin-when-cross-origin";

	# Optional security headers. Enable after confirming they do not block required third-party assets or browser APIs.
	#add_header      Permissions-Policy      "camera=(), microphone=(), geolocation=()";
	#add_header      Content-Security-Policy "default-src 'self'; img-src 'self' data:; script-src 'self'; style-src 'self' 'unsafe-inline';";

	# Additional per-application optimisations.
	charset utf-8;
	client_max_body_size 32m;
	client_body_timeout 60s;
	client_header_timeout 30s;

	# Enable GZip by default for common files.
	#include /etc/conductor/configs/common/gzip.conf;

	# Optional but sensible defaults for caching assets (eg. images, CSS) files etc.
	# location ~* \.(png|jpg|jpeg|gif|js|css|ico)$ {
	#    expires 30d;
	#    log_not_found off;
	# }

	# LetsEncrypt verification block
	include /etc/conductor/configs/common/wellknown.conf;

	# Disable access and error logs for requests to these common files.
	location = /favicon.ico { access_log off; log_not_found off; }
	location = /robots.txt  { access_log off; log_not_found off; }

	# Serve local, branded error pages when the upstream backend is unavailable.
	error_page 502 /.502.html;
	error_page 503 /.503.html;
	error_page 504 /.504.html;
	location ~ ^/\.(?:502|503|504)\.html$ {
		internal;
		add_header Cache-Control "no-store";
	}

	# Deny access to common project readme files that may disclose implementation details.
	location ~* (^|/)readme(?:\.(?:txt|md|markdown|html?))?$ {
		deny all;
		access_log off;
		log_not_found off;
		return 404;
	}

	# Deny access to .htaccess, .git and other hidden files before proxying to the upstream app.
	# LetsEncrypt ACME challenges are handled by Conductor; other /.well-known/ paths may be served by the backend.
	location ~ /\.(?!well-known(?:/|$)).* {
		deny all;
		access_log off;
		log_not_found off;
		return 404;
	}

	location / {
		#**************************************************************************************#
		# Update the temp port (9000) below to the required port for your backend application! #
		#**************************************************************************************#
		proxy_pass         http://127.0.0.1:9000;
		proxy_intercept_errors on;
		proxy_http_version 1.1;
		proxy_redirect     off;
		proxy_set_header   Host              $host;
		proxy_set_header   X-Real-IP         $remote_addr;
		proxy_set_header   X-Forwarded-For   $proxy_add_x_forwarded_for;
		proxy_set_header   X-Forwarded-Proto $scheme;
		proxy_set_header   X-Forwarded-Host  $host;
		proxy_set_header   X-Forwarded-Port  $server_port;

		# Enable these two lines for WebSocket or HTTP upgrade support.
		#proxy_set_header  Upgrade           $http_upgrade;
		#proxy_set_header  Connection        "upgrade";

		# Increase these for long-running requests or large upstream responses.
		#proxy_connect_timeout 60s;
		#proxy_send_timeout    60s;
		#proxy_read_timeout    60s;

		# Disable buffering for streaming APIs or server-sent events.
		#proxy_buffering off;
	}

}
