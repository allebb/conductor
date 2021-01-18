# /etc/cron.d/conductor_@@APPNAME@@: crontab entries for Conductor managed applications.
#
# Format is {schedule} {user} {command(s)}
#
# For the most part the {user} should be set to 'www-data', a Laravel Task Scheduler example would look as follows:
# * * * * * www-data cd @@APPPATH@@ && /usr/bin/php artisan schedule:run >> /dev/null 2>&1
#
# Remember that you can use different PHP versions if you need support for older PHP versions, simpy change the `php` binary
# path (eg. /usr/bin/php7.4 or /usr/bin/php8.0).
#
SHELL=/bin/sh
PATH=/usr/local/sbin:/usr/local/bin:/sbin:/bin:/usr/sbin:/usr/bin

