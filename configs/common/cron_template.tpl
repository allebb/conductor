# /etc/cron.d/conductor_@@APPNAME@@: crontab entries for Conductor managed applications.
#
# Format is {schedule} {user} {command(s)}
#
# For the most part the {user} should be set to 'www-data', a Laravel Task Scheduler example would look as follows:
# * * * * * www-data cd @@APPPATH@@ && /usr/bin/php artisan schedule:run >> /dev/null 2>&1
#
# The `php` binary defaults to PHP 8.5. If you need support for an older PHP version, simply change the `php` binary
# path (eg. /usr/bin/php8.1, /usr/bin/php8.2, /usr/bin/php8.3 or /usr/bin/php8.4).
#
SHELL=/bin/sh
PATH=/usr/local/sbin:/usr/local/bin:/sbin:/bin:/usr/sbin:/usr/bin

