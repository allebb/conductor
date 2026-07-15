#!/usr/bin/env bash

################################################################################
# Conductor Install Bootstrapper                                               #
# Written by Bobby Allen <ballen@bobbyallen.me>, 22/07/2014                    #
################################################################################

# Check to see if there are is a "BRANCH_INSTALL" environment variable set.
if [[ -z "${BRANCH_INSTALL}" ]]; then
  export BRANCH_INSTALL="stable"
else
  export BRANCH_INSTALL="${BRANCH_INSTALL}"
fi

INSTALLER_ARGS=()
for arg in "$@"; do
  case "$arg" in
    --proxy-only)
      INSTALLER_ARGS+=("$arg")
      ;;
    -h|--help)
      echo "Usage: bash install.sh [--proxy-only]"
      echo ""
      echo "Options:"
      echo "  --proxy-only    Install only Nginx and required PHP 8.5, skipping optional local services."
      exit 0
      ;;
    *)
      echo "Unknown option: $arg"
      echo "Usage: bash install.sh [--proxy-only]"
      exit 1
      ;;
  esac
done

# The installation script repository
SITE="https://raw.github.com/allebb/conductor/${BRANCH_INSTALL}/scripts"

# Detect what version of OS they are using and then send them off to the correct install script!
DISTRO=$(lsb_release -si| tr '[:upper:]' '[:lower:]')
VER=$(lsb_release -sr)

if [ "$DISTRO" != "debian" ]; then
  echo "Unsupported operating system: $DISTRO $VER"
  echo "Conductor now only supports Debian 12 and Debian 13."
  exit 1
fi

case "$VER" in
  12|13)
    ;;
  *)
    echo "Unsupported Debian version: $VER"
    echo "Conductor now only supports Debian 12 and Debian 13."
    exit 1
    ;;
esac

INSTALLER='install_'$DISTRO'_'$VER'.sh'

# Check and remove any installers that already exist on the local machine.
if [ -e /tmp/$INSTALLER ]
then
  rm /tmp/$INSTALLER -f
fi
sudo curl -fsSL $SITE'/'$INSTALLER -o /tmp/$INSTALLER
sudo chmod +x /tmp/$INSTALLER
sudo bash /tmp/$INSTALLER "${INSTALLER_ARGS[@]}"
