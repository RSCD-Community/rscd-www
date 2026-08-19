@echo off
rem Run the site locally with PHP's built-in web server.
rem
rem Usage: run.bat [port]        (default port 8080)
rem
rem The Windows twin of run.sh -- same checks, same messages. Production
rem serves index.php through Apache; this is for development.

setlocal
cd /d "%~dp0"

set "PORT=%~1"
if "%PORT%"=="" set "PORT=8080"

php -v >nul 2>&1
if errorlevel 1 (
   echo.
   echo The site needs PHP, and php was not found on this machine. 1>&2
   echo.
   echo   Download it from https://windows.php.net/download/ and add the 1>&2
   echo   folder containing php.exe to your PATH. 1>&2
   exit /b 1
)

php -r "exit(version_compare(PHP_VERSION, '8.1', '>=') ? 0 : 1);"
if errorlevel 1 (
   echo This PHP is too old -- the site needs PHP 8.1 or later. 1>&2
   echo Install a newer PHP from https://windows.php.net/download/ 1>&2
   exit /b 1
)

if not exist vendor (
   composer -V >nul 2>&1
   if errorlevel 1 (
      echo.
      echo vendor\ is missing and Composer is not installed, so the PHP 1>&2
      echo dependencies cannot be fetched. 1>&2
      echo.
      echo Install Composer from https://getcomposer.org/download/ then run 1>&2
      echo this script again. 1>&2
      exit /b 1
   )
   echo vendor\ is missing -- running "composer install" ^(first run only^)...
   call composer install
   if errorlevel 1 exit /b 1
)

if not exist app\app.json (
   copy app\app.json.example app\app.json >nul
   echo.
   echo There was no app\app.json, so the template has been copied into place.
   echo The site will start, but until you edit app\app.json with your real
   echo database settings, signing in and anything that touches the database
   echo will fail.
   echo.
   echo The database schema itself ships with the game server: rscd.sql in
   echo the rscd-server repository.
   echo.
)

echo Serving on http://localhost:%PORT% (Ctrl+C stops it)
php -S localhost:%PORT% index.php
