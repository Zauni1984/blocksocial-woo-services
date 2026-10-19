<?php
/**
 * Storefront integration for the Lite plugin: render, AJAX, fees, pickup
 * shipping suppression, order persistence. Fees are always recomputed
 * server-side; the client only ever sends a selection flag.
 *
 * @package BlockSocial\WooExtraServicesLite
 */

defined( 'ABSPATH' ) || exit;

/**
 * Lite checkout handler.
 */
class BWESL_Checkout {

	const SESSION_SEL = 'bwesl_sel';
	const SESSION_MSG = 'bwesl_msg';
	const NONCE       = 'bwesl_frontend';
	const ORDER_META  = '_bwesl_services';

	/**
	 * Settings.
	 *
	 * @var BWESL_Settings
	 */
	private $settings;

	/**
	 * Constructor.
	 *
	 * @param BWESL_Settings $settings Settings.
	 */
	public function __construct( BWESL_Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function hooks() {
		$location = $this->settings->get( 'display_location', 'checkout' );

		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ) );

		if ( 'cart' === $location || 'both' === $location ) {
			add_action( 'woocommerce_cart_collaterals', array( $this, 'render' ), 5 );
		}
		if ( 'checkout' === $location || 'both' === $location ) {
			add_action( 'woocommerce_review_order_before_payment', array( $this, 'render' ) );
		}

		add_action( 'woocommerce_cart_calculate_fees', array( $this, 'add_fees' ), 20 );

		add_filter( 'woocommerce_cart_shipping_packages', array( $this, 'tag_packages' ) );
		add_filter( 'woocommerce_package_rates', array( $this, 'maybe_free_shipping' ), 100, 2 );

		add_action( 'wp_ajax_bwesl_update', array( $this, 'ajax_update' ) );
		add_action( 'wp_ajax_nopriv_bwesl_update', array( $this, 'ajax_update' ) );
		add_action( 'woocommerce_checkout_update_order_review', array( $this, 'on_review_update' ) );

