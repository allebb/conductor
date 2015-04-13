# Upgrading Conductor

Upgrading your version of Conductor is as easy as running the automated upgrade script, from the console simply run:

```shell
/etc/conductor/upgrade.sh
```

Conductor should now be fully up to date, remember that the web server components are updated using 'apt-get upgrade' and is not covered in the above instructions.

## Upgrading to major versions

From time to time, major changes may be made to Conductor which may require additional manual steps, although rare, these changes between versions will be documented below.

### Upgrading from v2.x to v3.x

* Make a note of your MySQL root password under /etc/conductor.conf (or /etc/conductor/bin/conf/conductor.conf depending on when you originally installed Conductor).
* Run ``sudo /etc/conductor/upgrade.sh``
* Copy the Conductor configuration template to /etc/conductor.conf overwriting the existing one: ``sudo cp -f /etc/conductor/bin/conf/conductor.template.json /etc/conductor.conf``
* Edit **/etc/conductor.conf** and set the MySQL root password back (replace where is says 'ROOT_PASSWORD_HERE').
* Re-link the conductor CLI tool - ``ln -f -s /etc/conductor/bin/conductor.php /usr/bin/conductor``
* Test the upgrade was complete by running: ``sudo conductor -v``

