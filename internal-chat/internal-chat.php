<?php
/**
 * Plugin Name:       Belső Chat
 * Description:       Kizárólag belsős csevegés WordPress felhasználóknak. Több chat szoba, szerepkör alapú hozzáférés, Messenger stílusú lebegő chat ablak az admin felületen és (szobánként beállíthatóan) a weboldalon.
 * Version:           1.0.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            galandras12
 * License:           GPL-2.0-or-later
 * Text Domain:       internal-chat
 *
 * ADATMEGŐRZÉS: a plugin minden adatát (szobák, jogosultságok, üzenetek,
 * olvasottsági állapot) saját adatbázis-táblákban tárolja
 * ({prefix}iwc_rooms, {prefix}iwc_messages, {prefix}iwc_reads).
 * Ezeket a plugin SOHA nem törli: sem deaktiváláskor, sem frissítéskor,
 * sem a plugin törlésekor (nincs uninstall.php és uninstall hook).
 * Újratelepítés után minden adat automatikusan újra elérhető.
 */

defined( 'ABSPATH' ) || exit;

define( 'IWC_VERSION', '1.0.0' );
define( 'IWC_DB_VERSION', '1' );
define( 'IWC_FILE', __FILE__ );
define( 'IWC_DIR', plugin_dir_path( __FILE__ ) );
define( 'IWC_URL', plugin_dir_url( __FILE__ ) );

require_once IWC_DIR . 'includes/class-iwc-db.php';
require_once IWC_DIR . 'includes/class-iwc-rooms.php';
require_once IWC_DIR . 'includes/class-iwc-messages.php';
require_once IWC_DIR . 'includes/class-iwc-rest.php';
require_once IWC_DIR . 'includes/class-iwc-admin.php';
require_once IWC_DIR . 'includes/class-iwc-widget.php';

register_activation_hook( __FILE__, array( 'IWC_DB', 'install' ) );

// Frissítés után az aktiválási hook nem fut le, ezért minden betöltéskor
// ellenőrizzük a séma verzióját. A dbDelta csak hozzáad, soha nem töröl.
add_action( 'plugins_loaded', array( 'IWC_DB', 'maybe_upgrade' ) );

IWC_Rest::init();
IWC_Admin::init();
IWC_Widget::init();
