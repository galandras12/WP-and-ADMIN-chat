<?php
/**
 * Admin felület: szobák listázása, létrehozása, szerkesztése, archiválása.
 */

defined( 'ABSPATH' ) || exit;

class IWC_Admin {

	const SLUG = 'iwc-rooms';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_action( 'admin_post_iwc_save_room', array( __CLASS__, 'handle_save' ) );
		add_action( 'admin_post_iwc_set_status', array( __CLASS__, 'handle_status' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
	}

	public static function menu() {
		add_menu_page(
			__( 'Admin chat', 'internal-chat' ),
			__( 'Admin chat', 'internal-chat' ),
			'manage_options',
			self::SLUG,
			array( __CLASS__, 'render' ),
			'dashicons-format-chat',
			71
		);
		add_submenu_page( self::SLUG, __( 'Szobák', 'internal-chat' ), __( 'Szobák', 'internal-chat' ), 'manage_options', self::SLUG, array( __CLASS__, 'render' ) );
		add_submenu_page( self::SLUG, __( 'Admin chat beállítások', 'internal-chat' ), __( 'Beállítások', 'internal-chat' ), 'manage_options', 'iwc-settings', array( __CLASS__, 'render_settings' ) );
	}

	public static function register_settings() {
		register_setting(
			'iwc_settings',
			IWC_Stream::OPTION,
			array(
				'type'              => 'string',
				'default'           => '1',
				'sanitize_callback' => function ( $value ) {
					return $value ? '1' : '0';
				},
			)
		);
	}

	public static function render_settings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Admin chat beállítások', 'internal-chat' ); ?></h1>
			<form method="post" action="options.php">
				<?php settings_fields( 'iwc_settings' ); ?>
				<input type="hidden" name="<?php echo esc_attr( IWC_Stream::OPTION ); ?>" value="0">
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Valós idejű üzenetek', 'internal-chat' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( IWC_Stream::OPTION ); ?>" value="1" <?php checked( IWC_Stream::enabled() ); ?>>
								<?php esc_html_e( 'Bekapcsolva (Server-Sent Events)', 'internal-chat' ); ?>
							</label>
							<p class="description">
								<?php esc_html_e( 'Az új üzenetek kb. 1 másodpercen belül megjelennek. Minden előtérben lévő, nyitott chat ablak egy PHP folyamatot foglal le, amíg látható. Ha a tárhely túlterhelődik, vagy az üzenetek csak késve érkeznek, kapcsold ki: ilyenkor a chat 4 másodpercenkénti lekérdezéssel működik.', 'internal-chat' ); ?>
							</p>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}

	public static function assets( $hook ) {
		if ( 'toplevel_page_' . self::SLUG !== $hook ) {
			return;
		}
		wp_enqueue_media();
		wp_enqueue_style( 'iwc-admin', IWC_URL . 'assets/css/admin.css', array(), IWC_VERSION );
		wp_enqueue_script( 'iwc-admin', IWC_URL . 'assets/js/admin.js', array( 'jquery' ), IWC_VERSION, true );
		wp_localize_script(
			'iwc-admin',
			'IWC_ADMIN',
			array(
				'frameTitle'  => __( 'Szoba logó kiválasztása', 'internal-chat' ),
				'frameButton' => __( 'Logó használata', 'internal-chat' ),
			)
		);
	}

