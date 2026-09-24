<?php
/**
 * Adatbázis séma kezelése.
 *
 * Minden művelet nem-destruktív: a táblák csak létrejönnek vagy bővülnek,
 * a plugin semmilyen körülmények között nem töröl táblát vagy adatot.
 */

defined( 'ABSPATH' ) || exit;

class IWC_DB {

	const VERSION_OPTION = 'iwc_db_version';

	public static function table( $name ) {
		global $wpdb;
		return $wpdb->prefix . 'iwc_' . $name;
	}

	public static function maybe_upgrade() {
		if ( get_option( self::VERSION_OPTION ) !== IWC_DB_VERSION ) {
			self::install();
		}
	}

	public static function install() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset  = $wpdb->get_charset_collate();
		$rooms    = self::table( 'rooms' );
		$messages = self::table( 'messages' );
		$reads    = self::table( 'reads' );

		// A dbDelta csak létrehoz / új oszlopot, indexet ad hozzá, meglévő adatot nem érint.
		dbDelta(
			"CREATE TABLE $rooms (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				name varchar(191) NOT NULL DEFAULT '',
				logo_id bigint(20) unsigned NOT NULL DEFAULT 0,
				roles text NOT NULL,
				retention_years smallint(5) unsigned NOT NULL DEFAULT 1,
				visibility varchar(20) NOT NULL DEFAULT 'all',
				status varchar(20) NOT NULL DEFAULT 'active',
				created_by bigint(20) unsigned NOT NULL DEFAULT 0,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY status (status)
			) $charset;"
		);

		dbDelta(
			"CREATE TABLE $messages (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				room_id bigint(20) unsigned NOT NULL,
				user_id bigint(20) unsigned NOT NULL,
				user_name varchar(250) NOT NULL DEFAULT '',
				message longtext NOT NULL,
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY room_id (room_id,id),
				KEY room_created (room_id,created_at)
			) $charset;"
		);

		dbDelta(
			"CREATE TABLE $reads (
				user_id bigint(20) unsigned NOT NULL,
				room_id bigint(20) unsigned NOT NULL,
				last_read_id bigint(20) unsigned NOT NULL DEFAULT 0,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (user_id,room_id)
			) $charset;"
		);

		update_option( self::VERSION_OPTION, IWC_DB_VERSION );
	}
}
