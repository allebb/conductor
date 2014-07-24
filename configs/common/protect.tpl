# We disable the ability to view the conductor_db.json file via the web server.
location = /conductor_db.json {
    deny all;
}