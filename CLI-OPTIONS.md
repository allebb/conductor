# CLI Options

This document aims to provide the CLI options that enable unattended application management roles.

### Installing an application

In "interactive" mode, Conductor asks a series of questions when a new application is created, in order to automate this and allow for an unattended installation you can use the following commands:

* ``--fqdn`` - List of FQDN's (domains/sub-domains) that the application will respond on (if using multiples you must use double quotes encapsulate them).
* ``--environment`` - The 'APP_ENV' that the application will be hosted under (normally this should be ``--environment=production``).
* ``--mysql-pass`` - If this is set, a MySQL database will be created with the specified password!
* ``--git-uri`` - When this is set, the application will be deployed at creation from a Git repository (You should use the Git protocol over HTTPS when using private repositories to ensure that the use of SSH keys will allow for unattended authentication.)
* ``--path`` - This will enable you to over-ride the default '/public' site mapping thus allowing users to host Wordpress sites etc without having to place the site in a '/public' directory.
* ``--genkey`` - This will generate an SSH (deployment) key that can be used by this application.

So for example, setting up a standard Laravel type project you could use:

```shell
conductor new myapp --fqdn="mywebapp.com www.mywebapp.com" --environment="production" --mysql-pass="Password1234" --git-uri="https://github.com/bobsta63/pastie.git" --genkey
```

If you intended on hosting a Wordpress website instead and wish to manually upload the code, you would use:

```shell
conductor new myapp --fqdn="mywordpressblog.com www.mywordpressblog.com" --path="/" --mysql-pass="Password1234"
```

### Upgrading an application

In "interactive" mode, Conductor will prompt when the user requests an application upgrade if they wish to "take down" the application, if the user answers "Y" at the CLI prompt Conductor will attempt to run an ``artisan down`` and then ``artisan up`` after the upgrade this simply helps stop any data issues during the upgrade.

In order to stop the ``conductor upgrade X`` command from prompting for user input the user must ensure they use the ``--down=true`` option like so:

```shell
conductor upgrade myapp --down=true
```

Alternatively to keep the application running during the upgrade, you should use ``--down=false`` like so:

```shell
conductor upgrade myapp --down=false
```

