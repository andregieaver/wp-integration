<?php
declare( strict_types = 1 );

namespace AgentBridge;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reading and writing plugin source.
 */
final class FilesController extends Controller {

	public function register_routes(): void {
		$this->route( '/tree', 'GET', [ $this, 'tree' ] );
		$this->route( '/file', 'GET', [ $this, 'read' ] );
		$this->route( '/file', 'POST', [ $this, 'write' ], [], true );
		$this->route( '/file', 'DELETE', [ $this, 'delete' ], [], true );
		$this->route( '/restore', 'POST', [ $this, 'restore' ], [], true );
	}

	public function tree( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$slug = (string) $request->get_param( 'plugin' );

		if ( ! Paths::is_managed( $slug ) ) {
			return new WP_Error(
				'agent_bridge_not_managed',
				__( 'That plugin is not managed by the bridge.', 'agent-bridge' ),
				[ 'status' => 403 ]
			);
		}

		$root = realpath( Paths::plugin_root() . '/' . $slug );
		if ( ! $root || ! is_dir( $root ) ) {
			return new WP_Error( 'agent_bridge_not_found', __( 'No such plugin folder.', 'agent-bridge' ), [ 'status' => 404 ] );
		}

		$include_vendor = (bool) $request->get_param( 'include_vendor' );
		$with_hashes    = null === $request->get_param( 'hashes' ) ? true : (bool) $request->get_param( 'hashes' );

		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::SELF_FIRST
		);

		$files = [];
		$skipped_dirs = [];

		foreach ( $iterator as $item ) {
			$name = $item->getFilename();
			$rel  = ltrim( str_replace( $root, '', $item->getPathname() ), '/' );

			if ( $item->isDir() ) {
				if ( ! $include_vendor && Paths::is_ignored_dir( $name ) ) {
					// Recorded so the descendants can be filtered out by prefix
					// below: RecursiveIteratorIterator has no prune, and walking
					// node_modules produces tens of thousands of rows nobody
					// asked for and can time the request out. SELF_FIRST is what
					// guarantees this is seen before anything inside it.
					$skipped_dirs[] = $rel;
					$files[]        = [ 'path' => $rel, 'type' => 'dir', 'skipped' => true ];
				}
				continue;
			}

			// The iterator has no prune, so filter descendants by prefix.
			foreach ( $skipped_dirs as $skipped ) {
				if ( str_starts_with( $rel, $skipped . '/' ) ) {
					continue 2;
				}
			}

			$size  = $item->getSize();
			$entry = [
				'path'  => $rel,
				'type'  => 'file',
				'bytes' => $size,
				'mtime' => gmdate( 'c', (int) $item->getMTime() ),
				'ext'   => strtolower( (string) pathinfo( $name, PATHINFO_EXTENSION ) ),
			];

			$entry['editable'] = in_array( $entry['ext'], Paths::ALLOWED_EXT, true )
				&& ! in_array( strtolower( $name ), Paths::DENIED_NAMES, true );

			if ( $with_hashes && $entry['editable'] && $size <= $this->max_bytes() ) {
				$entry['sha256'] = hash_file( 'sha256', $item->getPathname() );
			}

			$files[] = $entry;
		}

		usort( $files, static fn( $a, $b ) => strcmp( $a['path'], $b['path'] ) );

