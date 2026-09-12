<?php
declare( strict_types = 1 );

namespace AgentBridge;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Listing what is installed, creating a new plugin, and turning one on or off.
 */
final class PluginsController extends Controller {

	public function register_routes(): void {
		$this->route( '/plugins', 'GET', [ $this, 'index' ] );
		$this->route( '/plugins', 'POST', [ $this, 'scaffold' ], [], true );
		$this->route( '/plugins/(?P<slug>[A-Za-z0-9][A-Za-z0-9._-]*)/activate', 'POST', [ $this, 'activate' ], [], true );
		$this->route( '/plugins/(?P<slug>[A-Za-z0-9][A-Za-z0-9._-]*)/deactivate', 'POST', [ $this, 'deactivate' ], [], true );
	}

	public function index(): WP_REST_Response {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$managed = Paths::managed();
		$out     = [];

		foreach ( get_plugins() as $file => $data ) {
			$slug = str_contains( $file, '/' ) ? dirname( $file ) : $file;

			$out[] = [
				'file'        => $file,
				'slug'        => $slug,
				'name'        => $data['Name'] ?? '',
				'version'     => $data['Version'] ?? '',
				'description' => $data['Description'] ?? '',
				'author'      => wp_strip_all_tags( (string) ( $data['Author'] ?? '' ) ),
				'requires_php'=> $data['RequiresPHP'] ?? '',
				'active'      => is_plugin_active( $file ),
				'managed'     => in_array( $slug, $managed, true ),
			];
		}

		usort( $out, static fn( $a, $b ) => strcasecmp( $a['name'], $b['name'] ) );

		return new WP_REST_Response( [ 'plugins' => $out, 'managed' => $managed ], 200 );
	}

	/**
	 * Create a new plugin folder with a working entry file, and nominate it.
	 *
	 * Scaffolding is the one write that does not require the target to be
	 * managed already — a plugin that does not exist yet cannot have been
	 * nominated, and requiring a trip to wp-admin between "create" and "edit"
	 * would make bootstrapping a new plugin a two-person job. It can still only
	 * ever create a *new* folder; an existing one is refused.
	 */
	public function scaffold( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$slug = sanitize_key( (string) $request->get_param( 'slug' ) );

		if ( ! preg_match( '/^[a-z0-9][a-z0-9-]{1,61}[a-z0-9]$/', $slug ) ) {
			return new WP_Error(
				'agent_bridge_bad_slug',
				__( 'Slug must be lowercase letters, digits and hyphens, 3 to 63 characters.', 'agent-bridge' ),
				[ 'status' => 400 ]
			);
		}

		$root = Paths::plugin_root() . '/' . $slug;
		if ( file_exists( $root ) ) {
			return new WP_Error(
				'agent_bridge_exists',
				__( 'A plugin folder with that slug already exists. Nominate it under Tools → Agent Bridge instead.', 'agent-bridge' ),
				[ 'status' => 409 ]
			);
		}

		$name        = sanitize_text_field( (string) ( $request->get_param( 'name' ) ?: ucwords( str_replace( '-', ' ', $slug ) ) ) );
		$description = sanitize_text_field( (string) ( $request->get_param( 'description' ) ?: '' ) );
		$author      = sanitize_text_field( (string) ( $request->get_param( 'author' ) ?: wp_get_current_user()->display_name ) );

		if ( ! wp_mkdir_p( $root ) ) {
			return new WP_Error(
				'agent_bridge_mkdir_failed',
				__( 'Could not create the plugin directory. Check that the web user can write to wp-content/plugins.', 'agent-bridge' ),
				[ 'status' => 500 ]
			);
		}

		$namespace = str_replace( ' ', '', ucwords( str_replace( '-', ' ', $slug ) ) );
		$constant  = strtoupper( str_replace( '-', '_', $slug ) );

		$entry = $this->entry_file( $slug, $name, $description, $author, $namespace, $constant );

		if ( false === file_put_contents( $root . '/' . $slug . '.php', $entry, LOCK_EX ) ) {
			return new WP_Error( 'agent_bridge_write_failed', __( 'Could not write the plugin entry file.', 'agent-bridge' ), [ 'status' => 500 ] );
		}

		wp_mkdir_p( $root . '/includes' );

		Paths::add_managed( $slug );

		Audit::log(
			'plugin.scaffold',
			$slug,
			[ 'bytes' => strlen( $entry ), 'sha_after' => $this->sha( $entry ), 'message' => 'created and nominated' ]
		);

		return new WP_REST_Response(
			[
				'created' => true,
				'slug'    => $slug,
				'file'    => $slug . '/' . $slug . '.php',
				'managed' => true,
				'active'  => false,
			],
			201
		);
	}

