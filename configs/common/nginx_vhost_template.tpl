#######################################################
# Nginx configuration file autogenerated by Conductor #
# https://github.com/bobsta63/conductor               #
#######################################################

server {
    listen          80;
    server_name     @@DOMAIN@@;

    access_log      /var/conductor/logs/@@APPNAME@@/access.log;
    error_log       /var/conductor/logs/@@APPNAME@@/error.log;
    rewrite_log     on;

    root            /var/conductor/applications/@@APPNAME@@/public;
    index           index.php;

    location ~* \.(png|jpg|jpeg|gif|js|css|ico)$ {
        expires 30d;
        log_not_found off;
    }

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }
    include /etc/conductor/configs/common/laravel4.tpl;
}