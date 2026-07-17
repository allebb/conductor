Conductor
=========

Conductor is a CLI utility to automate the installation of Laravel application servers complete with some scripts and CLI commands to help deploy and manage multiple web applications on the server with ease. Conductor can also be used as a reverse proxy/load-balancer and the installation process allows you to install a minimal set of components if you don't plan to host sites or applications directly.

Requirements
------------
Conductor is developed, tested and supported on Debian 12 (Bookworm) and Debian 13 (Trixie). Other Linux distributions, Ubuntu releases, and FreeBSD are no longer supported by the installer.

Installation
------------

Installation on supported Debian servers can be done effortlessly by simply running this command from the console!

> Please ensure you install the ``sudo``, ``lsb-release``, and ``curl`` packages **BEFORE** attempting to run the installer. You can do this by running ``apt install -y sudo lsb-release curl``.

```shell
bash -c "$(curl -fsSL https://raw.github.com/allebb/conductor/stable/install.sh)"
```

For a minimal reverse-proxy/load-balancer style installation, you can use ``--proxy-only``. This skips the optional local MySQL, Redis, SupervisorD, and additional PHP version prompts, and installs only the required PHP 8.5 runtime alongside Nginx and the core Conductor tooling:

```shell
bash -c "$(curl -fsSL https://raw.github.com/allebb/conductor/stable/install.sh)" -- --proxy-only
```

Proxy-only installs also set Conductor's default application template to ``proxy`` in ``/etc/conductor.conf``. You can still override the template for a specific application by passing ``--template={template}`` to ``conductor new``.

