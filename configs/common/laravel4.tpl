################################################################################
# Larvel Framework version 4.x configuration template for Conductor            #
################################################################################

if (!-d $request_filename) {
    rewrite ^/(.+)/$ /$1 permanent;
}

location ~* \.php$ {
    fastcgi_pass                    unix:/var/run/php5-fpm.sock;
    fastcgi_index                   index.php;
    fastcgi_split_path_info         ^(.+\.php)(.*)$;
    include                         /etc/nginx/fastcgi_params;
    fastcgi_param                   SCRIPT_FILENAME $document_root$fastcgi_script_name;
}

location ~ /\.ht {
    deny all;
}