	private static function page_url( array $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => self::SLUG ), $args ), admin_url( 'admin.php' ) );
	}

	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$action = isset( $_GET['action'] ) ? sanitize_key( $_GET['action'] ) : '';

		echo '<div class="wrap iwc-admin">';
		self::notice();

		if ( 'new' === $action ) {
			self::render_form( null );
		} elseif ( 'edit' === $action ) {
			$room = IWC_Rooms::get( isset( $_GET['room'] ) ? absint( $_GET['room'] ) : 0 );
			if ( $room ) {
				self::render_form( $room );
			} else {
				echo '<p>' . esc_html__( 'A szoba nem található.', 'internal-chat' ) . '</p>';
			}
		} else {
			self::render_list();
		}
		echo '</div>';
	}

	private static function notice() {
		$messages = array(
			'created'  => __( 'Szoba létrehozva.', 'internal-chat' ),
			'updated'  => __( 'Szoba mentve.', 'internal-chat' ),
			'archived' => __( 'Szoba archiválva. Az üzenetek megmaradtak, a szoba bármikor visszaállítható.', 'internal-chat' ),
			'restored' => __( 'Szoba visszaállítva.', 'internal-chat' ),
			'noname'   => __( 'A szoba nevét kötelező megadni.', 'internal-chat' ),
		);
		$key = isset( $_GET['iwc_msg'] ) ? sanitize_key( $_GET['iwc_msg'] ) : '';
		if ( isset( $messages[ $key ] ) ) {
			$type = 'noname' === $key ? 'error' : 'success';
			printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr( $type ), esc_html( $messages[ $key ] ) );
		}
	}

	private static function retention_label( $years ) {
		/* translators: %d: évek száma */
		return sprintf( _n( '%d év', '%d év', $years, 'internal-chat' ), $years );
	}

	private static function render_list() {
		$rooms    = IWC_Rooms::all();
		$roles    = IWC_Rooms::assignable_roles();
		$new_url  = self::page_url( array( 'action' => 'new' ) );
		?>
		<h1 class="wp-heading-inline"><?php esc_html_e( 'Admin chat – szobák', 'internal-chat' ); ?></h1>
		<a href="<?php echo esc_url( $new_url ); ?>" class="page-title-action"><?php esc_html_e( 'Új szoba', 'internal-chat' ); ?></a>
		<hr class="wp-header-end">

		<table class="widefat striped iwc-rooms-table">
			<thead>
				<tr>
					<th class="column-logo"><?php esc_html_e( 'Logó', 'internal-chat' ); ?></th>
					<th><?php esc_html_e( 'Név', 'internal-chat' ); ?></th>
					<th><?php esc_html_e( 'Tagok', 'internal-chat' ); ?></th>
					<th><?php esc_html_e( 'Előzmények', 'internal-chat' ); ?></th>
					<th><?php esc_html_e( 'Láthatóság', 'internal-chat' ); ?></th>
					<th><?php esc_html_e( 'Üzenetek', 'internal-chat' ); ?></th>
					<th><?php esc_html_e( 'Állapot', 'internal-chat' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php if ( ! $rooms ) : ?>
				<tr><td colspan="7"><?php esc_html_e( 'Még nincs chat szoba. Hozd létre az elsőt az „Új szoba” gombbal.', 'internal-chat' ); ?></td></tr>
			<?php endif; ?>
			<?php
			foreach ( $rooms as $room ) :
				$edit_url   = self::page_url( array( 'action' => 'edit', 'room' => $room->id ) );
				$is_active  = IWC_Rooms::STATUS_ACTIVE === $room->status;
				$status_url = wp_nonce_url(
					add_query_arg(
						array(
							'action' => 'iwc_set_status',
							'room'   => $room->id,
							'status' => $is_active ? IWC_Rooms::STATUS_ARCHIVED : IWC_Rooms::STATUS_ACTIVE,
						),
						admin_url( 'admin-post.php' )
					),
					'iwc_set_status_' . $room->id
				);
				$role_names = array( __( 'Adminisztrátor', 'internal-chat' ) );
				foreach ( IWC_Rooms::roles_of( $room ) as $key ) {
					$role_names[] = isset( $roles[ $key ] ) ? $roles[ $key ] : $key;
				}
				$logo = IWC_Rooms::logo_url( $room );
				?>
				<tr class="<?php echo $is_active ? '' : 'iwc-archived'; ?>">
					<td class="column-logo">
						<?php if ( $logo ) : ?>
							<img src="<?php echo esc_url( $logo ); ?>" alt="" class="iwc-logo-thumb">
						<?php else : ?>
							<span class="iwc-logo-thumb iwc-logo-placeholder"><?php echo esc_html( mb_strtoupper( mb_substr( $room->name, 0, 1 ) ) ); ?></span>
						<?php endif; ?>
					</td>
					<td>
						<strong><a href="<?php echo esc_url( $edit_url ); ?>"><?php echo esc_html( $room->name ); ?></a></strong>
						<div class="row-actions">
							<span class="edit"><a href="<?php echo esc_url( $edit_url ); ?>"><?php esc_html_e( 'Szerkesztés', 'internal-chat' ); ?></a> | </span>
							<span class="<?php echo $is_active ? 'trash' : 'restore'; ?>">
								<a href="<?php echo esc_url( $status_url ); ?>"><?php echo $is_active ? esc_html__( 'Archiválás', 'internal-chat' ) : esc_html__( 'Visszaállítás', 'internal-chat' ); ?></a>
							</span>
						</div>
					</td>
					<td><?php echo esc_html( implode( ', ', $role_names ) ); ?></td>
					<td><?php echo esc_html( self::retention_label( (int) $room->retention_years ) ); ?></td>
					<td>
						<?php
						echo IWC_Rooms::VISIBILITY_ADMIN === $room->visibility
							? esc_html__( 'Csak admin felületen', 'internal-chat' )
							: esc_html__( 'Minden oldalon', 'internal-chat' );
						?>
					</td>
					<td><?php echo esc_html( number_format_i18n( IWC_Messages::count( $room->id ) ) ); ?></td>
					<td><?php echo $is_active ? esc_html__( 'Aktív', 'internal-chat' ) : esc_html__( 'Archivált', 'internal-chat' ); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<p class="description">
			<?php esc_html_e( 'Az adminisztrátorok minden szobához hozzáférnek. A szobák, jogosultságok és üzenetek saját adatbázis-táblákban vannak, a plugin frissítése vagy törlése után is megmaradnak.', 'internal-chat' ); ?>
		</p>
		<?php
	}

	private static function render_form( $room ) {
		$is_new     = ! $room;
		$name       = $room ? $room->name : '';
		$logo_id    = $room ? (int) $room->logo_id : 0;
		$logo_url   = $room ? IWC_Rooms::logo_url( $room ) : '';
		$selected   = $room ? IWC_Rooms::roles_of( $room ) : array();
		$retention  = $room ? (int) $room->retention_years : 1;
		$visibility = $room ? $room->visibility : IWC_Rooms::VISIBILITY_ALL;
		?>
		<h1><?php echo $is_new ? esc_html__( 'Új chat szoba', 'internal-chat' ) : esc_html__( 'Chat szoba szerkesztése', 'internal-chat' ); ?></h1>
		<p><a href="<?php echo esc_url( self::page_url() ); ?>">&larr; <?php esc_html_e( 'Vissza a szobákhoz', 'internal-chat' ); ?></a></p>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="iwc_save_room">
			<input type="hidden" name="room_id" value="<?php echo esc_attr( $room ? $room->id : 0 ); ?>">
			<?php wp_nonce_field( 'iwc_save_room' ); ?>

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="iwc-name"><?php esc_html_e( 'Szoba neve', 'internal-chat' ); ?></label></th>
					<td><input type="text" id="iwc-name" name="name" class="regular-text" maxlength="191" required value="<?php echo esc_attr( $name ); ?>"></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Logó', 'internal-chat' ); ?></th>
					<td>
						<div class="iwc-logo-field">
							<img src="<?php echo esc_url( $logo_url ); ?>" alt="" class="iwc-logo-preview" <?php echo $logo_url ? '' : 'hidden'; ?>>
							<input type="hidden" name="logo_id" id="iwc-logo-id" value="<?php echo esc_attr( $logo_id ); ?>">
							<button type="button" class="button iwc-logo-select"><?php esc_html_e( 'Kép feltöltése / kiválasztása', 'internal-chat' ); ?></button>
							<button type="button" class="button-link-delete iwc-logo-remove" <?php echo $logo_id ? '' : 'hidden'; ?>><?php esc_html_e( 'Eltávolítás', 'internal-chat' ); ?></button>
						</div>
						<p class="description"><?php esc_html_e( 'A chat ablak tetején, a szoba neve előtt jelenik meg. Négyzetes kép ajánlott.', 'internal-chat' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Tagok (olvashatnak és írhatnak)', 'internal-chat' ); ?></th>
					<td>
						<fieldset>
							<?php foreach ( IWC_Rooms::assignable_roles() as $key => $label ) : ?>
								<label class="iwc-role">
									<input type="checkbox" name="roles[]" value="<?php echo esc_attr( $key ); ?>" <?php checked( in_array( $key, $selected, true ) ); ?>>
									<?php echo esc_html( $label ); ?>
								</label>
							<?php endforeach; ?>
						</fieldset>
						<p class="description"><?php esc_html_e( 'Az adminisztrátorok mindig tagjai minden szobának.', 'internal-chat' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="iwc-retention"><?php esc_html_e( 'Visszaolvasható előzmények', 'internal-chat' ); ?></label></th>
					<td>
						<select id="iwc-retention" name="retention_years">
							<?php foreach ( IWC_Rooms::RETENTION_CHOICES as $years ) : ?>
								<option value="<?php echo esc_attr( $years ); ?>" <?php selected( $retention, $years ); ?>><?php echo esc_html( self::retention_label( $years ) ); ?></option>
							<?php endforeach; ?>
						</select>
						<p class="description"><?php esc_html_e( 'A régebbi üzenetek nem törlődnek, csak nem jelennek meg – a korlát később növelhető.', 'internal-chat' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Chat ablak láthatósága', 'internal-chat' ); ?></th>
					<td>
						<fieldset>
							<label><input type="radio" name="visibility" value="<?php echo esc_attr( IWC_Rooms::VISIBILITY_ALL ); ?>" <?php checked( $visibility, IWC_Rooms::VISIBILITY_ALL ); ?>> <?php esc_html_e( 'Minden oldalon (weboldal és admin felület)', 'internal-chat' ); ?></label><br>
							<label><input type="radio" name="visibility" value="<?php echo esc_attr( IWC_Rooms::VISIBILITY_ADMIN ); ?>" <?php checked( $visibility, IWC_Rooms::VISIBILITY_ADMIN ); ?>> <?php esc_html_e( 'Csak az admin felületen', 'internal-chat' ); ?></label>
						</fieldset>
					</td>
				</tr>
			</table>

			<?php submit_button( $is_new ? __( 'Szoba létrehozása', 'internal-chat' ) : __( 'Mentés', 'internal-chat' ) ); ?>
		</form>
		<?php
	}

	public static function handle_save() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Nincs jogosultságod ehhez.', 'internal-chat' ), 403 );
		}
		check_admin_referer( 'iwc_save_room' );

		$room_id = isset( $_POST['room_id'] ) ? absint( $_POST['room_id'] ) : 0;
		$clean   = IWC_Rooms::sanitize( wp_unslash( $_POST ) );

		if ( '' === $clean['name'] ) {
			$args = $room_id ? array( 'action' => 'edit', 'room' => $room_id ) : array( 'action' => 'new' );
			wp_safe_redirect( self::page_url( array_merge( $args, array( 'iwc_msg' => 'noname' ) ) ) );
			exit;
		}

		if ( $room_id && IWC_Rooms::get( $room_id ) ) {
			IWC_Rooms::update( $room_id, $clean );
			$msg = 'updated';
		} else {
			$room_id = IWC_Rooms::create( $clean );
			$msg     = 'created';
		}

		wp_safe_redirect( self::page_url( array( 'action' => 'edit', 'room' => $room_id, 'iwc_msg' => $msg ) ) );
		exit;
	}

	/**
	 * Archiválás / visszaállítás. Szobát a plugin szándékosan nem töröl véglegesen,
	 * így a beszélgetések soha nem vesznek el.
	 */
	public static function handle_status() {
		$room_id = isset( $_GET['room'] ) ? absint( $_GET['room'] ) : 0;
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Nincs jogosultságod ehhez.', 'internal-chat' ), 403 );
		}
		check_admin_referer( 'iwc_set_status_' . $room_id );

		$status = isset( $_GET['status'] ) && IWC_Rooms::STATUS_ARCHIVED === $_GET['status']
			? IWC_Rooms::STATUS_ARCHIVED
			: IWC_Rooms::STATUS_ACTIVE;

		if ( IWC_Rooms::get( $room_id ) ) {
			IWC_Rooms::update( $room_id, array( 'status' => $status ) );
		}

		wp_safe_redirect( self::page_url( array( 'iwc_msg' => IWC_Rooms::STATUS_ARCHIVED === $status ? 'archived' : 'restored' ) ) );
		exit;
	}
}
