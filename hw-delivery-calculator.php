<?php
/**
 * Plugin Name:       HW Delivery Calculator
 * Plugin URI:        https://github.com/Waldoweb/hw-delivery-calculator
 * Description:       Delivery cost estimate for product pages. The visitor picks their street address (Google Places autocomplete) and sees the driving distance from the kennel and an estimated price. Information only: no cart, checkout or order changes, and nothing is sent to or saved on the site.
 * Version:           1.1.4
 * Author:            Hostetler Web
 * Author URI:        https://hostetlerweb.com
 * Update URI:        https://github.com/Waldoweb/hw-delivery-calculator
 * License:           GPL-2.0-or-later
 * Requires PHP:      7.4
 * Requires at least: 6.0
 * Text Domain:       hw-delivery-calculator
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Updates come from GitHub releases through Plugin Update Checker. Pushing a vX.Y.Z
// tag builds the release zip (.github/workflows/release.yml); sites check every 6
// hours and install it automatically.
if ( is_readable( __DIR__ . '/vendor/plugin-update-checker/plugin-update-checker.php' ) ) {
	require_once __DIR__ . '/vendor/plugin-update-checker/plugin-update-checker.php';
	\YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
		'https://github.com/Waldoweb/hw-delivery-calculator/',
		__FILE__,
		'hw-delivery-calculator',
		6
	)->getVcsApi()->enableReleaseAssets( '/\.zip($|[?&#])/i' );

	add_filter(
		'auto_update_plugin',
		function ( $update, $item ) {
			return ( isset( $item->plugin ) && plugin_basename( __FILE__ ) === $item->plugin ) ? true : $update;
		},
		10,
		2
	);
}

class HW_Delivery_Calculator {

	const VERSION = '1.1.4';
	const OPTION  = 'hw_delivery_settings';
	const HANDLE  = 'hw-delivery-calculator';
	const PAGE    = 'hw-delivery-calculator';

	/** Shortcode instances rendered on this request (for unique ids and one-time CSS). */
	protected static $instances = 0;

	public static function init() {
		add_shortcode( 'hw_delivery_calculator', array( __CLASS__, 'shortcode' ) );
		// Name used in the original brief; kept so either spelling works in templates.
		add_shortcode( 'hwd_delivery_calculator', array( __CLASS__, 'shortcode' ) );

		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'register_assets' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'admin_assets' ) );
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), array( __CLASS__, 'action_links' ) );

		// The script must run before the first tap on the address field, so keep it
		// out of "delay JavaScript" features. It is tiny; Google's script only loads
		// on that first interaction.
		add_filter( 'perfmatters_delay_js_exclusions', array( __CLASS__, 'delay_js_exclusions' ) );
		add_filter( 'rocket_delay_js_exclusions', array( __CLASS__, 'delay_js_exclusions' ) );
	}

	/* ------------------------------------------------------------------ */
	/* Settings                                                            */
	/* ------------------------------------------------------------------ */

	public static function defaults() {
		return array(
			'api_key'        => '',
			'kennel_address' => '',
			'kennel_label'   => '',
			'kennel_lat'     => '',
			'kennel_lng'     => '',
			'rate'           => 2.00,
			'max_miles'      => 600,
			'heading'        => __( 'Delivery estimate', 'hw-delivery-calculator' ),
			'intro'          => __( 'Enter your street address to see the driving distance from our kennel and an estimated delivery price.', 'hw-delivery-calculator' ),
			'label'          => __( 'Your street address', 'hw-delivery-calculator' ),
			'placeholder'    => __( 'Start typing your address', 'hw-delivery-calculator' ),
			'result'         => __( 'About {miles} driving miles from our kennel in {kennel}. Estimated delivery: {price} ({rate}/mile one-way)', 'hw-delivery-calculator' ),
			'note'           => __( 'This is an estimate. We will confirm the delivery price with you before your puppy travels.', 'hw-delivery-calculator' ),
			'over_limit'     => __( 'That address is about {miles} driving miles away, farther than our usual {max}-mile delivery area. Please contact us and we will work out the options with you.', 'hw-delivery-calculator' ),
		) + array_fill_keys( array_keys( self::style_fields() ), '' );
	}

	/**
	 * Styles section. Each field sets a --hwdc-* variable on .hwdc. Fields with a
	 * target also apply it there, but only when set, so the theme's own heading
	 * and body styles stay in charge until someone fills them in.
	 */
	public static function style_fields() {
		return array(
			'font_family'    => array(
				'group'   => 'font',
				'label'   => __( 'Font', 'hw-delivery-calculator' ),
				'var'     => '--hwdc-font',
				'target'  => '.hwdc',
				'prop'    => 'font-family',
				'default' => __( 'Theme font', 'hw-delivery-calculator' ),
				'help'    => __( 'The whole calculator, including the address field.', 'hw-delivery-calculator' ),
			),
			'heading_font'   => array(
				'group'   => 'font',
				'label'   => __( 'Heading font', 'hw-delivery-calculator' ),
				'var'     => '--hwdc-heading-font',
				'target'  => '.hwdc .hwdc__heading',
				'prop'    => 'font-family',
				'default' => __( 'Theme heading font', 'hw-delivery-calculator' ),
			),
			'heading_size'   => array(
				'group'   => 'font',
				'label'   => __( 'Heading size', 'hw-delivery-calculator' ),
				'var'     => '--hwdc-heading-size',
				'target'  => '.hwdc .hwdc__heading',
				'prop'    => 'font-size',
				'default' => __( 'Theme heading size', 'hw-delivery-calculator' ),
			),
			'heading_weight' => array(
				'group'   => 'font',
				'label'   => __( 'Heading weight', 'hw-delivery-calculator' ),
				'var'     => '--hwdc-heading-weight',
				'target'  => '.hwdc .hwdc__heading',
				'prop'    => 'font-weight',
				'default' => __( 'Theme heading weight', 'hw-delivery-calculator' ),
				'help'    => __( 'e.g. 400 (regular), 600 (semi-bold) or 700 (bold).', 'hw-delivery-calculator' ),
			),
			'font_size'      => array(
				'group'   => 'font',
				'label'   => __( 'Text size', 'hw-delivery-calculator' ),
				'var'     => '--hwdc-font-size',
				'target'  => '.hwdc',
				'prop'    => 'font-size',
				'default' => __( 'Theme size', 'hw-delivery-calculator' ),
				'help'    => __( 'e.g. 17px or 1.05rem. The address field never goes below 16px so iPhones do not zoom in.', 'hw-delivery-calculator' ),
			),
			'heading_color'  => array(
				'group'   => 'color',
				'label'   => __( 'Heading', 'hw-delivery-calculator' ),
				'var'     => '--hwdc-heading-color',
				'target'  => '.hwdc .hwdc__heading',
				'prop'    => 'color',
				'default' => __( 'Theme heading color', 'hw-delivery-calculator' ),
			),
			'text_color'     => array(
				'group'   => 'color',
				'label'   => __( 'Text', 'hw-delivery-calculator' ),
				'var'     => '--hwdc-text',
				'default' => __( 'Theme text color', 'hw-delivery-calculator' ),
			),
			'muted_color'    => array(
				'group'   => 'color',
				'label'   => __( 'Secondary text', 'hw-delivery-calculator' ),
				'var'     => '--hwdc-muted',
				'default' => '#5f6368',
				'help'    => __( 'The note under the result, the town line in suggestions, and "Calculating…".', 'hw-delivery-calculator' ),
			),
			'accent_color'   => array(
				'group'   => 'color',
				'label'   => __( 'Accent', 'hw-delivery-calculator' ),
				'var'     => '--hwdc-accent',
				'default' => '#1d6f42',
				'help'    => __( 'Focus ring, the result box\'s left border, the spinner and the highlighted suggestion marker.', 'hw-delivery-calculator' ),
			),
			'field_text'     => array(
				'group'   => 'color',
				'label'   => __( 'Field text', 'hw-delivery-calculator' ),
				'var'     => '--hwdc-field-text',
				'default' => __( 'Theme text color', 'hw-delivery-calculator' ),
				'help'    => __( 'Text typed in the address field and the suggestion list.', 'hw-delivery-calculator' ),
			),
			'field_bg'       => array(
				'group'   => 'color',
				'label'   => __( 'Field background', 'hw-delivery-calculator' ),
				'var'     => '--hwdc-bg',
				'default' => '#ffffff',
				'help'    => __( 'The address field and the suggestion list.', 'hw-delivery-calculator' ),
			),
			'border_color'   => array(
				'group'   => 'color',
				'label'   => __( 'Field border', 'hw-delivery-calculator' ),
				'var'     => '--hwdc-border',
				'default' => '#8c8f94',
			),
			'result_bg'      => array(
				'group'   => 'color',
				'label'   => __( 'Result background', 'hw-delivery-calculator' ),
				'var'     => '--hwdc-hover',
				'default' => '#f1f5f2',
				'help'    => __( 'The result box, and the highlighted suggestion.', 'hw-delivery-calculator' ),
			),
			'warn_color'     => array(
				'group'   => 'color',
				'label'   => __( 'Warning', 'hw-delivery-calculator' ),
				'var'     => '--hwdc-warn',
				'default' => '#8a5a00',
				'help'    => __( 'Over-the-limit box border and "choose your address" prompts.', 'hw-delivery-calculator' ),
			),
			'error_color'    => array(
				'group'   => 'color',
				'label'   => __( 'Error', 'hw-delivery-calculator' ),
				'var'     => '--hwdc-error',
				'default' => '#b32d2e',
			),
		);
	}

	/**
	 * A CSS value, or false if it is unsafe to print inside a <style> tag. Anything
	 * goes apart from characters that could end the declaration or the tag. A bare
	 * --name becomes var(--name).
	 */
	protected static function clean_css_value( $v ) {
		$v = trim( preg_replace( '/\s+/', ' ', (string) $v ) );
		if ( preg_match( '/^--[A-Za-z0-9_-]+$/', $v ) ) {
			$v = 'var(' . $v . ')';
		}
		if ( '' === $v ) {
			return '';
		}
		if ( strlen( $v ) > 200 || ! preg_match( '/^[A-Za-z0-9#%(),.\s\'"\/+*_-]+$/', $v ) || substr_count( $v, '"' ) % 2 || substr_count( $v, "'" ) % 2 ) {
			return false;
		}
		$depth = 0;
		foreach ( str_split( $v ) as $c ) {
			$depth += ( '(' === $c ) - ( ')' === $c );
			if ( $depth < 0 ) {
				return false;
			}
		}
		return 0 === $depth ? $v : false;
	}

	/** The Styles settings as CSS, or '' when none are set. */
	public static function style_css( $s ) {
		$vars  = '';
		$rules = array();
		foreach ( self::style_fields() as $key => $f ) {
			if ( empty( $s[ $key ] ) ) {
				continue;
			}
			$vars .= $f['var'] . ':' . $s[ $key ] . ';';
			if ( ! empty( $f['target'] ) ) {
				$rules[ $f['target'] ] = ( isset( $rules[ $f['target'] ] ) ? $rules[ $f['target'] ] : '' ) . $f['prop'] . ':var(' . $f['var'] . ');';
			}
		}
		if ( '' === $vars ) {
			return '';
		}
		$css = '.hwdc{' . $vars . '}';
		foreach ( $rules as $selector => $declarations ) {
			$css .= $selector . '{' . $declarations . '}';
		}
		return $css;
	}

	public static function settings() {
		$saved = get_option( self::OPTION, array() );
		return array_merge( self::defaults(), is_array( $saved ) ? $saved : array() );
	}

	protected static function is_configured( $s ) {
		return '' !== $s['api_key'] && '' !== $s['kennel_lat'] && '' !== $s['kennel_lng'];
	}

	public static function register_settings() {
		register_setting(
			'hw_delivery',
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize' ),
				'default'           => self::defaults(),
			)
		);
	}

	public static function sanitize( $in ) {
		$in  = is_array( $in ) ? wp_unslash( $in ) : array();
		$d   = self::defaults();
		$old = self::settings();
		$out = array();

		$out['api_key']        = isset( $in['api_key'] ) ? preg_replace( '/[^A-Za-z0-9_\-]/', '', $in['api_key'] ) : '';
		$out['kennel_address'] = isset( $in['kennel_address'] ) ? sanitize_text_field( $in['kennel_address'] ) : '';
		$out['kennel_label']   = isset( $in['kennel_label'] ) ? sanitize_text_field( $in['kennel_label'] ) : '';

		foreach ( array( 'kennel_lat' => 90, 'kennel_lng' => 180 ) as $field => $limit ) {
			$v             = isset( $in[ $field ] ) ? trim( (string) $in[ $field ] ) : '';
			if ( '' === $v || ( is_numeric( $v ) && abs( (float) $v ) <= $limit ) ) {
				$out[ $field ] = '' === $v ? '' : (string) round( (float) $v, 7 );
			} else {
				// Keep the working value rather than switching the calculator off.
				$out[ $field ] = $old[ $field ];
				add_settings_error( self::OPTION, $field, __( 'Kennel coordinates must be decimal degrees, e.g. 44.123456 and -85.654321. The previous value was kept.', 'hw-delivery-calculator' ) );
			}
		}

		$out['rate']      = isset( $in['rate'] ) && is_numeric( $in['rate'] ) ? max( 0, round( (float) $in['rate'], 2 ) ) : $d['rate'];
		$out['max_miles'] = isset( $in['max_miles'] ) && is_numeric( $in['max_miles'] ) ? max( 0, (int) $in['max_miles'] ) : $d['max_miles'];

		foreach ( array( 'heading', 'label', 'placeholder' ) as $field ) {
			$out[ $field ] = isset( $in[ $field ] ) ? sanitize_text_field( $in[ $field ] ) : $d[ $field ];
		}
		foreach ( array( 'intro', 'result' ) as $field ) {
			$out[ $field ] = isset( $in[ $field ] ) ? sanitize_textarea_field( $in[ $field ] ) : $d[ $field ];
		}
		// Note and over-limit message may hold a link (e.g. to the contact page).
		foreach ( array( 'note', 'over_limit' ) as $field ) {
			$out[ $field ] = isset( $in[ $field ] ) ? wp_kses_post( trim( $in[ $field ] ) ) : $d[ $field ];
		}

		$rejected = array();
		foreach ( self::style_fields() as $field => $f ) {
			$v = self::clean_css_value( isset( $in[ $field ] ) ? $in[ $field ] : '' );
			if ( false === $v ) {
				$v          = $old[ $field ];
				$rejected[] = $f['label'];
			}
			$out[ $field ] = $v;
		}
		if ( $rejected ) {
			add_settings_error(
				self::OPTION,
				'styles',
				sprintf(
					/* translators: %s: list of style field names */
					__( 'Styles: %s kept the previous value. Use a plain CSS value such as #1d6f42, 1.1rem or var(--primary); semicolons, braces and angle brackets are not allowed.', 'hw-delivery-calculator' ),
					implode( ', ', $rejected )
				)
			);
		}

		return $out;
	}

	/* ------------------------------------------------------------------ */
	/* Admin                                                               */
	/* ------------------------------------------------------------------ */

	public static function menu() {
		$parent = class_exists( 'WooCommerce' ) ? 'woocommerce' : 'options-general.php';
		add_submenu_page(
			$parent,
			__( 'Delivery Calculator', 'hw-delivery-calculator' ),
			__( 'Delivery Calculator', 'hw-delivery-calculator' ),
			'manage_options',
			self::PAGE,
			array( __CLASS__, 'settings_page' )
		);
	}

	public static function settings_url() {
		$base = class_exists( 'WooCommerce' ) ? 'admin.php' : 'options-general.php';
		return admin_url( $base . '?page=' . self::PAGE );
	}

	public static function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( self::settings_url() ) . '">' . esc_html__( 'Settings', 'hw-delivery-calculator' ) . '</a>' );
		return $links;
	}

	public static function admin_assets( $hook ) {
		if ( false === strpos( (string) $hook, self::PAGE ) ) {
			return;
		}
		wp_enqueue_script( self::HANDLE . '-admin', plugins_url( 'assets/js/admin.js', __FILE__ ), array(), self::VERSION, true );
		wp_localize_script(
			self::HANDLE . '-admin',
			'hwdcAdmin',
			array(
				'needKey'  => __( 'Enter the API key and the kennel address first.', 'hw-delivery-calculator' ),
				'looking'  => __( 'Looking up…', 'hw-delivery-calculator' ),
				'found'    => __( 'Found: %s. Check the pin link, then click Save Changes.', 'hw-delivery-calculator' ),
				'notFound' => __( 'Google could not find that address. Enter the coordinates by hand (right-click the spot in Google Maps and click the numbers to copy them).', 'hw-delivery-calculator' ),
				'failed'   => __( 'Lookup failed: %s', 'hw-delivery-calculator' ),
				'keyError' => __( 'Google rejected the API key. Check that Places API (New) is enabled and this site is in the key\'s website restrictions.', 'hw-delivery-calculator' ),
				'reload'   => __( 'The API key changed. Reload this page, then look up again.', 'hw-delivery-calculator' ),
				'styles'   => array_map(
					function ( $f ) {
						return array_intersect_key( $f, array_flip( array( 'var', 'target', 'prop' ) ) );
					},
					self::style_fields()
				),
			)
		);
		// The preview at the bottom of the page uses the front-end script.
		self::register_assets();
	}

	public static function settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$s    = self::settings();
		$name = self::OPTION;
		$lat  = $s['kennel_lat'];
		$lng  = $s['kennel_lng'];
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'HW Delivery Calculator', 'hw-delivery-calculator' ); ?></h1>
			<p>
				<?php
				printf(
					/* translators: %s: shortcode */
					esc_html__( 'Place %s in a product template (Oxygen or Bricks shortcode element, or any post content). Everything runs in the visitor\'s browser; addresses are never sent to or saved on this site.', 'hw-delivery-calculator' ),
					'<code>[hw_delivery_calculator]</code>'
				);
				?>
			</p>
			<?php
			// Pages under Settings print notices automatically; under WooCommerce they don't.
			if ( class_exists( 'WooCommerce' ) ) {
				settings_errors();
			}
			?>
			<form method="post" action="options.php">
				<?php settings_fields( 'hw_delivery' ); ?>

				<h2><?php esc_html_e( 'Google Maps', 'hw-delivery-calculator' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="hwdc-api_key"><?php esc_html_e( 'API key', 'hw-delivery-calculator' ); ?></label></th>
						<td>
							<input type="text" class="regular-text code" id="hwdc-api_key" name="<?php echo esc_attr( $name ); ?>[api_key]" value="<?php echo esc_attr( $s['api_key'] ); ?>" autocomplete="off" spellcheck="false">
							<p class="description">
								<?php esc_html_e( 'A browser key: visitors can see it, so lock it down in Google Cloud. Website restriction: this site\'s domain only. API restriction: Maps JavaScript API, Places API (New) and Routes API. Set a daily quota cap on each. Full steps are in the plugin readme.', 'hw-delivery-calculator' ); ?>
							</p>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Kennel location', 'hw-delivery-calculator' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="hwdc-kennel_address"><?php esc_html_e( 'Address', 'hw-delivery-calculator' ); ?></label></th>
						<td>
							<input type="text" class="large-text" id="hwdc-kennel_address" name="<?php echo esc_attr( $name ); ?>[kennel_address]" value="<?php echo esc_attr( $s['kennel_address'] ); ?>">
							<p>
								<button type="button" class="button" id="hwdc-lookup"><?php esc_html_e( 'Look up coordinates', 'hw-delivery-calculator' ); ?></button>
								<span id="hwdc-lookup-status" role="status" style="margin-left:8px"></span>
							</p>
							<p class="description"><?php esc_html_e( 'Never shown to customers; only the town below appears on the page. Used by the lookup button. Distances are measured from the coordinates below, not from this text or the WooCommerce store address.', 'hw-delivery-calculator' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="hwdc-kennel_lat"><?php esc_html_e( 'Coordinates', 'hw-delivery-calculator' ); ?></label></th>
						<td>
							<input type="text" inputmode="decimal" class="small-text code" style="width:9em" id="hwdc-kennel_lat" name="<?php echo esc_attr( $name ); ?>[kennel_lat]" value="<?php echo esc_attr( $lat ); ?>" placeholder="<?php esc_attr_e( 'Latitude', 'hw-delivery-calculator' ); ?>" aria-label="<?php esc_attr_e( 'Latitude', 'hw-delivery-calculator' ); ?>">
							<input type="text" inputmode="decimal" class="small-text code" style="width:9em" id="hwdc-kennel_lng" name="<?php echo esc_attr( $name ); ?>[kennel_lng]" value="<?php echo esc_attr( $lng ); ?>" placeholder="<?php esc_attr_e( 'Longitude', 'hw-delivery-calculator' ); ?>" aria-label="<?php esc_attr_e( 'Longitude', 'hw-delivery-calculator' ); ?>">
							<?php if ( '' !== $lat && '' !== $lng ) : ?>
								<a id="hwdc-pin" href="<?php echo esc_url( 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode( $lat . ',' . $lng ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Check the pin on Google Maps', 'hw-delivery-calculator' ); ?></a>
							<?php endif; ?>
							<p class="description"><?php esc_html_e( 'Latitude and longitude in decimal degrees (Michigan is roughly 43, -85).', 'hw-delivery-calculator' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="hwdc-kennel_label"><?php esc_html_e( 'Town shown to customers', 'hw-delivery-calculator' ); ?></label></th>
						<td>
							<input type="text" class="regular-text" id="hwdc-kennel_label" name="<?php echo esc_attr( $name ); ?>[kennel_label]" value="<?php echo esc_attr( $s['kennel_label'] ); ?>" placeholder="Hersey, MI">
							<p class="description"><?php esc_html_e( 'Fills {kennel} in the texts below. Keep it to the town, not the street address.', 'hw-delivery-calculator' ); ?></p>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Pricing', 'hw-delivery-calculator' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="hwdc-rate"><?php esc_html_e( 'Rate per mile ($)', 'hw-delivery-calculator' ); ?></label></th>
						<td>
							<input type="number" step="0.01" min="0" class="small-text" id="hwdc-rate" name="<?php echo esc_attr( $name ); ?>[rate]" value="<?php echo esc_attr( number_format( (float) $s['rate'], 2, '.', '' ) ); ?>">
							<p class="description"><?php esc_html_e( 'Charged on one-way driving miles. Miles and the price are rounded to whole numbers.', 'hw-delivery-calculator' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="hwdc-max_miles"><?php esc_html_e( 'Maximum distance (miles)', 'hw-delivery-calculator' ); ?></label></th>
						<td>
							<input type="number" step="1" min="0" class="small-text" id="hwdc-max_miles" name="<?php echo esc_attr( $name ); ?>[max_miles]" value="<?php echo esc_attr( (int) $s['max_miles'] ); ?>">
							<p class="description"><?php esc_html_e( 'Farther than this shows the "contact us" message instead of a price. 0 = no limit.', 'hw-delivery-calculator' ); ?></p>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Text', 'hw-delivery-calculator' ); ?></h2>
				<p><?php esc_html_e( 'Placeholders: {miles}, {price}, {rate}, {max} and {kennel}. Clear the heading or intro to hide it.', 'hw-delivery-calculator' ); ?></p>
				<table class="form-table" role="presentation">
					<?php
					self::text_row( 'heading', __( 'Heading', 'hw-delivery-calculator' ), $s );
					self::text_row( 'intro', __( 'Intro', 'hw-delivery-calculator' ), $s, true );
					self::text_row( 'label', __( 'Address field label', 'hw-delivery-calculator' ), $s );
					self::text_row( 'placeholder', __( 'Address field placeholder', 'hw-delivery-calculator' ), $s );
					self::text_row( 'result', __( 'Result', 'hw-delivery-calculator' ), $s, true, __( 'Example output: About 142 driving miles from our kennel in Hersey, MI. Estimated delivery: $284 ($2/mile one-way)', 'hw-delivery-calculator' ) );
					self::text_row( 'note', __( 'Note under the result', 'hw-delivery-calculator' ), $s, true, __( 'Basic HTML allowed, e.g. a link to the contact page.', 'hw-delivery-calculator' ) );
					self::text_row( 'over_limit', __( 'Over the maximum distance', 'hw-delivery-calculator' ), $s, true, __( 'Shown instead of a price. Basic HTML allowed.', 'hw-delivery-calculator' ) );
					?>
				</table>

				<h2><?php esc_html_e( 'Styles', 'hw-delivery-calculator' ); ?></h2>
				<p><?php esc_html_e( 'Leave a field empty to keep the default shown in it. Any CSS value works: #1d6f42, rgb(29 111 66), 1.1rem, "Open Sans", sans-serif, or one of your theme\'s variables such as var(--primary). Typing --primary on its own is enough.', 'hw-delivery-calculator' ); ?></p>
				<p class="description"><?php esc_html_e( 'This page does not load your theme, so its variables are not defined here and the preview falls back to the browser default for them. Add a fallback to see a color in the preview: var(--primary, #1d6f42).', 'hw-delivery-calculator' ); ?></p>
				<h3><?php esc_html_e( 'Fonts', 'hw-delivery-calculator' ); ?></h3>
				<table class="form-table" role="presentation">
					<?php
					foreach ( self::style_fields() as $field => $f ) {
						if ( 'font' === $f['group'] ) {
							self::style_row( $field, $f, $s );
						}
					}
					?>
				</table>
				<h3><?php esc_html_e( 'Colors', 'hw-delivery-calculator' ); ?></h3>
				<table class="form-table" role="presentation">
					<?php
					foreach ( self::style_fields() as $field => $f ) {
						if ( 'color' === $f['group'] ) {
							self::style_row( $field, $f, $s );
						}
					}
					?>
				</table>

				<?php submit_button(); ?>
			</form>

			<h2><?php esc_html_e( 'Preview', 'hw-delivery-calculator' ); ?></h2>
			<?php if ( self::is_configured( $s ) ) : ?>
				<p class="description"><?php esc_html_e( 'Uses the saved settings and makes real Google requests. Style changes show here as you type; click Save Changes to keep them. Where a style is left empty, your theme\'s fonts and colors apply on the site.', 'hw-delivery-calculator' ); ?></p>
				<div style="max-width:40rem;background:#fff;padding:16px 20px;border:1px solid #dcdcde;margin-top:12px">
					<?php echo self::shortcode( array() ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in render. ?>
				</div>
			<?php else : ?>
				<p><?php esc_html_e( 'Save an API key and kennel coordinates to see the calculator here.', 'hw-delivery-calculator' ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}

	protected static function text_row( $field, $label, $s, $multiline = false, $help = '' ) {
		$id   = 'hwdc-' . $field;
		$attr = esc_attr( self::OPTION ) . '[' . esc_attr( $field ) . ']';
		echo '<tr><th scope="row"><label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label></th><td>';
		if ( $multiline ) {
			echo '<textarea class="large-text" rows="3" id="' . esc_attr( $id ) . '" name="' . $attr . '">' . esc_textarea( $s[ $field ] ) . '</textarea>'; // phpcs:ignore WordPress.Security.EscapeOutput -- $attr escaped above.
		} else {
			echo '<input type="text" class="large-text" id="' . esc_attr( $id ) . '" name="' . $attr . '" value="' . esc_attr( $s[ $field ] ) . '">'; // phpcs:ignore WordPress.Security.EscapeOutput -- $attr escaped above.
		}
		if ( $help ) {
			echo '<p class="description">' . esc_html( $help ) . '</p>';
		}
		echo '</td></tr>';
	}

	protected static function style_row( $field, $f, $s ) {
		$id = 'hwdc-' . $field;
		echo '<tr><th scope="row"><label for="' . esc_attr( $id ) . '">' . esc_html( $f['label'] ) . '</label></th><td>';
		echo '<input type="text" class="regular-text code" id="' . esc_attr( $id ) . '" name="' . esc_attr( self::OPTION . '[' . $field . ']' ) . '" value="' . esc_attr( $s[ $field ] ) . '" placeholder="' . esc_attr( $f['default'] ) . '" autocomplete="off" spellcheck="false">';
		if ( 'color' === $f['group'] ) {
			// Not submitted (no name); admin.js copies the picked color into the text field.
			echo ' <input type="color" class="hwdc-color" data-for="' . esc_attr( $id ) . '" aria-label="' . esc_attr(
				/* translators: %s: style field name, e.g. Accent */
				sprintf( __( 'Pick the %s color', 'hw-delivery-calculator' ), $f['label'] )
			) . '" style="vertical-align:middle;width:40px;height:30px;padding:1px">';
		}
		if ( ! empty( $f['help'] ) ) {
			echo '<p class="description">' . esc_html( $f['help'] ) . '</p>';
		}
		echo '</td></tr>';
	}

	/* ------------------------------------------------------------------ */
	/* Front end                                                         */
	/* ------------------------------------------------------------------ */

	public static function register_assets() {
		wp_register_script(
			self::HANDLE,
			plugins_url( 'assets/js/hw-delivery-calculator.js', __FILE__ ),
			array(),
			self::VERSION,
			array( 'in_footer' => true )
		);
	}

	public static function delay_js_exclusions( $exclusions ) {
		$exclusions   = is_array( $exclusions ) ? $exclusions : array();
		$exclusions[] = 'hw-delivery-calculator/assets/js/hw-delivery-calculator.js';
		return $exclusions;
	}

	/**
	 * [hw_delivery_calculator heading="..." intro="..." heading_tag="h3"]
	 * heading/intro override the settings for one placement; an empty value hides them.
	 */
	public static function shortcode( $atts ) {
		$s    = self::settings();
		$atts = shortcode_atts(
			array(
				'heading'     => $s['heading'],
				'intro'       => $s['intro'],
				'heading_tag' => 'h3',
			),
			$atts,
			'hw_delivery_calculator'
		);

		if ( ! self::is_configured( $s ) ) {
			if ( current_user_can( 'manage_options' ) ) {
				return '<p style="padding:12px;border:1px dashed #b32d2e">' . sprintf(
					/* translators: %s: settings page link */
					esc_html__( 'HW Delivery Calculator is not set up yet. Add the Google Maps API key and kennel coordinates in %s. (Only administrators see this.)', 'hw-delivery-calculator' ),
					'<a href="' . esc_url( self::settings_url() ) . '">' . esc_html__( 'the settings', 'hw-delivery-calculator' ) . '</a>'
				) . '</p>';
			}
			return '';
		}

		self::$instances++;
		$id  = 'hwdc-' . self::$instances;
		$tag = in_array( strtolower( $atts['heading_tag'] ), array( 'h2', 'h3', 'h4', 'h5', 'p' ), true ) ? strtolower( $atts['heading_tag'] ) : 'h3';

		if ( ! wp_script_is( self::HANDLE, 'registered' ) ) {
			self::register_assets();
		}
		wp_enqueue_script( self::HANDLE );

		$config = apply_filters(
			'hw_delivery_calculator_config',
			array(
				'key'      => $s['api_key'],
				'origin'   => array(
					'lat' => (float) $s['kennel_lat'],
					'lng' => (float) $s['kennel_lng'],
				),
				'rate'     => (float) $s['rate'],
				'max'      => (int) $s['max_miles'],
				'currency' => 'USD',
				'locale'   => 'en-US',
				// Places (New) primary types an address suggestion may have.
				'types'    => array( 'street_address', 'premise', 'subpremise' ),
				'i18n'     => array(
					'loading'     => __( 'Loading address search…', 'hw-delivery-calculator' ),
					'calculating' => __( 'Calculating the driving distance…', 'hw-delivery-calculator' ),
					'fullStreet'  => __( 'Please enter your full street address', 'hw-delivery-calculator' ),
					'pickOne'     => __( 'Please choose your address from the list.', 'hw-delivery-calculator' ),
					'noMatches'   => __( 'No matching street address yet. Include your house number and street.', 'hw-delivery-calculator' ),
					'loadError'   => __( 'Address search is not available right now. Please try again later, or contact us for a delivery quote.', 'hw-delivery-calculator' ),
					'searchError' => __( 'Address search ran into a problem. Please try again.', 'hw-delivery-calculator' ),
					'noRoute'     => __( 'We could not find a driving route to that address. Please contact us for a delivery quote.', 'hw-delivery-calculator' ),
					'routeError'  => __( 'We could not calculate the distance right now. Please try again.', 'hw-delivery-calculator' ),
					'countOne'    => __( '1 address found. Use the down arrow to choose it.', 'hw-delivery-calculator' ),
					'countMany'   => __( '%d addresses found. Use the up and down arrows to choose one.', 'hw-delivery-calculator' ),
				),
			),
			$s
		);

		ob_start();
		if ( 1 === self::$instances && apply_filters( 'hw_delivery_calculator_inline_css', true ) ) {
			// Inline, so the box has its final size before any stylesheet loads (no layout shift).
			$css = file_get_contents( __DIR__ . '/assets/css/hw-delivery-calculator.css' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			if ( $css ) {
				echo '<style id="hw-delivery-calculator-css">' . wp_strip_all_tags( $css ) . '</style>'; // phpcs:ignore WordPress.Security.EscapeOutput
			}
		}
		// The Styles settings print even with the inline CSS turned off: they are set on purpose.
		$styles = 1 === self::$instances ? self::style_css( $s ) : '';
		if ( '' !== $styles ) {
			echo '<style id="hw-delivery-calculator-styles">' . $styles . '</style>'; // phpcs:ignore WordPress.Security.EscapeOutput -- values checked by clean_css_value().
		}
		?>
		<div class="hwdc" id="<?php echo esc_attr( $id ); ?>" data-hwdc="<?php echo esc_attr( wp_json_encode( $config ) ); ?>">
			<?php if ( '' !== trim( $atts['heading'] ) ) : ?>
				<<?php echo $tag; // phpcs:ignore WordPress.Security.EscapeOutput -- whitelisted. ?> class="hwdc__heading"><?php echo esc_html( $atts['heading'] ); ?></<?php echo $tag; // phpcs:ignore WordPress.Security.EscapeOutput ?>>
			<?php endif; ?>
			<?php if ( '' !== trim( $atts['intro'] ) ) : ?>
				<p class="hwdc__intro"><?php echo nl2br( esc_html( $atts['intro'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?></p>
			<?php endif; ?>
			<form class="hwdc__form" action="#" autocomplete="off" novalidate>
				<label class="hwdc__label" for="<?php echo esc_attr( $id ); ?>-input"><?php echo esc_html( $s['label'] ); ?></label>
				<div class="hwdc__field">
					<input class="hwdc__input" id="<?php echo esc_attr( $id ); ?>-input" type="text" role="combobox"
						aria-autocomplete="list" aria-expanded="false" aria-controls="<?php echo esc_attr( $id ); ?>-list"
						aria-describedby="<?php echo esc_attr( $id ); ?>-output"
						autocomplete="off" autocorrect="off" autocapitalize="off" spellcheck="false" enterkeyhint="search"
						placeholder="<?php echo esc_attr( $s['placeholder'] ); ?>">
					<span class="hwdc__spinner" aria-hidden="true"></span>
					<button type="button" class="hwdc__clear" aria-label="<?php esc_attr_e( 'Clear address', 'hw-delivery-calculator' ); ?>" hidden>
						<svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true" focusable="false"><path d="M6 6l12 12M18 6L6 18" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"/></svg>
					</button>
					<div class="hwdc__popup" hidden>
						<ul class="hwdc__list" id="<?php echo esc_attr( $id ); ?>-list" role="listbox" aria-label="<?php esc_attr_e( 'Address suggestions', 'hw-delivery-calculator' ); ?>"></ul>
						<div class="hwdc__attribution" aria-hidden="true">Google Maps</div>
					</div>
				</div>
				<span class="hwdc__sr" aria-live="polite"></span>
			</form>
			<div class="hwdc__output" id="<?php echo esc_attr( $id ); ?>-output" role="status" aria-live="polite" aria-atomic="true">
				<p class="hwdc__message" hidden></p>
				<div class="hwdc__result" hidden>
					<p class="hwdc__estimate"><?php echo self::fill( esc_html( $s['result'] ), $s ); // phpcs:ignore WordPress.Security.EscapeOutput ?></p>
					<?php if ( '' !== trim( wp_strip_all_tags( $s['note'] ) ) ) : ?>
						<p class="hwdc__note"><?php echo self::fill( nl2br( wp_kses_post( $s['note'] ) ), $s ); // phpcs:ignore WordPress.Security.EscapeOutput ?></p>
					<?php endif; ?>
				</div>
				<div class="hwdc__over" hidden>
					<p><?php echo self::fill( nl2br( wp_kses_post( $s['over_limit'] ) ), $s ); // phpcs:ignore WordPress.Security.EscapeOutput ?></p>
				</div>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Swap placeholders in already-escaped HTML: {kennel} and {max} are known now,
	 * the rest become spans the script fills once the distance is known.
	 */
	protected static function fill( $html, $s ) {
		$max = (int) $s['max_miles'];
		return strtr(
			$html,
			array(
				'{kennel}' => esc_html( $s['kennel_label'] ),
				'{max}'    => esc_html( number_format_i18n( $max ) ),
				'{miles}'  => '<span class="hwdc-v" data-v="miles"></span>',
				'{price}'  => '<span class="hwdc-v" data-v="price"></span>',
				'{rate}'   => '<span class="hwdc-v" data-v="rate"></span>',
			)
		);
	}
}

HW_Delivery_Calculator::init();