		add_action( 'woocommerce_checkout_create_order', array( $this, 'save_to_order' ), 10, 2 );
		add_action( 'woocommerce_order_details_after_order_table', array( $this, 'render_order' ) );
		add_action( 'woocommerce_email_after_order_table', array( $this, 'render_order' ) );
	}

	/**
	 * The two Lite services, as normalized definitions, honoring enable flags.
	 *
	 * @return array<string,array>
	 */
	private function active_services() {
		$s   = $this->settings->all();
		$out = array();

		if ( ! empty( $s['gift_enabled'] ) ) {
			$out['gift'] = array(
				'id'              => 'gift',
				'title'           => $s['gift_title'],
				'description'     => $s['gift_description'],
				'fee'             => (float) $s['gift_fee'],
				'taxable'         => ! empty( $s['gift_taxable'] ),
				'collect_message' => ! empty( $s['gift_collect_message'] ),
				'message_label'   => $s['gift_message_label'],
				'suppress'        => false,
			);
		}
		if ( ! empty( $s['pickup_enabled'] ) ) {
			$out['pickup'] = array(
				'id'              => 'pickup',
				'title'           => $s['pickup_title'],
				'description'     => $s['pickup_description'],
				'fee'             => (float) $s['pickup_fee'],
				'taxable'         => ! empty( $s['pickup_taxable'] ),
				'collect_message' => false,
				'message_label'   => '',
				'suppress'        => true,
			);
		}

		return $out;
	}

	/**
	 * Enqueue assets on cart/checkout.
	 *
	 * @return void
	 */
	public function enqueue() {
		if ( ! function_exists( 'is_cart' ) || ( ! is_cart() && ! is_checkout() ) ) {
			return;
		}
		wp_enqueue_style( 'bwesl-frontend', BWESL_PLUGIN_URL . 'assets/css/bwesl-frontend.css', array(), BWESL_VERSION );
		wp_enqueue_script( 'bwesl-frontend', BWESL_PLUGIN_URL . 'assets/js/bwesl-frontend.js', array( 'jquery' ), BWESL_VERSION, true );
		wp_localize_script(
			'bwesl-frontend',
			'bwesl',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( self::NONCE ),
				'isCart'  => is_cart(),
			)
		);
	}

	/**
	 * Render the services panel.
	 *
	 * @return void
	 */
	public function render() {
		if ( ! WC()->cart || WC()->cart->is_empty() ) {
			return;
		}
		$services = $this->active_services();
		if ( empty( $services ) ) {
			return;
		}

		$sel = $this->get_sel();
		$msg = $this->get_msg();

		echo '<div class="bwesl-services" id="bwesl-services">';
		echo '<h3 class="bwesl-services__heading">' . esc_html__( 'Extra services', 'bwes-lite' ) . '</h3>';

		foreach ( $services as $id => $svc ) {
			$checked = ! empty( $sel[ $id ] );
			echo '<div class="bwesl-service" data-service="' . esc_attr( $id ) . '">';
			echo '<div class="bwesl-service__title">' . esc_html( $svc['title'] ) . '</div>';
			if ( '' !== $svc['description'] ) {
				echo '<div class="bwesl-service__desc">' . esc_html( $svc['description'] ) . '</div>';
			}
			printf(
				'<label class="bwesl-option"><input type="checkbox" class="bwesl-input" name="bwesl[%1$s]" value="1" %2$s /> <span>%3$s%4$s</span></label>',
				esc_attr( $id ),
				checked( $checked, true, false ),
				esc_html__( 'Yes, add this', 'bwes-lite' ),
				$this->fee_label( $svc )
			);

			if ( ! empty( $svc['collect_message'] ) ) {
				$label = '' !== $svc['message_label'] ? $svc['message_label'] : __( 'Message', 'bwes-lite' );
				printf(
					'<p class="bwesl-message"><label for="bwesl-msg-%1$s">%2$s</label><textarea id="bwesl-msg-%1$s" class="bwesl-message-input" name="bwesl_message[%1$s]" rows="2" maxlength="500">%3$s</textarea></p>',
					esc_attr( $id ),
					esc_html( $label ),
					esc_textarea( isset( $msg[ $id ] ) ? $msg[ $id ] : '' )
				);
			}
			echo '</div>';
		}

		echo '</div>';
	}

	/**
	 * Build the inline fee label for an option.
	 *
	 * @param array $svc Service.
	 * @return string
	 */
	private function fee_label( array $svc ) {
		if ( 0.0 === (float) $svc['fee'] ) {
			return ' <span class="bwesl-price">' . esc_html__( 'free', 'bwes-lite' ) . '</span>';
		}

		return ' <span class="bwesl-price">+ ' . esc_html( wp_strip_all_tags( wc_price( $svc['fee'] ) ) ) . '</span>';
	}

	/**
	 * AJAX: persist selection into the session.
	 *
	 * @return void
	 */
	public function ajax_update() {
		check_ajax_referer( self::NONCE, 'nonce' );

		$raw_sel = isset( $_POST['selections'] ) && is_array( $_POST['selections'] ) ? wp_unslash( $_POST['selections'] ) : array();
		$raw_msg = isset( $_POST['messages'] ) && is_array( $_POST['messages'] ) ? wp_unslash( $_POST['messages'] ) : array();
		$this->store( $raw_sel, $raw_msg );

		if ( WC()->cart ) {
			WC()->cart->calculate_totals();
		}
		wp_send_json_success();
	}

	/**
	 * Capture bwesl fields when the checkout order review updates.
	 *
	 * @param string $post_data Serialized form data.
	 * @return void
	 */
	public function on_review_update( $post_data ) {
		$parsed = array();
		parse_str( (string) $post_data, $parsed );
		$this->store(
			isset( $parsed['bwesl'] ) && is_array( $parsed['bwesl'] ) ? $parsed['bwesl'] : array(),
			isset( $parsed['bwesl_message'] ) && is_array( $parsed['bwesl_message'] ) ? $parsed['bwesl_message'] : array()
		);
	}

	/**
	 * Sanitize + store selection/message in the session.
	 *
	 * @param array $raw_sel Raw selections.
	 * @param array $raw_msg Raw messages.
	 * @return void
	 */
	private function store( array $raw_sel, array $raw_msg ) {
		$services = $this->active_services();
		$sel      = array();
		$msg      = array();

		foreach ( $services as $id => $svc ) {
			$sel[ $id ] = ! empty( $raw_sel[ $id ] ) ? '1' : '';
			if ( ! empty( $svc['collect_message'] ) && isset( $raw_msg[ $id ] ) ) {
				$text = mb_substr( sanitize_textarea_field( (string) $raw_msg[ $id ] ), 0, 500 );
				if ( '' !== $text ) {
					$msg[ $id ] = $text;
				}
			}
		}

		if ( WC()->session ) {
			WC()->session->set( self::SESSION_SEL, $sel );
			WC()->session->set( self::SESSION_MSG, $msg );
		}
	}

	/**
	 * Add the configured fees to the cart (server-side source of truth).
	 *
	 * @param WC_Cart $cart Cart.
	 * @return void
	 */
	public function add_fees( $cart ) {
		if ( ( is_admin() && ! wp_doing_ajax() ) || ! $cart instanceof WC_Cart ) {
			return;
		}

		$services = $this->active_services();
		$sel      = $this->get_sel();

		foreach ( $services as $id => $svc ) {
			if ( empty( $sel[ $id ] ) || 0.0 === (float) $svc['fee'] ) {
				continue;
			}
			$cart->add_fee( $svc['title'], (float) $svc['fee'], (bool) $svc['taxable'], '' );
		}
	}

	/**
	 * Whether pickup is currently selected.
	 *
	 * @return bool
	 */
	private function pickup_selected() {
		$services = $this->active_services();
		if ( ! isset( $services['pickup'] ) ) {
			return false;
		}
		$sel = $this->get_sel();

		return ! empty( $sel['pickup'] );
	}

	/**
	 * Make package hashes depend on the pickup flag so rates recalc on toggle.
	 *
	 * @param array $packages Packages.
	 * @return array
	 */
	public function tag_packages( $packages ) {
		$flag = $this->pickup_selected() ? 1 : 0;
		if ( is_array( $packages ) ) {
			foreach ( $packages as $k => $p ) {
				$packages[ $k ]['bwesl_pickup'] = $flag;
			}
		}

		return $packages;
	}

	/**
	 * Replace shipping with a single free pickup rate when pickup is selected.
	 *
	 * @param array $rates   Rates.
	 * @param array $package Package.
	 * @return array
	 */
	public function maybe_free_shipping( $rates, $package ) {
		if ( ! $this->pickup_selected() ) {
			return $rates;
		}
		$services = $this->active_services();
		$label    = isset( $services['pickup']['title'] ) && '' !== $services['pickup']['title']
			? $services['pickup']['title']
			: __( 'Local pickup', 'bwes-lite' );

		return array( 'bwesl_pickup' => new WC_Shipping_Rate( 'bwesl_pickup', $label, 0.0, array(), 'bwesl_pickup' ) );
	}

	/**
	 * Persist selected services onto the order.
	 *
	 * @param WC_Order $order Order.
	 * @param array    $data  Checkout data.
	 * @return void
	 */
	public function save_to_order( $order, $data ) {
		$services = $this->active_services();
		$sel      = $this->get_sel();
		$msg      = $this->get_msg();
		$stored   = array();

		foreach ( $services as $id => $svc ) {
			if ( empty( $sel[ $id ] ) ) {
				continue;
			}
			$entry = array(
				'title' => $svc['title'],
				'fee'   => (float) $svc['fee'],
			);
			if ( ! empty( $svc['collect_message'] ) && ! empty( $msg[ $id ] ) ) {
				$entry['message'] = $msg[ $id ];
			}
			$stored[] = $entry;
		}

		if ( ! empty( $stored ) ) {
			$order->update_meta_data( self::ORDER_META, $stored );
		}
	}

	/**
	 * Show chosen services on the order page / emails.
	 *
	 * @param WC_Order $order Order.
	 * @return void
	 */
	public function render_order( $order ) {
		if ( ! $order instanceof WC_Order ) {
			return;
		}
		$stored = $order->get_meta( self::ORDER_META );
		if ( empty( $stored ) || ! is_array( $stored ) ) {
			return;
		}

		echo '<h2>' . esc_html__( 'Extra services', 'bwes-lite' ) . '</h2><ul>';
		foreach ( $stored as $entry ) {
			echo '<li>' . esc_html( $entry['title'] );
			if ( ! empty( $entry['message'] ) ) {
				echo '<br /><em>' . esc_html( $entry['message'] ) . '</em>';
			}
			echo '</li>';
		}
		echo '</ul>';
	}

	/**
	 * Session selection map.
	 *
	 * @return array
	 */
	private function get_sel() {
		if ( ! WC()->session ) {
			return array();
		}
		$v = WC()->session->get( self::SESSION_SEL, array() );

		return is_array( $v ) ? $v : array();
	}

	/**
	 * Session message map.
	 *
	 * @return array
	 */
	private function get_msg() {
		if ( ! WC()->session ) {
			return array();
		}
		$v = WC()->session->get( self::SESSION_MSG, array() );

		return is_array( $v ) ? $v : array();
	}
}