	public function activate( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$slug = (string) $request->get_param( 'slug' );

		$file = $this->plugin_file( $slug );
		if ( is_wp_error( $file ) ) {
			return $file;
		}

		if ( is_plugin_active( $file ) ) {
			return new WP_REST_Response( [ 'active' => true, 'changed' => false, 'file' => $file ], 200 );
		}

		// activate_plugin() includes the file in a sandbox and hands back a
		// WP_Error on fatal rather than taking the request down with it. That is
		// the whole reason for going through it instead of touching the option.
		$result = activate_plugin( $file );

		if ( is_wp_error( $result ) ) {
			Audit::log( 'plugin.activate', $slug, [ 'result' => 'error', 'message' => $result->get_error_message() ] );
			return new WP_Error(
				'agent_bridge_activation_failed',
				sprintf(
					/* translators: %s: underlying error. */
					__( 'Activation failed and the plugin was left inactive: %s', 'agent-bridge' ),
					$result->get_error_message()
				),
				[ 'status' => 422 ]
			);
		}

		Audit::log( 'plugin.activate', $slug );

		return new WP_REST_Response( [ 'active' => true, 'changed' => true, 'file' => $file ], 200 );
	}

	public function deactivate( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$slug = (string) $request->get_param( 'slug' );

		$file = $this->plugin_file( $slug );
		if ( is_wp_error( $file ) ) {
			return $file;
		}

		// Refusing to switch the bridge off through the bridge: the call would
		// succeed and the response would never be delivered, leaving the caller
		// with a timeout and no route back in.
		if ( plugin_basename( FILE ) === $file ) {
			return new WP_Error(
				'agent_bridge_self',
				__( 'The bridge will not deactivate itself. Do that from wp-admin.', 'agent-bridge' ),
				[ 'status' => 409 ]
			);
		}

		if ( ! is_plugin_active( $file ) ) {
			return new WP_REST_Response( [ 'active' => false, 'changed' => false, 'file' => $file ], 200 );
		}

		deactivate_plugins( [ $file ] );
		Audit::log( 'plugin.deactivate', $slug );

		return new WP_REST_Response( [ 'active' => false, 'changed' => true, 'file' => $file ], 200 );
	}

	/** @return string|WP_Error The "slug/entry.php" key WordPress knows a plugin by. */
	private function plugin_file( string $slug ): string|WP_Error {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		if ( ! Paths::is_managed( $slug ) ) {
			return new WP_Error(
				'agent_bridge_not_managed',
				__( 'That plugin is not managed by the bridge.', 'agent-bridge' ),
				[ 'status' => 403 ]
			);
		}

		foreach ( array_keys( get_plugins() ) as $file ) {
			if ( dirname( $file ) === $slug || $file === $slug ) {
				return $file;
			}
		}

		return new WP_Error(
			'agent_bridge_not_found',
			__( 'WordPress does not recognise a plugin with that slug. Does its entry file have a plugin header?', 'agent-bridge' ),
			[ 'status' => 404 ]
		);
	}

	private function entry_file( string $slug, string $name, string $description, string $author, string $namespace, string $constant ): string {
		$year = gmdate( 'Y' );

		return <<<PHPFILE
<?php
/**
 * Plugin Name:       {$name}
 * Description:       {$description}
 * Version:           0.1.0
 * Requires at least: 6.4
 * Requires PHP:      8.0
 * Author:            {$author}
 * License:           GPL-2.0-or-later
 * Text Domain:       {$slug}
 *
 * @package {$namespace}
 * @since {$year}
 */

declare( strict_types = 1 );

namespace {$namespace};

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const VERSION = '0.1.0';

define( '{$constant}_FILE', __FILE__ );
define( '{$constant}_DIR', plugin_dir_path( __FILE__ ) );
define( '{$constant}_URL', plugin_dir_url( __FILE__ ) );

add_action(
	'plugins_loaded',
	static function (): void {
		// Bootstrap goes here.
	}
);

PHPFILE;
	}
}
