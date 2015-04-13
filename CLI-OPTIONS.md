# CLI Options

This document aims to provide the CLI options that enable unattended application management roles.

### Installing an application

In "interactive" mode, Conductor asks a series of questions when a new application is created, in order to automate this and allow for an unattended installation you can use the following commands:

``--fqdns`` - List of FQDN's that the application will respond on (if using multiples you must use `"` to encapsulate them).
``--environment`` - The 'APP_ENV' that the application will be hosted under (normally this should be ``--environment=production``).
``--mysql-password`` - If this is set, a MySQL database will be created with the specified password!
``--git-uri`` - This this is set, the application will be deployed at creation from a Git repository (You should use the Git protocol over HTTPS when using private repositories to ensure that the use of SSH keys will allow for unattended authentication.)
``--no-public`` - This will disable the standard '/public' site mapping thus allowing users to host Wordpress sites etc without having to place the site in a '/public' directory.

So for example, setting up a standard Laravel type project you would use:

```conductor new myapp --fqdns="myapp.com www.myappc.com" --environment=production --mysql-password=Password1234 --git-uri="https://github.com/bobsta63/pastie.git"```

If you intend on hosting a Wordpress application and wish to manually upload the code, you would use:

```conductor new myapp --fqdns="myapp.com www.myappc.com" --environment=production --mysql-password=Password1234 --no-public```

### Upgrading an applicaiton

In "interactive" mode, Conductor will prompt when the user requests an application upgrade if they wish to "take down" the application, if the user answers "Y" at the CLI prompt Conductor will attempt to run an ``artisan down`` and then ``artisan up`` after the upgrade this simply helps stop any data issues during the upgrade.

In order to stop the ``conductor upgrade X`` command from prompting for user input the user must ensure they use the ``--down=true`` option like so:

```conductor upgrade myapp --down=true```

Alternatively to keep the application running during the upgrade, you should use ``--down=false`` like so:

```conductor upgrade myapp --down=false```