If you would like to automate the deployments of your applications using Git and/or standalone webhooks, you should check out [Hooker](https://github.com/allebb/hooker) - Another tool that I've built ;)

If you wish to install Conductor from a specific branch, you can set the ``BRANCH_INSTALL`` environment variable before running the installer like so:

```shell
export BRANCH_INSTALL="stable" # Set the name of the Git Branch you want to install from.
bash -c "$(curl -fsSL https://raw.github.com/allebb/conductor/stable/install.sh)" # Then, when we run the installer, it'll clone and install from the required branch!
```

If you choose to install additional PHP versions, each of the FPM pools are started automatically. If you don't intend on using specific versions you can disable them (freeing resources) as follows:

```shell
# List all existing/installed PHP-FPM units:
systemctl list-units --type=service | grep fpm

# You can individually disable them like so:
sudo systemctl disable php7.4-fpm && sudo systemctl stop php7.4-fpm 
sudo systemctl disable php8.1-fpm && sudo systemctl stop php8.1-fpm 
sudo systemctl disable php8.4-fpm && sudo systemctl stop php8.4-fpm 
sudo systemctl disable php8.5-fpm && sudo systemctl stop php8.5-fpm 

# Want to re-enable them (to use with web applications requiring specific PHP versions), simply run:
sudo systemctl enable php7.4-fpm && sudo systemctl start php7.4-fpm 
```

Upgrading Conductor
-------------------

Upgrade instructions can be found in the [UPGRADE](UPGRADE.md) section.

What does this install
----------------------

Out of the box this script will install and configure the following packages using aptitude:-

* Nginx
* PHP 8.5 (required by Conductor)
* Git Client
* CertBot (LetsEncrypt)
* Logrotate

The current Debian installers will also ask whether you want to install:

* MySQL
* Redis
* Supervisor
* Additional PHP versions for hosted applications

If you choose not to install MySQL locally, Conductor will not ask database provisioning questions when creating or deleting applications.

Optional post-install hardening:

* Fail2Ban (recommended)
* nftables (recommended)
* CrowdSec

How to use it
-------------
It's pretty straight forward to use, I'll go over briefly the main features (CLI options) and what they do.

### List of available commands and what they do

### Shell completion

The Debian installers install Bash completion for ``conductor`` via ``/etc/bash_completion.d/conductor``. Completion is split between that shell hook and the PHP CLI: Bash handles the Tab key, then calls Conductor's hidden ``__complete`` command to return commands, options, subcommands, and deployed application names from ``/etc/conductor.conf`` paths.

#### ```conductor list```

A simple command that displays the names of the currently deployed applications on the server.

#### ```conductor stats```

Displays operating system uptime, Nginx daemon uptime, Nginx connection details, enabled/disabled virtual host counts, enabled/disabled stream configuration counts, all configured server IP addresses, and the public IP. Use ``conductor stats --format=json`` for machine-readable output.

#### ```conductor metrics```

Displays Conductor metrics in Prometheus text exposition format, suitable for node_exporter's textfile collector and Grafana dashboards. See [Grafana_integration.md](Grafana_integration.md) for setup examples.

#### ```conductor new {app name}```

When you first want to deploy a new instance of a Laravel application on to your server, you are required to SSH in (or you could write a web-based application to speak to the **conductor(( behind if you wanted too) to your server and then execute the following command:-

 ```sudo conductor new {app name}```

There are other application configurations you can use but by default Conductor will use the default Nginx virtualhost "template" (laravel) but this default can be changed in ``/etc/conductor.conf`` if, for example you only really setup and deploy Wordpress sites. You can however set the required template this when creating an application from the CLI by specifying the ``--template={template}`` option, the current templates that are available are:

* ``wordpress`` - Wordpress "single site" configuration.
* ``laravel`` - Laravel sites/applications.
* ``proxy`` - A generic reverse proxy configuration (great for proxying traffic to backend applications such as a Docker container, a .NET Core, Python or Go application - the possibilities are endless ;))
* ``html`` - A simple HTML configuration (great for static websites or when using Static site generator such as [Gatsby](https://github.com/gatsbyjs/gatsby/), [Jekyll](https://jekyllrb.com/) or [Sculpin](https://sculpin.io/)).

As an example, if you wanted to use a generic proxy template for your application you should use the following command:

```shell
sudo conductor new {app name} --template=proxy --target="http://localhost:9000"
```

If you are creating an application non-interactively, add ``--auto-reload`` to test the Nginx configuration and gracefully reload Nginx after the virtual host has been created without prompting:

```shell
sudo conductor new {app name} --fqdn="example.com" --template=proxy --target="http://localhost:9000" --auto-reload
```

The ``--target`` value is only valid for proxy templates and must be an HTTP(S) URL with a host and explicit port number.

Proxy applications do not ask for a hosted directory because the virtual host serves from the application root. Conductor will also create custom ``502.html``, ``503.html``, and ``504.html`` pages in ``/var/conductor/applications/{app name}/.conductor/error_pages/`` and configure Nginx to show them if the backend application is unreachable. If those copied pages or the ``.conductor/error_pages`` directory are deleted, Nginx falls back to the shared (default) proxy error pages in ``/var/conductor/error-pages/``.

All vhost templates include custom ``401.html``, ``403.html``, ``404.html``, and ``500.html`` error pages by default. Conductor creates local copies in the vhost's ``.conductor/error_pages`` directory (so you can customise them per-site if you wish) first and falls back to shared (default) versions in ``/var/conductor/error-pages/`` if a local page or directory is removed. Comment out ``include /etc/conductor/configs/common/conductor_error_pages.conf;`` in a vhost if the application should handle these responses itself.

For non-proxy applications, Conductor creates a ``conductor.html`` placeholder page in the application's document root and adds it as the last index file. This confirms that the virtual host is ready; you can safely delete ``conductor.html`` after (or before) deploying your own site or application.

This command will prompt you for the 'FQDN' (or you can add multiple addresses of which the Virtualhost will serve requests for, these should be separated by spaces!). After entering the FQDN(s) for the new application you will then be asked for your application's environment type (this basically sets the ``APP_ENV`` environment variable for Nginx of which can then be used by your PHP application like so ``$_SERVER['APP_ENV']``), if your application requires a MySQL database and as you would expect if you decide you do need a MySQL database Conductor will automatically create a database and MySQL user with permissions to only that database (to keep things secure!). The last part of the deployment you are asked how you would like to deploy your application, you have three options of which are as follows:-

* Git - Keep things automated and use Git to clone and keep your application up to date, this is highly recommended as it's so simple to do... This is what Conductor does best ;-)
* Restore from a backup - You can restore from an application back-up taken from either on your current server of if you're migrating from another server; when restoring from a backup Conductor will automatically extract the contents of the specified backup archive and will also automatically import any MySQL databases if found in the backup archive.
* Manual - You are responsible to manually upload the files using SCP/FTP to the ```/var/conductor/applications/{app name}/``` directory or manually clone your into this directory using a VCS client (such as ``git`` or ``hg``).

The templates are just that, a template! Once your application has provisioned on the server you can then run ``conductor edit {appname}`` which will open up your virtual host configuration template and you can tweak it as you need.

That it, once you have passed through all the prompts your application will then be live and accessible!

### Optional Nginx stream proxies

The installer enables a top-level Nginx include for custom TCP/UDP stream configs when the Nginx stream module is available. Custom stream configs can be placed in:

```shell
/etc/conductor/streams/
```

Conductor copies commented ``.conf.example`` files into that directory during installation. Rename an example to ``.conf`` to enable it, or add your own as required. Each enabled stream file should include its own top-level ``stream { ... }`` block. Whilst this is optional and the conductor CLI doesn't provide any management of these (you have to manage them manually), this has been added for those that use Conductor more as a reverse-proxy/load-balancer and can be extremely useful especially when you are using split DNS and proxying internal and external traffic and need a common gateway address. The directory configuration (auto-loading of ``.conf`` files) works in the same way that the other Conductor http/virtual hosts files work and therefore adds commonality and eases administration.

Proxy templates also include a commented cache example. The shared ``conductor_proxy`` cache zone is defined in ``/etc/conductor/configs/common/conductor_nginx.conf`` and stores cached responses under ``/var/conductor/cache/nginx-proxy``. Uncomment the proxy cache lines in a proxy vhost when you want that site to use it.

### Optional Fail2Ban and nftables protection

Conductor includes an optional installer for Fail2Ban and nftables (software firewall) and will monitor for suspitious activity and block bad actors if required. It is not run by the main installer, so you can add it after Conductor is installed, if you wish by running:

```shell
sudo bash /etc/conductor/utils/install_fail2ban_nftables.sh
```

Each Nginx vhost template includes a commented security log line:

```nginx
#access_log /tmp/conductor_{appname}.seclog conductor_security;
```

Run ``sudo conductor waf {app name} --enable`` for any application/website vhost you want to enable bundled WAF rules and Fail2Ban/CrowdSec security logging for. Use ``--auto-reload`` to gracefully reload Nginx automatically after the configuration test passes. The Fail2Ban templates watch ``/tmp/conductor_*.seclog``.

The installer enables these default jails, you can adjust the triggers and ban period as you see fit though:

| Jail | Trigger | Default ban |
| --- | --- | --- |
| ``conductor-manual`` | Manual bans created with ``conductor ban {ip_address}`` | Permanent until explicitly unbanned or purged |
| ``conductor-nginx-scanner`` | 5 scanner probes for common sensitive paths in 10 minutes | 1 hour |
| ``conductor-nginx-4xx`` | 80 non-auth/non-WAF/non-GeoIP 4xx responses in 10 minutes | 30 minutes |
| ``conductor-nginx-401`` | 20 HTTP 401 responses in 10 minutes | 1 hour |
| ``conductor-nginx-403`` | 30 HTTP 403 responses in 10 minutes | 2 hours |
| ``conductor-nginx-waf-violation`` | 20 HTTP 406 WAF rejections in 10 minutes | 3 days |
| ``conductor-nginx-geoip-block`` | 2000 HTTP 444 GeoIP blocks in 1 minute | 24 hours |
| ``conductor-nginx-burst`` | 800 total requests in 30 seconds | 10 minutes |
| ``conductor-nginx-dos`` | 2000 total requests in 1 minute | 24 hours |

Each Conductor Fail2Ban jail also posts JSON ban/unban events to ``https://bin.hallinet.com/z7jw38z7`` (just a default, you should customise this to something you manage) using the installed ``conductor-webhook`` action. The payload includes the event type, jail/action name, IP address, and ban duration for ban events. Edit ``/etc/fail2ban/action.d/conductor-webhook.conf`` if you need to change or disable the endpoint.

> These values can be manually adjusted to fit your personal requirements by editting the default configurations that are installed to ``/etc/conductor/configs/common/fail2ban/``.

The security log format records the timestamp, client IP, Conductor application id, status code, request line, and user agent to keep things "lean" while still making ban webhooks attributable to a site/application. This client IP is the address Nginx sees for the connection. If you access a public hostname from inside the same LAN, router hairpin/NAT reflection can make the request appear to come from your public WAN address instead of your private LAN address. Only configure Nginx real-IP headers when traffic arrives through a trusted proxy whose address ranges you control. The ``/tmp`` path is often memory-backed (which is perfect) on modern Linux systems, but not always; check ``findmnt /tmp`` if this matters for your server. The main installer installs logrotate rules for the normal per-vhost ``access.log`` and ``error.log`` files under ``/var/conductor/logs/*/``, plus matching security logs at 10MB with three compressed rotations.

Once Fail2Ban support is installed, Conductor can manage bans directly:

```shell
# List all current IP bans:
sudo conductor ban list
# Delete all current IP bans (allowing those users access, again):
sudo conductor ban purge
# Manually add an IP address ban:
sudo conductor ban 203.0.113.10
# Manually remove an IP address ban:
sudo conductor unban 203.0.113.10
```

Manual bans are added to the ``conductor-manual`` jail and remain in place until explicitly unbanned or purged. ``ban purge`` clears all IP addresses currently banned by Fail2Ban, including bans created by non-Conductor jails.

#### nftables default firewall behaviour

The optional Fail2Ban+nftables installer keeps nftables passive by default. In other words, nftables is installed and available as the firewall backend used by Fail2Ban to block offending IP addresses, but Conductor does not automatically change the server into a "deny all inbound traffic" firewall. This avoids accidentally locking you out of an existing server.

Conductor's Fail2Ban jails install nftables ban rules on both the ``input`` and ``forward`` hooks. The ``input`` hook covers normal traffic delivered directly to Nginx on the server. The ``forward`` hook helps in NAT, bridge, container, or lab topologies where HTTP/HTTPS packets traverse forwarding before they reach the web service.

After you have installed the optional Fail2Ban+nftables support, you can choose to make nftables block all inbound ports by default and then explicitly allow only the ports you need. At minimum, keep your SSH port open (TCP/22 by default) so you do not lock yourself out, plus HTTP and HTTPS for web traffic:

```shell
sudo nft flush ruleset
sudo nft add table inet filter
sudo nft 'add chain inet filter input { type filter hook input priority 0; policy drop; }'
sudo nft 'add chain inet filter forward { type filter hook forward priority 0; policy drop; }'
sudo nft 'add chain inet filter output { type filter hook output priority 0; policy accept; }'
sudo nft add rule inet filter input ct state established,related accept
sudo nft add rule inet filter input iif lo accept
sudo nft add rule inet filter input tcp dport '{ 22, 80, 443 }' ct state new accept
sudo nft add rule inet filter input ip protocol icmp accept
sudo nft add rule inet filter input ip6 nexthdr ipv6-icmp accept
sudo sh -c 'nft list ruleset > /etc/nftables.conf'
sudo systemctl enable --now nftables
sudo systemctl restart fail2ban
```

If your SSH daemon listens on a non-standard port, replace ``22`` with your actual SSH port before applying the rules. If you later edit ``/etc/nftables.conf`` manually, reload the persisted rules and restart Fail2Ban so its dynamic ban rules are recreated on top of the base firewall:

```shell
sudo nft -f /etc/nftables.conf
sudo systemctl restart fail2ban
```

LetsEncrypt verification does not need an extra public firewall port. Conductor runs Certbot's HTTP-01 standalone listener on local port ``8998`` and Nginx proxies public ``/.well-known/acme-challenge/`` requests from normal HTTP port ``80`` to ``127.0.0.1:8998``. Keep TCP/80 open publicly for certificate issue/renewal, but do not expose TCP/8998.

### Optional Nginx GeoIP country blocking

The Debian installers include the Nginx GeoIP2 module and creates ``/var/conductor/geoip``. To download or refresh the free DB-IP country database, run:

```shell
sudo conductor geoipdb --update
```

This writes ``/var/conductor/geoip/dbip-country-lite.mmdb``. The common Nginx configuration includes a commented shared ``geoip2`` lookup, and each vhost template includes a commented block showing how to drop requests from selected countries for that specific vhost. Uncomment the shared lookup, uncomment the per-vhost block, add the ISO country codes you want to block, then run ``sudo nginx -t`` and reload Nginx.

The bundled updater uses DB-IP's Lite country database, which requires attribution to DB-IP.com when used in an application.

### Optional CrowdSec protection

Conductor also includes an optional post-install script for [CrowdSec](https://www.crowdsec.net/). It is not run by the main installer, so you can add it after Conductor is installed, if you wish by running:

```shell
sudo bash /etc/conductor/utils/install_crowdsec.sh
```

CrowdSec's firewall bouncer uses nftables. If nftables is not already installed, run the optional Fail2Ban+nftables installer first:

```shell
sudo bash /etc/conductor/utils/install_fail2ban_nftables.sh
```

The script installs CrowdSec, installs a firewall bouncer, adds common http collections, and configures CrowdSec to monitor Conductor's optional lean security logs:

```shell
/tmp/conductor_*.seclog
```

This is the same optional log file path used by Conductor's Fail2Ban support, so you do not need CrowdSec to read the normal disk-based Nginx access logs. Uncomment the ``access_log /tmp/conductor_{appname}.seclog conductor_security;`` line in each vhost you want CrowdSec and/or Fail2Ban to monitor, then test and reload Nginx.

```shell
sudo bash /etc/conductor/utils/install_crowdsec.sh --bouncer=nftables
```

If ``crowdsec`` or a CrowdSec firewall bouncer package is not already available from apt, the script will add the official CrowdSec package repository. The installer accepts either the CrowdSec nftables-specific bouncer package or Debian's generic ``crowdsec-firewall-bouncer`` package when the distro provides that instead. Use ``--skip-repo-bootstrap`` if you prefer to add and audit package repositories manually.

CrowdSec and Fail2Ban can be used together. If CrowdSec is installed, CrowdSec will handle the automatic bans instead of Fail2Ban. The CrowdSec installer will offer to disable Conductor's automatic Fail2Ban jails and keep only the ``conductor-manual`` jail enabled for ``conductor ban`` and ``conductor unban`` commands.

When CrowdSec is installed, ``conductor ban list`` also shows a summary count of local CrowdSec IP bans at the bottom. It does not print all global/community CrowdSec decisions.

You can however also manually remove a local Crowdsec decision (auto-ban) by using the ``conductor unban {ipaddress}`` command, this will first attempt to remove the IP address from the Fail2Ban manual bans but if not found, will then fall back and attempt to remove it from Crowdsec's local (ban) decission. Global/community bans will remain in-place however!

#### ```conductor destroy {app name}```

Will remove the application from the server, removes the Nginx configuration for this application and will also drop the MySQL database and MySQL user (if present), this command basically removes the named application and immediately stops serving the content.

#### ```conductor showkey {app name}```

Prints the application's SSH deployment public key again, so it can be copied into GitHub or another deployment platform after the original application setup output has gone.

#### ```conductor enable {app name}``` / ```conductor disable {app name}```

Enables or disables an application's Nginx virtual host by renaming its configuration between ``{app name}.conf`` and ``{app name}.disabled``. Conductor will test the Nginx configuration and ask whether to reload Nginx so the change takes effect.

Add ``--auto-reload`` to gracefully reload Nginx automatically after the configuration test passes.

The ``conductor list`` command shows the current virtual host status: ``[/]`` for enabled, ``[x]`` for disabled, and ``[?]`` if no matching virtual host configuration was found.

#### ```conductor auth {app name}```

Enables or disables HTTP Basic authentication for an application's Nginx virtual host.

```shell
# Create (or update/reset password) for a user account.
sudo conductor auth {app name} set {username} {password}
# Deletes (removes) a user account.
sudo conductor auth {app name} delete {username}
# Enable the auth_basic support for this application.
sudo conductor auth {app name} --enable
# Disable the auth_basic support for this application.
sudo conductor auth {app name} --disable
```

Add ``--auto-reload`` to ``--enable`` or ``--disable`` to gracefully reload Nginx automatically after the configuration test passes. Without it, Conductor asks whether to reload (to apply the changes) and defaults to yes.

#### ```conductor waf {app name}```

Opens the application's WAF configuration file at ``/etc/conductor/wafs/{app name}.conf``. This file is included inside the application's Nginx virtual host and can contain per-application WAF, access-control, and file-protection rules. ``--enable`` enables both the WAF include and the ``conductor_security`` ``.seclog`` access log used by Fail2Ban/CrowdSec. ``--disable`` disables both the WAF include and the security log.

New WAF files include ``/etc/conductor/configs/common/xcaler_community_search_engines.conf``, ``/etc/conductor/configs/common/xcaler_community_ai_bots.conf``, ``/etc/conductor/configs/common/xcaler_community_sql_injection.conf``, ``/etc/conductor/configs/common/xcaler_community_path_traversal.conf``, and ``/etc/conductor/configs/common/xcaler_community_common_paths.conf`` by default. These shared snippets block common search-engine crawlers, common AI search/training/user-agent bots, common SQL injection probes, common path traversal/local file inclusion probes in paths or query strings, and common sensitive files/directories such as ``wp-config.php``, readme/license files, backup/config files, ``node_modules/``, ``.git/``, and ``.env`` files. Matching requests return ``406 Not Acceptable`` and render the shared WAF rejection page from ``/var/conductor/error-pages/406.html``. Remove or comment an include in the per-application WAF file if that application should allow the matching traffic.

Run ``sudo conductor waf rulesets --update-community`` to download the latest Xcaler community lists from ``https://lists.xcaler.com/`` into ``/etc/conductor/configs/common/xcaler_community_{type}.conf``. The command prints ``updated!`` or ``failed!`` for each ruleset file, validates the Nginx configuration, reverts the downloaded rulesets if validation fails, and gracefully reloads Nginx when validation succeeds.

```shell
sudo conductor waf {app name}
sudo conductor waf {app name} --enable
sudo conductor waf {app name} --disable
```

The command tests Nginx after edits or enable/disable changes. Add ``--auto-reload`` to ``--enable`` or ``--disable`` to gracefully reload Nginx automatically after the configuration test passes. Without it, Conductor asks whether to reload and defaults to yes.

#### ```conductor dump {app name}``` and ```conductor load {app name}```

Writes an application's active virtual host configuration to STDOUT, or replaces it from STDIN. Use ``--waf`` to target the application's WAF-like configuration file instead.

```shell
sudo conductor dump {app name} > /tmp/{random}.tmp
sudo conductor load {app name} < /tmp/{random}.tmp

sudo conductor dump {app name} --waf > /tmp/{random}.tmp
sudo conductor load {app name} --waf < /tmp/{random}.tmp
```

``load`` writes the new content, runs ``nginx -t``, restores the previous file if the test fails, and gracefully reloads Nginx automatically after a successful test. This is intended for web applications or automation that need to read, edit, and write Conductor-managed configuration through the CLI without opening an interactive editor.

#### ```conductor update {app name}```
The upgrade command does three things, firstly it gives you the option of putting your application into 'offline mode' of which is up to you (you're prompted for your decision here), before it upgrades anything an automatic 'snapshot' is taken and stored separately to enable you to 'roll-back' later if required.. So next if Conductor finds that the application was previously deployed by Git or has a ```.git``` directory it will use the application's SSH deployment key to run a ```git fetch --all``` and then a ```git reset --hard @{u}``` to pull in the latest changes from the currently checked-out branch's configured upstream. If no git directory is found, Conductor assumes you're doing a 'manual upgrade' and prompts you at this point to upload the new files into your application's root directory... once this is complete you should confirm that the files have all been uploaded... Next Conductor will now execute any database migrations and then clear the application cache as well as dump the autoloader and finally (if you choose to 'take the application offline' during the upgrade process) it will now be automatically put back on-line!

#### ```conductor rollback {app name}```
This is basically the opposite of ```conductor upgrade {app name}```, this uses that last snapshot that was automatically taken the last time that you preformed an ```conductor upgrade {app name}``` on your application.

You will be prompted to confirm that you wish to revert to the last database snapshot, it is recommended in most situations that you do this, if however you choose 'no' you will be given the option to run Laravel's ``migrate:rollback`` function instead!

#### ```conductor backup {app name}```

This command provides an extremely easy way to backup both your application files, dependencies, access and error logs including your MySQL database (if you're using one for your application) have them compressed, timestamped and then stored under /var/conductor/backups for you to either 'restore' from in future or to manually SSH/FTP them to a remote machine or even set-up an automated process to Rsync them out to a remote backup server etc... the choices are endless!

*If you wish to automate backups of ALL applications on the server please check out the 'Automated backups' section below.*

#### ```conductor restore {app name}```

Yep, you got it... it's the reverse of ```conductor backup {app name}``` but just to be clear, this command will prompt for a backup file to be used before then deleting the contents of your application's hosting and logs directory and then restoring the application files and logs from the archive and will lastly drop and restore your MySQL database too (again, if your application uses one!)

#### ```conductor start {app name}```

This command enables you to start serving your Laravel 4.x application, this invokes the 'php artistan start' command. You only need to run this if you've recently 'stopped' your application as all newly provisioned applications will be in the 'started' state by default.

#### ```conductor stop {app name}```

This command enables you to stop serving your Laravel 4.x application, this invokes the 'php artistan stop' command.

#### ```conductor services start```

A very simple and quick method to start ALL dependent/bundled Conductor managed daemons  in the recommended order.

#### ```conductor services stop```

A very simple and quick method to stop ALL dependent/bundled Conductor managed daemons in the recommended order.

#### ```conductor services status```

A very simple and quick method to display the current status of ALL dependent/bundled Conductor managed daemons.

#### ```conductor services restart```

A very simple and quick method to restart ALL dependent/bundled Conductor managed daemons in the recommended order. This performs a service restart; use ``reload`` instead when you only need to apply Nginx configuration or certificate changes gracefully.

#### ```conductor services reload```

When manually changing configuration of one or more of the dependent/bundled daemons this command will attempt to safely reload the configuration without dropping existing connections. For Nginx this gracefully applies new virtual host config, stream config, SSL certificate paths, and renewed SSL certificate contents as long as the configuration test passes. Existing Nginx workers continue serving current connections while new workers start with the updated configuration. If the new Nginx configuration is invalid, the reload should fail and the existing workers should continue serving the previous configuration.

#### ```conductor test```

Tests the active Nginx configuration. The command returns ``0`` when the configuration is valid and ``1`` when Nginx reports an error. On success it prints ``Nginx configuration test successful!``; on failure it prints Nginx's original error output so the exact file, line, and issue are visible.

By default, ``auto-reload-nginx`` is set to ``true`` in ``/etc/conductor.conf``, so Conductor gracefully reloads Nginx automatically after successful configuration tests instead of asking each time. Set it to ``false`` if you prefer the interactive reload prompt. The ``--auto-reload`` flag still forces an automatic reload for commands that support it.

#### ```conductor --version```

Displays the current version of the Conductor application that you are currently running on your server, this should help determine whether you are running the latest version by comparing with our latest release's page on our website.

CLI options for non-interactive operation
-----------------------------------------
In the default "interactive" mode, Conductor prompts for various options and questions, if however you wish to script this for unattended (non-interactive mode) etc. since v3, you can now use the [CLI Options document](CLI-OPTIONS.md) document to see the available CLI options.

Generating and updating LetsEncrypt Certificates
------------------------------------------------
You can generate and update LetsEncrypt certificates by running the following command:

```shell
sudo conductor letsencrypt {appname}
```

Once the SSL certificate has been generated, Conductor will ask if you want to enable the SSL virtual host configuration automatically. The default answer is yes.

If you already have a LetsEncrypt certificate and only want to enable the SSL virtual host blocks, without requesting a certificate, run ``sudo conductor letsencrypt {appname} --enable``.

If you want to disable the SSL virtual host blocks and restore the default HTTP block, without deleting a certificate, run ``sudo conductor letsencrypt {appname} --disable``.

Add ``--auto-reload`` to ``--enable``, ``--disable``, or certificate creation commands to gracefully reload Nginx automatically after the configuration test passes.

Your SSL certificates will automatically be renewed as required.

If you want to remove an SSL certificate from your server you should use ``sudo conductor letsencrypt {appname} --delete``. This will also reset the virtual host back to the default HTTP block.

If you wish to force a renewal of the SSL certificate you can use ``sudo conductor letsencrypt {appname} --force-renew``.

Automating application backups
------------------------------
An automation script specifically designed to be used with CRON jobs etc. can be found in the ``utils/`` directory, this shell script will automatically backup all applications on the server and will also remove older backups (as configured in the script), the default backup retention is 7 days!

To configure this task, place the ```utils/scheduled_backups.sh``` script in a directory of your choice on the server (or used directly from the default installation path!) and then set-up a CRON task as follows:-

```shell
0 0 * * * /etc/conductor/utils/scheduled_backups.sh
```

The above example will execute the task daily at midnight, the default configuration will ensure that backups older than 7 days are also deleted (to ensure that your disks don't fill up!) this setting is configurable by editing the ```DAYS``` constant inside the script.

You may wish to then have a remote server 'pull' and 'archive' these backups of which will be located in ``/var/conductor/backups/``.

Automating composer updates
---------------------------
As conductor is designed to be a 'set and forget' system, we've now implemented an script that you can add as a CRON job (to get rid of those nasty '30 days out of date' errors), by adding this script to the CRONtab you can be sure that Composer is automatically updated on the first day of every month at 03:00.

```shell
0 3 1 * * /etc/conductor/utils/update_composer.sh
```

Automating GeoIP database updates
---------------------------------
It is recommended to automate the GeoIP database updates (expecially if you've kept the feature enabled)

We recommend adding this line to the crontab so that updates are applied monthly (first day of the month at 00:14):

```shell
14 0 1 * * /usr/bin/conductor geoipdb --update
```

Automating Xcaler community WAF ruleset updates
-----------------------------------------------
If you use the bundled Xcaler community WAF rulesets, we recommend updating them regularly so new common probes and bot patterns are picked up automatically.

For example, add this line to the crontab to update the community rulesets once a week, every Sunday at 02:30:

```shell
30 2 * * 0 /usr/bin/conductor waf rulesets --update-community
```

The command validates the Nginx configuration after downloading the rulesets, reverts automatically if validation fails, and gracefully reloads Nginx when the updated rulesets pass.

The use of different PHP versions
---------------------------

PHP 8.5 is always installed and is the default runtime required by Conductor. The Debian installers can optionally install additional PHP versions for hosted applications.

If however you need to set a specific application or site to use another installed PHP version you can edit the virtual host configuration in ``/etc/conductor/configs/{sitename}.conf`` and change the socket that PHP-FPM is running on, for example you should change:

```shell
fastcgi_pass                    unix:/var/run/php/php8.5-fpm.sock;
```

to...

```shell
fastcgi_pass                    unix:/var/run/php/php7.4-fpm.sock;
```

alternatively, if you want to use PHP 8.0 you can ofcourse use:

```shell
fastcgi_pass                    unix:/var/run/php/php8.0-fpm.sock;
```

You should then reload nginx by running ``sudo service nginx reload`` or by using the ``sudo conductor services reload`` command.

If you have configured the Laravel Scheduler or any other framework specific cron jobs, you should update your CRON job lines too, for example:

```shell
* * * * * cd /var/conductor/application/{appname} && php artisan schedule:run >> /dev/null 2>&1
```

Should be changed to...

```shell
* * * * * cd /var/conductor/application/{appname} && php7.4 artisan schedule:run >> /dev/null 2>&1
```

**Notice the replacement of the ``php`` binary with the ``php7.4`` specific binary! If you fail to do this, your scheduled tasks will run using default PHP 8.5 runtime!

If you want your server to use PHP 7.4 by default, you can update the default socket path that will be used when provisioning new virtual host configuration, to do this you should edit the main Conductor configuration settings file here: ``/etc/conductor.conf``, change this line:

```text
        "fpmsocket": "/var/run/php/php8.5-fpm.sock",
```

to...

```text
        "fpmsocket": "/var/run/php/php7.4-fpm.sock",
```

The next time you provision an application/site, it will instead use this default instead.

Help and support
----------------
You can drop me an email at [ballen@bobbyallen.me](mailto:ballen@bobbyallen.me) I'll do my best to respond ASAP!
