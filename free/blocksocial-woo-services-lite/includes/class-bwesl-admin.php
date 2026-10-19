<?php
/**
 * Admin settings screen for the Lite plugin (capability + nonce gated).
 *
 * @package BlockSocial\WooExtraServicesLite
 */

defined( 'ABSPATH' ) || exit;

/**
 * Lite admin screen.
 */
class BWESL_Admin {

	const SLUG = 'bwesl-settings';
	const CAP  = 'manage_woocommerce';

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
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_post_bwesl_save', array( $this, 'handle_save' ) );
	}

	/**
	 * Add the submenu under WooCommerce.
	 *
	 * @return void
	 */
	public function menu() {
		add_submenu_page(
			'woocommerce',
			__( 'Extra Services Lite', 'bwes-lite' ),
			__( 'Extra Services', 'bwes-lite' ),
			self::CAP,
			self::SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * Render the settings page.
	 *
	 * @return void
	 */
	public function render() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'bwes-lite' ) );
		}

		$s = $this->settings->all();

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'BlockSocial — Extra Services (Lite)', 'bwes-lite' ) . '</h1>';

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['bwesl_saved'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Settings saved.', 'bwes-lite' ) . '</p></div>';
		}

		$this->upsell_banner();

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'bwesl_save' );
		echo '<input type="hidden" name="action" value="bwesl_save" />';

		// General.
		echo '<h2>' . esc_html__( 'General', 'bwes-lite' ) . '</h2>';
		echo '<table class="form-table" role="presentation"><tbody>';
		echo '<tr><th scope="row">' . esc_html__( 'Show services on', 'bwes-lite' ) . '</th><td><select name="s[display_location]">';
		foreach ( array(
			'checkout' => __( 'Checkout page', 'bwes-lite' ),
			'cart'     => __( 'Cart page', 'bwes-lite' ),
			'both'     => __( 'Both cart and checkout', 'bwes-lite' ),
		) as $val => $label ) {
			printf( '<option value="%1$s" %2$s>%3$s</option>', esc_attr( $val ), selected( $s['display_location'], $val, false ), esc_html( $label ) );
		}
		echo '</select></td></tr></tbody></table>';

		// Gift wrapping.
		echo '<h2>' . esc_html__( 'Gift wrapping', 'bwes-lite' ) . '</h2>';
		echo '<table class="form-table" role="presentation"><tbody>';
		$this->row_toggle( 's[gift_enabled]', __( 'Enable', 'bwes-lite' ), ! empty( $s['gift_enabled'] ) );
		$this->row_text( 's[gift_title]', __( 'Title', 'bwes-lite' ), $s['gift_title'] );
		$this->row_text( 's[gift_description]', __( 'Description', 'bwes-lite' ), $s['gift_description'], 'large-text' );
		$this->row_fee( 's[gift_fee]', __( 'Fee', 'bwes-lite' ), $s['gift_fee'] );
		$this->row_toggle( 's[gift_taxable]', __( 'Charge tax on this fee', 'bwes-lite' ), ! empty( $s['gift_taxable'] ) );
		$this->row_toggle( 's[gift_collect_message]', __( 'Collect a gift message', 'bwes-lite' ), ! empty( $s['gift_collect_message'] ) );
		$this->row_text( 's[gift_message_label]', __( 'Message field label', 'bwes-lite' ), $s['gift_message_label'] );
		echo '</tbody></table>';

		// Local pickup.
		echo '<h2>' . esc_html__( 'Local pickup', 'bwes-lite' ) . '</h2>';
		echo '<table class="form-table" role="presentation"><tbody>';
		$this->row_toggle( 's[pickup_enabled]', __( 'Enable', 'bwes-lite' ), ! empty( $s['pickup_enabled'] ) );
		$this->row_text( 's[pickup_title]', __( 'Title', 'bwes-lite' ), $s['pickup_title'] );
		$this->row_text( 's[pickup_description]', __( 'Description', 'bwes-lite' ), $s['pickup_description'], 'large-text' );
		$this->row_fee( 's[pickup_fee]', __( 'Fee', 'bwes-lite' ), $s['pickup_fee'] );
		$this->row_toggle( 's[pickup_taxable]', __( 'Charge tax on this fee', 'bwes-lite' ), ! empty( $s['pickup_taxable'] ) );
		echo '<tr><td colspan="2"><p class="description">' . esc_html__( 'When pickup is selected, the normal shipping cost is set to zero.', 'bwes-lite' ) . '</p></td></tr>';
		echo '</tbody></table>';

		submit_button( __( 'Save changes', 'bwes-lite' ) );
		echo '</form>';
		echo '</div>';
	}

	/**
	 * Render the Pro upsell banner.
	 *
	 * @return void
	 */
	private function upsell_banner() {
		echo '<div style="border:1px solid #c3c4c7;border-left:4px solid #2271b1;background:#fff;padding:14px 16px;margin:16px 0;max-width:820px;">';
		echo '<strong>' . esc_html__( 'Need more?', 'bwes-lite' ) . '</strong> ';
		echo esc_html__( 'BlockSocial Extra Services Pro adds express & priority tiers, carrier choice (DHL instead of DPD), distance-based local delivery, unlimited custom services, per-role fees (e.g. dropshipping) and automatic updates.', 'bwes-lite' );
		echo ' <a href="' . esc_url( BWESL_PRO_URL ) . '" target="_blank" rel="noopener" class="button button-primary" style="margin-left:6px;">' . esc_html__( 'Upgrade to Pro', 'bwes-lite' ) . '</a>';
		echo '</div>';
	}

	/**
	 * Save handler.
	 *
	 * @return void
	 */
	public function handle_save() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'bwes-lite' ) );
		}
		check_admin_referer( 'bwesl_save' );

		$raw = isset( $_POST['s'] ) && is_array( $_POST['s'] ) ? wp_unslash( $_POST['s'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$this->settings->save( $raw );

		wp_safe_redirect( add_query_arg( array( 'page' => self::SLUG, 'bwesl_saved' => 1 ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/* --------------------------------------------------------------------- */

	/**
	 * Text input row.
	 *
	 * @param string $name  Field name.
	 * @param string $label Label.
	 * @param string $value Value.
	 * @param string $class CSS class.
	 * @return void
	 */
	private function row_text( $name, $label, $value, $class = 'regular-text' ) {
		printf(
			'<tr><th scope="row">%1$s</th><td><input type="text" name="%2$s" value="%3$s" class="%4$s" /></td></tr>',
			esc_html( $label ),
			esc_attr( $name ),
			esc_attr( $value ),
			esc_attr( $class )
		);
	}

	/**
	 * Fee input row.
	 *
	 * @param string $name  Field name.
	 * @param string $label Label.
	 * @param float  $value Value.
	 * @return void
	 */
	private function row_fee( $name, $label, $value ) {
		printf(
			'<tr><th scope="row">%1$s</th><td><input type="text" name="%2$s" value="%3$s" class="small-text" /></td></tr>',
			esc_html( $label ),
			esc_attr( $name ),
			esc_attr( (string) $value )
		);
	}

	/**
	 * Checkbox row.
	 *
	 * @param string $name    Field name.
	 * @param string $label   Label.
	 * @param bool   $checked Checked.
	 * @return void
	 */
	private function row_toggle( $name, $label, $checked ) {
		printf(
			'<tr><th scope="row">%1$s</th><td><label><input type="checkbox" name="%2$s" value="1" %3$s /> %1$s</label></td></tr>',
			esc_html( $label ),
			esc_attr( $name ),
			checked( $checked, true, false )
		);
	}
}
