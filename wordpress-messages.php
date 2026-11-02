<?php
/**
 * Plugin Name: WordPress Messages
 * Description: Üyelerin birbirlerine erişilebilir bir arayüz üzerinden özel mesaj göndermesini sağlar.
 * Version: 1.0.0
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * Author: WordPress Messages
 * Text Domain: wordpress-messages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WORDPRESS_MESSAGES_VERSION', '1.0.0' );

/**
 * Return the messages table name.
 *
 * @return string
 */
function wordpress_messages_table_name() {
	global $wpdb;

	return $wpdb->prefix . 'wordpress_messages';
}

/**
 * Create or update the messages table.
 *
 * @return void
 */
function wordpress_messages_create_table() {
	global $wpdb;

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';

	$table_name      = wordpress_messages_table_name();
	$charset_collate = $wpdb->get_charset_collate();
	$sql             = "CREATE TABLE $table_name (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		sender_id bigint(20) unsigned NOT NULL,
		recipient_id bigint(20) unsigned NOT NULL,
		message text NOT NULL,
		sent_at datetime NOT NULL,
		PRIMARY KEY  (id),
		KEY recipient_id (recipient_id),
		KEY sender_id (sender_id)
	) $charset_collate;";

	dbDelta( $sql );
	update_option( 'wordpress_messages_version', WORDPRESS_MESSAGES_VERSION );
}

register_activation_hook( __FILE__, 'wordpress_messages_create_table' );
add_action( 'plugins_loaded', 'wordpress_messages_maybe_upgrade' );

/**
 * Upgrade the database when the plugin version changes.
 *
 * @return void
 */
function wordpress_messages_maybe_upgrade() {
	if ( WORDPRESS_MESSAGES_VERSION !== get_option( 'wordpress_messages_version' ) ) {
		wordpress_messages_create_table();
	}
}

add_action( 'admin_init', 'wordpress_messages_register_settings' );

/**
 * Register plugin settings.
 *
 * @return void
 */
function wordpress_messages_register_settings() {
	register_setting(
		'wordpress_messages_settings',
		'wordpress_messages_enabled',
		array(
			'type'              => 'string',
			'default'           => '1',
			'sanitize_callback' => 'wordpress_messages_sanitize_enabled',
		)
	);

	add_settings_section(
		'wordpress_messages_general',
		'Genel ayarlar',
		'__return_false',
		'wordpress-messages'
	);

	add_settings_field(
		'wordpress_messages_enabled',
		'Mesajlaşma',
		'wordpress_messages_enabled_field',
		'wordpress-messages',
		'wordpress_messages_general'
	);
}

/**
 * Sanitize the enabled setting.
 *
 * @param mixed $value Submitted setting value.
 * @return string
 */
function wordpress_messages_sanitize_enabled( $value ) {
	return in_array( (string) $value, array( '1', 'true', 'on' ), true ) ? '1' : '0';
}

/**
 * Render the enabled setting checkbox.
 *
 * @return void
 */
function wordpress_messages_enabled_field() {
	$enabled = '1' === get_option( 'wordpress_messages_enabled', '1' );
	?>
	<input type="hidden" name="wordpress_messages_enabled" value="0">
	<label for="wordpress_messages_enabled">
		<input
			type="checkbox"
			id="wordpress_messages_enabled"
			name="wordpress_messages_enabled"
			value="1"
			<?php checked( $enabled ); ?>
		>
		Üyeler arasındaki mesajlaşmayı etkinleştir
	</label>
	<?php
}

add_action( 'admin_menu', 'wordpress_messages_add_settings_page' );

/**
 * Add the plugin settings page.
 *
 * @return void
 */
function wordpress_messages_add_settings_page() {
	add_options_page(
		'WordPress Messages',
		'WordPress Messages',
		'manage_options',
		'wordpress-messages',
		'wordpress_messages_render_settings_page'
	);
}

/**
 * Render the plugin settings page.
 *
 * @return void
 */
function wordpress_messages_render_settings_page() {
	?>
	<div class="wrap">
		<h1>WordPress Messages</h1>
		<form action="options.php" method="post">
			<?php
			settings_fields( 'wordpress_messages_settings' );
			do_settings_sections( 'wordpress-messages' );
			submit_button();
			?>
		</form>
		<p>Mesajlaşma sayfası oluşturmak için içerik alanına <code>[wordpress_messages]</code> kısa kodunu ekleyin.</p>
	</div>
	<?php
}

