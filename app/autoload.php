<?php
namespace {
    /*
     * Whether this is the live site is a property of the host, not of the
     * source, so it is not a constant anybody edits and then has to remember
     * not to commit. A deployment marks itself live by creating app/live.flag;
     * without it every other copy -- a fork, a laptop, a staging box -- stays
     * in test mode, which is the safe default: Stripe test keys, errors on
     * screen. The flag is git-ignored and excluded from the deploy rsync, so
     * pushing an update can never flip a box back to test, and cloning the
     * repository can never make somebody's laptop take real payments.
     */
    define('__LIVE__', file_exists(__DIR__ . DIRECTORY_SEPARATOR . 'live.flag'));
    define('__STAGING__', false);
    if(__LIVE__ === false) {
        ini_set('display_errors', 1);
        ini_set('display_startup_errors', 1);
        error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE & ~E_WARNING);
    }
    else {
        error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE & ~E_WARNING);
        ini_set('display_errors', 0);
        ini_set('display_startup_errors', 0);
    }
    define('__ROOTS__', __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR);
    define('__TEMPLATE_FILE__', __ROOTS__ . 'app' . DIRECTORY_SEPARATOR . 'app.htaccess');
    define('__CONFIG_FILE__', __ROOTS__ . 'app' . DIRECTORY_SEPARATOR . 'app.json');
    define('__ROUTER_DIR__', __ROOTS__ . 'app' . DIRECTORY_SEPARATOR . 'routes' . DIRECTORY_SEPARATOR);
    define('__HTML_DIR__', __ROOTS__ . 'ui' . DIRECTORY_SEPARATOR . 'html' . DIRECTORY_SEPARATOR);
    define('__LOGGER_DIR__', __ROOTS__ . 'app' . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR);
    define('__LOGGER_FILE__', 'sys.log');
    mb_language('uni');
    mb_regex_encoding('UTF-8');
    setlocale(LC_ALL, 'en_US.UTF-8');
    date_default_timezone_set('UTC');
}
namespace RSCD {
    require __ROOTS__ . 'vendor' . DIRECTORY_SEPARATOR  . 'autoload.php';
    use \RSCD\Model\App;

    function cron($argv) {
        if(empty($argv)) {
            return;
        }
        $argLen = strlen($argv[1]);
        $cmd = '--cron=';
        $cmdLen = strlen($cmd);
        if($argLen > $cmdLen && substr($argv[1], 0, $cmdLen) == $cmd) {
            $class = substr($argv[1], $cmdLen);
            $file = __ROOTS__ . 'src' . DIRECTORY_SEPARATOR . 'RSCD'
                    . DIRECTORY_SEPARATOR . 'Cron'
                    . DIRECTORY_SEPARATOR . $class . '.php';
            if(file_exists($file)) {
                require $file;
                $app = new App('\\RSCD\\Cron\\' . $class);
                $app->cron($argv);
                $app->stop();
            }
            else {
                print $file . ' does not exist' . PHP_EOL;
            }
            exit;
        }
    }

    if(isset($argv) && count($argv) > 1) {
        cron($argv);
    }

    $app = new App();
    $app->run();
}
