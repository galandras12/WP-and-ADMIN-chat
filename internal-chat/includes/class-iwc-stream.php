<?php
/**
 * Valós idejű frissítés Server-Sent Events (SSE) segítségével.
 *
 * A kapcsolat legfeljebb IWC_Stream::lifetime() másodpercig él, utána a szerver
 * lezárja és a böngésző azonnal újranyitja. Így egy PHP folyamat sosem ragad be,
 * a jogosultságok és a nonce rendszeresen újraellenőrződnek.
 * A kapcsolat alatt a szerver másodpercenként egy olcsó "ujjlenyomatot" számol
 * (legújabb üzenet, szobák módosítása, saját olvasottság), és csak változáskor küld adatot.
 */

defined( 'ABSPATH' ) || exit;

class IWC_Stream {

	const OPTION = 'iwc_realtime';

	public static function enabled() {
		return '0' !== (string) get_option( self::OPTION, '1' );
	}

	/** A kapcsolat élettartama másodpercben. */
	public static function lifetime() {
		return max( 5, (int) apply_filters( 'iwc_stream_lifetime', 25 ) );
	}

	/** Két változás-ellenőrzés közti idő másodpercben. */
	public static function interval() {
		return max( 0.25, (float) apply_filters( 'iwc_stream_interval', 1 ) );
	}

	public static function serve( WP_REST_Request $request ) {
		if ( ! self::enabled() ) {
			return new WP_Error( 'iwc_stream_disabled', __( 'A valós idejű mód ki van kapcsolva.', 'internal-chat' ), array( 'status' => 404 ) );
		}

		$user_id  = get_current_user_id();
		$context  = $request['context'];
		$room     = (int) $request['room'];
		$after    = (int) $request['after'];
		$full     = (bool) $request['full'];
		$lifetime = self::lifetime();
		$interval = self::interval();

		self::prepare_output( $lifetime );

		$start     = microtime( true );
		$last_sent = $start;
		$signature = null;

		while ( true ) {
			$current = self::signature( $user_id, $context );
			if ( $current !== $signature ) {
				$signature = $current;
				$state     = IWC_Rest::state( $user_id, $context, $room, $after, $full );

				$room = $state['room'];
				$full = false;
				foreach ( $state['messages'] as $message ) {
					$after = max( $after, $message['id'] );
				}
				self::send( 'state', $state );
				$last_sent = microtime( true );
			} elseif ( microtime( true ) - $last_sent >= 5 ) {
				// Életjel: ebből derül ki, ha a böngésző már bezárta a kapcsolatot.
				echo ": ping\n\n";
				flush();
				$last_sent = microtime( true );
			}

			if ( connection_aborted() || microtime( true ) - $start >= $lifetime ) {
				break;
			}
			usleep( (int) ( $interval * 1000000 ) );
		}

		self::send( 'bye', array() );
		exit;
	}

	private static function prepare_output( $lifetime ) {
		if ( function_exists( 'session_status' ) && PHP_SESSION_ACTIVE === session_status() ) {
			session_write_close();
		}
		ignore_user_abort( false );
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( $lifetime + 15 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
		@ini_set( 'zlib.output_compression', '0' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		@ini_set( 'implicit_flush', '1' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}

		status_header( 200 );
		header( 'Content-Type: text/event-stream; charset=utf-8' );
		header( 'Cache-Control: no-cache, no-store, must-revalidate, no-transform' );
		header( 'X-Accel-Buffering: no' ); // nginx: ne pufferelje a választ.

		// Néhány proxy csak egy bizonyos méret felett kezd továbbítani.
		echo ':' . str_repeat( ' ', 2048 ) . "\n\n";
		echo "retry: 3000\n\n";
		flush();
	}

	private static function send( $event, $data ) {
		echo 'event: ' . $event . "\n";
		echo 'data: ' . wp_json_encode( $data ) . "\n\n";
		flush();
	}

	/**
	 * Olcsó ujjlenyomat: ha változik, új állapotot küldünk.
	 */
	private static function signature( $user_id, $context ) {
		global $wpdb;
		$rooms_table    = IWC_DB::table( 'rooms' );
		$messages_table = IWC_DB::table( 'messages' );
		$reads_table    = IWC_DB::table( 'reads' );

		$rooms  = $wpdb->get_row( "SELECT MAX(updated_at) AS u, COUNT(*) AS c FROM $rooms_table", ARRAY_N );
		$ids    = wp_list_pluck( IWC_Rooms::for_user( $user_id, $context ), 'id' );
		$latest = 0;
		if ( $ids ) {
			$ids    = implode( ',', array_map( 'intval', $ids ) );
			$latest = $wpdb->get_var( "SELECT MAX(id) FROM $messages_table WHERE room_id IN ($ids)" );
		}
		$read = $wpdb->get_row(
			$wpdb->prepare( "SELECT MAX(updated_at), SUM(last_read_id) FROM $reads_table WHERE user_id = %d", $user_id ),
			ARRAY_N
		);

		return md5( wp_json_encode( array( $rooms, $ids, $latest, $read ) ) );
	}
}
