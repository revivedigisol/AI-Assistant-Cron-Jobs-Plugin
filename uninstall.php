<?php
/**
 * Removes everything the plugin stored on this site: its tables (leads and
 * send log), options and scheduled events. Runs only from WordPress's
 * "Delete" action on the Plugins screen.
 */

defined('WP_UNINSTALL_PLUGIN') || exit;

require_once __DIR__ . '/src/Autoloader.php';
\Enle\DailyCron\Autoloader::register(__DIR__ . '/src/');

\Enle\DailyCron\Cron\Scheduler::clear();
\Enle\DailyCron\Cron\Lock::clear();
\Enle\DailyCron\Storage\Schema::uninstall();

delete_option(\Enle\DailyCron\Settings::OPTION);
delete_option(\Enle\DailyCron\Cron\Runner::LAST_RUN_OPTION);
