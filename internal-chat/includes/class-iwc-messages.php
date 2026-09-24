<?php
/**
 * Üzenetek és olvasottsági állapot.
 *
 * Az előzmény-korlát (1–10 év) csak a visszaolvashatóságot szabályozza:
 * a régebbi üzenetek nem törlődnek, így a korlát később bővíthető.
 */

defined( 'ABSPATH' ) || exit;

class IWC_Messages {

	const MAX_LENGTH = 5000;

	public static function add( $room, $user_id, $text ) {
		global $wpdb;
		$user = get_userdata( $user_id );
		$wpdb->insert(
			IWC_DB::table( 'messages' ),
			array(
				'room_id'    => (int) $room->id,
				'user_id'    => (int) $user_id,
				'user_name'  => $user ? $user->display_name : '',
				'message'    => $text,
				'created_at' => current_time( 'mysql', true ),
			)
		);
		$id = (int) $wpdb->insert_id;
		if ( $id ) {
			self::mark_read( $user_id, $room->id, $id );
		}
		return $id ? self::get( $id ) : null;
	}

	public static function get( $id ) {
		global $wpdb;
		$table = IWC_DB::table( 'messages' );
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id = %d", $id ) );
	}

	/**
	 * A legutóbbi üzenetek, vagy ha $before_id meg van adva, az az előttiek.
	 *
	 * @return array{0: array, 1: bool} [ növekvő sorrendű sorok, van-e még régebbi ]
	 */
	public static function page( $room, $limit = 50, $before_id = 0 ) {
		global $wpdb;
		$table = IWC_DB::table( 'messages' );
		$limit = max( 1, min( 100, (int) $limit ) );

		$sql  = "SELECT * FROM $table WHERE room_id = %d AND created_at >= %s";
		$args = array( (int) $room->id, IWC_Rooms::cutoff( $room ) );
		if ( $before_id > 0 ) {
			$sql   .= ' AND id < %d';
			$args[] = (int) $before_id;
		}
		$sql   .= ' ORDER BY id DESC LIMIT %d';
		$args[] = $limit + 1;

		$rows     = $wpdb->get_results( $wpdb->prepare( $sql, $args ) );
		$has_more = count( $rows ) > $limit;
		if ( $has_more ) {
			array_pop( $rows );
		}
		return array( array_reverse( $rows ), $has_more );
	}

	public static function after( $room, $after_id, $limit = 200 ) {
		global $wpdb;
		$table = IWC_DB::table( 'messages' );
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM $table WHERE room_id = %d AND id > %d AND created_at >= %s ORDER BY id ASC LIMIT %d",
				(int) $room->id,
				(int) $after_id,
				IWC_Rooms::cutoff( $room ),
				(int) $limit
			)
		);
	}

	public static function count( $room_id ) {
		global $wpdb;
		$table = IWC_DB::table( 'messages' );
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $table WHERE room_id = %d", $room_id ) );
	}

	public static function last_read( $user_id, $room_id ) {
		global $wpdb;
		$table = IWC_DB::table( 'reads' );
		return (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT last_read_id FROM $table WHERE user_id = %d AND room_id = %d", $user_id, $room_id )
		);
	}

	/**
	 * Olvasottnak jelöl mindent $message_id-ig. Visszafelé soha nem léptet.
	 */
	public static function mark_read( $user_id, $room_id, $message_id ) {
		global $wpdb;
		$table = IWC_DB::table( 'reads' );
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO $table (user_id, room_id, last_read_id, updated_at) VALUES (%d, %d, %d, %s)
				ON DUPLICATE KEY UPDATE last_read_id = GREATEST(last_read_id, VALUES(last_read_id)), updated_at = VALUES(updated_at)",
				$user_id,
				$room_id,
				$message_id,
				current_time( 'mysql', true )
			)
		);
	}

	/**
	 * Mások olvasatlan üzenetei a szobában.
	 *
	 * @return array{count: int, latest_id: int}
	 */
	public static function unread( $room, $user_id ) {
		global $wpdb;
		$table = IWC_DB::table( 'messages' );
		$row   = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT COUNT(*) AS c, MAX(id) AS m FROM $table WHERE room_id = %d AND id > %d AND user_id <> %d AND created_at >= %s",
				(int) $room->id,
				self::last_read( $user_id, $room->id ),
				(int) $user_id,
				IWC_Rooms::cutoff( $room )
			)
		);
		return array(
			'count'     => $row ? (int) $row->c : 0,
			'latest_id' => $row && $row->m ? (int) $row->m : 0,
		);
	}

	/**
	 * Egy üzenet a kliens számára.
	 */
	public static function format( $row, $current_user_id ) {
		static $users = array();

		$uid = (int) $row->user_id;
		if ( ! isset( $users[ $uid ] ) ) {
			$user          = get_userdata( $uid );
			$users[ $uid ] = array(
				// Ha a felhasználót időközben törölték, a mentett név marad meg.
				'name'   => $user ? $user->display_name : '',
				'avatar' => get_avatar_url( $uid, array( 'size' => 64 ) ),
			);
		}

		$timestamp = strtotime( $row->created_at . ' UTC' );

		return array(
			'id'     => (int) $row->id,
			'user'   => $uid,
			'name'   => '' !== $users[ $uid ]['name'] ? $users[ $uid ]['name'] : $row->user_name,
			'avatar' => $users[ $uid ]['avatar'],
			'time'   => wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $timestamp ),
			'iso'    => gmdate( 'c', $timestamp ),
			'text'   => $row->message,
			'mine'   => $uid === (int) $current_user_id,
		);
	}
}
