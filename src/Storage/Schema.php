<?php

namespace Enle\DailyCron\Storage;

defined('ABSPATH') || exit;

/**
 * The plugin's two tables, on the site it is activated on ($wpdb->prefix is
 * per-site on multisite). Created with dbDelta() on activation and whenever
 * the stored schema version differs (WordPress skips activation on updates).
 */
final class Schema
{
    public const VERSION = '1';

    private const OPTION = 'enle_dc_db_version';

    public static function leads(): string
    {
        global $wpdb;

        return $wpdb->prefix . 'enle_dc_leads';
    }

    public static function log(): string
    {
        global $wpdb;

        return $wpdb->prefix . 'enle_dc_log';
    }

    public static function maybeUpgrade(): void
    {
        if (get_option(self::OPTION) !== self::VERSION) {
            self::install();
        }
    }

    public static function install(): void
    {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset = $wpdb->get_charset_collate();

        // dbDelta() is picky: two spaces after PRIMARY KEY, one field per line.
        dbDelta(
            'CREATE TABLE ' . self::leads() . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  email varchar(191) NOT NULL,
  name varchar(191) NOT NULL DEFAULT '',
  crm_contact_id bigint(20) unsigned NOT NULL DEFAULT 0,
  report_title varchar(255) NOT NULL DEFAULT '',
  report_url varchar(500) NOT NULL DEFAULT '',
  report longtext NOT NULL,
  enrolled_at datetime NOT NULL,
  status varchar(20) NOT NULL DEFAULT 'active',
  stop_reason varchar(40) NOT NULL DEFAULT '',
  sent longtext NOT NULL,
  skipped varchar(255) NOT NULL DEFAULT '',
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY email (email),
  KEY status (status)
) {$charset};"
        );

        dbDelta(
            'CREATE TABLE ' . self::log() . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  lead_id bigint(20) unsigned NOT NULL DEFAULT 0,
  step varchar(20) NOT NULL DEFAULT '',
  action varchar(20) NOT NULL,
  detail text NOT NULL,
  subject varchar(255) NOT NULL DEFAULT '',
  body mediumtext NOT NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY lead_id (lead_id),
  KEY created_at (created_at)
) {$charset};"
        );

        update_option(self::OPTION, self::VERSION, true);
    }

    public static function uninstall(): void
    {
        global $wpdb;

        // Table names come from $wpdb->prefix only; nothing user-supplied.
        $wpdb->query('DROP TABLE IF EXISTS ' . self::log()); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $wpdb->query('DROP TABLE IF EXISTS ' . self::leads()); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        delete_option(self::OPTION);
    }
}
