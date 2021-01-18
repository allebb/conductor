# Conductor managed Nginx Virtual Host File
#
# IMPORTANT:
# If you manually edit this file you must ensure that the list of managed domains below are updated if you
# add or remove domains to this virtualhost configuration as Conductor will use this list to provision and
# manage certificates with LetsEncrypt certificates on your behalf. You must ensure that each domain or sub-
# domain is separated with a single space.
#
# To generate or manually renew LetsEncrypt certificates you should use the `conductor letsencrypt {name}`
# command in your terminal.
#
#:: Application name: [@@APPNAME@@]
#:: Managed domains: [@@DOMAIN@@]
#

# Enable this configuration block if you wish to configure SSL and force all HTTP traffic over SSL (https).
#server {
#       listen         80;
#       server_name    @@DOMAIN@@;
#       include        /etc/conductor/configs/common/wellknown.conf;
#       return         301 https://$server_name$request_uri;
#}

# If you wish to redirect HTTPS traffic too, such as from a `www.` sub-domain to your TLD, you should enable this configuration block.
# Be sure to replace the two occurrences of `{yourdomain}` in this configuration block with your TLD.
#server {
#        listen                  443 ssl;
#        ssl_certificate         /etc/letsencrypt/live/@@APPNAME@@/fullchain.pem;
#        ssl_certificate_key     /etc/letsencrypt/live/@@APPNAME@@/privkey.pem;
#        ssl_trusted_certificate /etc/letsencrypt/live/@@APPNAME@@/chain.pem;
#        include /etc/nginx/snippets/ssl-params.conf;
#        server_name   www.{yourdomain};
#        return        301 https://{yourdomain}$request_uri;
#}

server {

	# Comment this line out if you wish to switch to HTTPS (but then enable the next code block!).
	listen                   80;

	# Uncomment to enable default LetsEncrypt certificates.
	#listen                  443 ssl;
	#ssl_certificate         /etc/letsencrypt/live/@@APPNAME@@/fullchain.pem;
	#ssl_certificate_key     /etc/letsencrypt/live/@@APPNAME@@/privkey.pem;
	#ssl_trusted_certificate /etc/letsencrypt/live/@@APPNAME@@/chain.pem;
	#include /etc/nginx/snippets/ssl-params.conf;

	server_name     @@DOMAIN@@;
	server_tokens   off;

	# Application path and index file settings.
	root            /var/conductor/applications/@@APPPATH@@;
	index           index.html index.htm;

	# Logging settings
	access_log      @@HLOGS@@access.log;
	error_log       @@HLOGS@@error.log;
	rewrite_log     off;

	# Recommended security headers
	add_header      X-Frame-Options         "SAMEORIGIN";
	add_header      X-XSS-Protection        "1; mode=block";
	add_header      X-Content-Type-Options  "nosniff";

	# Additional per-application optimisations.
	charset utf-8;
	client_max_body_size 2m;

	# Enable GZip by default for common files.
	include /etc/conductor/configs/common/gzip.conf;

	# Optional but sensible defaults for caching assets (eg. images, CSS) files etc.
	location ~* \.(png|jpg|jpeg|gif|js|css|ico|html|htm)$ {
		expires 30d;
		log_not_found off;
	}

	# LetsEncrypt verification block
	include /etc/conductor/configs/common/wellknown.conf;

	# Disable access and error logs for requests to these common files.
	location = /favicon.ico { allow_all; access_log off; log_not_found off; }
	location = /robots.txt  { allow_all; access_log off; log_not_found off; }

	# LetsEncrypt verification block
	include /etc/conductor/configs/common/wellknown.conf;

	# Deny access to .htaccess, .git and other hidden files by default.
	location ~ /\.(?!well-known).* {
		deny all;
		access_log off;
		log_not_found off;
		return 404;
	}

}