add_action( 'admin_post_wordpress_messages_send', 'wordpress_messages_send' );

/**
 * Process a message submission from a logged-in member.
 *
 * @return void
 */
function wordpress_messages_send() {
	if ( ! isset( $_SERVER['REQUEST_METHOD'] ) || 'POST' !== strtoupper( $_SERVER['REQUEST_METHOD'] ) ) {
		wp_die( esc_html__( 'Geçersiz istek yöntemi.', 'wordpress-messages' ), '', array( 'response' => 405 ) );
	}

	if ( ! is_user_logged_in() ) {
		wp_die( esc_html__( 'Mesaj göndermek için giriş yapmalısınız.', 'wordpress-messages' ), '', array( 'response' => 403 ) );
	}

	check_admin_referer( 'wordpress_messages_send' );

	$redirect_url = wp_get_referer();
	if ( ! $redirect_url ) {
		$redirect_url = home_url( '/' );
	}

	if ( '1' !== get_option( 'wordpress_messages_enabled', '1' ) ) {
		wordpress_messages_redirect_with_status( $redirect_url, 'disabled' );
	}

	$recipient_login = isset( $_POST['recipient_login'] ) ? sanitize_user( wp_unslash( $_POST['recipient_login'] ) ) : '';
	$message         = isset( $_POST['message'] ) ? sanitize_textarea_field( wp_unslash( $_POST['message'] ) ) : '';
	$recipient       = $recipient_login ? get_user_by( 'login', $recipient_login ) : false;
	$message_length  = preg_match_all( '/./us', $message );

	if ( ! $recipient || (int) $recipient->ID === get_current_user_id() || '' === trim( $message ) || false === $message_length || $message_length > 5000 ) {
		wordpress_messages_redirect_with_status( $redirect_url, 'invalid' );
	}

	global $wpdb;
	$inserted = $wpdb->insert(
		wordpress_messages_table_name(),
		array(
			'sender_id'    => get_current_user_id(),
			'recipient_id' => (int) $recipient->ID,
			'message'      => $message,
			'sent_at'      => current_time( 'mysql' ),
		),
		array( '%d', '%d', '%s', '%s' )
	);

	wordpress_messages_redirect_with_status( $redirect_url, $inserted ? 'sent' : 'error' );
}

/**
 * Redirect back to the messaging page with a status message.
 *
 * @param string $url    URL to return to.
 * @param string $status Status key.
 * @return void
 */
function wordpress_messages_redirect_with_status( $url, $status ) {
	wp_safe_redirect( add_query_arg( 'wordpress_messages_status', $status, $url ) );
	exit;
}

add_shortcode( 'wordpress_messages', 'wordpress_messages_render_shortcode' );

/**
 * Render the member inbox and compose form.
 *
 * @return string
 */
