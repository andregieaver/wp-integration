<?php
declare( strict_types = 1 );

namespace AgentBridge\Admin;

use AgentBridge\Audit;
use AgentBridge\Auth;
use AgentBridge\Backup;
use AgentBridge\Lint;
use AgentBridge\Paths;
use function AgentBridge\is_readonly;
use const AgentBridge\OPT_ALLOW_IPS;
use const AgentBridge\OPT_MANAGED;
use const AgentBridge\OPT_MAXBYTES;
use const AgentBridge\REST_NS;
use const AgentBridge\VERSION;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tools → Agent Bridge.
 *
 * The one thing this screen must do well is make the blast radius visible: which
 * plugins are reachable, whether writing is on, and what the bridge has done.
 */
final class SettingsPage {

	private const SLUG  = 'agent-bridge';
	private const NONCE = 'agent_bridge_admin';

	/** Set for one render after the secret is rotated; never stored in plaintext. */
	private string $fresh_secret = '';

	public function boot(): void {
		add_action( 'admin_menu', [ $this, 'register_page' ] );
		add_action( 'admin_post_agent_bridge_save', [ $this, 'handle_post' ] );
	}

	public function register_page(): void {
		add_management_page(
			__( 'Agent Bridge', 'agent-bridge' ),
			__( 'Agent Bridge', 'agent-bridge' ),
			'manage_options',
			self::SLUG,
			[ $this, 'render' ]
		);
	}

