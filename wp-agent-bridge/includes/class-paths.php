<?php
declare( strict_types = 1 );

namespace AgentBridge;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Everything the bridge touches goes through resolve(). One door, so there is
 * one place to be right about traversal, symlinks and the managed allowlist.
 */
final class Paths {

	/**
	 * Extensions the bridge will read or write. An allowlist rather than a
	 * denylist: a new dangerous extension appears every few years, and a
	 * denylist is only ever as current as the day it was written.
	 */
	public const ALLOWED_EXT = [
		'php', 'phtml', 'inc',
		'js', 'mjs', 'cjs', 'jsx', 'ts', 'tsx', 'map',
		'css', 'scss', 'sass', 'less',
		'json', 'md', 'txt', 'yml', 'yaml', 'xml',
		'po', 'pot', 'html', 'htm', 'svg', 'csv',
	];

	/**
	 * Names refused whatever their extension, because each one changes how the
	 * server itself behaves rather than what the plugin does.
	 */
	public const DENIED_NAMES = [
		'.htaccess', '.user.ini', 'php.ini', 'web.config', '.env', 'wp-config.php',
	];

	public static function plugin_root(): string {
		$root = realpath( WP_PLUGIN_DIR );
		return $root ? rtrim( $root, '/' ) : rtrim( WP_PLUGIN_DIR, '/' );
	}

	/** Plugin folder slugs the admin has nominated as writable. */
	public static function managed(): array {
		$managed = (array) get_option( OPT_MANAGED, [] );
		return array_values( array_unique( array_filter( array_map( 'strval', $managed ) ) ) );
	}

	public static function is_managed( string $slug ): bool {
		// The bridge is never editable through the bridge. Rewriting the file
		// that is serving the current request is how you get a fatal with no
		// route left to fix it — and the admin screen, which is the way back in,
		// is served by this same plugin.
		if ( $slug === self::own_slug() ) {
			return false;
		}

		return in_array( $slug, self::managed(), true );
	}

	public static function own_slug(): string {
		return dirname( plugin_basename( FILE ) );
	}

	public static function add_managed( string $slug ): void {
		if ( $slug === self::own_slug() ) {
			return;
		}

		$managed   = self::managed();
		$managed[] = $slug;
		update_option( OPT_MANAGED, array_values( array_unique( $managed ) ), false );
	}

	public static function remove_managed( string $slug ): void {
		update_option( OPT_MANAGED, array_values( array_diff( self::managed(), [ $slug ] ) ), false );
	}

	/**
	 * Turn a bridge-relative path ("my-plugin/includes/thing.php") into an
	 * absolute one, or explain why not.
	 *
	 * @param bool $must_exist false when resolving the destination of a new file.
	 * @return array{abs:string,slug:string,rel:string,root:string}|WP_Error
	 */
	public static function resolve( string $path, bool $must_exist = true ): array|WP_Error {
		$path = trim( $path );

		if ( '' === $path ) {
			return self::bad( 'Path is empty.' );
		}

		if ( str_contains( $path, "\0" ) ) {
			return self::bad( 'Path contains a null byte.' );
		}

		// Normalise separators before any inspection, so a Windows-style
		// "..\\.." cannot slip past a check that only looked for "../".
		$path = str_replace( '\\', '/', $path );
		$path = ltrim( $path, '/' );

		if ( preg_match( '#(^|/)\.\.(/|$)#', $path ) ) {
			return self::bad( 'Path traversal is not allowed.' );
		}

		$segments = array_values( array_filter( explode( '/', $path ), static fn( $s ) => '' !== $s && '.' !== $s ) );

		if ( count( $segments ) < 2 ) {
			return self::bad( 'Path must name a file inside a plugin folder, e.g. "my-plugin/my-plugin.php".' );
		}

		$slug = array_shift( $segments );

		if ( ! preg_match( '/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $slug ) ) {
			return self::bad( 'Plugin folder name is not valid.' );
		}

		if ( ! self::is_managed( $slug ) ) {
			return new WP_Error(
				'agent_bridge_not_managed',
				sprintf(
					/* translators: %s: plugin folder slug. */
					__( 'The plugin folder "%s" is not managed by the bridge. Add it under Tools → Agent Bridge.', 'agent-bridge' ),
					$slug
				),
				[ 'status' => 403 ]
			);
		}

		$basename = end( $segments );
		if ( in_array( strtolower( (string) $basename ), self::DENIED_NAMES, true ) ) {
			return self::bad( sprintf( 'Files named "%s" are refused.', $basename ) );
		}

		$ext = strtolower( (string) pathinfo( (string) $basename, PATHINFO_EXTENSION ) );
		if ( ! in_array( $ext, self::ALLOWED_EXT, true ) ) {
			return self::bad(
				sprintf( 'Extension ".%s" is not on the allowlist.', $ext ?: '(none)' )
			);
		}

		$root = self::plugin_root() . '/' . $slug;
		$real_root = realpath( $root );

		if ( ! $real_root || ! is_dir( $real_root ) ) {
			return new WP_Error(
				'agent_bridge_missing_plugin',
				sprintf(
					/* translators: %s: plugin folder slug. */
					__( 'The plugin folder "%s" does not exist on disk.', 'agent-bridge' ),
					$slug
				),
				[ 'status' => 404 ]
			);
		}

		$rel = implode( '/', $segments );
		$abs = $real_root . '/' . $rel;

		// realpath() is what actually resolves symlinks. Check the file when it
		// exists and its parent directory when it does not, because a path that
		// is about to be created has no realpath of its own.
		$probe = file_exists( $abs ) ? $abs : dirname( $abs );
		$real  = realpath( $probe );

		if ( ! $real ) {
			return new WP_Error(
				'agent_bridge_missing_parent',
				__( 'The containing directory does not exist.', 'agent-bridge' ),
				[ 'status' => 404 ]
			);
		}

		if ( $real !== $real_root && ! str_starts_with( $real, $real_root . '/' ) ) {
			// A symlink inside the plugin pointing out of it lands here.
			return self::bad( 'Resolved path escapes the plugin folder.' );
		}

		if ( $must_exist && ! is_file( $abs ) ) {
			return new WP_Error(
				'agent_bridge_not_found',
				__( 'No such file.', 'agent-bridge' ),
				[ 'status' => 404 ]
			);
		}

		return [
			'abs'  => $abs,
			'slug' => $slug,
			'rel'  => $rel,
			'root' => $real_root,
		];
	}

	/** Directories never worth walking or shipping over the wire. */
	public static function is_ignored_dir( string $name ): bool {
		return in_array(
			$name,
			[ '.git', '.svn', 'node_modules', 'vendor', '.idea', '.vscode', '__pycache__', '.DS_Store' ],
			true
		);
	}

	private static function bad( string $message ): WP_Error {
		return new WP_Error( 'agent_bridge_bad_path', $message, [ 'status' => 400 ] );
	}
}
