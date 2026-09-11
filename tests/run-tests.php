<?php
/**
 * The path resolver and the syntax checker, tested directly.
 *
 * These two are what stand between an authenticated caller and the rest of the
 * filesystem, so they are checked against the attacks they exist to stop rather
 * than only against the happy path.
 */

declare( strict_types = 1 );

require_once __DIR__ . '/bootstrap.php';

use AgentBridge\Lint;
use AgentBridge\Paths;

$passed = 0;
$failed = 0;

function check( string $name, bool $condition, string $detail = '' ): void {
	global $passed, $failed;

	if ( $condition ) {
		++$passed;
		echo "  ok    {$name}\n";
		return;
	}

	++$failed;
	echo "  FAIL  {$name}" . ( $detail ? " — {$detail}" : '' ) . "\n";
}

/** Build the fixture tree fresh on every run. */
function fixtures(): string {
	$root = __DIR__ . '/fixtures/wp/wp-content/plugins';

	if ( is_dir( __DIR__ . '/fixtures' ) ) {
		$it = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( __DIR__ . '/fixtures', FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $it as $item ) {
			$item->isDir() && ! $item->isLink() ? rmdir( $item->getPathname() ) : unlink( $item->getPathname() );
		}
		rmdir( __DIR__ . '/fixtures' );
	}

	mkdir( $root . '/my-plugin/includes', 0o777, true );
	mkdir( $root . '/other-plugin', 0o777, true );
	mkdir( $root . '/wp-agent-bridge', 0o777, true );
	mkdir( __DIR__ . '/fixtures/wp/wp-content/uploads', 0o777, true );

	file_put_contents( $root . '/my-plugin/my-plugin.php', "<?php\n// entry\n" );
	file_put_contents( $root . '/my-plugin/includes/thing.php', "<?php\n// thing\n" );
	file_put_contents( $root . '/my-plugin/style.css', "body{}\n" );
	file_put_contents( $root . '/other-plugin/other.php', "<?php\n" );
	file_put_contents( __DIR__ . '/fixtures/wp/wp-config.php', "<?php\n// secrets\n" );

	// A symlink inside the managed plugin pointing out of it: the case realpath
	// confinement exists for, and the one a string-prefix check would miss.
	@symlink( __DIR__ . '/fixtures/wp', $root . '/my-plugin/escape' );

	return $root;
}

fixtures();
update_option( 'agent_bridge_managed_plugins', [ 'my-plugin' ] );

echo "\nPaths::resolve — accepts\n";

$ok = Paths::resolve( 'my-plugin/my-plugin.php' );
check( 'entry file resolves', ! is_wp_error( $ok ) && isset( $ok['abs'] ) && is_file( $ok['abs'] ) );

$ok = Paths::resolve( 'my-plugin/includes/thing.php' );
check( 'nested file resolves', ! is_wp_error( $ok ) && $ok['rel'] === 'includes/thing.php' );

$ok = Paths::resolve( 'my-plugin/style.css' );
check( 'css resolves', ! is_wp_error( $ok ) );

$ok = Paths::resolve( 'my-plugin/includes/new-file.php', false );
check( 'new file in existing dir resolves when must_exist is false', ! is_wp_error( $ok ) );

$ok = Paths::resolve( '/my-plugin/my-plugin.php' );
check( 'leading slash is tolerated', ! is_wp_error( $ok ) );

echo "\nPaths::resolve — refuses\n";

$cases = [
	'traversal out of plugins'      => '../../wp-config.php',
	'traversal via managed plugin'  => 'my-plugin/../../wp-config.php',
	'traversal mid-path'            => 'my-plugin/includes/../../../wp-config.php',
	'backslash traversal'           => 'my-plugin\\..\\..\\wp-config.php',
	'unmanaged plugin'              => 'other-plugin/other.php',
	'the bridge itself'             => 'wp-agent-bridge/wp-agent-bridge.php',
	'denied extension'              => 'my-plugin/deploy.sh',
	'no extension'                  => 'my-plugin/Makefile',
	'htaccess by name'              => 'my-plugin/.htaccess',
	'user ini by name'              => 'my-plugin/.user.ini',
	'bare plugin folder'            => 'my-plugin',
	'empty path'                    => '',
	'symlink escaping the plugin'   => 'my-plugin/escape/wp-config.php',
	'nonexistent plugin folder'     => 'ghost-plugin/ghost.php',
];

foreach ( $cases as $name => $path ) {
	$result = Paths::resolve( $path );
	check(
		$name,
		is_wp_error( $result ),
		is_wp_error( $result ) ? '' : 'resolved to ' . ( $result['abs'] ?? '?' )
	);
}

// A null byte cannot go through the normal call because of the string type, but
// it can arrive from JSON, so it is checked the same way.
$result = Paths::resolve( "my-plugin/thing.php\0.txt" );
check( 'null byte in path', is_wp_error( $result ) );

echo "\nPaths::resolve — self-protection\n";

update_option( 'agent_bridge_managed_plugins', [ 'my-plugin', 'wp-agent-bridge' ] );
check(
	'bridge stays unmanaged even if the option says otherwise',
	is_wp_error( Paths::resolve( 'wp-agent-bridge/wp-agent-bridge.php' ) )
);
Paths::add_managed( 'wp-agent-bridge' );
check( 'add_managed refuses the bridge', ! Paths::is_managed( 'wp-agent-bridge' ) );
update_option( 'agent_bridge_managed_plugins', [ 'my-plugin' ] );

echo "\nLint\n";

check( 'valid php passes', true === Lint::check( 'x.php', "<?php\n\$a = 1;\necho \$a;\n" ) );
check( 'missing brace fails', is_wp_error( Lint::check( 'x.php', "<?php\nfunction a() {\n" ) ) );
check( 'stray token fails', is_wp_error( Lint::check( 'x.php', "<?php\n\$a = ;\n" ) ) );
check( 'valid json passes', true === Lint::check( 'x.json', '{"a":1}' ) );
check( 'invalid json fails', is_wp_error( Lint::check( 'x.json', '{"a":1,}' ) ) );
check( 'css is not parsed', true === Lint::check( 'x.css', 'body { this is not css' ) );
check( 'empty php file passes', true === Lint::check( 'x.php', '' ) );
check( 'lint method is reported', in_array( Lint::method(), [ 'exec', 'token_parse' ], true ) );

echo "\n{$passed} passed, {$failed} failed\n";

exit( $failed > 0 ? 1 : 0 );