		return new WP_REST_Response(
			[
				'plugin' => $slug,
				'root'   => $root,
				'count'  => count( $files ),
				'files'  => $files,
			],
			200
		);
	}

	public function read( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$resolved = Paths::resolve( (string) $request->get_param( 'path' ) );
		if ( is_wp_error( $resolved ) ) {
			return $resolved;
		}

		$size = (int) filesize( $resolved['abs'] );
		if ( $size > $this->max_bytes() ) {
			return new WP_Error(
				'agent_bridge_too_large',
				sprintf(
					/* translators: 1: file size, 2: limit. */
					__( 'File is %1$d bytes, over the %2$d byte limit.', 'agent-bridge' ),
					$size,
					$this->max_bytes()
				),
				[ 'status' => 413 ]
			);
		}

		$contents = file_get_contents( $resolved['abs'] );
		if ( false === $contents ) {
			return new WP_Error( 'agent_bridge_unreadable', __( 'Could not read the file.', 'agent-bridge' ), [ 'status' => 500 ] );
		}

		if ( $this->looks_binary( $contents ) ) {
			return new WP_Error( 'agent_bridge_binary', __( 'File appears to be binary.', 'agent-bridge' ), [ 'status' => 415 ] );
		}

		return new WP_REST_Response(
			[
				'path'     => $resolved['slug'] . '/' . $resolved['rel'],
				'contents' => $contents,
				'sha256'   => $this->sha( $contents ),
				'bytes'    => $size,
				'mtime'    => gmdate( 'c', (int) filemtime( $resolved['abs'] ) ),
			],
			200
		);
	}

	/**
	 * Write, with the four things that make it survivable: the path is confined,
	 * the caller proves it read the current version, the source parses, and the
	 * old bytes are kept.
	 */
	public function write( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$path     = (string) $request->get_param( 'path' );
		$contents = $request->get_param( 'contents' );

		if ( ! is_string( $contents ) ) {
			return new WP_Error( 'agent_bridge_bad_body', __( 'A "contents" string is required.', 'agent-bridge' ), [ 'status' => 400 ] );
		}

		if ( strlen( $contents ) > $this->max_bytes() ) {
			return new WP_Error( 'agent_bridge_too_large', __( 'Contents exceed the size limit.', 'agent-bridge' ), [ 'status' => 413 ] );
		}

		$resolved = Paths::resolve( $path, false );
		if ( is_wp_error( $resolved ) ) {
			return $resolved;
		}

		$abs     = $resolved['abs'];
		$exists  = is_file( $abs );
		$current = $exists ? (string) file_get_contents( $abs ) : null;
		$sha_before = null === $current ? null : $this->sha( $current );

		// Optimistic concurrency. Between the agent reading a file and writing
		// it back, someone may have edited it in the WordPress plugin editor or
		// redeployed. Their change is not ours to silently overwrite.
		$expected = $request->get_param( 'expected_sha' );
		$force    = (bool) $request->get_param( 'force' );

		if ( ! $force ) {
			if ( $exists && ! is_string( $expected ) ) {
				return new WP_Error(
					'agent_bridge_sha_required',
					__( 'This file exists; send "expected_sha" from your last read, or "force": true.', 'agent-bridge' ),
					[ 'status' => 428 ]
				);
			}

			if ( $exists && ! hash_equals( (string) $sha_before, (string) $expected ) ) {
				return new WP_Error(
					'agent_bridge_stale',
					__( 'The file changed since you read it; nothing was written. Re-read and retry.', 'agent-bridge' ),
					[ 'status' => 409, 'current_sha' => $sha_before ]
				);
			}

			if ( ! $exists && is_string( $expected ) && '' !== $expected ) {
				return new WP_Error(
					'agent_bridge_stale',
					__( 'You sent expected_sha but the file does not exist; it may have been deleted.', 'agent-bridge' ),
					[ 'status' => 409 ]
				);
			}
		}

		$lint = Lint::check( $abs, $contents );
		if ( is_wp_error( $lint ) ) {
			Audit::log(
				'file.write',
				$resolved['slug'] . '/' . $resolved['rel'],
				[ 'result' => 'rejected', 'message' => $lint->get_error_message() ]
			);
			return $lint;
		}

		$backup_id = null;
		if ( $exists ) {
			$backup = Backup::capture( $resolved['slug'], $resolved['rel'], $abs );
			if ( is_wp_error( $backup ) ) {
				return $backup;
			}
			$backup_id = $backup['id'];
		} else {
			$dir = dirname( $abs );
			if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
				return new WP_Error( 'agent_bridge_mkdir_failed', __( 'Could not create the containing directory.', 'agent-bridge' ), [ 'status' => 500 ] );
			}
		}

		if ( false === file_put_contents( $abs, $contents, LOCK_EX ) ) {
			Audit::log(
				'file.write',
				$resolved['slug'] . '/' . $resolved['rel'],
				[ 'result' => 'error', 'message' => 'file_put_contents failed', 'sha_before' => $sha_before ]
			);
			return new WP_Error(
				'agent_bridge_write_failed',
				__( 'The write failed. Check filesystem permissions for the web user.', 'agent-bridge' ),
				[ 'status' => 500 ]
			);
		}

		clearstatcache( true, $abs );
		$sha_after = $this->sha( $contents );

		Audit::log(
			'file.write',
			$resolved['slug'] . '/' . $resolved['rel'],
			[
				'bytes'      => strlen( $contents ),
				'sha_before' => $sha_before,
				'sha_after'  => $sha_after,
				'message'    => $exists ? 'updated' : 'created',
			]
		);

		return new WP_REST_Response(
			[
				'path'      => $resolved['slug'] . '/' . $resolved['rel'],
				'created'   => ! $exists,
				'bytes'     => strlen( $contents ),
				'sha256'    => $sha_after,
				'backup_id' => $backup_id,
				'linted'    => Lint::method(),
			],
			$exists ? 200 : 201
		);
	}

	public function delete( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$resolved = Paths::resolve( (string) $request->get_param( 'path' ) );
		if ( is_wp_error( $resolved ) ) {
			return $resolved;
		}

		$abs        = $resolved['abs'];
		$current    = (string) file_get_contents( $abs );
		$sha_before = $this->sha( $current );
		$expected   = $request->get_param( 'expected_sha' );

		if ( ! (bool) $request->get_param( 'force' ) ) {
			if ( ! is_string( $expected ) ) {
				return new WP_Error(
					'agent_bridge_sha_required',
					__( 'Send "expected_sha" from your last read, or "force": true.', 'agent-bridge' ),
					[ 'status' => 428 ]
				);
			}
			if ( ! hash_equals( $sha_before, $expected ) ) {
				return new WP_Error(
					'agent_bridge_stale',
					__( 'The file changed since you read it; nothing was deleted.', 'agent-bridge' ),
					[ 'status' => 409, 'current_sha' => $sha_before ]
				);
			}
		}

		$backup = Backup::capture( $resolved['slug'], $resolved['rel'], $abs );
		if ( is_wp_error( $backup ) ) {
			return $backup;
		}

		if ( ! unlink( $abs ) ) {
			return new WP_Error( 'agent_bridge_delete_failed', __( 'Could not delete the file.', 'agent-bridge' ), [ 'status' => 500 ] );
		}

		Audit::log(
			'file.delete',
			$resolved['slug'] . '/' . $resolved['rel'],
			[ 'sha_before' => $sha_before, 'message' => 'backup ' . $backup['id'] ]
		);

		return new WP_REST_Response(
			[ 'deleted' => true, 'path' => $resolved['slug'] . '/' . $resolved['rel'], 'backup_id' => $backup['id'] ],
			200
		);
	}

	public function restore( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$id = (string) $request->get_param( 'backup_id' );

		$restore = Backup::restore( $id );
		if ( is_wp_error( $restore ) ) {
			return $restore;
		}

		$target   = $restore['target'];
		$contents = $restore['contents'];
		$abs      = $target['abs'];

		// A restore is still a write, so it keeps a copy of whatever it replaces.
		// Undoing an undo has to be possible too.
		if ( is_file( $abs ) ) {
			$counter = Backup::capture( $target['slug'], $target['rel'], $abs );
			if ( is_wp_error( $counter ) ) {
				return $counter;
			}
		} else {
			$dir = dirname( $abs );
			if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
				return new WP_Error( 'agent_bridge_mkdir_failed', __( 'Could not recreate the containing directory.', 'agent-bridge' ), [ 'status' => 500 ] );
			}
		}

		if ( false === file_put_contents( $abs, $contents, LOCK_EX ) ) {
			return new WP_Error( 'agent_bridge_write_failed', __( 'The restore write failed.', 'agent-bridge' ), [ 'status' => 500 ] );
		}

		Audit::log(
			'file.restore',
			$target['slug'] . '/' . $target['rel'],
			[ 'bytes' => strlen( $contents ), 'sha_after' => $this->sha( $contents ), 'message' => 'from backup ' . $id ]
		);

		return new WP_REST_Response(
			[
				'restored' => true,
				'path'     => $target['slug'] . '/' . $target['rel'],
				'sha256'   => $this->sha( $contents ),
				'from'     => $id,
			],
			200
		);
	}
}