	public function handle_post(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'agent-bridge' ), 403 );
		}

		check_admin_referer( self::NONCE );

		$action = isset( $_POST['bridge_action'] ) ? sanitize_key( wp_unslash( $_POST['bridge_action'] ) ) : '';
		$notice = '';

		switch ( $action ) {
			case 'rotate':
				$secret = Auth::rotate_secret();
				// Passed through a one-shot transient rather than the URL: a
				// query string lands in server logs, browser history and the
				// referrer of every asset the next page loads.
				set_transient( 'agent_bridge_fresh_secret_' . get_current_user_id(), $secret, 120 );
				$notice = 'rotated';
				break;

			case 'revoke':
				Auth::revoke_secret();
				$notice = 'revoked';
				break;

			case 'save':
				$managed = isset( $_POST['managed'] ) ? (array) wp_unslash( $_POST['managed'] ) : [];
				$managed = array_values( array_filter( array_map( 'sanitize_key', $managed ) ) );
				update_option( OPT_MANAGED, $managed, false );

				$ips_raw = isset( $_POST['allowed_ips'] ) ? sanitize_textarea_field( wp_unslash( $_POST['allowed_ips'] ) ) : '';
				$ips     = array_values(
					array_filter(
						array_map( 'trim', preg_split( '/[\r\n,]+/', $ips_raw ) ?: [] ),
						static fn( $ip ) => (bool) filter_var( $ip, FILTER_VALIDATE_IP )
					)
				);
				update_option( OPT_ALLOW_IPS, $ips, false );

				$max = isset( $_POST['max_bytes'] ) ? absint( wp_unslash( $_POST['max_bytes'] ) ) : 0;
				update_option( OPT_MAXBYTES, $max > 0 ? min( $max, 20 * 1024 * 1024 ) : 2 * 1024 * 1024, false );

				Audit::log( 'settings.save', null, [ 'message' => 'managed: ' . implode( ', ', $managed ) ] );
				$notice = 'saved';
				break;

			case 'purge_backups':
				Backup::purge_all();
				Audit::log( 'backups.purge', null, [ 'message' => 'all backups deleted' ] );
				$notice = 'purged';
				break;
		}

		wp_safe_redirect( add_query_arg( 'bridge_notice', $notice, admin_url( 'tools.php?page=' . self::SLUG ) ) );
		exit;
	}

	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$key    = 'agent_bridge_fresh_secret_' . get_current_user_id();
		$secret = get_transient( $key );
		if ( $secret ) {
			$this->fresh_secret = (string) $secret;
			delete_transient( $key );
		}

		$notice  = isset( $_GET['bridge_notice'] ) ? sanitize_key( wp_unslash( $_GET['bridge_notice'] ) ) : '';
		$managed = Paths::managed();
		$ips     = (array) get_option( OPT_ALLOW_IPS, [] );

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Agent Bridge', 'agent-bridge' ); ?></h1>

			<?php $this->render_notice( $notice ); ?>
			<?php $this->render_secret_panel(); ?>

			<h2><?php esc_html_e( 'Status', 'agent-bridge' ); ?></h2>
			<table class="widefat striped" style="max-width:52rem">
				<tbody>
					<?php
					$this->row( __( 'Plugin version', 'agent-bridge' ), VERSION );
					$this->row( __( 'REST base', 'agent-bridge' ), esc_url_raw( rest_url( REST_NS ) ) );
					$this->row(
						__( 'Writing', 'agent-bridge' ),
						is_readonly()
							? __( 'Disabled — AGENT_BRIDGE_READONLY is set in wp-config.php', 'agent-bridge' )
							: __( 'Enabled', 'agent-bridge' )
					);
					$this->row(
						__( 'Bridge secret', 'agent-bridge' ),
						Auth::has_secret() ? __( 'Set', 'agent-bridge' ) : __( 'Not set — the API refuses every request', 'agent-bridge' )
					);
					$this->row(
						__( 'Syntax checker', 'agent-bridge' ),
						'exec' === Lint::method()
							? __( 'php -l (exec available)', 'agent-bridge' )
							: __( 'tokenizer fallback (exec unavailable)', 'agent-bridge' )
					);
					$this->row(
						__( 'Audit table', 'agent-bridge' ),
						Audit::table_exists() ? __( 'Ready', 'agent-bridge' ) : __( 'Missing — deactivate and reactivate the plugin', 'agent-bridge' )
					);
					$this->row( __( 'Managed plugins', 'agent-bridge' ), $managed ? implode( ', ', $managed ) : __( 'None — the bridge can read and write nothing', 'agent-bridge' ) );
					$this->row( __( 'Backups', 'agent-bridge' ), (string) count( Backup::listing( null, 1000 ) ) );
					?>
				</tbody>
			</table>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( self::NONCE ); ?>
				<input type="hidden" name="action" value="agent_bridge_save">
				<input type="hidden" name="bridge_action" value="save">

				<h2><?php esc_html_e( 'Plugins the bridge may read and write', 'agent-bridge' ); ?></h2>
				<p class="description" style="max-width:52rem">
					<?php esc_html_e( 'Anything not ticked here is invisible to the API, including WordPress core and every theme. Tick only the plugins you are actively developing.', 'agent-bridge' ); ?>
				</p>

				<table class="widefat striped" style="max-width:52rem">
					<thead><tr>
						<th style="width:3rem"></th>
						<th><?php esc_html_e( 'Plugin', 'agent-bridge' ); ?></th>
						<th><?php esc_html_e( 'Folder', 'agent-bridge' ); ?></th>
						<th><?php esc_html_e( 'Active', 'agent-bridge' ); ?></th>
					</tr></thead>
					<tbody>
					<?php foreach ( get_plugins() as $file => $data ) : ?>
						<?php
						$slug = str_contains( $file, '/' ) ? dirname( $file ) : $file;
						$self = plugin_basename( \AgentBridge\FILE ) === $file;
						?>
						<tr>
							<td>
								<input type="checkbox" name="managed[]"
									value="<?php echo esc_attr( $slug ); ?>"
									<?php checked( in_array( $slug, $managed, true ) ); ?>
									<?php disabled( $self ); ?>>
							</td>
							<td>
								<strong><?php echo esc_html( (string) ( $data['Name'] ?? $slug ) ); ?></strong>
								<?php if ( $self ) : ?>
									<em>— <?php esc_html_e( 'the bridge itself; not editable through itself', 'agent-bridge' ); ?></em>
								<?php endif; ?>
							</td>
							<td><code><?php echo esc_html( $slug ); ?></code></td>
							<td><?php echo is_plugin_active( $file ) ? esc_html__( 'Yes', 'agent-bridge' ) : '—'; ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>

				<h2><?php esc_html_e( 'Restrictions', 'agent-bridge' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="allowed_ips"><?php esc_html_e( 'IP allowlist', 'agent-bridge' ); ?></label></th>
						<td>
							<textarea id="allowed_ips" name="allowed_ips" rows="3" class="large-text code"
								placeholder="203.0.113.8"><?php echo esc_textarea( implode( "\n", $ips ) ); ?></textarea>
							<p class="description"><?php esc_html_e( 'One address per line. Leave empty to allow any address. Only the direct connecting address is checked; forwarded headers are recorded but never trusted.', 'agent-bridge' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="max_bytes"><?php esc_html_e( 'Maximum file size', 'agent-bridge' ); ?></label></th>
						<td>
							<input type="number" id="max_bytes" name="max_bytes" min="1024" step="1024"
								value="<?php echo esc_attr( (string) get_option( OPT_MAXBYTES, 2097152 ) ); ?>" class="regular-text">
							<p class="description"><?php esc_html_e( 'Bytes. Applies to both reads and writes.', 'agent-bridge' ); ?></p>
						</td>
					</tr>
				</table>

				<?php submit_button( __( 'Save settings', 'agent-bridge' ) ); ?>
			</form>

			<?php $this->render_audit(); ?>
		</div>
		<?php
	}

	private function render_secret_panel(): void {
		?>
		<div class="card" style="max-width:52rem;padding:1rem 1.25rem">
			<h2 style="margin-top:0"><?php esc_html_e( 'Bridge secret', 'agent-bridge' ); ?></h2>

			<?php if ( '' !== $this->fresh_secret ) : ?>
				<div class="notice notice-success inline" style="margin:0 0 1rem">
					<p><strong><?php esc_html_e( 'Copy this now — it is not stored and cannot be shown again.', 'agent-bridge' ); ?></strong></p>
					<p><input type="text" readonly class="large-text code"
						value="<?php echo esc_attr( $this->fresh_secret ); ?>"
						onfocus="this.select()"></p>
				</div>
			<?php endif; ?>

			<p class="description">
				<?php esc_html_e( 'Sent by the client as an X-Bridge-Secret header, alongside an application password belonging to an administrator. Both are required: an application password on its own opens nothing here.', 'agent-bridge' ); ?>
			</p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
				<?php wp_nonce_field( self::NONCE ); ?>
				<input type="hidden" name="action" value="agent_bridge_save">
				<input type="hidden" name="bridge_action" value="rotate">
				<?php submit_button( Auth::has_secret() ? __( 'Rotate secret', 'agent-bridge' ) : __( 'Generate secret', 'agent-bridge' ), 'primary', 'submit', false ); ?>
			</form>

			<?php if ( Auth::has_secret() ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;margin-left:.5rem">
					<?php wp_nonce_field( self::NONCE ); ?>
					<input type="hidden" name="action" value="agent_bridge_save">
					<input type="hidden" name="bridge_action" value="revoke">
					<?php submit_button( __( 'Revoke', 'agent-bridge' ), 'delete', 'submit', false ); ?>
				</form>
			<?php endif; ?>
		</div>
		<?php
	}

	private function render_audit(): void {
		$entries = Audit::recent( 25 );
		?>
		<h2><?php esc_html_e( 'Recent activity', 'agent-bridge' ); ?></h2>
		<?php if ( ! $entries ) : ?>
			<p><?php esc_html_e( 'Nothing yet.', 'agent-bridge' ); ?></p>
		<?php else : ?>
			<table class="widefat striped" style="max-width:72rem">
				<thead><tr>
					<th><?php esc_html_e( 'When (UTC)', 'agent-bridge' ); ?></th>
					<th><?php esc_html_e( 'Who', 'agent-bridge' ); ?></th>
					<th><?php esc_html_e( 'IP', 'agent-bridge' ); ?></th>
					<th><?php esc_html_e( 'Action', 'agent-bridge' ); ?></th>
					<th><?php esc_html_e( 'Target', 'agent-bridge' ); ?></th>
					<th><?php esc_html_e( 'Result', 'agent-bridge' ); ?></th>
					<th><?php esc_html_e( 'Note', 'agent-bridge' ); ?></th>
				</tr></thead>
				<tbody>
				<?php foreach ( $entries as $entry ) : ?>
					<tr>
						<td><?php echo esc_html( (string) $entry['created_at'] ); ?></td>
						<td><?php echo esc_html( (string) $entry['user_login'] ); ?></td>
						<td><code><?php echo esc_html( (string) $entry['ip'] ); ?></code></td>
						<td><code><?php echo esc_html( (string) $entry['action'] ); ?></code></td>
						<td><code><?php echo esc_html( (string) ( $entry['target'] ?? '' ) ); ?></code></td>
						<td><?php echo esc_html( (string) $entry['result'] ); ?></td>
						<td><?php echo esc_html( (string) ( $entry['message'] ?? '' ) ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:1rem">
			<?php wp_nonce_field( self::NONCE ); ?>
			<input type="hidden" name="action" value="agent_bridge_save">
			<input type="hidden" name="bridge_action" value="purge_backups">
			<?php submit_button( __( 'Delete all backups', 'agent-bridge' ), 'secondary', 'submit', false ); ?>
			<span class="description" style="margin-left:.5rem">
				<?php esc_html_e( 'Backups hold copies of plugin source; clear them when you are done developing.', 'agent-bridge' ); ?>
			</span>
		</form>
		<?php
	}

	private function row( string $label, string $value ): void {
		printf(
			'<tr><td style="width:16rem"><strong>%s</strong></td><td><code>%s</code></td></tr>',
			esc_html( $label ),
			esc_html( $value )
		);
	}

	private function render_notice( string $notice ): void {
		$messages = [
			'rotated' => __( 'A new bridge secret was generated.', 'agent-bridge' ),
			'revoked' => __( 'The bridge secret was revoked. The API now refuses every request.', 'agent-bridge' ),
			'saved'   => __( 'Settings saved.', 'agent-bridge' ),
			'purged'  => __( 'All backups were deleted.', 'agent-bridge' ),
		];

		if ( ! isset( $messages[ $notice ] ) ) {
			return;
		}

		printf(
			'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
			esc_html( $messages[ $notice ] )
		);
	}
}
