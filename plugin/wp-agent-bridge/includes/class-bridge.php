<?php
declare( strict_types = 1 );

namespace AgentBridge;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Wiring only: registers the REST controllers and the admin screen.
 */
final class Bridge {

	private static ?Bridge $instance = null;

	public static function instance(): Bridge {
		return self::$instance ??= new self();
	}

	private function __construct() {}

	public function boot(): void {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );

		if ( is_admin() ) {
			( new Admin\SettingsPage() )->boot();
		}

		// The audit table is created on activation, but a plugin copied into
		// place and activated by a file-level deploy never fires that hook.
		add_action( 'admin_init', [ $this, 'maybe_upgrade' ] );
	}

	public function register_routes(): void {
		( new SiteController() )->register_routes();
		( new FilesController() )->register_routes();
		( new PluginsController() )->register_routes();
	}

	public function maybe_upgrade(): void {
		if ( get_option( 'agent_bridge_db_version' ) === VERSION ) {
			return;
		}

		Installer::create_audit_table();
		update_option( 'agent_bridge_db_version', VERSION, false );
	}
}
