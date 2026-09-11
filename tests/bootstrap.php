<?php
/**
 * Enough of WordPress to exercise the parts of the plugin worth testing on
 * their own. Not a WordPress test suite — just the handful of functions Paths
 * and Lint reach for, so the security boundary can be checked without standing
 * up a site.
 */

declare( strict_types = 1 );

namespace {
	define( 'ABSPATH', __DIR__ . '/fixtures/wp/' );
	define( 'WP_PLUGIN_DIR', __DIR__ . '/fixtures/wp/wp-content/plugins' );

	class WP_Error {
		public function __construct(
			private string $code = '',
			private string $message = '',
			private array $data = []
		) {}

		public function get_error_code(): string {
			return $this->code;
		}

		public function get_error_message(): string {
			return $this->message;
		}

		public function get_error_data(): array {
			return $this->data;
		}
	}

	function is_wp_error( $thing ): bool {
		return $thing instanceof \WP_Error;
	}

	$GLOBALS['__options'] = [];

	function get_option( string $key, $default = false ) {
		return $GLOBALS['__options'][ $key ] ?? $default;
	}

	function update_option( string $key, $value, $autoload = null ): bool {
		$GLOBALS['__options'][ $key ] = $value;
		return true;
	}

	function plugin_basename( string $file ): string {
		return 'wp-agent-bridge/wp-agent-bridge.php';
	}

	function __( string $text, string $domain = '' ): string {
		return $text;
	}

	function wp_tempnam( string $prefix = '' ) {
		return tempnam( sys_get_temp_dir(), $prefix );
	}
}

namespace AgentBridge {
	const OPT_MANAGED   = 'agent_bridge_managed_plugins';
	const OPT_SECRET    = 'agent_bridge_secret_hash';
	const OPT_ALLOW_IPS = 'agent_bridge_allowed_ips';
	const OPT_BACKUPKEY = 'agent_bridge_backup_key';
	const OPT_MAXBYTES  = 'agent_bridge_max_file_bytes';

	define( 'AgentBridge\\FILE', \ABSPATH . 'wp-content/plugins/wp-agent-bridge/wp-agent-bridge.php' );

	require_once __DIR__ . '/../plugin/wp-agent-bridge/includes/class-paths.php';
	require_once __DIR__ . '/../plugin/wp-agent-bridge/includes/class-lint.php';
}
