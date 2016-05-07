# Installing on FreeBSD

The FreeBSD installation is currently in BETA and some additional steps are required when compared to the Ubuntu installer, to install on FreeBSD please following these steps:

```shell
# Download some required packages that aren't available on the base version of FreeBSD...
pkg install sudo bash wget openssl ca_root_nss

# Lets now run rehash to ensure that the CLI can see the new PATH roots...
rehash

# Now Symlink the CA root certificates (if your server doesn't already have them installed)
sudo ln -f -s /usr/local/share/certs/ca-root-nss.crt /etc/ssl/cert.pem
sudo ln -f -s /usr/local/share/certs/ca-root-nss.crt /usr/local/etc/cert.pem
sudo ln -f -s /usr/local/share/certs/ca-root-nss.crt /usr/local/openssl/cert.pem

# Now download and then execute the installer...
wget https://raw.github.com/bobsta63/conductor/master/scripts/install_freebsd_10.1.sh -O install.sh
sudo bash install.sh

# Edit /usr/local/etc/php-fpm.conf and uncomment this section:

;listen.owner = www
;listen.group = www
;listen.mode = 0660

# Now restart the PHP-FPM service...
service php-fpm restart
```

During the installation MySQL server will be installed and the ``root`` account will have a random password generated, to view this password, edit ``/etc/conductor.conf``. Conductor requires the root password in order to perform creation of user accounts and databases during it's operation.

Check that it's installed and working by entering the following command at the terminal!

```shell
conductor -h
```
