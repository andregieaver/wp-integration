<?php
declare( strict_types = 1 );

namespace AgentBridge;

use ParseError;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Nothing is written until it parses.
 *
 * A PHP fatal in an active plugin takes the whole site down, admin included, and
 * the bridge that would let an agent undo it is served by that same site. So the
 * check is not a nicety — it is what keeps a bad write from locking everyone out
 * of the thing they need in order to fix it.
 */
final class Lint {

	/** Which mechanism is available, for reporting on /status. */
	public static function method(): string {
		return self::php_binary() ? 'exec' : 'token_parse';
	}

	public static function check( string $path, string $contents ): bool|WP_Error {
		$ext = strtolower( (string) pathinfo( $path, PATHINFO_EXTENSION ) );

		return match ( $ext ) {
			'php', 'phtml', 'inc' => self::check_php( $contents ),
			'json'                => self::check_json( $contents ),
			default               => true,
		};
	}

	public static function check_php( string $code ): bool|WP_Error {
		$binary = self::php_binary();

		if ( $binary ) {
			$result = self::lint_with_binary( $binary, $code );
			// A false here means the subprocess could not be run at all, in
			// which case fall through rather than declaring the code good.
			if ( $result instanceof WP_Error || true === $result ) {
				return $result;
			}
		}

		return self::lint_with_tokenizer( $code );
	}

	/**
	 * `php -l` is the authoritative check: it is the same parser that will run
	 * the file, including whatever the host's PHP version rejects.
	 */
	private static function lint_with_binary( string $binary, string $code ): bool|WP_Error {
		$tmp = wp_tempnam( 'agent-bridge-lint' );
		if ( ! $tmp ) {
			return false;
		}

		try {
			if ( false === file_put_contents( $tmp, $code ) ) {
				return false;
			}

			$cmd = escapeshellcmd( $binary ) . ' -l ' . escapeshellarg( $tmp ) . ' 2>&1';
			$out = [];
			$status = 0;
			@exec( $cmd, $out, $status );

			if ( 0 === $status ) {
				return true;
			}

			$message = trim( implode( "\n", $out ) );
			if ( '' === $message ) {
				return false; // exec was blocked or produced nothing; fall back.
			}

			// The temp path in the message is noise to the caller.
			$message = str_replace( $tmp, '(file)', $message );

			return new WP_Error(
				'agent_bridge_parse_error',
				sprintf(
					/* translators: %s: parser output. */
					__( 'PHP syntax check failed, nothing was written: %s', 'agent-bridge' ),
					$message
				),
				[ 'status' => 422 ]
			);
		} finally {
			@unlink( $tmp );
		}
	}

	/**
	 * Fallback for hosts with exec() disabled. TOKEN_PARSE makes the tokenizer
	 * run the real parser and throw on invalid source, so this catches the same
	 * class of error — it just cannot report the host PHP's version-specific
	 * complaints as precisely.
	 */
	private static function lint_with_tokenizer( string $code ): bool|WP_Error {
		if ( ! function_exists( 'token_get_all' ) ) {
			return new WP_Error(
				'agent_bridge_no_linter',
				__( 'No syntax checker is available on this host (exec disabled and tokenizer missing). Refusing to write PHP.', 'agent-bridge' ),
				[ 'status' => 501 ]
			);
		}

		try {
			token_get_all( $code, TOKEN_PARSE );
			return true;
		} catch ( ParseError $e ) {
			return new WP_Error(
				'agent_bridge_parse_error',
				sprintf(
					/* translators: %s: parser message. */
					__( 'PHP syntax check failed, nothing was written: %s', 'agent-bridge' ),
					$e->getMessage()
				),
				[ 'status' => 422 ]
			);
		}
	}

	private static function check_json( string $code ): bool|WP_Error {
		if ( '' === trim( $code ) ) {
			return true;
		}

		json_decode( $code );
		if ( JSON_ERROR_NONE === json_last_error() ) {
			return true;
		}

		return new WP_Error(
			'agent_bridge_parse_error',
			sprintf(
				/* translators: %s: json error. */
				__( 'JSON is invalid, nothing was written: %s', 'agent-bridge' ),
				json_last_error_msg()
			),
			[ 'status' => 422 ]
		);
	}

	/** @return string|null Path to a usable PHP CLI binary, if exec is allowed. */
	private static function php_binary(): ?string {
		static $resolved = false;
		static $binary   = null;

		if ( $resolved ) {
			return $binary;
		}
		$resolved = true;

		$disabled = array_map( 'trim', explode( ',', (string) ini_get( 'disable_functions' ) ) );
		if ( in_array( 'exec', $disabled, true ) || ! function_exists( 'exec' ) ) {
			return $binary;
		}

		// PHP_BINARY is the running interpreter, which under mod_php or FPM is
		// not a CLI binary and will not accept -l. Only trust it when it is.
		$candidates = [];
		if ( defined( 'PHP_BINARY' ) && PHP_BINARY && str_contains( basename( PHP_BINARY ), 'php' ) ) {
			$candidates[] = PHP_BINARY;
		}
		$candidates[] = 'php';

		foreach ( $candidates as $candidate ) {
			$out    = [];
			$status = 0;
			@exec( escapeshellcmd( $candidate ) . ' -v 2>&1', $out, $status );
			if ( 0 === $status && str_contains( strtolower( implode( ' ', $out ) ), 'cli' ) ) {
				$binary = $candidate;
				break;
			}
		}

		return $binary;
	}
}
