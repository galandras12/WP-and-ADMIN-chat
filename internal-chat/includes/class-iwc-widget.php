<?php
/**
 * A lebegő chat ablak betöltése a weboldalon és az admin felületen.
 */

defined( 'ABSPATH' ) || exit;

class IWC_Widget {

	public static function init() {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_front' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_admin' ) );
	}

	public static function enqueue_front() {
		self::enqueue( 'front' );
	}

	public static function enqueue_admin() {
		self::enqueue( 'admin' );
	}

	private static function enqueue( $context ) {
		if ( ! is_user_logged_in() || is_customize_preview() || ( defined( 'IFRAME_REQUEST' ) && IFRAME_REQUEST ) ) {
			return;
		}
		$user_id = get_current_user_id();
		// Ha a felhasználónak ebben a környezetben nincs szobája, nem töltünk be semmit.
		if ( ! IWC_Rooms::for_user( $user_id, $context ) ) {
			return;
		}

		wp_enqueue_style( 'iwc-chat', IWC_URL . 'assets/css/chat.css', array(), IWC_VERSION );
		wp_enqueue_script( 'iwc-chat', IWC_URL . 'assets/js/chat.js', array(), IWC_VERSION, true );
		wp_localize_script(
			'iwc-chat',
			'IWC_CONFIG',
			array(
				'restUrl' => esc_url_raw( rest_url( IWC_Rest::NS . '/' ) ),
				'nonce'   => wp_create_nonce( 'wp_rest' ),
				'context' => $context,
				'userId'  => $user_id,
				'poll'    => (int) apply_filters( 'iwc_poll_interval', 4000 ),
				'i18n'    => array(
					'placeholder' => __( 'Aa', 'internal-chat' ),
					'send'        => __( 'Küldés', 'internal-chat' ),
					'minimize'    => __( 'Kis méret', 'internal-chat' ),
					'close'       => __( 'Bezárás', 'internal-chat' ),
					'open'        => __( 'Belső chat megnyitása', 'internal-chat' ),
					'switchRoom'  => __( 'Szoba váltása', 'internal-chat' ),
					'empty'       => __( 'Még nincs üzenet ebben a szobában.', 'internal-chat' ),
					'loading'     => __( 'Betöltés…', 'internal-chat' ),
					'expired'     => __( 'A munkamenet lejárt, kérlek frissítsd az oldalt.', 'internal-chat' ),
					'sendError'   => __( 'Az üzenet elküldése nem sikerült.', 'internal-chat' ),
				),
			)
		);
	}
}
