<?php
/**
 * Plugin Name: Enle Daily Cron: Idea-to-Market Follow-ups
 * Description: Once a day, sends each Idea Validation lead the next email in Enle's Idea-to-Market sequence (Day 3, 7, 14), written by Claude from the lead's own Validation Report. Activate on enle.org only.
 * Version:     1.0.3
 * Requires at least: 6.5
 * Requires PHP: 8.1
 * Author:      Enle
 * License:     GPL-2.0-or-later
 * Text Domain: enle-daily-cron
 */

defined('ABSPATH') || exit;

define('ENLE_DC_VERSION', '1.0.3');
define('ENLE_DC_FILE', __FILE__);
define('ENLE_DC_DIR', plugin_dir_path(__FILE__));

require_once ENLE_DC_DIR . 'src/Autoloader.php';
\Enle\DailyCron\Autoloader::register(ENLE_DC_DIR . 'src/');

register_activation_hook(__FILE__, array(\Enle\DailyCron\Plugin::class, 'activate'));
register_deactivation_hook(__FILE__, array(\Enle\DailyCron\Plugin::class, 'deactivate'));

\Enle\DailyCron\Plugin::boot();
