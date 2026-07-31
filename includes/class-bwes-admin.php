<?php
/**
 * Admin settings UI.
 *
 * All write actions are capability-gated (manage_woocommerce) and nonce
 * protected. All input is sanitized through BWES_Services / explicit casts,
 * and all output is escaped.
 *
 * @package BlockSocial\WooExtraServices
 */

defined( 'ABSPATH' ) || exit;

/**
 * Renders and processes the admin screens.
 */
class BWES_Admin {

	const MENU_SLUG = 'bwes-settings';
	const CAP       = 'manage_woocommerce';

	/**
	 * Modules registry.
	 *
	 * @var BWES_Modules
	 */
	private $modules;

	/**
	 * Services store.
	 *
	 * @var BWES_Services
	 */
	private $services;

	/**
	 * Constructor.
	 *
	 * @param BWES_Modules  $modules  Modules registry.
	 * @param BWES_Services $services Services store.
	 */
	public function __construct( BWES_Modules $modules, BWES_Services $services ) {
		$this->modules  = $modules;
		$this->services = $services;
	}

	/**
	 * Register admin hooks.
	 *
	 * @return void
	 */
	public function hooks() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_filter( 'plugin_action_links_' . BWES_PLUGIN_BASENAME, array( $this, 'action_links' ) );

