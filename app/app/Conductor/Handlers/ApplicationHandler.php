<?php

class ApplicationHandler
{

    // Creates Nginx configuration file and reloads the Nginx configuration for changes to take affect.
    public function provisionApplication($data)
    {

    }

    // Save the application settings t othe database.
    public function saveApplication($data)
    {
        Event::fire('application.provision', $data);
    }

}
