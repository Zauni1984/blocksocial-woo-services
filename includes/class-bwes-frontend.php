<?php
/**
 * Storefront integration: rendering, AJAX, fees, order persistence.
 *
 * @package BlockSocial\WooExtraServices
 */

defined( 'ABSPATH' ) || exit;

/**
 * Handles everything the customer sees and the fees they pay.
 */
class BWES_Frontend {

	const SESSION_SELECTIONS = 'bwes_selections';
	const SESSION_MESSAGES   = 'bwes_messages';
	const NONCE_ACTION       = 'bwes_frontend';
	const ORDER_META         = '_bwes_selected_services';

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
	 * Map of fee line label => metadata, built while adding fees so the created
	 * order fee items can be tagged (e.g. for invoices / Germanized).
	 *
	 * @var array<string,array>
	 */
	private $fee_meta_map = array();

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
	 * Register hooks.
	 *
	 * @return void
	 */
	public function hooks() {
		$settings = get_option( BWES_Install::SETTINGS_OPTION, array() );
		$location = isset( $settings['display_location'] ) ? $settings['display_location'] : 'checkout';

		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );

		if ( 'cart' === $location || 'both' === $location ) {
			add_action( 'woocommerce_cart_collaterals', array( $this, 'render' ), 5 );
		}
		if ( 'checkout' === $location || 'both' === $location ) {
			add_action( 'woocommerce_review_order_before_payment', array( $this, 'render' ) );
		}

		// Fee application — the single source of truth for pricing.
		add_action( 'woocommerce_cart_calculate_fees', array( $this, 'add_fees' ), 20 );

		// Local pickup (and any "suppress shipping" service) removes shipping costs.
		add_filter( 'woocommerce_cart_shipping_packages', array( $this, 'tag_shipping_packages' ) );
		add_filter( 'woocommerce_package_rates', array( $this, 'maybe_suppress_shipping' ), 100, 2 );

		// AJAX (logged-in + guests).
		add_action( 'wp_ajax_bwes_update_selections', array( $this, 'ajax_update_selections' ) );
		add_action( 'wp_ajax_nopriv_bwes_update_selections', array( $this, 'ajax_update_selections' ) );

		// Validate + persist on checkout.
		add_action( 'woocommerce_checkout_process', array( $this, 'validate_checkout' ) );
		add_action( 'woocommerce_checkout_create_order', array( $this, 'save_to_order' ), 10, 2 );

		// Tag the created order fee line items so the fee (and any message) is
		// clearly attributed on the order and on invoices (e.g. Germanized).
		add_action( 'woocommerce_checkout_create_order_fee_item', array( $this, 'tag_fee_item' ), 10, 4 );

		// Show the choices on the order / emails / admin.
		add_action( 'woocommerce_order_details_after_order_table', array( $this, 'render_order_details' ) );
		add_action( 'woocommerce_email_after_order_table', array( $this, 'render_order_details' ) );
		add_action( 'woocommerce_admin_order_data_after_shipping_address', array( $this, 'render_admin_order_details' ) );

