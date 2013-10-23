<?php

// Creates Nginx configuration file and reloads the Nginx configuration for changes to take affect.
Event::listen('application.provision', 'Conductor\Handlers\ApplicationHandler@provisionApplication');
Event::listen('application.create', 'Conductor\Handlers\ApplicationHandler@saveApplication');
Event::listen('application.destroy', 'Conductor\Handlers\ApplicationHandler@destroyApplication');

// Deploys application from a Git repository.
Event::listen('git.deploy', 'Conductor\Handlers\GitHandler@cloneRepository');
Event::listen('git.update', 'Conductor\Handlers\GitHandler@pullRepository');

// Provisions a MySQL database, user account and then tightens security.
Event::listen('mysql.provision', 'Conductor\Handlers\MySQLHandler@provisionDatabase');
Event::listen('mysql.destroy', 'Conductor\Handlers\MySQLHandler@destroyDatabase');


