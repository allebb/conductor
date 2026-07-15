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

# If you wish to redirect HTTPS traffic too, such as from a `www.` subdomain to your TLD, you should enable this configuration block.
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
	index           index.php conductor.html;

	# Logging settings
	access_log      @@HLOGS@@access.log;
	error_log       @@HLOGS@@error.log;
	rewrite_log     on;

	# Fail2Ban (optional) protection, uncomment to enable!
	#access_log     /tmp/conductor_@@APPNAME@@.seclog conductor_security;

	# Recommended security headers
	add_header      X-Frame-Options         "SAMEORIGIN";
	add_header      X-XSS-Protection        "1; mode=block";
	add_header      X-Content-Type-Options  "nosniff";
	add_header      Referrer-Policy         "strict-origin-when-cross-origin";

	# Optional security headers. Enable after confirming they do not block required plugins, themes, or browser APIs.
	#add_header     Permissions-Policy      "camera=(), microphone=(), geolocation=()";
	#add_header     Content-Security-Policy "default-src 'self'; img-src 'self' data: https:; script-src 'self' 'unsafe-inline' 'unsafe-eval'; style-src 'self' 'unsafe-inline';";

	# Additional per-application optimisations.
	charset utf-8;
	client_max_body_size 32m;
	client_body_timeout 60s;
	client_header_timeout 30s;

	# Example GeoIP country block. Enable the geoip2 lookup in
	# /etc/conductor/configs/common/conductor_nginx.conf, then add ISO 3166-1
	# alpha-2 country codes to the regex for this vhost.
	#
	# if ($conductor_geoip_country_code ~ ^(CN|RU)$) {
	#	return 444;
	# }

	# Optional HTTP Basic authentication managed by `conductor auth`.
	# -- C:Start HTTP Basic Auth Block -- #
	#auth_basic           "Restricted";
	#auth_basic_user_file /etc/conductor/auth/.htpasswd_@@APPNAME@@;
	# -- C:End HTTP Basic Auth Block -- #

	# Enable GZip by default for common files.
	include /etc/conductor/configs/common/gzip.conf;

	# Optional but sensible defaults for caching assets (eg. images, CSS) files etc.
	location ~* \.(?:png|jpg|jpeg|gif|webp|avif|svg|js|css|ico|woff|woff2)$ {
	   expires 30d;
	   add_header Cache-Control "public";
	   log_not_found off;
	}

	# LetsEncrypt verification block
	include /etc/conductor/configs/common/wellknown.conf;

	# Disable access and error logs for requests to these common files.
	location = /favicon.ico { allow all; access_log off; log_not_found off; }
	location = /robots.txt  { allow all; access_log off; log_not_found off; }

	# Root location handler configuration.
	location / {
		try_files $uri $uri/ /index.php?$args;
	}

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
	#	deny all;
	#	access_log off;
	#	log_not_found off;
	#	return 404;
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

		# Optionally you can override any (default) PHP configuration (php.ini) values:
		#fastcgi_param  PHP_VALUE  upload_max_filesize=32M;
		#fastcgi_param  PHP_VALUE  post_max_size=38M;
		#fastcgi_read_timeout           120s;
		#fastcgi_buffers                16 16k;
		#fastcgi_buffer_size            32k;

		# START APPLICATION ENV VARIABLES
		fastcgi_param                   APP_ENV @@ENVIROMENT@@;
		# END APPLICATION ENV VARIABLES
	}

}
