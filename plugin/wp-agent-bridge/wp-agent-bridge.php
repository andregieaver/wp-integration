<?php
/**
 * Plugin Name:       Agent Bridge
 * Plugin URI:        https://github.com/andregieaver/wp-agent-bridge
 * Description:       Exposes a guarded REST API so an AI coding agent can read and write the source of nominated plugins, tail the debug log and inspect site state.
 * Version:           0.1.0
 * Requires at least: 6.4
 * Requires PHP:      8.0
 * Author:            HumanWebX
 * License:           GPL-2.0-or-later
 * Text Domain:       agent-bridge
 *
 * Why this exists: plugin source cannot be reached over the core REST API, so an
 * agent working on a site has no way to see the code it is being asked to fix.
 * This plugin is that missing surface — deliberately narrow, confined to plugins
 * an admin has nominated, and auditing everything it does.
 */

declare( strict_types = 1 );

namespace AgentBridge;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const VERSION       = '0.1.0';
const REST_NS       = 'agent-bridge/v1';
const OPT_SECRET    = 'agent_bridge_secret_hash';
const OPT_MANAGED   = 'agent_bridge_managed_plugins';
const OPT_ALLOW_IPS = 'agent_bridge_allowed_ips';
const OPT_BACKUPKEY = 'agent_bridge_backup_key';
const OPT_MAXBYTES  = 'agent_bridge_max_file_bytes';

define( 'AgentBridge\\FILE', __FILE__ );
define( 'AgentBridge\\DIR', plugin_dir_path( __FILE__ ) );

/**
 * Two constants, both optional, both read from wp-config.php only.
 *
 * AGENT_BRIDGE_DISABLE  — the whole plugin goes dark; no routes are registered.
 * AGENT_BRIDGE_READONLY — reads keep working, every mutating route refuses.
 *
 * They live in wp-config.php rather than in options so that someone holding a
 * stolen application password cannot switch them off through the API they just
 * got into.
 */
function is_disabled(): bool {
	return defined( 'AGENT_BRIDGE_DISABLE' ) && AGENT_BRIDGE_DISABLE;
}

function is_readonly(): bool {
	return defined( 'AGENT_BRIDGE_READONLY' ) && AGENT_BRIDGE_READONLY;
}

spl_autoload_register(
	static function ( string $class ): void {
		if ( ! str_starts_with( $class, __NAMESPACE__ . '\\' ) ) {
			return;
		}

		$relative = substr( $class, strlen( __NAMESPACE__ ) + 1 );
		$parts    = explode( '\\', $relative );
		$name     = array_pop( $parts );
		$sub      = $parts ? strtolower( implode( '/', $parts ) ) . '/' : '';

		$file = DIR . 'includes/' . $sub . 'class-' . strtolower(
			preg_replace( '/(?<!^)[A-Z]/', '-$0', $name )
		) . '.php';

		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
);

require_once DIR . 'includes/class-installer.php';

register_activation_hook( __FILE__, [ Installer::class, 'activate' ] );
register_deactivation_hook( __FILE__, [ Installer::class, 'deactivate' ] );

add_action(
	'plugins_loaded',
	static function (): void {
		if ( is_disabled() ) {
			return;
		}
		Bridge::instance()->boot();
	}
);
