<?php
/**
 * Chat szobák: tárolás, jogosultság, láthatóság.
 */

defined( 'ABSPATH' ) || exit;

class IWC_Rooms {

	/** Az előzmények visszaolvashatósága években (legördülő menü értékei). */
	const RETENTION_CHOICES = array( 1, 2, 3, 4, 5, 10 );

	/** A kérésben elsőként felsorolt szerepkörök, ebben a sorrendben. */
	const PRIMARY_ROLES = array( 'subscriber', 'contributor', 'author', 'editor' );

	const VISIBILITY_ALL   = 'all';
	const VISIBILITY_ADMIN = 'admin';

	const STATUS_ACTIVE   = 'active';
	const STATUS_ARCHIVED = 'archived';

	/**
	 * A szobához rendelhető szerepkörök (az adminisztrátor nélkül, mert ő mindig hozzáfér).
	 *
	 * @return array role_key => fordított név
	 */
	public static function assignable_roles() {
		$names = wp_roles()->get_names();
		unset( $names['administrator'] );

		$out = array();
		foreach ( self::PRIMARY_ROLES as $key ) {
			if ( isset( $names[ $key ] ) ) {
				$out[ $key ] = translate_user_role( $names[ $key ] );
				unset( $names[ $key ] );
			}
		}
		foreach ( $names as $key => $name ) {
			$out[ $key ] = translate_user_role( $name );
		}
		return $out;
	}

	public static function get( $id ) {
		global $wpdb;
		$table = IWC_DB::table( 'rooms' );
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id = %d", $id ) );
	}

	/**
	 * @param string|null $status Szűrés állapotra, null = mind.
	 */
	public static function all( $status = null ) {
		global $wpdb;
		$table = IWC_DB::table( 'rooms' );
		if ( $status ) {
			return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $table WHERE status = %s ORDER BY name ASC, id ASC", $status ) );
		}
		return $wpdb->get_results( "SELECT * FROM $table ORDER BY status ASC, name ASC, id ASC" );
	}

	/**
	 * Admin űrlapból érkező adatok tisztítása.
	 */
	public static function sanitize( array $data ) {
		$roles = isset( $data['roles'] ) ? array_map( 'sanitize_key', (array) $data['roles'] ) : array();
		$roles = array_values( array_intersect( $roles, array_keys( self::assignable_roles() ) ) );

		$retention = isset( $data['retention_years'] ) ? absint( $data['retention_years'] ) : 1;
		if ( ! in_array( $retention, self::RETENTION_CHOICES, true ) ) {
			$retention = 1;
		}

		$visibility = isset( $data['visibility'] ) && self::VISIBILITY_ADMIN === $data['visibility']
			? self::VISIBILITY_ADMIN
			: self::VISIBILITY_ALL;

		$logo_id = isset( $data['logo_id'] ) ? absint( $data['logo_id'] ) : 0;
		if ( $logo_id && ! wp_attachment_is_image( $logo_id ) ) {
			$logo_id = 0;
		}

		return array(
			'name'            => isset( $data['name'] ) ? mb_substr( sanitize_text_field( $data['name'] ), 0, 191 ) : '',
			'logo_id'         => $logo_id,
			'roles'           => implode( ',', $roles ),
			'retention_years' => $retention,
			'visibility'      => $visibility,
		);
	}

	public static function create( array $clean ) {
		global $wpdb;
		$now = current_time( 'mysql', true );
		$wpdb->insert(
			IWC_DB::table( 'rooms' ),
			array_merge(
				$clean,
				array(
					'status'     => self::STATUS_ACTIVE,
					'created_by' => get_current_user_id(),
					'created_at' => $now,
					'updated_at' => $now,
				)
			)
		);
		return (int) $wpdb->insert_id;
	}

	public static function update( $id, array $fields ) {
		global $wpdb;
		$fields['updated_at'] = current_time( 'mysql', true );
		return false !== $wpdb->update( IWC_DB::table( 'rooms' ), $fields, array( 'id' => (int) $id ) );
	}

	public static function roles_of( $room ) {
		return array_filter( explode( ',', (string) $room->roles ) );
	}

	/**
	 * Tagja-e (olvashat és írhat) a felhasználó a szobának.
	 */
	public static function user_can_access( $room, $user_id ) {
		if ( ! $room || self::STATUS_ACTIVE !== $room->status || ! $user_id ) {
			return false;
		}
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return false;
		}
		if ( user_can( $user, 'manage_options' ) ) {
			return true;
		}
		return (bool) array_intersect( (array) $user->roles, self::roles_of( $room ) );
	}

	/**
	 * A felhasználó számára elérhető aktív szobák az adott környezetben.
	 *
	 * @param string $context 'admin' = admin felület (minden szoba), 'front' = weboldal (csak a "minden oldalon" láthatók).
	 */
	public static function for_user( $user_id, $context ) {
		$out = array();
		foreach ( self::all( self::STATUS_ACTIVE ) as $room ) {
			if ( 'front' === $context && self::VISIBILITY_ALL !== $room->visibility ) {
				continue;
			}
			if ( self::user_can_access( $room, $user_id ) ) {
				$out[] = $room;
			}
		}
		return $out;
	}

	public static function logo_url( $room, $size = 'thumbnail' ) {
		if ( ! $room->logo_id ) {
			return '';
		}
		$url = wp_get_attachment_image_url( (int) $room->logo_id, $size );
		return $url ? $url : '';
	}

	/**
	 * A legrégebbi még visszaolvasható üzenet időpontja (UTC, MySQL formátum).
	 */
	public static function cutoff( $room ) {
		$years = max( 1, (int) $room->retention_years );
		return gmdate( 'Y-m-d H:i:s', strtotime( '-' . $years . ' years' ) );
	}
}
