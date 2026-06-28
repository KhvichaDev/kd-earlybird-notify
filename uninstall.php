<?php
/**
 * Fired when the plugin is uninstalled.
 * Cleans up all custom database tables and options if the safeguard option is enabled.
 *
 * @package khvichadev-waitlist-notify
 */

// If uninstall not called from WordPress, exit.
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Only proceed with database cleanup if the administrator enabled this safeguard option
$delete_data = (bool) get_option('kdwn_delete_data_on_uninstall', false);

if ($delete_data) {
    global $wpdb;

    // 1. Delete custom subscribers database table
    $wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}kdwn_subscribers" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

    // 2. Clean up all options from wp_options table starting with kdwn_
    $wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'kdwn_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
}

