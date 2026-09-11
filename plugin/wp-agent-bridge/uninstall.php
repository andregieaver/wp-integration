<?php
/**
 * Removal: the secret, the settings, the audit trail and every backup.
 *
 * Backups hold verbatim copies of plugin source, so leaving them behind after an
 * uninstall would quietly keep that source on disk under a path nobody is
 * looking at any more.
 */

declare( strict_types = 1 );

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

$agent_bridge_key = get_option( 'agent_bridge_backup_key' );

if ( is_string( $agent_bridge_key ) && '' !== $agent_bridge_key ) {
	$agent_bridge_root = rtrim( WP_CONTENT_DIR, '/' ) . '/agent-bridge-backups-' . $agent_bridge_key;

	if ( is_dir( $agent_bridge_root ) ) {
		$agent_bridge_items = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $agent_bridge_root, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ( $agent_bridge_items as $agent_bridge_item ) {
			if ( $agent_bridge_item->isDir() ) {
				rmdir( $agent_bridge_item->getPathname() );
			} else {
				unlink( $agent_bridge_item->getPathname() );
			}
		}

		rmdir( $agent_bridge_root );
	}
}

foreach (
	[
		'agent_bridge_secret_hash',
		'agent_bridge_managed_plugins',
		'agent_bridge_allowed_ips',
		'agent_bridge_backup_key',
		'agent_bridge_max_file_bytes',
		'agent_bridge_db_version',
	] as $agent_bridge_option
) {
	delete_option( $agent_bridge_option );
}

$wpdb->query( "drop table if exists {$wpdb->prefix}agent_bridge_audit" ); // phpcs:ignore WordPress.DB