		// Keep fees fresh when the shipping address changes on checkout.
		add_action( 'woocommerce_checkout_update_order_review', array( $this, 'on_order_review_update' ) );
	}

	/**
	 * Enqueue front-end assets only on cart / checkout.
	 *
	 * @return void
	 */
	public function enqueue_assets() {
		if ( ! function_exists( 'is_cart' ) || ( ! is_cart() && ! is_checkout() ) ) {
			return;
		}

		wp_enqueue_style( 'bwes-frontend', BWES_PLUGIN_URL . 'assets/css/bwes-frontend.css', array(), BWES_VERSION );
		wp_enqueue_script( 'bwes-frontend', BWES_PLUGIN_URL . 'assets/js/bwes-frontend.js', array( 'jquery' ), BWES_VERSION, true );

		wp_localize_script(
			'bwes-frontend',
			'bwesFrontend',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( self::NONCE_ACTION ),
				'isCart'  => is_cart(),
			)
		);
	}

	/**
	 * Render the extra-services panel.
	 *
	 * @return void
	 */
	public function render() {
		if ( ! WC()->cart || WC()->cart->is_empty() ) {
			return;
		}

		$services = $this->services->get_available_for_current_user( WC()->cart );
		if ( empty( $services ) ) {
			return;
		}

		$selections = $this->get_selections();
		$messages   = $this->get_messages();

		echo '<div class="bwes-services" id="bwes-services">';
		echo '<h3 class="bwes-services__heading">' . esc_html__( 'Extra services', 'bwes' ) . '</h3>';

		foreach ( $services as $service ) {
			$this->render_service( $service, $selections, $messages );
		}

		echo '</div>';
	}

	/**
	 * Render one service block.
	 *
	 * @param array $service    Service definition.
	 * @param array $selections Current selections.
	 * @param array $messages   Current messages.
	 * @return void
	 */
	private function render_service( array $service, array $selections, array $messages ) {
		$id       = $service['id'];
		$field_id = 'bwes-field-' . $id;
		$current  = isset( $selections[ $id ] ) ? $selections[ $id ] : '';

		echo '<div class="bwes-service bwes-service--' . esc_attr( $service['type'] ) . '" data-service="' . esc_attr( $id ) . '">';
		echo '<div class="bwes-service__title">' . esc_html( $service['title'] ) . '</div>';

		if ( '' !== $service['description'] ) {
			echo '<div class="bwes-service__desc">' . wp_kses_post( wpautop( $service['description'] ) ) . '</div>';
		}

		switch ( $service['type'] ) {
			case 'checkbox':
			case 'radius':
				$label_price = $this->fee_preview_label( $service );
				printf(
					'<label class="bwes-option"><input type="checkbox" class="bwes-input" name="bwes[%1$s]" id="%2$s" value="1" %3$s /> <span>%4$s%5$s</span></label>',
					esc_attr( $id ),
					esc_attr( $field_id ),
					checked( ! empty( $current ), true, false ),
					esc_html( 'radius' === $service['type'] ? __( 'Yes, deliver to my address', 'bwes' ) : __( 'Yes, add this', 'bwes' ) ),
					$label_price ? ' <span class="bwes-price">' . esc_html( $label_price ) . '</span>' : ''
				);
				break;

			case 'radio':
				foreach ( $service['options'] as $option ) {
					$checked = ( (string) $current === (string) $option['key'] )
						|| ( '' === $current && ! empty( $option['default'] ) );
					$price   = $this->option_fee_label( $option );
					printf(
						'<label class="bwes-option"><input type="radio" class="bwes-input" name="bwes[%1$s]" value="%2$s" %3$s /> <span>%4$s%5$s</span></label>',
						esc_attr( $id ),
						esc_attr( $option['key'] ),
						checked( $checked, true, false ),
						esc_html( $option['label'] ),
						$price ? ' <span class="bwes-price">' . esc_html( $price ) . '</span>' : ''
					);
				}
				break;
		}

		if ( ! empty( $service['collect_message'] ) ) {
			$msg   = isset( $messages[ $id ] ) ? $messages[ $id ] : '';
			$label = '' !== $service['message_label'] ? $service['message_label'] : __( 'Message', 'bwes' );
			printf(
				'<p class="bwes-message"><label for="%1$s">%2$s</label><textarea id="%1$s" class="bwes-message-input" name="bwes_message[%3$s]" rows="2" maxlength="500">%4$s</textarea></p>',
				esc_attr( 'bwes-msg-' . $id ),
				esc_html( $label ),
				esc_attr( $id ),
				esc_textarea( $msg )
			);
		}

		echo '</div>';
	}

	/**
	 * Human-readable fee preview for checkbox/radius services.
	 *
	 * @param array $service Service.
	 * @return string
	 */
	private function fee_preview_label( array $service ) {
		if ( 'radius' === $service['type'] ) {
			return __( 'fee based on distance', 'bwes' );
		}

		if ( 'flat' === $service['fee_type'] ) {
			if ( 0.0 === (float) $service['fee'] ) {
				return __( 'free', 'bwes' );
			}
			return '+ ' . wp_strip_all_tags( wc_price( $service['fee'] ) );
		}
		if ( 'percent' === $service['fee_type'] ) {
			return '+ ' . rtrim( rtrim( (string) $service['fee'], '0' ), '.' ) . '%';
		}
		return '+ ' . wp_strip_all_tags( wc_price( $service['fee'] ) ) . ' ' . __( 'per item', 'bwes' );
	}

	/**
	 * Human-readable fee for a radio option.
	 *
	 * @param array $option Option.
	 * @return string
	 */
	private function option_fee_label( array $option ) {
		$fee_type = isset( $option['fee_type'] ) ? $option['fee_type'] : 'flat';
		if ( 0.0 === (float) $option['fee'] ) {
			return '';
		}
		if ( 'percent' === $fee_type ) {
			return '+ ' . rtrim( rtrim( (string) $option['fee'], '0' ), '.' ) . '%';
		}
		$suffix = 'per_item' === $fee_type ? ' ' . __( 'per item', 'bwes' ) : '';
		return '+ ' . wp_strip_all_tags( wc_price( $option['fee'] ) ) . $suffix;
	}

	/**
	 * AJAX handler: persist selections into the WC session.
	 *
	 * @return void
	 */
	public function ajax_update_selections() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		$raw_sel = isset( $_POST['selections'] ) && is_array( $_POST['selections'] )
			? wp_unslash( $_POST['selections'] )
			: array();
		$raw_msg = isset( $_POST['messages'] ) && is_array( $_POST['messages'] )
			? wp_unslash( $_POST['messages'] )
			: array();

		$this->store_from_request( $raw_sel, $raw_msg );

		if ( WC()->cart ) {
			WC()->cart->calculate_totals();
		}

		wp_send_json_success( array( 'stored' => true ) );
	}

	/**
	 * Sanitize + store selections and messages coming from a request array.
	 *
	 * @param array $raw_selections Raw selection map.
	 * @param array $raw_messages   Raw message map.
	 * @return void
	 */
	private function store_from_request( array $raw_selections, array $raw_messages ) {
		$available   = $this->services->get_available_for_current_user( WC()->cart );
		$selections  = array();
		$messages    = array();

		foreach ( $available as $id => $service ) {
			$value = isset( $raw_selections[ $id ] ) ? $raw_selections[ $id ] : null;
			$clean = $this->sanitize_selection( $service, $value );
			if ( null !== $clean && '' !== $clean ) {
				$selections[ $id ] = $clean;
			}

			if ( ! empty( $service['collect_message'] ) && isset( $raw_messages[ $id ] ) ) {
				$msg = sanitize_textarea_field( (string) $raw_messages[ $id ] );
				$msg = mb_substr( $msg, 0, 500 );
				if ( '' !== $msg ) {
					$messages[ $id ] = $msg;
				}
			}
		}

		if ( WC()->session ) {
			WC()->session->set( self::SESSION_SELECTIONS, $selections );
			WC()->session->set( self::SESSION_MESSAGES, $messages );
		}
	}

	/**
	 * Validate & normalize a single selection against its service definition.
	 *
	 * @param array $service Service definition.
	 * @param mixed $value   Raw value.
	 * @return string|null Clean value, or null.
	 */
	public function sanitize_selection( array $service, $value ) {
		switch ( $service['type'] ) {
			case 'checkbox':
			case 'radius':
				return ! empty( $value ) ? '1' : '';

			case 'radio':
				$key = sanitize_key( is_scalar( $value ) ? (string) $value : '' );
				if ( '' === $key ) {
					return '';
				}
				return null !== BWES_Fees::find_option( $service, $key ) ? $key : '';
		}

		return '';
	}

	/**
	 * Add the configured fees to the cart. Single source of pricing truth.
	 *
	 * @param WC_Cart $cart Cart instance.
	 * @return void
	 */
	public function add_fees( $cart ) {
		if ( is_admin() && ! wp_doing_ajax() ) {
			return;
		}
		if ( ! $cart instanceof WC_Cart ) {
			return;
		}

		$available  = $this->services->get_available_for_current_user( $cart );
		$selections = $this->get_selections();
		$messages   = $this->get_messages();

		// Reset the per-request map that links fee lines back to services.
		$this->fee_meta_map = array();

		foreach ( $available as $id => $service ) {
			$selection = isset( $selections[ $id ] ) ? $selections[ $id ] : null;

			// Re-validate the selection against the current definition.
			$selection = $this->sanitize_selection( $service, $selection );
			if ( null === $selection || '' === $selection ) {
				continue;
			}

			$fee = BWES_Fees::compute( $service, $selection, $cart );
			if ( null === $fee || 0.0 === (float) $fee ) {
				continue;
			}

			$label = $this->fee_label( $service, $selection );
			$cart->add_fee(
				$label,
				$fee,
				(bool) $service['taxable'],
				$service['taxable'] ? $service['tax_class'] : ''
			);

			// Remember how to tag the resulting order fee item.
			$this->fee_meta_map[ $label ] = array(
				'service_id'    => $id,
				'message'       => ( ! empty( $service['collect_message'] ) && ! empty( $messages[ $id ] ) ) ? $messages[ $id ] : '',
				'message_label' => '' !== $service['message_label'] ? $service['message_label'] : __( 'Message', 'bwes' ),
			);
		}
	}

	/**
	 * Attach service metadata to the order fee line item during checkout.
	 *
	 * The fee itself is already a real order line (created by WooCommerce from
	 * the cart fee), so it appears on invoices — including Germanized invoices —
	 * automatically. This additionally stores a hidden service reference and, if
	 * present, renders the customer's message as visible meta beneath the line.
	 *
	 * @param WC_Order_Item_Fee $item    The order fee item being created.
	 * @param string            $fee_key The cart fee key.
	 * @param object            $fee     The cart fee object.
	 * @param WC_Order          $order   The order being created.
	 * @return void
	 */
	public function tag_fee_item( $item, $fee_key, $fee, $order ) {
		$name = isset( $fee->name ) && '' !== $fee->name ? $fee->name : $item->get_name();
		if ( ! isset( $this->fee_meta_map[ $name ] ) ) {
			return;
		}

		$info = $this->fee_meta_map[ $name ];

		// Hidden reference (underscore-prefixed keys are not shown to customers).
		$item->add_meta_data( '_bwes_service_id', $info['service_id'], true );

		// Visible message (e.g. gift message) so it prints on the invoice/email.
		if ( '' !== $info['message'] ) {
			$item->add_meta_data( $info['message_label'], $info['message'], true );
		}
	}

	/**
	 * Return the currently selected service that suppresses shipping (e.g. local
	 * pickup), or null when none is active.
	 *
	 * @return array|null
	 */
	private function get_active_shipping_suppressor() {
		if ( ! WC()->cart ) {
			return null;
		}

		$available  = $this->services->get_available_for_current_user( WC()->cart );
		$selections = $this->get_selections();

		foreach ( $available as $id => $service ) {
			if ( empty( $service['suppress_shipping'] ) ) {
				continue;
			}
			$selection = $this->sanitize_selection( $service, isset( $selections[ $id ] ) ? $selections[ $id ] : null );
			if ( null !== $selection && '' !== $selection ) {
				return $service;
			}
		}

		return null;
	}

	/**
	 * Make shipping packages depend on the pickup selection so WooCommerce
	 * invalidates its cached rates whenever the customer toggles it.
	 *
	 * @param array $packages Shipping packages.
	 * @return array
	 */
	public function tag_shipping_packages( $packages ) {
		$flag = $this->get_active_shipping_suppressor() ? 1 : 0;

		if ( is_array( $packages ) ) {
			foreach ( $packages as $key => $package ) {
				$packages[ $key ]['bwes_suppress_shipping'] = $flag;
			}
		}

		return $packages;
	}

	/**
	 * When a shipping-suppressing service (local pickup) is selected, replace the
	 * available shipping rates with a single zero-cost pickup rate so no shipping
	 * cost is charged.
	 *
	 * @param array $rates   Available rates.
	 * @param array $package Shipping package.
	 * @return array
	 */
	public function maybe_suppress_shipping( $rates, $package ) {
		$service = $this->get_active_shipping_suppressor();
		if ( null === $service ) {
			return $rates;
		}

		$label = '' !== $service['title'] ? $service['title'] : __( 'Local pickup', 'bwes' );

		$rate = new WC_Shipping_Rate(
			'bwes_pickup',
			$label,
			0.0,
			array(),
			'bwes_pickup'
		);

		return array( 'bwes_pickup' => $rate );
	}

	/**
	 * Build the fee line label shown in totals.
	 *
	 * @param array  $service   Service.
	 * @param string $selection Selection.
	 * @return string
	 */
	private function fee_label( array $service, $selection ) {
		if ( 'radio' === $service['type'] ) {
			$option = BWES_Fees::find_option( $service, $selection );
			if ( $option ) {
				return $service['title'] . ': ' . $option['label'];
			}
		}

		return $service['title'];
	}

	/**
	 * Validate required selections on checkout submit.
	 *
	 * @return void
	 */
	public function validate_checkout() {
		$available = $this->services->get_available_for_current_user( WC()->cart );
		$messages  = $this->get_messages();
		$selections = $this->get_selections();

		foreach ( $available as $id => $service ) {
			// Example rule: gift message length is bounded (already enforced),
			// nothing is strictly required by default. Hook for custom rules.
			/**
			 * Allow custom validation per service.
			 *
			 * @param string $error     Error message (empty = valid).
			 * @param array  $service   Service definition.
			 * @param mixed  $selection Current selection.
			 * @param string $message   Current message.
			 */
			$error = apply_filters(
				'bwes_validate_service',
				'',
				$service,
				isset( $selections[ $id ] ) ? $selections[ $id ] : null,
				isset( $messages[ $id ] ) ? $messages[ $id ] : ''
			);

			if ( is_string( $error ) && '' !== $error ) {
				wc_add_notice( esc_html( $error ), 'error' );
			}
		}
	}

	/**
	 * Persist the chosen services onto the order.
	 *
	 * @param WC_Order $order Order object.
	 * @param array    $data  Posted checkout data.
	 * @return void
	 */
	public function save_to_order( $order, $data ) {
		$available  = $this->services->get_available_for_current_user( WC()->cart );
		$selections = $this->get_selections();
		$messages   = $this->get_messages();
		$stored     = array();

		foreach ( $available as $id => $service ) {
			$selection = $this->sanitize_selection( $service, isset( $selections[ $id ] ) ? $selections[ $id ] : null );
			if ( null === $selection || '' === $selection ) {
				continue;
			}

			$fee = BWES_Fees::compute( $service, $selection, WC()->cart );

			$entry = array(
				'id'       => $id,
				'title'    => $service['title'],
				'type'     => $service['type'],
				'value'    => $selection,
				'fee'      => null !== $fee ? (float) $fee : 0.0,
			);

			if ( 'radio' === $service['type'] ) {
				$option = BWES_Fees::find_option( $service, $selection );
				$entry['option_label'] = $option ? $option['label'] : $selection;
			}

			if ( ! empty( $service['collect_message'] ) && ! empty( $messages[ $id ] ) ) {
				$entry['message'] = $messages[ $id ];
			}

			$stored[] = $entry;
		}

		if ( ! empty( $stored ) ) {
			$order->update_meta_data( self::ORDER_META, $stored );
		}
	}

	/**
	 * Render the chosen services on the order-received page and emails.
	 *
	 * @param WC_Order $order Order object.
	 * @return void
	 */
	public function render_order_details( $order ) {
		if ( ! $order instanceof WC_Order ) {
			return;
		}
		$stored = $order->get_meta( self::ORDER_META );
		if ( empty( $stored ) || ! is_array( $stored ) ) {
			return;
		}

		echo '<h2 class="bwes-order-heading">' . esc_html__( 'Extra services', 'bwes' ) . '</h2>';
		echo '<ul class="bwes-order-list">';
		foreach ( $stored as $entry ) {
			$label = isset( $entry['option_label'] ) ? $entry['title'] . ': ' . $entry['option_label'] : $entry['title'];
			echo '<li>' . esc_html( $label );
			if ( ! empty( $entry['message'] ) ) {
				echo '<br /><em>' . esc_html( $entry['message'] ) . '</em>';
			}
			echo '</li>';
		}
		echo '</ul>';
	}

	/**
	 * Render the chosen services inside the admin order screen.
	 *
	 * @param WC_Order $order Order object.
	 * @return void
	 */
	public function render_admin_order_details( $order ) {
		if ( ! $order instanceof WC_Order ) {
			return;
		}
		$stored = $order->get_meta( self::ORDER_META );
		if ( empty( $stored ) || ! is_array( $stored ) ) {
			return;
		}

		echo '<div class="bwes-admin-order"><h4>' . esc_html__( 'Extra services', 'bwes' ) . '</h4><ul>';
		foreach ( $stored as $entry ) {
			$label = isset( $entry['option_label'] ) ? $entry['title'] . ': ' . $entry['option_label'] : $entry['title'];
			echo '<li>' . esc_html( $label );
			if ( isset( $entry['fee'] ) ) {
				echo ' — ' . wp_kses_post( wc_price( $entry['fee'] ) );
			}
			if ( ! empty( $entry['message'] ) ) {
				echo '<br /><em>' . esc_html( $entry['message'] ) . '</em>';
			}
			echo '</li>';
		}
		echo '</ul></div>';
	}

	/**
	 * When the checkout order review updates, capture posted bwes fields so the
	 * fee recalculation reflects the latest selection.
	 *
	 * @param string $post_data Serialized checkout form data.
	 * @return void
	 */
	public function on_order_review_update( $post_data ) {
		$parsed = array();
		parse_str( (string) $post_data, $parsed );

		$raw_sel = isset( $parsed['bwes'] ) && is_array( $parsed['bwes'] ) ? $parsed['bwes'] : array();
		$raw_msg = isset( $parsed['bwes_message'] ) && is_array( $parsed['bwes_message'] ) ? $parsed['bwes_message'] : array();

		$this->store_from_request( $raw_sel, $raw_msg );
	}

	/**
	 * Get sanitized selection map from the session.
	 *
	 * @return array<string,string>
	 */
	private function get_selections() {
		if ( ! WC()->session ) {
			return array();
		}
		$stored = WC()->session->get( self::SESSION_SELECTIONS, array() );

		return is_array( $stored ) ? $stored : array();
	}

	/**
	 * Get sanitized message map from the session.
	 *
	 * @return array<string,string>
	 */
	private function get_messages() {
		if ( ! WC()->session ) {
			return array();
		}
		$stored = WC()->session->get( self::SESSION_MESSAGES, array() );

		return is_array( $stored ) ? $stored : array();
	}
}
