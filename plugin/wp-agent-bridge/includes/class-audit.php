<?php
declare( strict_types = 1 );

namespace AgentBridge;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * What the bridge did, who asked, and from where.
 *
 * Recorded for every mutating call and every rejected authentication. A tool
 * that can rewrite plugin source has to be able to answer "what changed and when"
 * afterwards; without that, an incident is unreconstructable.
 */
final class Audit {

	/**
	 * @param array{result?:string,message?:string,bytes?:int,sha_before?:?string,sha_after?:?string} $extra
	 */
	public static function log( string $action, ?string $target = null, array $extra = [] ): void {
		global $wpdb;

		$user = wp_get_current_user();

		// Suppress errors: an audit write must never be the thing that turns a
		// successful file write into a 500 for the caller. A missing table is
		// surfaced on the settings page instead.
		$wpdb->hide_errors();
		$wpdb->insert(
			Installer::table(),
			[
				'created_at' => current_time( 'mysql', true ),
				'user_id'    => $user ? (int) $user->ID : 0,
				'user_login' => $user ? (string) $user->user_login : '',
				'ip'         => Auth::client_ip(),
				'action'     => substr( $action, 0, 40 ),
				'target'     => $target,
				'bytes'      => (int) ( $extra['bytes'] ?? 0 ),
				'sha_before' => $extra['sha_before'] ?? null,
				'sha_after'  => $extra['sha_after'] ?? null,
				'result'     => substr( (string) ( $extra['result'] ?? 'ok' ), 0, 20 ),
				'message'    => $extra['message'] ?? null,
			],
			[ '%s', '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s' ]
		);
		$wpdb->show_errors();
	}

	/** @return array<int,array<string,mixed>> */
	public static function recent( int $limit = 50, ?string $action = null ): array {
		global $wpdb;

		$table = Installer::table();
		$limit = max( 1, min( 500, $limit ) );

		if ( $action ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"select * from {$table} where action = %s order by id desc limit %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$action,
					$limit
				),
				ARRAY_A
			);
		} else {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"select * from {$table} order by id desc limit %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$limit
				),
				ARRAY_A
			);
		}

		return is_array( $rows ) ? $rows : [];
	}

	public static function table_exists(): bool {
		global $wpdb;
		$table = Installer::table();
		return (bool) $wpdb->get_var( $wpdb->prepare( 'show tables like %s', $table ) );
	}
}
