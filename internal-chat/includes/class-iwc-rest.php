<?php
/**
 * REST API a chat ablak számára (csak bejelentkezett felhasználóknak).
 */

defined( 'ABSPATH' ) || exit;

class IWC_Rest {

	const NS = 'iwc/v1';

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	/** A /poll és a /stream közös paraméterei. */
	public static function state_args() {
		return array(
			'context' => array(
				'type'    => 'string',
				'enum'    => array( 'front', 'admin' ),
				'default' => 'front',
			),
			'room'    => array(
				'type'    => 'integer',
				'default' => 0,
			),
			'after'   => array(
				'type'    => 'integer',
				'default' => 0,
			),
			'full'    => array(
				'type'    => 'boolean',
				'default' => false,
			),
		);
	}

	public static function routes() {
		register_rest_route(
			self::NS,
			'/poll',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'poll' ),
				'permission_callback' => 'is_user_logged_in',
				'args'                => self::state_args(),
			)
		);

		register_rest_route(
			self::NS,
			'/stream',
			array(
				'methods'             => 'GET',
				'callback'            => array( 'IWC_Stream', 'serve' ),
				'permission_callback' => 'is_user_logged_in',
				'args'                => self::state_args(),
			)
		);

		register_rest_route(
			self::NS,
			'/rooms/(?P<id>\d+)/messages',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( __CLASS__, 'history' ),
					'permission_callback' => 'is_user_logged_in',
					'args'                => array(
						'before' => array(
							'type'    => 'integer',
							'default' => 0,
						),
						'limit'  => array(
							'type'    => 'integer',
							'default' => 30,
						),
					),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( __CLASS__, 'send' ),
					'permission_callback' => 'is_user_logged_in',
					'args'                => array(
						'message' => array(
							'type'     => 'string',
							'required' => true,
						),
					),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/rooms/(?P<id>\d+)/read',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'read' ),
				'permission_callback' => 'is_user_logged_in',
				'args'                => array(
					'last_id' => array(
						'type'     => 'integer',
						'required' => true,
					),
				),
			)
		);
	}

	/**
	 * @return object|WP_Error
	 */
	private static function room_for_request( WP_REST_Request $request ) {
		$room = IWC_Rooms::get( (int) $request['id'] );
		if ( ! IWC_Rooms::user_can_access( $room, get_current_user_id() ) ) {
			return new WP_Error( 'iwc_forbidden', __( 'Nincs hozzáférésed ehhez a szobához.', 'internal-chat' ), array( 'status' => 403 ) );
		}
		return $room;
	}

	private static function format_rows( array $rows, $user_id ) {
		$out = array();
		foreach ( $rows as $row ) {
			$out[] = IWC_Messages::format( $row, $user_id );
		}
		return $out;
	}

	public static function poll( WP_REST_Request $request ) {
		return rest_ensure_response(
			self::state( get_current_user_id(), $request['context'], (int) $request['room'], (int) $request['after'], (bool) $request['full'] )
		);
	}

	/**
	 * Szobalista olvasatlan számlálókkal + az aktuális szoba új üzenetei.
	 *
	 * @param bool $full Teljes betöltés (első megnyitás / szobaváltás): a szoba legutóbbi üzenetei.
	 *                   Ha a kért szoba nem elérhető, a válasz mindig teljes (full = true).
	 */
	public static function state( $user_id, $context, $requested, $after, $full ) {
		$rooms = IWC_Rooms::for_user( $user_id, $context );

		$current   = null;
		$fallback  = $rooms ? $rooms[0] : null;
		$newest    = 0;
		$room_data = array();
		foreach ( $rooms as $room ) {
			$unread = IWC_Messages::unread( $room, $user_id );
			if ( $unread['latest_id'] > $newest ) {
				// Ha nincs (érvényes) kiválasztott szoba, a legfrissebb olvasatlan üzenet szobája nyílik meg.
				$newest   = $unread['latest_id'];
				$fallback = $room;
			}

			$room_data[] = array(
				'id'               => (int) $room->id,
				'name'             => $room->name,
				'logo'             => IWC_Rooms::logo_url( $room ),
				'unread'           => $unread['count'],
				'latest_unread_id' => $unread['latest_id'],
			);
			if ( (int) $room->id === $requested ) {
				$current = $room;
			}
		}
		if ( ! $current ) {
			$current = $fallback;
			$full    = true;
		}

		$data = array(
			'rooms'     => $room_data,
			'room'      => $current ? (int) $current->id : 0,
			'full'      => $full,
			'messages'  => array(),
			'last_read' => 0,
			'has_more'  => false,
			'nonce'     => wp_create_nonce( 'wp_rest' ),
		);

		if ( $current ) {
			if ( $full ) {
				list( $rows, $data['has_more'] ) = IWC_Messages::page( $current, 50 );
			} else {
				$rows = IWC_Messages::after( $current, $after );
			}
			$data['messages']  = self::format_rows( $rows, $user_id );
			$data['last_read'] = IWC_Messages::last_read( $user_id, $current->id );
		}

		return $data;
	}

	/** Régebbi üzenetek (felfelé görgetéskor). */
	public static function history( WP_REST_Request $request ) {
		$room = self::room_for_request( $request );
		if ( is_wp_error( $room ) ) {
			return $room;
		}
		list( $rows, $has_more ) = IWC_Messages::page( $room, (int) $request['limit'], (int) $request['before'] );
		return rest_ensure_response(
			array(
				'messages' => self::format_rows( $rows, get_current_user_id() ),
				'has_more' => $has_more,
			)
		);
	}

	public static function send( WP_REST_Request $request ) {
		$room = self::room_for_request( $request );
		if ( is_wp_error( $room ) ) {
			return $room;
		}

		// Az üzenet sima szövegként tárolódik, a kliens mindig szövegként (nem HTML-ként) jeleníti meg.
		$text = wp_check_invalid_utf8( (string) $request['message'], true );
		$text = str_replace( array( "\r\n", "\r" ), "\n", $text );
		$text = trim( preg_replace( '/[\x00-\x08\x0B-\x1F\x7F]/u', '', $text ) );
		if ( '' === $text ) {
			return new WP_Error( 'iwc_empty', __( 'Üres üzenet nem küldhető.', 'internal-chat' ), array( 'status' => 400 ) );
		}
		$text = mb_substr( $text, 0, IWC_Messages::MAX_LENGTH );

		$user_id = get_current_user_id();
		$row     = IWC_Messages::add( $room, $user_id, $text );
		if ( ! $row ) {
			return new WP_Error( 'iwc_db', __( 'Az üzenetet nem sikerült elmenteni.', 'internal-chat' ), array( 'status' => 500 ) );
		}
		return rest_ensure_response( IWC_Messages::format( $row, $user_id ) );
	}

	public static function read( WP_REST_Request $request ) {
		$room = self::room_for_request( $request );
		if ( is_wp_error( $room ) ) {
			return $room;
		}
		IWC_Messages::mark_read( get_current_user_id(), $room->id, (int) $request['last_id'] );
		return rest_ensure_response( array( 'ok' => true ) );
	}
}
