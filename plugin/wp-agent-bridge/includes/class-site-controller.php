<?php
declare( strict_types = 1 );

namespace AgentBridge;

use WP_REST_Request;
use WP_REST_Response;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * What the site is, what the bridge is allowed to do on it, and what has gone
 * wrong lately.
 */
final class SiteController extends Controller {

	public function register_routes(): void {
		$this->route( '/status', 'GET', [ $this, 'status' ] );
		$this->route( '/logs', 'GET', [ $this, 'logs' ] );
		$this->route( '/audit', 'GET', [ $this, 'audit' ] );
		$this->route( '/backups', 'GET', [ $this, 'backups' ] );
	}

	public function status(): WP_REST_Response {
		global $wp_version;

		$log = self::debug_log_path();

		return new WP_REST_Response(
			[
				'bridge' => [
					'version'      => VERSION,
					'write_enabled'=> ! is_readonly(),
					'readonly'     => is_readonly(),
					'lint_method'  => Lint::method(),
					'max_bytes'    => $this->max_bytes(),
					'managed'      => Paths::managed(),
					'audit_ready'  => Audit::table_exists(),
				],
				'site' => [
					'name'         => get_bloginfo( 'name' ),
					'home_url'     => home_url(),
					'site_url'     => site_url(),
					'wp_version'   => $wp_version,
					'php_version'  => PHP_VERSION,
					'is_multisite' => is_multisite(),
					'environment'  => wp_get_environment_type(),
					'locale'       => get_locale(),
					'theme'        => wp_get_theme()->get( 'Name' ),
					'plugin_dir'   => Paths::plugin_root(),
				],
				'debug' => [
					'wp_debug'     => defined( 'WP_DEBUG' ) && WP_DEBUG,
					'wp_debug_log' => defined( 'WP_DEBUG_LOG' ) ? WP_DEBUG_LOG : false,
					'log_path'     => $log,
					'log_bytes'    => $log && is_readable( $log ) ? filesize( $log ) : 0,
				],
			],
			200
		);
	}

	/**
	 * The tail of debug.log. Reading from the end rather than loading the file:
	 * a debug log on a busy site with a noisy notice can be hundreds of MB, and
	 * file() on that is an out-of-memory fatal.
	 */
	public function logs( WP_REST_Request $request ): WP_REST_Response {
		$lines = (int) ( $request->get_param( 'lines' ) ?: 200 );
		$lines = max( 1, min( 2000, $lines ) );
		$path  = self::debug_log_path();

		if ( ! $path || ! is_readable( $path ) ) {
			return new WP_REST_Response(
				[
					'available' => false,
					'reason'    => __( 'No readable debug.log. Set WP_DEBUG and WP_DEBUG_LOG in wp-config.php.', 'agent-bridge' ),
					'lines'     => [],
				],
				200
			);
		}

		return new WP_REST_Response(
			[
				'available' => true,
				'path'      => $path,
				'bytes'     => filesize( $path ),
				'lines'     => self::tail( $path, $lines ),
			],
			200
		);
	}

	public function audit( WP_REST_Request $request ): WP_REST_Response {
		$limit  = (int) ( $request->get_param( 'limit' ) ?: 50 );
		$action = $request->get_param( 'action_filter' );

		return new WP_REST_Response(
			[ 'entries' => Audit::recent( $limit, $action ? (string) $action : null ) ],
			200
		);
	}

	public function backups( WP_REST_Request $request ): WP_REST_Response {
		$slug  = $request->get_param( 'plugin' );
		$limit = (int) ( $request->get_param( 'limit' ) ?: 100 );

		return new WP_REST_Response(
			[ 'backups' => Backup::listing( $slug ? (string) $slug : null, $limit ) ],
			200
		);
	}

	public static function debug_log_path(): ?string {
		if ( defined( 'WP_DEBUG_LOG' ) && is_string( WP_DEBUG_LOG ) && '' !== WP_DEBUG_LOG ) {
			return WP_DEBUG_LOG;
		}

		$default = rtrim( WP_CONTENT_DIR, '/' ) . '/debug.log';

		return file_exists( $default ) ? $default : null;
	}

	/** @return array<int,string> */
	private static function tail( string $path, int $lines ): array {
		$handle = fopen( $path, 'rb' );
		if ( ! $handle ) {
			return [];
		}

		$buffer    = '';
		$chunk     = 8192;
		$position  = filesize( $path );
		$found     = 0;

		while ( $position > 0 && $found <= $lines ) {
			$read     = (int) min( $chunk, $position );
			$position -= $read;
			fseek( $handle, $position );
			$data   = (string) fread( $handle, $read );
			$buffer = $data . $buffer;
			$found  = substr_count( $buffer, "\n" );
		}

		fclose( $handle );

		$all = explode( "\n", rtrim( $buffer, "\n" ) );

		return array_values( array_slice( $all, -$lines ) );
	}
}
