#!/bin/bash
# Run the site locally with PHP's built-in web server.
#
# Usage: ./run.sh [port]        (default port 8080)
#
# This is the development spelling. Production serves index.php through Apache
# with app/app.htaccess semantics -- see the Deployment section of the README.
#
# Preflight is deliberate and chatty: PHP 8.1+, Composer's vendor/ directory
# (installed for you if composer is on the PATH), and app/app.json (copied
# from the template for you, then you fill in the real values). Each missing
# piece says what it is and how to get it, because "500" teaches nobody
# anything.

cd "$(dirname "$0")" || exit 1
PORT="${1:-8080}"

if ! command -v php >/dev/null 2>&1; then
   echo "" >&2
   echo "The site needs PHP, and 'php' was not found on this machine." >&2
   echo "" >&2
   echo "  Ubuntu/Debian:  sudo apt install php-cli php-mysql php-mbstring php-xml php-curl" >&2
   echo "  Fedora/RHEL:    sudo dnf install php-cli php-mysqlnd php-mbstring php-xml" >&2
   echo "  macOS:          brew install php" >&2
   echo "  Windows:        https://windows.php.net/download/" >&2
   exit 1
fi

# 8.1 is the floor the code is written against (readonly properties, enums in
# dependencies). php -r prints 1 when the running PHP is at least that.
if [ "$(php -r 'echo (int) version_compare(PHP_VERSION, "8.1", ">=");')" != "1" ]; then
   echo "This PHP is $(php -r 'echo PHP_VERSION;'), but the site needs PHP 8.1 or later." >&2
   echo "Install a newer PHP (see the README's Run It section)." >&2
   exit 1
fi

if [ ! -d vendor ]; then
   if command -v composer >/dev/null 2>&1; then
      echo "vendor/ is missing -- running 'composer install' (first run only)..."
      composer install || exit 1
   else
      echo "" >&2
      echo "vendor/ is missing and Composer is not installed, so the PHP" >&2
      echo "dependencies cannot be fetched." >&2
      echo "" >&2
      echo "Install Composer from https://getcomposer.org/download/" >&2
      echo "(or 'sudo apt install composer'), then run this script again." >&2
      exit 1
   fi
fi

if [ ! -f app/app.json ]; then
   cp app/app.json.example app/app.json
   echo ""
   echo "There was no app/app.json, so the template has been copied into place."
   echo "The site will start, but until you edit app/app.json with your real"
   echo "database (and, if you want email, SMTP) settings, signing in and"
   echo "anything that touches the database will fail."
   echo ""
   echo "The database schema itself ships with the game server: rscd.sql in"
   echo "the rscd-server repository. Its install.sh imports it for you."
   echo ""
fi

echo "Serving on http://localhost:$PORT (Ctrl+C stops it)"
exec php -S "localhost:$PORT" index.php