		add_action( 'admin_post_bwes_save_modules', array( $this, 'handle_save_modules' ) );
		add_action( 'admin_post_bwes_save_service', array( $this, 'handle_save_service' ) );
		add_action( 'admin_post_bwes_delete_service', array( $this, 'handle_delete_service' ) );
	}

	/**
	 * Register the admin menu under WooCommerce.
	 *
	 * @return void
	 */
	public function register_menu() {
		add_submenu_page(
			'woocommerce',
			__( 'Extra Services', 'bwes' ),
			__( 'Extra Services', 'bwes' ),
			self::CAP,
			self::MENU_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Add a settings link to the plugins list.
	 *
	 * @param array $links Existing links.
	 * @return array
	 */
	public function action_links( $links ) {
		$url  = admin_url( 'admin.php?page=' . self::MENU_SLUG );
		$link = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'bwes' ) . '</a>';
		array_unshift( $links, $link );

		return $links;
	}

	/**
	 * Enqueue admin assets only on our screen.
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public function enqueue_assets( $hook ) {
		if ( 'woocommerce_page_' . self::MENU_SLUG !== $hook ) {
			return;
		}
		wp_enqueue_style( 'bwes-admin', BWES_PLUGIN_URL . 'assets/css/bwes-admin.css', array(), BWES_VERSION );
		wp_enqueue_script( 'bwes-admin', BWES_PLUGIN_URL . 'assets/js/bwes-admin.js', array( 'jquery' ), BWES_VERSION, true );
	}

	/**
	 * Router for the settings page.
	 *
	 * @return void
	 */
	public function render_page() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'bwes' ) );
		}

		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'modules'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		echo '<div class="wrap bwes-wrap">';
		echo '<h1>' . esc_html__( 'BlockSocial — Extra Services', 'bwes' ) . '</h1>';

		$this->render_notices();
		$this->render_tabs( $tab );

		if ( 'services' === $tab ) {
			$action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( 'edit' === $action || 'new' === $action ) {
				$this->render_service_editor();
			} else {
				$this->render_services_list();
			}
		} else {
			$this->render_modules_tab();
		}

		echo '</div>';
	}

	/**
	 * Render admin notices from query args.
	 *
	 * @return void
	 */
	private function render_notices() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$msg = isset( $_GET['bwes_msg'] ) ? sanitize_key( wp_unslash( $_GET['bwes_msg'] ) ) : '';
		if ( '' === $msg ) {
			return;
		}

		$map = array(
			'modules_saved'  => __( 'Module settings saved.', 'bwes' ),
			'service_saved'  => __( 'Service saved.', 'bwes' ),
			'service_deleted' => __( 'Service deleted.', 'bwes' ),
		);

		if ( isset( $map[ $msg ] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $map[ $msg ] ) . '</p></div>';
		}
	}

	/**
	 * Render the tab navigation.
	 *
	 * @param string $current Current tab.
	 * @return void
	 */
	private function render_tabs( $current ) {
		$tabs = array(
			'modules'  => __( 'Modules & Settings', 'bwes' ),
			'services' => __( 'Services', 'bwes' ),
		);

		echo '<h2 class="nav-tab-wrapper">';
		foreach ( $tabs as $slug => $label ) {
			$url   = admin_url( 'admin.php?page=' . self::MENU_SLUG . '&tab=' . $slug );
			$class = $slug === $current ? ' nav-tab-active' : '';
			printf(
				'<a href="%1$s" class="nav-tab%2$s">%3$s</a>',
				esc_url( $url ),
				esc_attr( $class ),
				esc_html( $label )
			);
		}
		echo '</h2>';
	}

	/* --------------------------------------------------------------------- *
	 * Modules & general settings tab
	 * --------------------------------------------------------------------- */

	/**
	 * Render the modules + general settings form.
	 *
	 * @return void
	 */
	private function render_modules_tab() {
		$defs     = $this->modules->get_definitions();
		$enabled  = $this->modules->get_enabled_map();
		$settings = wp_parse_args( get_option( BWES_Install::SETTINGS_OPTION, array() ), BWES_Install::default_settings() );

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'bwes_save_modules' );
		echo '<input type="hidden" name="action" value="bwes_save_modules" />';

		echo '<h2>' . esc_html__( 'Modules', 'bwes' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Enable or disable each feature module. Disabling a module hides all of its services and removes any fee it would add.', 'bwes' ) . '</p>';

		echo '<table class="widefat striped bwes-modules-table"><tbody>';
		foreach ( $defs as $id => $def ) {
			printf(
				'<tr><td class="bwes-module-toggle"><label><input type="checkbox" name="modules[%1$s]" value="1" %2$s /> <strong>%3$s</strong></label></td><td>%4$s</td></tr>',
				esc_attr( $id ),
				checked( ! empty( $enabled[ $id ] ), true, false ),
				esc_html( $def['label'] ),
				esc_html( $def['description'] )
			);
		}
		echo '</tbody></table>';

		echo '<h2>' . esc_html__( 'General settings', 'bwes' ) . '</h2>';
		echo '<table class="form-table" role="presentation"><tbody>';

		// Display location.
		echo '<tr><th scope="row">' . esc_html__( 'Show services on', 'bwes' ) . '</th><td>';
		$locations = array(
			'checkout' => __( 'Checkout page', 'bwes' ),
			'cart'     => __( 'Cart page', 'bwes' ),
			'both'     => __( 'Both cart and checkout', 'bwes' ),
		);
		echo '<select name="settings[display_location]">';
		foreach ( $locations as $val => $label ) {
			printf(
				'<option value="%1$s" %2$s>%3$s</option>',
				esc_attr( $val ),
				selected( $settings['display_location'], $val, false ),
				esc_html( $label )
			);
		}
		echo '</select></td></tr>';

		echo '</tbody></table>';

		echo '<h3>' . esc_html__( 'Local radius delivery — store origin', 'bwes' ) . '</h3>';
		echo '<p class="description">' . esc_html__( 'Used by the Local radius delivery module. Provide either coordinates (most reliable) or an address to geocode.', 'bwes' ) . '</p>';
		echo '<table class="form-table" role="presentation"><tbody>';

		printf(
			'<tr><th scope="row"><label for="bwes-origin-address">%1$s</label></th><td><input type="text" class="regular-text" id="bwes-origin-address" name="settings[origin_address]" value="%2$s" /></td></tr>',
			esc_html__( 'Origin address', 'bwes' ),
			esc_attr( $settings['origin_address'] )
		);
		printf(
			'<tr><th scope="row"><label for="bwes-origin-lat">%1$s</label></th><td><input type="text" class="small-text" id="bwes-origin-lat" name="settings[origin_lat]" value="%2$s" /> <input type="text" class="small-text" name="settings[origin_lng]" value="%3$s" placeholder="%4$s" /> <span class="description">%5$s</span></td></tr>',
			esc_html__( 'Origin lat / lng', 'bwes' ),
			esc_attr( $settings['origin_lat'] ),
			esc_attr( $settings['origin_lng'] ),
			esc_attr__( 'longitude', 'bwes' ),
			esc_html__( 'Optional but recommended.', 'bwes' )
		);
		printf(
			'<tr><th scope="row"><label for="bwes-geocode-endpoint">%1$s</label></th><td><input type="url" class="regular-text code" id="bwes-geocode-endpoint" name="settings[geocode_endpoint]" value="%2$s" /><p class="description">%3$s</p></td></tr>',
			esc_html__( 'Geocoding endpoint', 'bwes' ),
			esc_attr( $settings['geocode_endpoint'] ),
			esc_html__( 'Nominatim-compatible search endpoint. Respect the usage policy of whichever service you use.', 'bwes' )
		);

		echo '</tbody></table>';

		submit_button( __( 'Save changes', 'bwes' ) );
		echo '</form>';
	}

	/**
	 * Persist module toggles + general settings.
	 *
	 * @return void
	 */
	public function handle_save_modules() {
		$this->guard( 'bwes_save_modules' );

		$posted_modules = isset( $_POST['modules'] ) && is_array( $_POST['modules'] ) ? wp_unslash( $_POST['modules'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$map            = array();
		foreach ( array_keys( $this->modules->get_definitions() ) as $id ) {
			$map[ $id ] = ! empty( $posted_modules[ $id ] );
		}
		$this->modules->save_enabled_map( $map );

		$posted_settings = isset( $_POST['settings'] ) && is_array( $_POST['settings'] ) ? wp_unslash( $_POST['settings'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$this->save_settings( $posted_settings );

		$this->redirect_with( 'modules', 'modules_saved' );
	}

	/**
	 * Sanitize + save general settings.
	 *
	 * @param array $posted Raw posted settings.
	 * @return void
	 */
	private function save_settings( array $posted ) {
		$defaults = BWES_Install::default_settings();

		$location = isset( $posted['display_location'] ) ? sanitize_key( $posted['display_location'] ) : 'checkout';
		if ( ! in_array( $location, array( 'checkout', 'cart', 'both' ), true ) ) {
			$location = 'checkout';
		}

		$lat = isset( $posted['origin_lat'] ) ? trim( sanitize_text_field( $posted['origin_lat'] ) ) : '';
		$lng = isset( $posted['origin_lng'] ) ? trim( sanitize_text_field( $posted['origin_lng'] ) ) : '';
		$lat = is_numeric( $lat ) ? (string) (float) $lat : '';
		$lng = is_numeric( $lng ) ? (string) (float) $lng : '';

		$endpoint = isset( $posted['geocode_endpoint'] ) ? esc_url_raw( trim( $posted['geocode_endpoint'] ) ) : $defaults['geocode_endpoint'];
		if ( '' === $endpoint ) {
			$endpoint = $defaults['geocode_endpoint'];
		}

		$settings = array(
			'display_location' => $location,
			'origin_address'   => isset( $posted['origin_address'] ) ? sanitize_text_field( $posted['origin_address'] ) : '',
			'origin_lat'       => $lat,
			'origin_lng'       => $lng,
			'geocode_endpoint' => $endpoint,
		);

		update_option( BWES_Install::SETTINGS_OPTION, $settings );
	}

	/* --------------------------------------------------------------------- *
	 * Services tab
	 * --------------------------------------------------------------------- */

	/**
	 * Render the list of all services.
	 *
	 * @return void
	 */
	private function render_services_list() {
		$services  = $this->services->all();
		$defs      = $this->modules->get_definitions();
		$new_url   = admin_url( 'admin.php?page=' . self::MENU_SLUG . '&tab=services&action=new' );

		echo '<p><a href="' . esc_url( $new_url ) . '" class="button button-primary">' . esc_html__( 'Add custom service', 'bwes' ) . '</a></p>';

		echo '<table class="widefat striped bwes-services-table">';
		echo '<thead><tr>';
		echo '<th>' . esc_html__( 'Service', 'bwes' ) . '</th>';
		echo '<th>' . esc_html__( 'Module', 'bwes' ) . '</th>';
		echo '<th>' . esc_html__( 'Type', 'bwes' ) . '</th>';
		echo '<th>' . esc_html__( 'Fee', 'bwes' ) . '</th>';
		echo '<th>' . esc_html__( 'Roles', 'bwes' ) . '</th>';
		echo '<th>' . esc_html__( 'Status', 'bwes' ) . '</th>';
		echo '<th>' . esc_html__( 'Actions', 'bwes' ) . '</th>';
		echo '</tr></thead><tbody>';

		if ( empty( $services ) ) {
			echo '<tr><td colspan="7">' . esc_html__( 'No services yet.', 'bwes' ) . '</td></tr>';
		}

		$roles = $this->services->get_selectable_roles();

		foreach ( $services as $service ) {
			$edit_url   = admin_url( 'admin.php?page=' . self::MENU_SLUG . '&tab=services&action=edit&service=' . rawurlencode( $service['id'] ) );
			$module_lbl = isset( $defs[ $service['module'] ]['label'] ) ? $defs[ $service['module'] ]['label'] : $service['module'];

			$role_list = empty( $service['allowed_roles'] )
				? __( 'Everyone', 'bwes' )
				: implode( ', ', array_map(
					static function ( $r ) use ( $roles ) {
						return isset( $roles[ $r ] ) ? $roles[ $r ] : $r;
					},
					$service['allowed_roles']
				) );

			echo '<tr>';
			echo '<td><strong>' . esc_html( $service['title'] ) . '</strong></td>';
			echo '<td>' . esc_html( $module_lbl ) . ( $this->modules->is_active( $service['module'] ) ? '' : ' <em>(' . esc_html__( 'module off', 'bwes' ) . ')</em>' ) . '</td>';
			echo '<td>' . esc_html( $service['type'] ) . '</td>';
			echo '<td>' . esc_html( $this->fee_summary( $service ) ) . '</td>';
			echo '<td>' . esc_html( $role_list ) . '</td>';
			echo '<td>' . ( $service['enabled'] ? esc_html__( 'Enabled', 'bwes' ) : esc_html__( 'Disabled', 'bwes' ) ) . '</td>';
			echo '<td><a href="' . esc_url( $edit_url ) . '">' . esc_html__( 'Edit', 'bwes' ) . '</a>';
			echo ' | ' . $this->delete_link( $service['id'] );
			echo '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * Build a short textual fee summary.
	 *
	 * @param array $service Service.
	 * @return string
	 */
	private function fee_summary( array $service ) {
		if ( 'radio' === $service['type'] || 'radius' === $service['type'] ) {
			return sprintf(
				/* translators: %d: number of options */
				_n( '%d option', '%d options', count( $service['options'] ), 'bwes' ),
				count( $service['options'] )
			);
		}

		$suffix = 'percent' === $service['fee_type'] ? '%' : '';
		return $service['fee'] . $suffix . ( 'per_item' === $service['fee_type'] ? '/item' : '' );
	}

	/**
	 * Build a nonce-protected delete link (as a mini form).
	 *
	 * @param string $id Service id.
	 * @return string
	 */
	private function delete_link( $id ) {
		$url = wp_nonce_url(
			admin_url( 'admin-post.php?action=bwes_delete_service&service=' . rawurlencode( $id ) ),
			'bwes_delete_service_' . $id
		);

		return '<a href="' . esc_url( $url ) . '" class="bwes-delete" onclick="return confirm(\'' . esc_js( __( 'Delete this service?', 'bwes' ) ) . '\');">' . esc_html__( 'Delete', 'bwes' ) . '</a>';
	}

	/**
	 * Render the single-service editor.
	 *
	 * @return void
	 */
	private function render_service_editor() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$id      = isset( $_GET['service'] ) ? sanitize_key( wp_unslash( $_GET['service'] ) ) : '';
		$service = '' !== $id ? $this->services->get( $id ) : null;
		$is_new  = null === $service;

		if ( $is_new ) {
			$service = $this->services->normalize(
				array(
					'module'  => 'custom',
					'type'    => 'checkbox',
					'enabled' => true,
					'taxable' => true,
				)
			);
		}

		$defs      = $this->modules->get_definitions();
		$roles     = $this->services->get_selectable_roles();
		$tax_classes = array( '' => __( 'Standard', 'bwes' ) );
		foreach ( WC_Tax::get_tax_class_slugs() as $slug ) {
			$tax_classes[ $slug ] = $slug;
		}

		$back_url = admin_url( 'admin.php?page=' . self::MENU_SLUG . '&tab=services' );

		echo '<p><a href="' . esc_url( $back_url ) . '">&larr; ' . esc_html__( 'Back to services', 'bwes' ) . '</a></p>';
		echo '<h2>' . ( $is_new ? esc_html__( 'Add service', 'bwes' ) : esc_html__( 'Edit service', 'bwes' ) ) . '</h2>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="bwes-service-form">';
		wp_nonce_field( 'bwes_save_service' );
		echo '<input type="hidden" name="action" value="bwes_save_service" />';
		echo '<input type="hidden" name="service[id]" value="' . esc_attr( $is_new ? '' : $service['id'] ) . '" />';

		echo '<table class="form-table" role="presentation"><tbody>';

		// Title.
		$this->text_row( 'service[title]', __( 'Title', 'bwes' ), $service['title'], true );

		// Description.
		echo '<tr><th scope="row"><label for="bwes-desc">' . esc_html__( 'Description', 'bwes' ) . '</label></th><td>';
		echo '<textarea id="bwes-desc" name="service[description]" rows="3" class="large-text">' . esc_textarea( $service['description'] ) . '</textarea></td></tr>';

		// Module.
		echo '<tr><th scope="row">' . esc_html__( 'Module', 'bwes' ) . '</th><td><select name="service[module]">';
		foreach ( $defs as $mid => $def ) {
			printf(
				'<option value="%1$s" %2$s>%3$s</option>',
				esc_attr( $mid ),
				selected( $service['module'], $mid, false ),
				esc_html( $def['label'] )
			);
		}
		echo '</select><p class="description">' . esc_html__( 'Which module this service belongs to. It only shows when that module is enabled.', 'bwes' ) . '</p></td></tr>';

		// Type.
		echo '<tr><th scope="row">' . esc_html__( 'Input type', 'bwes' ) . '</th><td><select name="service[type]" id="bwes-type">';
		$types = array(
			'checkbox' => __( 'Checkbox (single opt-in fee)', 'bwes' ),
			'radio'    => __( 'Choice (radio options)', 'bwes' ),
			'radius'   => __( 'Distance tiers (radius)', 'bwes' ),
		);
		foreach ( $types as $val => $label ) {
			printf(
				'<option value="%1$s" %2$s>%3$s</option>',
				esc_attr( $val ),
				selected( $service['type'], $val, false ),
				esc_html( $label )
			);
		}
		echo '</select></td></tr>';

		// Simple fee (checkbox).
		echo '<tr class="bwes-row-simple-fee"><th scope="row">' . esc_html__( 'Fee', 'bwes' ) . '</th><td>';
		printf( '<input type="text" name="service[fee]" value="%s" class="small-text" /> ', esc_attr( (string) $service['fee'] ) );
		echo '<select name="service[fee_type]">';
		$fee_types = array(
			'flat'     => __( 'Flat amount', 'bwes' ),
			'percent'  => __( 'Percent of subtotal', 'bwes' ),
			'per_item' => __( 'Per item', 'bwes' ),
		);
		foreach ( $fee_types as $val => $label ) {
			printf(
				'<option value="%1$s" %2$s>%3$s</option>',
				esc_attr( $val ),
				selected( $service['fee_type'], $val, false ),
				esc_html( $label )
			);
		}
		echo '</select><p class="description">' . esc_html__( 'Used for the checkbox type. Negative values act as a discount.', 'bwes' ) . '</p></td></tr>';

		// Options editor (radio/radius).
		echo '<tr class="bwes-row-options"><th scope="row">' . esc_html__( 'Options / tiers', 'bwes' ) . '</th><td>';
		$this->render_options_editor( $service );
		echo '</td></tr>';

		// Taxable.
		echo '<tr><th scope="row">' . esc_html__( 'Tax', 'bwes' ) . '</th><td>';
		printf(
			'<label><input type="checkbox" name="service[taxable]" value="1" %s /> %s</label> ',
			checked( $service['taxable'], true, false ),
			esc_html__( 'Charge tax on this fee', 'bwes' )
		);
		echo '<select name="service[tax_class]">';
		foreach ( $tax_classes as $slug => $label ) {
			printf(
				'<option value="%1$s" %2$s>%3$s</option>',
				esc_attr( $slug ),
				selected( $service['tax_class'], $slug, false ),
				esc_html( $label )
			);
		}
		echo '</select></td></tr>';

		// Suppress shipping (local pickup / local delivery).
		echo '<tr><th scope="row">' . esc_html__( 'Shipping', 'bwes' ) . '</th><td>';
		printf(
			'<label><input type="checkbox" name="service[suppress_shipping]" value="1" %s /> %s</label>',
			checked( ! empty( $service['suppress_shipping'] ), true, false ),
			esc_html__( 'Selecting this option removes all shipping costs (e.g. local pickup / local delivery)', 'bwes' )
		);
		echo '<p class="description">' . esc_html__( 'When selected at checkout, shipping rates are replaced by a single free option. Any fee configured above still applies.', 'bwes' ) . '</p>';
		echo '</td></tr>';

		// Exclusive.
		echo '<tr><th scope="row">' . esc_html__( 'Exclusive', 'bwes' ) . '</th><td>';
		printf(
			'<label><input type="checkbox" name="service[exclusive]" value="1" %s /> %s</label>',
			checked( ! empty( $service['exclusive'] ), true, false ),
			esc_html__( 'Selecting this option deactivates all other services (e.g. local delivery)', 'bwes' )
		);
		echo '</td></tr>';

		// Hint.
		echo '<tr><th scope="row"><label for="bwes-hint">' . esc_html__( 'Hint', 'bwes' ) . '</label></th><td>';
		printf(
			'<input type="text" id="bwes-hint" name="service[hint]" value="%s" class="large-text" placeholder="%s" />',
			esc_attr( $service['hint'] ),
			esc_attr__( 'e.g. Please enter your address to see the fee', 'bwes' )
		);
		echo '<p class="description">' . esc_html__( 'Optional note shown below the option (useful for distance-based delivery).', 'bwes' ) . '</p>';
		echo '</td></tr>';

		// Gift message.
		echo '<tr><th scope="row">' . esc_html__( 'Message field', 'bwes' ) . '</th><td>';
		printf(
			'<label><input type="checkbox" name="service[collect_message]" value="1" %s /> %s</label> ',
			checked( $service['collect_message'], true, false ),
			esc_html__( 'Collect a message from the customer', 'bwes' )
		);
		printf(
			'<input type="text" name="service[message_label]" value="%s" class="regular-text" placeholder="%s" />',
			esc_attr( $service['message_label'] ),
			esc_attr__( 'Field label', 'bwes' )
		);
		echo '</td></tr>';

		// Allowed roles.
		echo '<tr><th scope="row">' . esc_html__( 'Visible to roles', 'bwes' ) . '</th><td>';
		echo '<fieldset class="bwes-roles">';
		echo '<p class="description">' . esc_html__( 'Leave all unchecked to show to everyone. Select roles to restrict this option to specific user groups (e.g. dropshipping / wholesale fees).', 'bwes' ) . '</p>';
		foreach ( $roles as $slug => $label ) {
			printf(
				'<label class="bwes-role"><input type="checkbox" name="service[allowed_roles][]" value="%1$s" %2$s /> %3$s</label>',
				esc_attr( $slug ),
				checked( in_array( $slug, $service['allowed_roles'], true ), true, false ),
				esc_html( $label )
			);
		}
		echo '</fieldset></td></tr>';

		// Position + enabled.
		$this->text_row( 'service[position]', __( 'Sort order', 'bwes' ), (string) $service['position'], false, 'small-text' );

		echo '<tr><th scope="row">' . esc_html__( 'Status', 'bwes' ) . '</th><td>';
		printf(
			'<label><input type="checkbox" name="service[enabled]" value="1" %s /> %s</label>',
			checked( $service['enabled'], true, false ),
			esc_html__( 'Enabled', 'bwes' )
		);
		echo '</td></tr>';

		echo '</tbody></table>';
		submit_button( $is_new ? __( 'Create service', 'bwes' ) : __( 'Save service', 'bwes' ) );
		echo '</form>';
	}

	/**
	 * Render the repeatable options editor (radio + radius).
	 *
	 * @param array $service Service.
	 * @return void
	 */
	private function render_options_editor( array $service ) {
		echo '<div class="bwes-options-editor" id="bwes-options-editor">';
		echo '<table class="widefat bwes-options-table"><thead><tr>';
		echo '<th>' . esc_html__( 'Key', 'bwes' ) . '</th>';
		echo '<th>' . esc_html__( 'Label', 'bwes' ) . '</th>';
		echo '<th>' . esc_html__( 'Fee', 'bwes' ) . '</th>';
		echo '<th class="bwes-col-feetype">' . esc_html__( 'Fee type', 'bwes' ) . '</th>';
		echo '<th class="bwes-col-maxkm">' . esc_html__( 'Max km', 'bwes' ) . '</th>';
		echo '<th class="bwes-col-default">' . esc_html__( 'Default', 'bwes' ) . '</th>';
		echo '<th></th>';
		echo '</tr></thead><tbody id="bwes-options-body">';

		$options = ! empty( $service['options'] ) ? $service['options'] : array( array() );
		foreach ( $options as $opt ) {
			$this->render_option_row( $opt );
		}

		echo '</tbody></table>';
		echo '<p><button type="button" class="button" id="bwes-add-option">' . esc_html__( 'Add option', 'bwes' ) . '</button></p>';

		// Row template for JS cloning.
		echo '<script type="text/template" id="bwes-option-template">';
		$this->render_option_row( array() );
		echo '</script>';

		echo '</div>';
	}

	/**
	 * Render a single option row.
	 *
	 * @param array $opt Option data.
	 * @return void
	 */
	private function render_option_row( array $opt ) {
		$key      = isset( $opt['key'] ) ? $opt['key'] : '';
		$label    = isset( $opt['label'] ) ? $opt['label'] : '';
		$fee      = isset( $opt['fee'] ) ? (string) $opt['fee'] : '';
		$fee_type = isset( $opt['fee_type'] ) ? $opt['fee_type'] : 'flat';
		$max_km   = isset( $opt['max_km'] ) ? (string) $opt['max_km'] : '';
		$default  = ! empty( $opt['default'] );

		echo '<tr class="bwes-option-row">';
		printf( '<td><input type="text" name="option_key[]" value="%s" class="bwes-opt-key" /></td>', esc_attr( $key ) );
		printf( '<td><input type="text" name="option_label[]" value="%s" /></td>', esc_attr( $label ) );
		printf( '<td><input type="text" name="option_fee[]" value="%s" class="small-text" /></td>', esc_attr( $fee ) );

		echo '<td class="bwes-col-feetype"><select name="option_fee_type[]">';
		foreach ( array( 'flat' => __( 'Flat', 'bwes' ), 'percent' => __( 'Percent', 'bwes' ), 'per_item' => __( 'Per item', 'bwes' ) ) as $val => $lbl ) {
			printf( '<option value="%1$s" %2$s>%3$s</option>', esc_attr( $val ), selected( $fee_type, $val, false ), esc_html( $lbl ) );
		}
		echo '</select></td>';

		printf( '<td class="bwes-col-maxkm"><input type="text" name="option_max_km[]" value="%s" class="small-text" /></td>', esc_attr( $max_km ) );
		printf( '<td class="bwes-col-default"><input type="checkbox" name="option_default[]" value="1" %s /></td>', checked( $default, true, false ) );
		echo '<td><button type="button" class="button-link bwes-remove-option">&times;</button></td>';
		echo '</tr>';
	}

	/**
	 * Persist a single service.
	 *
	 * @return void
	 */
	public function handle_save_service() {
		$this->guard( 'bwes_save_service' );

		$raw = isset( $_POST['service'] ) && is_array( $_POST['service'] ) ? wp_unslash( $_POST['service'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput

		$service = array(
			'id'              => isset( $raw['id'] ) ? sanitize_key( $raw['id'] ) : '',
			'module'          => isset( $raw['module'] ) ? sanitize_key( $raw['module'] ) : 'custom',
			'title'           => isset( $raw['title'] ) ? sanitize_text_field( $raw['title'] ) : '',
			'description'     => isset( $raw['description'] ) ? wp_kses_post( $raw['description'] ) : '',
			'type'            => isset( $raw['type'] ) ? sanitize_key( $raw['type'] ) : 'checkbox',
			'fee'             => isset( $raw['fee'] ) ? $raw['fee'] : 0,
			'fee_type'        => isset( $raw['fee_type'] ) ? sanitize_key( $raw['fee_type'] ) : 'flat',
			'taxable'         => ! empty( $raw['taxable'] ),
			'tax_class'       => isset( $raw['tax_class'] ) ? sanitize_text_field( $raw['tax_class'] ) : '',
			'enabled'         => ! empty( $raw['enabled'] ),
			'collect_message' => ! empty( $raw['collect_message'] ),
			'message_label'   => isset( $raw['message_label'] ) ? sanitize_text_field( $raw['message_label'] ) : '',
			'suppress_shipping' => ! empty( $raw['suppress_shipping'] ),
			'exclusive'       => ! empty( $raw['exclusive'] ),
			'hint'            => isset( $raw['hint'] ) ? sanitize_text_field( $raw['hint'] ) : '',
			'position'        => isset( $raw['position'] ) ? absint( $raw['position'] ) : 100,
			'allowed_roles'   => isset( $raw['allowed_roles'] ) ? (array) $raw['allowed_roles'] : array(),
			'options'         => $this->collect_options_from_post( isset( $raw['type'] ) ? sanitize_key( $raw['type'] ) : 'checkbox' ),
		);

		if ( '' === $service['title'] ) {
			$service['title'] = __( 'Untitled service', 'bwes' );
		}

		$this->services->upsert( $service );

		$this->redirect_with( 'services', 'service_saved' );
	}

	/**
	 * Build the options array from the parallel POST arrays.
	 *
	 * @param string $type Service type.
	 * @return array
	 */
	private function collect_options_from_post( $type ) {
		if ( ! in_array( $type, array( 'radio', 'radius' ), true ) ) {
			return array();
		}

		// phpcs:disable WordPress.Security.ValidatedSanitizedInput -- sanitized below via normalize().
		$keys      = isset( $_POST['option_key'] ) && is_array( $_POST['option_key'] ) ? wp_unslash( $_POST['option_key'] ) : array();
		$labels    = isset( $_POST['option_label'] ) && is_array( $_POST['option_label'] ) ? wp_unslash( $_POST['option_label'] ) : array();
		$fees      = isset( $_POST['option_fee'] ) && is_array( $_POST['option_fee'] ) ? wp_unslash( $_POST['option_fee'] ) : array();
		$fee_types = isset( $_POST['option_fee_type'] ) && is_array( $_POST['option_fee_type'] ) ? wp_unslash( $_POST['option_fee_type'] ) : array();
		$max_kms   = isset( $_POST['option_max_km'] ) && is_array( $_POST['option_max_km'] ) ? wp_unslash( $_POST['option_max_km'] ) : array();
		$defaults  = isset( $_POST['option_default'] ) && is_array( $_POST['option_default'] ) ? wp_unslash( $_POST['option_default'] ) : array();
		// phpcs:enable

		$options = array();
		$count   = count( $labels );
		for ( $i = 0; $i < $count; $i++ ) {
			$label = isset( $labels[ $i ] ) ? sanitize_text_field( $labels[ $i ] ) : '';
			$key   = isset( $keys[ $i ] ) ? sanitize_key( $keys[ $i ] ) : '';
			if ( '' === $label && '' === $key ) {
				continue;
			}

			$opt = array(
				'key'   => $key,
				'label' => $label,
				'fee'   => isset( $fees[ $i ] ) ? $fees[ $i ] : 0,
			);

			if ( 'radius' === $type ) {
				$opt['max_km'] = isset( $max_kms[ $i ] ) ? (float) str_replace( ',', '.', $max_kms[ $i ] ) : 0;
			} else {
				$opt['fee_type'] = isset( $fee_types[ $i ] ) ? sanitize_key( $fee_types[ $i ] ) : 'flat';
				// option_default is a sparse checkbox array; treat any present truthy value at this index as default.
				$opt['default'] = ! empty( $defaults[ $i ] );
			}

			$options[] = $opt;
		}

		return $options;
	}

	/**
	 * Delete a service.
	 *
	 * @return void
	 */
	public function handle_delete_service() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'bwes' ) );
		}

		$id = isset( $_GET['service'] ) ? sanitize_key( wp_unslash( $_GET['service'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		check_admin_referer( 'bwes_delete_service_' . $id );

		if ( '' !== $id ) {
			$this->services->delete( $id );
		}

		$this->redirect_with( 'services', 'service_deleted' );
	}

	/* --------------------------------------------------------------------- *
	 * Helpers
	 * --------------------------------------------------------------------- */

	/**
	 * Capability + nonce guard for admin-post handlers.
	 *
	 * @param string $action Nonce action.
	 * @return void
	 */
	private function guard( $action ) {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'bwes' ) );
		}
		check_admin_referer( $action );
	}

	/**
	 * Redirect back to a tab with a message code.
	 *
	 * @param string $tab Tab slug.
	 * @param string $msg Message code.
	 * @return void
	 */
	private function redirect_with( $tab, $msg ) {
		$url = add_query_arg(
			array(
				'page'     => self::MENU_SLUG,
				'tab'      => $tab,
				'bwes_msg' => $msg,
			),
			admin_url( 'admin.php' )
		);
		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Render a simple text input row.
	 *
	 * @param string $name     Field name.
	 * @param string $label    Label.
	 * @param string $value    Value.
	 * @param bool   $required Whether required.
	 * @param string $class    Input CSS class.
	 * @return void
	 */
	private function text_row( $name, $label, $value, $required = false, $class = 'regular-text' ) {
		$id = 'bwes-' . sanitize_key( $name );
		printf(
			'<tr><th scope="row"><label for="%1$s">%2$s</label></th><td><input type="text" id="%1$s" name="%3$s" value="%4$s" class="%5$s" %6$s /></td></tr>',
			esc_attr( $id ),
			esc_html( $label ),
			esc_attr( $name ),
			esc_attr( $value ),
			esc_attr( $class ),
			$required ? 'required' : ''
		);
	}
}
