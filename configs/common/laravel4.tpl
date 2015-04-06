################################################################################
# Larvel Framework version 4.x configuration template for Conductor            #
################################################################################

if (!-d $request_filename) {
    rewrite ^/(.+)/$ /$1 permanent;
}

location = /favicon.ico { access_log off; log_not_found off; }
location = /robots.txt  { access_log off; log_not_found off; }

location ~* \.php$ {
    try_files $uri /index.php =404;
    fastcgi_pass                    unix:/var/run/php5-fpm.sock;
    fastcgi_index                   index.php;
    fastcgi_split_path_info         ^(.+\.php)(.*)$;
    include                         /etc/nginx/fastcgi_params;
    fastcgi_param                   SCRIPT_FILENAME $document_root$fastcgi_script_name;
}

location ~ /\.ht {
    deny all;
}