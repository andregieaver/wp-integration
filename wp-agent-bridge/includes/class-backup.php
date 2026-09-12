<?php
declare( strict_types = 1 );

namespace AgentBridge;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Every overwrite and every delete keeps a copy first, so "undo the last thing
 * the agent did" is a real operation rather than a hope about version control.
 *
 * Copies live under wp-content in a directory whose name carries a per-install
 * random token. That directory is web-reachable on most hosts, and the files in
 * it are plugin source: the token is what stops someone who knows this plugin is
 * installed from simply fetching it. Copies are additionally stored with a .bak
 * extension so no PHP handler will execute them, and the directory ships with
 * both an .htaccess deny and an empty index.php for the servers that honour each.
 */
final class Backup {

	public static function root(): string {
		$key = (string) get_option( OPT_BACKUPKEY, '' );
		if ( '' === $key ) {
			$key = bin2hex( random_bytes( 16 ) );
			update_option( OPT_BACKUPKEY, $key, false );
		}

		return rtrim( WP_CONTENT_DIR, '/' ) . '/agent-bridge-backups-' . $key;
	}

	public static function ensure_dir(): bool {
		$root = self::root();

		if ( ! is_dir( $root ) && ! wp_mkdir_p( $root ) ) {
			return false;
		}

		$htaccess = $root . '/.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			file_put_contents(
				$htaccess,
				"# Managed by Agent Bridge.\n<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n\tOrder allow,deny\n\tDeny from all\n</IfModule>\n"
			);
		}

		$index = $root . '/index.php';
		if ( ! file_exists( $index ) ) {
			file_put_contents( $index, "<?php\n// Silence is golden.\n" );
		}

		return true;
	}

	/**
	 * @return array{id:string,path:string}|WP_Error
	 */
	public static function capture( string $slug, string $rel, string $abs ): array|WP_Error {
		if ( ! self::ensure_dir() ) {
			return new WP_Error(
				'agent_bridge_backup_failed',
				__( 'Could not create the backup directory; refusing to write.', 'agent-bridge' ),
				[ 'status' => 500 ]
			);
		}

		$contents = file_get_contents( $abs );
		if ( false === $contents ) {
			return new WP_Error(
				'agent_bridge_backup_failed',
				__( 'Could not read the existing file to back it up; refusing to write.', 'agent-bridge' ),
				[ 'status' => 500 ]
			);
		}

		$id   = gmdate( 'Ymd-His' ) . '-' . bin2hex( random_bytes( 4 ) );
		$dir  = self::root() . '/' . $slug;
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return new WP_Error(
				'agent_bridge_backup_failed',
				__( 'Could not create the backup directory for this plugin; refusing to write.', 'agent-bridge' ),
				[ 'status' => 500 ]
			);
		}

		$blob = $dir . '/' . $id . '.bak';
		$meta = $dir . '/' . $id . '.json';

		if ( false === file_put_contents( $blob, $contents ) ) {
			return new WP_Error(
				'agent_bridge_backup_failed',
				__( 'Could not write the backup; refusing to write.', 'agent-bridge' ),
				[ 'status' => 500 ]
			);
		}

		file_put_contents(
			$meta,
			(string) wp_json_encode(
				[
					'id'         => $id,
					'slug'       => $slug,
					'rel'        => $rel,
					'path'       => $slug . '/' . $rel,
					'sha256'     => hash( 'sha256', $contents ),
					'bytes'      => strlen( $contents ),
					'captured_at'=> gmdate( 'c' ),
					'user_id'    => get_current_user_id(),
				],
				JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
			)
		);

		return [ 'id' => $id, 'path' => $blob ];
	}

	/** @return array<int,array<string,mixed>> */
	public static function listing( ?string $slug = null, int $limit = 100 ): array {
		$root = self::root();
		if ( ! is_dir( $root ) ) {
			return [];
		}

		$dirs = $slug ? [ $root . '/' . $slug ] : ( glob( $root . '/*', GLOB_ONLYDIR ) ?: [] );

		$out = [];
		foreach ( $dirs as $dir ) {
			foreach ( glob( $dir . '/*.json' ) ?: [] as $meta ) {
				$decoded = json_decode( (string) file_get_contents( $meta ), true );
				if ( is_array( $decoded ) ) {
					$out[] = $decoded;
				}
			}
		}

		usort( $out, static fn( $a, $b ) => strcmp( (string) ( $b['id'] ?? '' ), (string) ( $a['id'] ?? '' ) ) );

		return array_slice( $out, 0, max( 1, $limit ) );
	}

	/** @return array<string,mixed>|WP_Error */
	public static function restore( string $id ): array|WP_Error {
		if ( ! preg_match( '/^[0-9]{8}-[0-9]{6}-[a-f0-9]{8}$/', $id ) ) {
			return new WP_Error( 'agent_bridge_bad_backup_id', __( 'Malformed backup id.', 'agent-bridge' ), [ 'status' => 400 ] );
		}

		$root = self::root();
		$meta_files = glob( $root . '/*/' . $id . '.json' ) ?: [];

		if ( ! $meta_files ) {
			return new WP_Error( 'agent_bridge_backup_not_found', __( 'No such backup.', 'agent-bridge' ), [ 'status' => 404 ] );
		}

		$meta = json_decode( (string) file_get_contents( $meta_files[0] ), true );
		if ( ! is_array( $meta ) || empty( $meta['path'] ) ) {
			return new WP_Error( 'agent_bridge_backup_corrupt', __( 'Backup metadata is unreadable.', 'agent-bridge' ), [ 'status' => 500 ] );
		}

		$blob = dirname( $meta_files[0] ) . '/' . $id . '.bak';
		$contents = file_get_contents( $blob );
		if ( false === $contents ) {
			return new WP_Error( 'agent_bridge_backup_corrupt', __( 'Backup contents are unreadable.', 'agent-bridge' ), [ 'status' => 500 ] );
		}

		// Route the restore through the same resolver everything else uses, so a
		// backup cannot become a way to write somewhere resolve() would refuse.
		$target = Paths::resolve( (string) $meta['path'], false );
		if ( is_wp_error( $target ) ) {
			return $target;
		}

		return [ 'target' => $target, 'contents' => $contents, 'meta' => $meta ];
	}

	public static function purge_all(): void {
		$root = self::root();
		if ( ! is_dir( $root ) ) {
			return;
		}

		$it = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ( $it as $item ) {
			$item->isDir() ? @rmdir( $item->getPathname() ) : @unlink( $item->getPathname() );
		}

		@rmdir( $root );
	}
}
