<?php
/**
 * PHPUnit bootstrap: runs the plugin's code without WordPress. WordPress is
 * replaced by the stand-ins in Support/wordpress.php; the database, Claude,
 * mail and the lock by the fakes in Support/Fakes.php.
 */

define('ABSPATH', __DIR__ . '/');
define('ENLE_DC_TESTING', true);

ini_set('error_log', sys_get_temp_dir() . '/enle-daily-cron-tests.log');

$plugin = dirname(__DIR__) . '/';

require $plugin . 'tools/vendor/autoload.php';
require __DIR__ . '/Support/wordpress.php';

require $plugin . 'src/Autoloader.php';
\Enle\DailyCron\Autoloader::register($plugin . 'src/');
