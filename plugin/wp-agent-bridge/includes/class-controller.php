<?php
declare( strict_types = 1 );

namespace AgentBridge;

use WP_Error;
use WP_REST_Request;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shared plumbing for the three controllers.
 */
abstract class Controller {

	abstract public function register_routes(): void;

	/** @param array<string,mixed> $args */
	protected function route( string $path, string $methods, callable $callback, array $args = [], bool $mutating = false ): void {
		register_rest_route(
			REST_NS,
			$path,
			[
				'methods'             => $methods,
				'callback'            => $mutating ? $this->guard_write( $callback ) : $callback,
				'permission_callback' => [ Auth::class, 'check' ],
				'args'                => $args,
			]
		);
	}

	/**
	 * AGENT_BRIDGE_READONLY is checked here rather than in each handler, so a
	 * route added later cannot forget about it.
	 */
	private function guard_write( callable $callback ): callable {
		return static function ( WP_REST_Request $request ) use ( $callback ) {
			if ( is_readonly() ) {
				return new WP_Error(
					'agent_bridge_readonly',
					__( 'AGENT_BRIDGE_READONLY is defined in wp-config.php; the bridge will not write.', 'agent-bridge' ),
					[ 'status' => 403 ]
				);
			}

			return $callback( $request );
		};
	}

	protected function sha( string $contents ): string {
		return hash( 'sha256', $contents );
	}

	protected function max_bytes(): int {
		$configured = (int) get_option( OPT_MAXBYTES, 2 * 1024 * 1024 );
		return $configured > 0 ? $configured : 2 * 1024 * 1024;
	}

	/**
	 * A file the bridge should hand back as text. Source is text by definition;
	 * anything with a null byte in it is something the agent cannot usefully
	 * edit and should not be sent as a JSON string.
	 */
	protected function looks_binary( string $contents ): bool {
		return str_contains( substr( $contents, 0, 8000 ), "\0" );
	}
}
