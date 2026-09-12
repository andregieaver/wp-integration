<?php
declare( strict_types = 1 );

namespace AgentBridge;

use WP_Error;
use WP_REST_Request;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Two factors, deliberately.
 *
 * An application password is a bearer credential that travels in every request
 * and ends up in shell history, CI logs and screenshots. On its own it would
 * make this plugin a one-secret remote code execution path. The second factor
 * is a header the bridge issues and the site never emails, so a leaked
 * application password alone opens nothing here.
 */
final class Auth {

	public const HEADER = 'X-Bridge-Secret';

	/** REST permission_callback for every route on the bridge. */
	public static function check( WP_REST_Request $request ): bool|WP_Error {
		$transport = self::check_transport();
		if ( is_wp_error( $transport ) ) {
			return $transport;
		}

		if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) {
			// 401 rather than 403 when nobody authenticated at all, so a client
			// can tell "your password is wrong" from "your user is not an admin".
			return new WP_Error(
				'agent_bridge_unauthorized',
				__( 'Authentication required: use an application password belonging to an administrator.', 'agent-bridge' ),
				[ 'status' => is_user_logged_in() ? 403 : 401 ]
			);
		}

		$stored = (string) get_option( OPT_SECRET, '' );
		if ( '' === $stored ) {
			return new WP_Error(
				'agent_bridge_no_secret',
				__( 'No bridge secret has been generated yet. Generate one under Tools → Agent Bridge.', 'agent-bridge' ),
				[ 'status' => 503 ]
			);
		}

		$presented = (string) $request->get_header( self::HEADER );
		if ( '' === $presented || ! hash_equals( $stored, hash( 'sha256', $presented ) ) ) {
			Audit::log( 'auth.reject', null, [ 'result' => 'denied', 'message' => 'bad or missing bridge secret' ] );
			return new WP_Error(
				'agent_bridge_bad_secret',
				__( 'Missing or invalid X-Bridge-Secret header.', 'agent-bridge' ),
				[ 'status' => 403 ]
			);
		}

		$ip_check = self::check_ip();
		if ( is_wp_error( $ip_check ) ) {
			return $ip_check;
		}

		return true;
	}

	/**
	 * The secret is useless the moment it crosses the wire in clear text, so an
	 * unencrypted request is refused before the comparison rather than after.
	 * Proxy-terminated TLS is accepted because it is the common shape (Cloudflare,
	 * most managed hosts) and is_ssl() alone reports false there.
	 */
	private static function check_transport(): bool|WP_Error {
		if ( defined( 'AGENT_BRIDGE_ALLOW_INSECURE' ) && AGENT_BRIDGE_ALLOW_INSECURE ) {
			return true;
		}

		if ( is_ssl() ) {
			return true;
		}

		$forwarded = strtolower( (string) ( $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '' ) );
		if ( 'https' === $forwarded ) {
			return true;
		}

		if ( in_array( wp_get_environment_type(), [ 'local', 'development' ], true ) ) {
			return true;
		}

		return new WP_Error(
			'agent_bridge_insecure',
			__( 'The bridge refuses plain HTTP. Serve the site over TLS, or define AGENT_BRIDGE_ALLOW_INSECURE for a local environment.', 'agent-bridge' ),
			[ 'status' => 403 ]
		);
	}

	private static function check_ip(): bool|WP_Error {
		$allowed = (array) get_option( OPT_ALLOW_IPS, [] );
		$allowed = array_filter( array_map( 'trim', $allowed ) );

		if ( ! $allowed ) {
			return true; // Empty list means "no restriction", which is the default.
		}

		$ip = self::client_ip();
		if ( in_array( $ip, $allowed, true ) ) {
			return true;
		}

		Audit::log( 'auth.reject', null, [ 'result' => 'denied', 'message' => "ip {$ip} not on allowlist" ] );

		return new WP_Error(
			'agent_bridge_ip_denied',
			__( 'This IP address is not on the bridge allowlist.', 'agent-bridge' ),
			[ 'status' => 403 ]
		);
	}

	public static function client_ip(): string {
		// REMOTE_ADDR is the only value a client cannot set. The forwarded
		// headers are recorded for the audit trail but never trusted for the
		// allowlist decision, which is why this returns REMOTE_ADDR alone.
		$ip = (string) ( $_SERVER['REMOTE_ADDR'] ?? '' );
		return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '';
	}

	/** Issue a new secret, return the plaintext once, store only its hash. */
	public static function rotate_secret(): string {
		$secret = bin2hex( random_bytes( 32 ) );
		update_option( OPT_SECRET, hash( 'sha256', $secret ), false );
		Audit::log( 'auth.rotate', null, [ 'message' => 'bridge secret rotated' ] );
		return $secret;
	}

	public static function has_secret(): bool {
		return '' !== (string) get_option( OPT_SECRET, '' );
	}

	public static function revoke_secret(): void {
		delete_option( OPT_SECRET );
		Audit::log( 'auth.revoke', null, [ 'message' => 'bridge secret revoked' ] );
	}
}
