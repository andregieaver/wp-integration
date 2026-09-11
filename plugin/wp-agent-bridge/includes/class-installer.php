<?php
declare( strict_types = 1 );

namespace AgentBridge;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Activation-time setup: the audit table, and the two random values the plugin
 * cannot function without.
 */
final class Installer {

	public static function activate(): void {
		self::create_audit_table();

		// The backup directory name carries a per-install random token. Backups
		// of PHP source sit under wp-content, which is web-readable on most
		// hosts; an unguessable path is what keeps the source from being
		// fetched by anyone who knows the plugin is installed.
		if ( ! get_option( OPT_BACKUPKEY ) ) {
			add_option( OPT_BACKUPKEY, bin2hex( random_bytes( 16 ) ), '', false );
		}

		add_option( OPT_MANAGED, [], '', false );
		add_option( OPT_ALLOW_IPS, [], '', false );
		add_option( OPT_MAXBYTES, 2 * 1024 * 1024, '', false );

		Backup::ensure_dir();
	}

	public static function deactivate(): void {
		// Nothing is torn down on deactivate — the audit trail and backups
		// outlive a toggle. uninstall.php is where removal happens.
	}

	public static function create_audit_table(): void {
		global $wpdb;

		$table   = $wpdb->prefix . 'agent_bridge_audit';
		$collate = $wpdb->get_charset_collate();

		$sql = "create table {$table} (
			id bigint(20) unsigned not null auto_increment,
			created_at datetime not null default current_timestamp,
			user_id bigint(20) unsigned not null default 0,
			user_login varchar(60) not null default '',
			ip varchar(45) not null default '',
			action varchar(40) not null default '',
			target text null,
			bytes bigint(20) not null default 0,
			sha_before char(64) null,
			sha_after char(64) null,
			result varchar(20) not null default 'ok',
			message text null,
			primary key (id),
			key created_at (created_at),
			key action (action)
		) {$collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'agent_bridge_audit';
	}
}
