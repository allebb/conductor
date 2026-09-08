[program:@@APPNAME@@-@@INSTANCE@@]
process_name=%(program_name)s_%(process_num)02d
command=@@PHPBIN@@ @@APPPATH@@/artisan queue:work --sleep=3 --tries=3 --max-time=3600
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=@@WEBUSER@@
# Increase this for parallel processing.
numprocs=1
redirect_stderr=true
stdout_logfile=@@LOGPATH@@/@@APPNAME@@-@@INSTANCE@@.log
stopwaitsecs=3600
