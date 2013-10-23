<?php

// Creates Nginx configuration file and reloads the Nginx configuration for changes to take affect.
Event::listen('applicaiton.provision', 'Conductor\Handlers\ApplicationHandler@provisionApplication');
Event::listen('applicaiton.create', 'Conductor\Handlers\ApplicationHandler@saveApplication');

// Deploys application from a Git repository.
Event::listen('git.deploy', 'Conductor\Handlers\GitDeployHandler');

// Provisions a MySQL database, user account and then tightens security.
Event::listen('mysql.provision', 'Conductor\Handlers\MySQLProvisionHandler');