function wordpress_messages_render_shortcode() {
	if ( ! is_user_logged_in() ) {
		return '<p>' . esc_html__( 'Mesajlarınızı görüntülemek ve mesaj göndermek için giriş yapmalısınız.', 'wordpress-messages' ) . '</p>';
	}

	if ( '1' !== get_option( 'wordpress_messages_enabled', '1' ) ) {
		return '<p>' . esc_html__( 'Mesajlaşma şu anda devre dışı.', 'wordpress-messages' ) . '</p>';
	}

	$recipient    = false;
	$recipient_id = isset( $_GET['wordpress_messages_recipient'] ) ? absint( wp_unslash( $_GET['wordpress_messages_recipient'] ) ) : 0;
	if ( $recipient_id && $recipient_id !== get_current_user_id() ) {
		$recipient = get_userdata( $recipient_id );
	}

	$messages    = wordpress_messages_get_user_messages( get_current_user_id() );
	$status      = isset( $_GET['wordpress_messages_status'] ) ? sanitize_key( wp_unslash( $_GET['wordpress_messages_status'] ) ) : '';
	$instance_id = wp_unique_id( 'wordpress-messages-' );
	$notices     = array(
		'sent'     => 'Mesajınız gönderildi.',
		'invalid'  => 'Alıcıyı ve mesajı kontrol edip tekrar deneyin. Kendinize mesaj gönderemezsiniz.',
		'error'    => 'Mesaj gönderilemedi. Lütfen tekrar deneyin.',
		'disabled' => 'Mesajlaşma şu anda devre dışı.',
	);

	ob_start();
	?>
	<div class="wordpress-messages">
		<h2><?php echo esc_html__( 'Mesajlarım', 'wordpress-messages' ); ?></h2>
		<?php if ( isset( $notices[ $status ] ) ) : ?>
			<p role="status"><?php echo esc_html( $notices[ $status ] ); ?></p>
		<?php endif; ?>

		<section aria-labelledby="<?php echo esc_attr( $instance_id . '-compose-heading' ); ?>">
			<h3 id="<?php echo esc_attr( $instance_id . '-compose-heading' ); ?>"><?php echo esc_html__( 'Yeni mesaj', 'wordpress-messages' ); ?></h3>
			<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
				<input type="hidden" name="action" value="wordpress_messages_send">
				<?php wp_nonce_field( 'wordpress_messages_send' ); ?>
				<p>
					<label for="<?php echo esc_attr( $instance_id . '-recipient' ); ?>"><?php echo esc_html__( 'Alıcının kullanıcı adı', 'wordpress-messages' ); ?></label>
					<input
						type="text"
						id="<?php echo esc_attr( $instance_id . '-recipient' ); ?>"
						name="recipient_login"
						value="<?php echo $recipient ? esc_attr( $recipient->user_login ) : ''; ?>"
						autocomplete="off"
						required
					>
				</p>
				<p>
					<label for="<?php echo esc_attr( $instance_id . '-message' ); ?>"><?php echo esc_html__( 'Mesajınız', 'wordpress-messages' ); ?></label>
					<textarea id="<?php echo esc_attr( $instance_id . '-message' ); ?>" name="message" rows="5" maxlength="5000" required></textarea>
				</p>
				<?php submit_button( esc_html__( 'Mesajı gönder', 'wordpress-messages' ) ); ?>
			</form>
		</section>

		<section aria-labelledby="<?php echo esc_attr( $instance_id . '-list-heading' ); ?>">
			<h3 id="<?php echo esc_attr( $instance_id . '-list-heading' ); ?>"><?php echo esc_html__( 'Gelen ve gönderilen mesajlar', 'wordpress-messages' ); ?></h3>
			<?php if ( empty( $messages ) ) : ?>
				<p><?php echo esc_html__( 'Henüz mesajınız yok.', 'wordpress-messages' ); ?></p>
			<?php else : ?>
				<ul>
					<?php foreach ( $messages as $message ) : ?>
						<?php
						$sender    = get_userdata( (int) $message->sender_id );
						$recipient = get_userdata( (int) $message->recipient_id );
						if ( ! $sender || ! $recipient ) {
							continue;
						}
						$is_incoming = (int) $message->recipient_id === get_current_user_id();
						$other_user  = $is_incoming ? $sender : $recipient;
						?>
						<li>
							<article>
								<p>
									<strong><?php echo esc_html__( 'Gönderen:', 'wordpress-messages' ); ?></strong>
									<?php echo esc_html( $sender->display_name ); ?>
									<strong><?php echo esc_html__( 'Alıcı:', 'wordpress-messages' ); ?></strong>
									<?php echo esc_html( $recipient->display_name ); ?>
								</p>
								<p>
									<time datetime="<?php echo esc_attr( mysql2date( 'c', $message->sent_at ) ); ?>">
										<?php echo esc_html( mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $message->sent_at ) ); ?>
									</time>
								</p>
								<p><?php echo nl2br( esc_html( $message->message ) ); ?></p>
								<?php if ( $is_incoming ) : ?>
									<a href="<?php echo esc_url( add_query_arg( 'wordpress_messages_recipient', (int) $other_user->ID, get_permalink() ) ); ?>">
										<?php echo esc_html( sprintf( __( '%s kullanıcısına yanıtla', 'wordpress-messages' ), $other_user->display_name ) ); ?>
									</a>
								<?php endif; ?>
							</article>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</section>
	</div>
	<?php

	return ob_get_clean();
}

/**
 * Fetch a user's recent incoming and outgoing messages.
 *
 * @param int $user_id User ID.
 * @return array
 */
function wordpress_messages_get_user_messages( $user_id ) {
	global $wpdb;

	$table_name = wordpress_messages_table_name();
	$query      = $wpdb->prepare(
		"SELECT id, sender_id, recipient_id, message, sent_at
		FROM $table_name
		WHERE sender_id = %d OR recipient_id = %d
		ORDER BY sent_at DESC, id DESC
		LIMIT 50",
		$user_id,
		$user_id
	);

	return $wpdb->get_results( $query );
}
