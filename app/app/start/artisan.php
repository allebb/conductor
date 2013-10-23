<?php

/*
|--------------------------------------------------------------------------
| Register The Artisan Commands
|--------------------------------------------------------------------------
|
| Each available Artisan command must be registered with the console so
| that it is available to be called. We'll register every command so
| the console gets access to each of the command object instances.
|
*/


Artisan::add(new Conductor\Commands\AppList);
Artisan::add(new Conductor\Commands\AppDeploy);
Artisan::add(new Conductor\Commands\AppDestroy);
Artisan::add(new Conductor\Commands\AppUpgrade);
Artisan::add(new Conductor\Commands\AppRollback);
Artisan::add(new Conductor\Commands\AppBackup);
Artisan::add(new Conductor\Commands\AppRestore);
Artisan::add(new Conductor\Commands\AppDepUpgrade);
