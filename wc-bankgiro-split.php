<?php
/**
 * Plugin Name: Woo SplitPay 50/50-manual
 * Description: Splits WooCommerce orders into a 50% deposit upon purchase and 50% final payment via manual bank transfer prior to delivery, with custom checkout and order confirmation instructions.
 * Version:     2.1.0
 * Author:      Area 404
 * Text Domain: wc-bankgiro-split
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * WC requires at least: 6.0
 *
 * @package WC_Bankgiro_Split
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

if ( ! defined( 'WC_BG_SPLIT_VERSION' ) ) {
	define( 'WC_BG_SPLIT_VERSION', '2.1.0' );
}
if ( ! defined( 'WC_BG_SPLIT_PLUGIN_FILE' ) ) {
	define( 'WC_BG_SPLIT_PLUGIN_FILE', __FILE__ );
}
if ( ! defined( 'WC_BG_SPLIT_PLUGIN_BASENAME' ) ) {
	define( 'WC_BG_SPLIT_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );
}
if ( ! defined( 'WC_BG_SPLIT_PLUGIN_DIR' ) ) {
	define( 'WC_BG_SPLIT_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
}
if ( ! defined( 'WC_BG_SPLIT_PLUGIN_URL' ) ) {
	define( 'WC_BG_SPLIT_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
}
if ( ! defined( 'WC_BG_SPLIT_GITHUB_REPO' ) ) {
	define( 'WC_BG_SPLIT_GITHUB_REPO', 'Kickeking/Woo-SplitPay-50-50-manual' );
}

/**
 * ============================================================================
 * 1. GITHUB AUTO-UPDATER CLASS (Hardcoded Background WordPress Transients)
 * ============================================================================
 */
if ( ! class_exists( 'WC_Bankgiro_Split_GitHub_Updater' ) ) {
class WC_Bankgiro_Split_GitHub_Updater {

	/**
	 * Full path to main plugin file.
	 *
	 * @var string
	 */
	private $plugin_file;

	/**
	 * Plugin basename slug (folder/file.php).
	 *
	 * @var string
	 */
	private $plugin_slug;

	/**
	 * Hardcoded GitHub repository in 'owner/repo' format.
	 *
	 * @var string
	 */
	private $repo;

	/**
	 * Cached normalized release response for current request.
	 *
	 * @var array|false|null
	 */
	private $github_response = null;

	/**
	 * Constructor.
	 *
	 * @param string $plugin_file Main plugin file path.
	 */
	public function __construct( $plugin_file ) {
		$this->plugin_file = $plugin_file;
		$this->plugin_slug = plugin_basename( $plugin_file );
		$this->repo        = WC_BG_SPLIT_GITHUB_REPO;

		if ( empty( $this->repo ) || false === strpos( $this->repo, '/' ) ) {
			return;
		}

		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'check_for_update' ) );
		add_filter( 'plugins_api', array( $this, 'plugin_popup_info' ), 10, 3 );
		add_filter( 'upgrader_post_install', array( $this, 'after_install' ), 10, 3 );
	}

	/**
	 * Safely normalize release data from either a cached stdClass object, cached array, or fresh GitHub API payload.
	 * Prevents PHP 8+ fatal TypeErrors when switching between object/array transient formats.
	 *
	 * @param mixed $raw Raw or cached release payload.
	 * @return array|false Normalized array with keys 'version', 'package', 'body', 'published', 'html_url', or false.
	 */
	private function normalize_release_data( $raw ) {
		if ( is_object( $raw ) ) {
			$raw = (array) $raw;
		}

		if ( ! is_array( $raw ) || empty( $raw ) ) {
			return false;
		}

		// Already normalized array format.
		if ( ! empty( $raw['version'] ) && ! empty( $raw['package'] ) ) {
			return array(
				'version'   => ltrim( (string) $raw['version'], 'vV' ),
				'package'   => (string) $raw['package'],
				'body'      => isset( $raw['body'] ) ? (string) $raw['body'] : '',
				'published' => isset( $raw['published'] ) ? (string) $raw['published'] : '',
				'html_url'  => isset( $raw['html_url'] ) ? (string) $raw['html_url'] : 'https://github.com/' . $this->repo,
			);
		}

		// Raw GitHub API payload (or legacy stdClass transient).
		if ( empty( $raw['tag_name'] ) ) {
			return false;
		}

		$asset_url = '';
		if ( ! empty( $raw['assets'] ) && ( is_array( $raw['assets'] ) || is_object( $raw['assets'] ) ) ) {
			foreach ( (array) $raw['assets'] as $asset ) {
				if ( is_object( $asset ) ) {
					$asset = (array) $asset;
				}
				if ( is_array( $asset ) && ! empty( $asset['browser_download_url'] ) && preg_match( '/\.zip$/i', (string) $asset['browser_download_url'] ) ) {
					$asset_url = (string) $asset['browser_download_url'];
					break;
				}
			}
		}

		if ( empty( $asset_url ) && ! empty( $raw['zipball_url'] ) ) {
			$asset_url = (string) $raw['zipball_url'];
		}

		if ( empty( $asset_url ) ) {
			return false;
		}

		return array(
			'version'   => ltrim( (string) $raw['tag_name'], 'vV' ),
			'package'   => $asset_url,
			'body'      => isset( $raw['body'] ) ? (string) $raw['body'] : '',
			'published' => isset( $raw['published_at'] ) ? (string) $raw['published_at'] : '',
			'html_url'  => isset( $raw['html_url'] ) ? (string) $raw['html_url'] : 'https://github.com/' . $this->repo,
		);
	}

	/**
	 * Fetch latest release info from GitHub API with 12-hour WordPress transient caching.
	 *
	 * @return array|false
	 */
	private function fetch_latest_release() {
		if ( empty( $this->repo ) || false === strpos( $this->repo, '/' ) ) {
			return false;
		}

		if ( null !== $this->github_response ) {
			if ( false === $this->github_response ) {
				return false;
			}
			$normalized_mem = $this->normalize_release_data( $this->github_response );
			$this->github_response = $normalized_mem ? $normalized_mem : false;
			return $this->github_response;
		}

		$transient_key = 'wc_bg_split_gh_rel_' . md5( $this->repo );
		$force_check   = ! empty( $_GET['force-check'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$cached        = $force_check ? false : get_transient( $transient_key );

		if ( false !== $cached ) {
			if ( 'error_backoff' === $cached ) {
				$this->github_response = false;
				return false;
			}
			$normalized_cache = $this->normalize_release_data( $cached );
			if ( false !== $normalized_cache ) {
				$this->github_response = $normalized_cache;
				return $this->github_response;
			}
			delete_transient( $transient_key );
		}

		$api_url = sprintf( 'https://api.github.com/repos/%s/releases/latest', $this->repo );
		$headers = array(
			'Accept'     => 'application/vnd.github.v3+json',
			'User-Agent' => 'WordPress/Woo-SplitPay-50-50-Updater',
		);

		$response = wp_remote_get(
			$api_url,
			array(
				'headers' => $headers,
				'timeout' => 10,
			)
		);

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			set_transient( $transient_key, 'error_backoff', HOUR_IN_SECONDS );
			$this->github_response = false;
			return false;
		}

		$body       = json_decode( wp_remote_retrieve_body( $response ), true );
		$normalized = $this->normalize_release_data( $body );

		if ( false === $normalized ) {
			set_transient( $transient_key, 'error_backoff', HOUR_IN_SECONDS );
			$this->github_response = false;
			return false;
		}

		set_transient( $transient_key, $normalized, 12 * HOUR_IN_SECONDS );
		$this->github_response = $normalized;

		return $this->github_response;
	}

	/**
	 * Inject update into WordPress update transient when a newer GitHub release exists.
	 *
	 * @param object $transient WordPress update_plugins transient.
	 * @return object
	 */
	public function check_for_update( $transient ) {
		if ( empty( $this->repo ) || empty( $transient ) || ! is_object( $transient ) || empty( $transient->checked ) ) {
			return $transient;
		}

		$release = $this->fetch_latest_release();
		if ( ! is_array( $release ) || empty( $release['version'] ) || empty( $release['package'] ) ) {
			return $transient;
		}

		if ( version_compare( $release['version'], WC_BG_SPLIT_VERSION, '>' ) ) {
			$plugin_data = array(
				'id'            => $this->plugin_slug,
				'slug'          => dirname( $this->plugin_slug ),
				'plugin'        => $this->plugin_slug,
				'new_version'   => $release['version'],
				'url'           => isset( $release['html_url'] ) ? $release['html_url'] : 'https://github.com/' . $this->repo,
				'package'       => $release['package'],
				'icons'         => array(),
				'banners'       => array(),
				'banners_rtl'   => array(),
				'tested'        => '',
				'requires_php'  => '7.4',
				'compatibility' => new stdClass(),
			);

			if ( ! isset( $transient->response ) || ! is_array( $transient->response ) ) {
				$transient->response = array();
			}

			$transient->response[ $this->plugin_slug ] = (object) $plugin_data;
		}

		return $transient;
	}

	/**
	 * Populate the "View version details" modal in WP Admin -> Plugins.
	 *
	 * @param false|object|array $result The result object or array.
	 * @param string             $action The type of information being requested.
	 * @param object             $args   Plugin API arguments.
	 * @return false|object|array
	 */
	public function plugin_popup_info( $result, $action, $args ) {
		if ( empty( $this->repo ) || 'plugin_information' !== $action || ! is_object( $args ) || empty( $args->slug ) ) {
			return $result;
		}

		if ( dirname( $this->plugin_slug ) !== $args->slug && $this->plugin_slug !== $args->slug ) {
			return $result;
		}

		$release = $this->fetch_latest_release();
		if ( ! is_array( $release ) || empty( $release['version'] ) ) {
			return $result;
		}

		$info                = new stdClass();
		$info->name          = 'Woo SplitPay 50/50-manual';
		$info->slug          = dirname( $this->plugin_slug );
		$info->version       = $release['version'];
		$info->author        = '<a href="https://github.com/' . esc_attr( $this->repo ) . '">Area 404</a>';
		$info->homepage      = isset( $release['html_url'] ) ? $release['html_url'] : 'https://github.com/' . $this->repo;
		$info->download_link = isset( $release['package'] ) ? $release['package'] : '';
		$info->sections      = array(
			'description' => 'Splits WooCommerce orders into a 50% deposit upon purchase and 50% final payment via manual bank transfer prior to delivery, with custom checkout and order confirmation instructions.',
			'changelog'   => ! empty( $release['body'] ) ? nl2br( esc_html( $release['body'] ) ) : __( 'Latest release from GitHub.', 'wc-bankgiro-split' ),
		);

		return $info;
	}

	/**
	 * Ensure extracted GitHub folder matches the installed plugin directory name.
	 *
	 * @param bool  $response   Installation response.
	 * @param array $hook_extra Extra arguments passed to hooked filters.
	 * @param array $result     Installation result data.
	 * @return array
	 */
	public function after_install( $response, $hook_extra, $result ) {
		if ( is_wp_error( $result ) || ! is_array( $result ) || empty( $result['destination'] ) ) {
			return $result;
		}

		if ( ! is_array( $hook_extra ) || empty( $hook_extra['plugin'] ) || $this->plugin_slug !== $hook_extra['plugin'] ) {
			return $result;
		}

		global $wp_filesystem;
		$install_directory = plugin_dir_path( $this->plugin_file );
		$source_directory  = $result['destination'];
		$nested_subdir     = trailingslashit( $source_directory ) . dirname( $this->plugin_slug );

		if ( $wp_filesystem ) {
			if ( $wp_filesystem->is_dir( $nested_subdir ) && $wp_filesystem->exists( trailingslashit( $nested_subdir ) . basename( $this->plugin_file ) ) ) {
				$wp_filesystem->move( $nested_subdir, $install_directory, true );
				$wp_filesystem->delete( $source_directory, true );
			} elseif ( untrailingslashit( $source_directory ) !== untrailingslashit( $install_directory ) ) {
				$wp_filesystem->move( $source_directory, $install_directory, true );
			}
		}

		$result['destination'] = $install_directory;

		if ( ! function_exists( 'is_plugin_active' ) && defined( 'ABSPATH' ) && file_exists( ABSPATH . 'wp-admin/includes/plugin.php' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		if ( function_exists( 'is_plugin_active' ) && is_plugin_active( $this->plugin_slug ) ) {
			if ( function_exists( 'activate_plugin' ) ) {
				activate_plugin( $this->plugin_slug );
			}
		}

		return $result;
	}
}
}

add_action(
	'init',
	function () {
		if ( class_exists( 'WC_Bankgiro_Split_GitHub_Updater' ) ) {
			new WC_Bankgiro_Split_GitHub_Updater( WC_BG_SPLIT_PLUGIN_FILE );
		}
	}
);

/**
 * ============================================================================
 * 2. ASSET REGISTRATION & ENQUEUING (Admin & Frontend Checkout)
 * ============================================================================
 */

if ( ! function_exists( 'wc_bankgiro_split_enqueue_admin_assets' ) ) {
/**
 * Enqueue CSS, JS, and WP Media library on the gateway settings screen.
 *
 * @param string $hook Admin page hook.
 */
function wc_bankgiro_split_enqueue_admin_assets( $hook ) {
	$page    = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$section = isset( $_GET['section'] ) ? sanitize_text_field( wp_unslash( $_GET['section'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$tab     = isset( $_GET['tab'] ) ? sanitize_text_field( wp_unslash( $_GET['tab'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

	if ( 'wc-settings' !== $page || 'checkout' !== $tab || 'bankgiro_split' !== $section ) {
		return;
	}

	wp_enqueue_media();

	wp_enqueue_style(
		'wc-bg-split-admin-style',
		WC_BG_SPLIT_PLUGIN_URL . 'assets/css/admin-style.css',
		array(),
		WC_BG_SPLIT_VERSION
	);

	wp_enqueue_script(
		'wc-bg-split-admin-script',
		WC_BG_SPLIT_PLUGIN_URL . 'assets/js/admin-script.js',
		array( 'jquery' ),
		WC_BG_SPLIT_VERSION,
		true
	);
}
}
add_action( 'admin_enqueue_scripts', 'wc_bankgiro_split_enqueue_admin_assets' );

if ( ! function_exists( 'wc_bankgiro_split_enqueue_checkout_assets' ) ) {
/**
 * Enqueue Frontend CSS and JS on checkout pages.
 */
function wc_bankgiro_split_enqueue_checkout_assets() {
	if ( ! function_exists( 'is_checkout' ) ) {
		return;
	}

	$gw_settings = wc_bankgiro_split_get_gateway_settings();
	if ( 'yes' !== $gw_settings['enabled'] ) {
		return;
	}

	$is_checkout       = is_checkout() || ( function_exists( 'is_checkout_pay_page' ) && is_checkout_pay_page() );
	$is_order_received = function_exists( 'is_order_received_page' ) && is_order_received_page();

	if ( $is_checkout || $is_order_received ) {
		wp_enqueue_style(
			'wc-bg-split-checkout-style',
			WC_BG_SPLIT_PLUGIN_URL . 'assets/css/checkout-style.css',
			array(),
			WC_BG_SPLIT_VERSION
		);

		wp_enqueue_script(
			'wc-bg-split-checkout-script',
			WC_BG_SPLIT_PLUGIN_URL . 'assets/js/checkout-script.js',
			array( 'jquery' ),
			WC_BG_SPLIT_VERSION,
			true
		);
	}
}
}
add_action( 'wp_enqueue_scripts', 'wc_bankgiro_split_enqueue_checkout_assets' );

/**
 * ============================================================================
 * 3. DEFAULT SETTINGS & HELPER FUNCTIONS
 * ============================================================================
 */

if ( ! function_exists( 'wc_bankgiro_split_get_default_split_options' ) ) {
/**
 * Default options for the Split (50/50) mode repeater.
 *
 * @return array
 */
function wc_bankgiro_split_get_default_split_options() {
	return array(
		array(
			'option_key' => 'split',
			'label'      => __( 'Betala 50% i förskott (Resterande 50% vid leverans)', 'wc-bankgiro-split' ),
			'percentage' => 50,
		),
		array(
			'option_key' => 'full',
			'label'      => __( 'Betala 100% direkt (Order skickas när full likvid syns)', 'wc-bankgiro-split' ),
			'percentage' => 100,
		),
	);
}
}

if ( ! function_exists( 'wc_bankgiro_split_get_default_delbetalning_campaigns' ) ) {
/**
 * Default campaign tiers for Delbetalning (LF Finans installment table).
 *
 * @return array
 */
function wc_bankgiro_split_get_default_delbetalning_campaigns() {
	return array(
		array(
			'campaign_name' => '3 mån räntefritt',
			'interest_rate' => '0 %',
			'setup_fee'     => '95 kr',
			'monthly_fee'   => '35 kr',
			'max_amount'    => '50 000 kr',
		),
		array(
			'campaign_name' => '6 mån räntefritt',
			'interest_rate' => '0 %',
			'setup_fee'     => '195 kr',
			'monthly_fee'   => '35 kr',
			'max_amount'    => '100 000 kr',
		),
		array(
			'campaign_name' => '12 mån räntefritt',
			'interest_rate' => '0 %',
			'setup_fee'     => '295 kr',
			'monthly_fee'   => '35 kr',
			'max_amount'    => '100 000 kr',
		),
		array(
			'campaign_name' => '24 mån räntefritt',
			'interest_rate' => '0 %',
			'setup_fee'     => '395 kr',
			'monthly_fee'   => '35 kr',
			'max_amount'    => '100 000 kr',
		),
		array(
			'campaign_name' => '36 mån räntefritt',
			'interest_rate' => '0 %',
			'setup_fee'     => '495 kr',
			'monthly_fee'   => '35 kr',
			'max_amount'    => '100 000 kr',
		),
	);
}
}

if ( ! function_exists( 'wc_bankgiro_split_get_default_ovrig_info' ) ) {
/**
 * Default Swedish informational terms for Delbetalning (Övrig information).
 *
 * @return string HTML/text.
 */
function wc_bankgiro_split_get_default_ovrig_info() {
	return "<h4>ÖVRIG INFORMATION – LF FINANS DELBETALNING</h4>\n" .
		"<ul>\n" .
		"  <li>Delbetalning erbjuds i samarbete med <strong>LF Finans</strong>. Sedvanlig kreditprövning görs vid köptillfället vilket i vissa fall innebär att en kreditupplysning tas.</li>\n" .
		"  <li>Du får direkt besked om din ansökan har godkänts vid genomfört köp.</li>\n" .
		"  <li>Månadsfakturan skickas via vald aviseringsmetod (e-post eller pappersfaktura) och administration av lånet sköts enkelt via LF Finans kundportal.</li>\n" .
		"  <li>Vid räntefri delbetalning tillkommer endast administrativ uppläggningsavgift samt månatlig aviavgift enligt tabellen ovan.</li>\n" .
		"  <li>Du kan när som helst välja att slutbetala hela det återstående beloppet utan extra kostnader.</li>\n" .
		"</ul>\n" .
		"<p>För fullständiga villkor och information om LF Finans kreditavtal, vänligen besök <a href=\"https://www.lansforsakringar.se\" target=\"_blank\" rel=\"noopener noreferrer\">www.lansforsakringar.se</a>.</p>";
}
}

if ( ! function_exists( 'wc_bankgiro_split_get_gateway_settings' ) ) {
/**
 * Retrieve the gateway settings with complete defaults and fallback values.
 *
 * @return array
 */
function wc_bankgiro_split_get_gateway_settings() {
	$settings = get_option( 'woocommerce_bankgiro_split_settings', array() );
	if ( ! is_array( $settings ) ) {
		$settings = array();
	}

	$default_term = __( 'Äganderättsförbehåll: Fönstren/dörrarna förblir säljarens egendom tills full likvid har erhållits.', 'wc-bankgiro-split' );

	$split_options = isset( $settings['split_options'] ) && is_array( $settings['split_options'] ) && ! empty( $settings['split_options'] )
		? $settings['split_options']
		: wc_bankgiro_split_get_default_split_options();

	$delbetalning_campaigns = isset( $settings['delbetalning_campaigns'] ) && is_array( $settings['delbetalning_campaigns'] ) && ! empty( $settings['delbetalning_campaigns'] )
		? $settings['delbetalning_campaigns']
		: wc_bankgiro_split_get_default_delbetalning_campaigns();

	$delbetalning_ovrig_info = ! empty( $settings['delbetalning_ovrig_info'] )
		? $settings['delbetalning_ovrig_info']
		: wc_bankgiro_split_get_default_ovrig_info();

	return array(
		'enabled'                 => isset( $settings['enabled'] ) ? $settings['enabled'] : 'no',
		'title'                   => ! empty( $settings['title'] ) ? $settings['title'] : __( 'Bankgiro & Delbetalning', 'wc-bankgiro-split' ),
		'description'             => isset( $settings['description'] ) ? $settings['description'] : __( 'Betala säkert via delbetalning eller manuell banköverföring.', 'wc-bankgiro-split' ),
		'minimum_amount'          => isset( $settings['minimum_amount'] ) && '' !== $settings['minimum_amount'] ? (float) $settings['minimum_amount'] : 10000.0,

		// Mode enablement
		'enable_split'            => isset( $settings['enable_split'] ) ? $settings['enable_split'] : 'yes',
		'enable_delbetalning'     => isset( $settings['enable_delbetalning'] ) ? $settings['enable_delbetalning'] : 'yes',

		// Split (50/50) Settings
		'split_title'             => ! empty( $settings['split_title'] ) ? $settings['split_title'] : __( 'Dela upp betalningen (50/50)', 'wc-bankgiro-split' ),
		'split_subtitle'          => ! empty( $settings['split_subtitle'] ) ? $settings['split_subtitle'] : __( '50% vid beställning, 50% innan leverans', 'wc-bankgiro-split' ),
		'split_description'       => isset( $settings['split_description'] ) ? $settings['split_description'] : __( 'Betala 50% i förskott och resterande 50% innan leverans via Bankgiro.', 'wc-bankgiro-split' ),
		'split_logo'              => ! empty( $settings['split_logo'] ) ? $settings['split_logo'] : '',
		'receiver_name'           => ! empty( $settings['receiver_name'] ) ? $settings['receiver_name'] : 'Din Butik AB',
		'bankgiro_number'         => ! empty( $settings['bankgiro_number'] ) ? $settings['bankgiro_number'] : '123-4567',
		'terms_text'              => ! empty( $settings['terms_text'] ) ? $settings['terms_text'] : $default_term,
		'split_options'           => $split_options,

		// Delbetalning Settings
		'delbetalning_title'         => ! empty( $settings['delbetalning_title'] ) ? $settings['delbetalning_title'] : __( 'Delbetalning', 'wc-bankgiro-split' ),
		'delbetalning_subtitle'      => ! empty( $settings['delbetalning_subtitle'] ) ? $settings['delbetalning_subtitle'] : __( 'LF Finans / Månadsvis delbetalning', 'wc-bankgiro-split' ),
		'delbetalning_description'   => isset( $settings['delbetalning_description'] ) ? $settings['delbetalning_description'] : __( 'Välj flexibel delbetalning upp till 36 månader via LF Finans.', 'wc-bankgiro-split' ),
		'delbetalning_logo'          => ! empty( $settings['delbetalning_logo'] ) ? $settings['delbetalning_logo'] : '',
		'delbetalning_terms_url'     => ! empty( $settings['delbetalning_terms_url'] ) ? $settings['delbetalning_terms_url'] : 'https://www.lansforsakringar.se',
		'delbetalning_terms_heading' => ! empty( $settings['delbetalning_terms_heading'] ) ? $settings['delbetalning_terms_heading'] : __( 'Villkor', 'wc-bankgiro-split' ),
		'delbetalning_campaigns'     => $delbetalning_campaigns,
		'delbetalning_ovrig_info'    => $delbetalning_ovrig_info,
	);
}
}

if ( ! function_exists( 'wc_bankgiro_split_get_order_payment_data' ) ) {
/**
 * Extract or calculate the payment and campaign details for an order.
 * Seamlessly handles both 'split' and 'delbetalning' modes with historical integrity.
 *
 * @param WC_Order $order The WooCommerce Order object.
 * @return array
 */
function wc_bankgiro_split_get_order_payment_data( $order ) {
	$order_number = (string) $order->get_order_number();
	$saved_ocr    = (string) $order->get_meta( '_bg_ocr_number' );
	$ocr          = ! empty( $saved_ocr ) ? $saved_ocr : $order_number;

	// Mode: 'split' or 'delbetalning' (defaults to 'split' for backwards compatibility)
	$mode = (string) $order->get_meta( '_bg_payment_mode' );
	if ( empty( $mode ) ) {
		$mode = 'split';
	}

	$total      = (float) $order->get_total();
	$ssn        = (string) $order->get_meta( '_bg_customer_ssn' );
	$receipt_id = absint( $order->get_meta( '_bg_receipt_attachment_id' ) );
	$receipt_url= $receipt_id ? wp_get_attachment_url( $receipt_id ) : (string) $order->get_meta( '_bg_receipt_attachment_url' );

	if ( 'delbetalning' === $mode ) {
		$campaign_name = (string) $order->get_meta( '_bg_campaign_name' );
		$interest_rate = (string) $order->get_meta( '_bg_interest_rate' );
		$setup_fee     = (string) $order->get_meta( '_bg_setup_fee' );
		$monthly_fee   = (string) $order->get_meta( '_bg_monthly_fee' );
		$max_amount    = (string) $order->get_meta( '_bg_max_amount' );

		// Fallback to first campaign if meta missing on legacy order
		if ( empty( $campaign_name ) ) {
			$defaults      = wc_bankgiro_split_get_default_delbetalning_campaigns();
			$campaign_name = $defaults[0]['campaign_name'];
			$interest_rate = $defaults[0]['interest_rate'];
			$setup_fee     = $defaults[0]['setup_fee'];
			$monthly_fee   = $defaults[0]['monthly_fee'];
			$max_amount    = $defaults[0]['max_amount'];
		}

		// Parse leading integer from campaign name for months calculation
		$saved_months  = $order->get_meta( '_bg_months' );
		$saved_monthly = $order->get_meta( '_bg_monthly_cost' );

		if ( '' !== $saved_months && false !== $saved_months ) {
			$months = (int) $saved_months;
		} else {
			preg_match( '/^\s*(\d+)/', $campaign_name, $m );
			$months = ! empty( $m[1] ) ? (int) $m[1] : 0;
		}

		if ( '' !== $saved_monthly && false !== $saved_monthly && is_numeric( $saved_monthly ) ) {
			$monthly_cost = (float) $saved_monthly;
		} elseif ( $months > 0 && $total > 0 && is_numeric( $total ) ) {
			$monthly_cost = round( $total / $months );
		} else {
			$monthly_cost = 0.0;
		}

		return array(
			'mode'          => 'delbetalning',
			'ocr'           => $ocr,
			'order_number'  => $order_number,
			'total'         => $total,
			'to_pay'        => 0.0,
			'remaining'     => 0.0,
			'ssn'           => $ssn,
			'receipt_id'    => $receipt_id,
			'receipt_url'   => $receipt_url,
			'campaign_name' => $campaign_name,
			'interest_rate' => $interest_rate,
			'setup_fee'     => $setup_fee,
			'monthly_fee'   => $monthly_fee,
			'max_amount'    => $max_amount,
			'months'        => $months,
			'monthly_cost'  => $monthly_cost,
		);
	}

	// Mode is Split (50/50 or full)
	$choice = (string) $order->get_meta( '_bg_payment_choice' );
	if ( ! in_array( $choice, array( 'full', 'split' ), true ) ) {
		$choice = 'split';
	}

	$saved_plan = (string) $order->get_meta( '_bg_payment_plan' );
	$plan_percentage = ! empty( $saved_plan ) ? $saved_plan : ( ( 'split' === $choice ) ? '50%' : '100%' );

	$plan_label = ( 'split' === $choice || '50%' === $plan_percentage )
		? __( '50% Förskott (50% vid leverans)', 'wc-bankgiro-split' )
		: __( '100% Direktbetalning', 'wc-bankgiro-split' );

	$saved_pay = $order->get_meta( '_bg_amount_to_pay' );
	$to_pay    = ( '' !== $saved_pay && is_numeric( $saved_pay ) )
		? (float) $saved_pay
		: ( ( 'split' === $choice ) ? round( $total * 0.5, 2 ) : $total );

	$saved_rem = $order->get_meta( '_bg_remaining_amount' );
	$remaining = ( '' !== $saved_rem && is_numeric( $saved_rem ) )
		? (float) $saved_rem
		: ( ( 'split' === $choice ) ? max( 0.0, round( $total - $to_pay, 2 ) ) : 0.0 );

	return array(
		'mode'            => 'split',
		'ocr'             => $ocr,
		'choice'          => $choice,
		'plan_percentage' => $plan_percentage,
		'plan_label'      => $plan_label,
		'total'           => $total,
		'to_pay'          => $to_pay,
		'remaining'       => $remaining,
		'ssn'             => $ssn,
		'receipt_id'      => $receipt_id,
		'receipt_url'     => $receipt_url,
	);
}
}

/**
 * ============================================================================
 * 4. EMAIL-SAFE HTML BOX & UNIFIED YAYMAIL HOOK
 * ============================================================================
 */

if ( ! function_exists( 'wc_bankgiro_split_get_payment_box_table_html' ) ) {
/**
 * Render an email-safe, table-based HTML box with inline CSS styling containing
 * payment instructions. Supports both Split (50/50 Bankgiro) and Delbetalning (LF Finans).
 *
 * CRITICAL RULE: In checkout, all campaign comparison options are shown in the table.
 * BUT in emails and Thank You page, ONLY the single specific campaign/plan option
 * chosen by the customer is rendered!
 *
 * @param WC_Order $order The WooCommerce Order object.
 * @return string Email-safe HTML table markup.
 */
function wc_bankgiro_split_get_payment_box_table_html( $order ) {
	$gw_settings = wc_bankgiro_split_get_gateway_settings();
	$data        = wc_bankgiro_split_get_order_payment_data( $order );

	ob_start();

	// DELBETALNING (LF Finans Installment Mode)
	if ( 'delbetalning' === $data['mode'] ) {
		$delb_label = rtrim( $gw_settings['delbetalning_title'], ':' ) . ':';
		?>
		<table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="width: 100%; border-collapse: separate; border-spacing: 0; background-color: #f8fafc; border: 1px solid #cbd5e1; border-left: 4px solid #0d4277; border-radius: 8px; margin-top: 20px; margin-bottom: 20px; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; color: #1e293b;">
			<tbody>
				<tr>
					<td style="padding: 24px;">
						<table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="width: 100%; border-collapse: collapse;">
							<tbody>
								<!-- Heading -->
								<tr>
									<td colspan="2" style="padding: 0 0 14px 0; font-size: 20px; font-weight: 700; color: #0d4277; line-height: 1.3;">
										<?php echo esc_html__( 'Betalningsinformation – Delbetalning', 'wc-bankgiro-split' ); ?>
									</td>
								</tr>

								<!-- Receiver (Company Name) -->
								<tr>
									<td style="padding: 6px 0; font-size: 14px; color: #334155; width: 40%; vertical-align: middle;">
										<strong><?php echo esc_html__( 'Mottagare:', 'wc-bankgiro-split' ); ?></strong>
									</td>
									<td style="padding: 6px 0; font-size: 14px; color: #0f172a; vertical-align: middle;">
										<?php echo esc_html( $gw_settings['receiver_name'] ); ?>
									</td>
								</tr>

								<!-- Dynamic Delbetalning Title & Subtitle -->
								<tr>
									<td style="padding: 6px 0; font-size: 14px; color: #334155; vertical-align: middle;">
										<strong><?php echo esc_html( $delb_label ); ?></strong>
									</td>
									<td style="padding: 6px 0; font-size: 14px; color: #0f172a; vertical-align: middle;">
										<?php echo esc_html( $gw_settings['delbetalning_subtitle'] ); ?>
									</td>
								</tr>

								<!-- Order Number -->
								<tr>
									<td style="padding: 6px 0; font-size: 14px; color: #334155; vertical-align: middle;">
										<strong><?php echo esc_html__( 'Ordernummer:', 'wc-bankgiro-split' ); ?></strong>
									</td>
									<td style="padding: 6px 0; font-size: 14px; vertical-align: middle;">
										<span style="display: inline-block; font-size: 15px; font-family: Consolas, Monaco, monospace; background-color: #e0f2fe; color: #0369a1; padding: 3px 10px; border-radius: 4px; font-weight: 700;">
											<?php echo esc_html( $order->get_order_number() ); ?>
										</span>
									</td>
								</tr>

								<!-- Chosen Campaign Plan ONLY -->
								<tr>
									<td style="padding: 6px 0; font-size: 14px; color: #334155; vertical-align: middle;">
										<strong><?php echo esc_html__( 'Vald betalningsplan:', 'wc-bankgiro-split' ); ?></strong>
									</td>
									<td style="padding: 6px 0; font-size: 15px; font-weight: 700; color: #0d4277; vertical-align: middle;">
										<?php echo esc_html( $data['campaign_name'] ); ?>
									</td>
								</tr>

								<!-- Upplägg (Monthly Cost & Calculations) -->
								<tr>
									<td style="padding: 12px 0 6px 0; font-size: 15px; color: #0f172a; vertical-align: top; border-top: 1px solid #e2e8f0;">
										<strong><?php echo esc_html__( 'Upplägg:', 'wc-bankgiro-split' ); ?></strong>
									</td>
									<td style="padding: 12px 0 6px 0; vertical-align: top; border-top: 1px solid #e2e8f0;">
										<?php if ( ! empty( $data['months'] ) && $data['months'] > 0 ) : ?>
											<?php
											$monthly_formatted = number_format( $data['monthly_cost'], 0, ',', ' ' ) . ' kr/mån';
											$total_formatted   = number_format( $data['total'], 0, ',', ' ' ) . ' kr';
											?>
											<span style="font-size: 17px; font-weight: 700; color: #16a34a;">
												<?php
												echo esc_html(
													sprintf(
														/* translators: %1$s: Monthly amount, %2$s: Order total, %3$d: Months */
														__( 'Ca %1$s (%2$s / %3$d mån)', 'wc-bankgiro-split' ),
														$monthly_formatted,
														$total_formatted,
														$data['months']
													)
												);
												?>
											</span>
											<div style="margin-top: 4px; font-size: 13px; color: #64748b;">
												<?php
												echo esc_html(
													sprintf(
														/* translators: %1$s: Interest, %2$s: Setup fee, %3$s: Monthly fee */
														__( 'Ränta: %1$s | Uppläggningsavgift: %2$s | Månatlig aviavgift: %3$s', 'wc-bankgiro-split' ),
														$data['interest_rate'],
														$data['setup_fee'],
														$data['monthly_fee']
													)
												);
												?>
											</div>
										<?php else : ?>
											<span style="font-size: 14px; font-weight: 600; color: #0d4277;">
												<?php
												echo esc_html(
													sprintf(
														/* translators: %1$s: Interest, %2$s: Setup fee, %3$s: Monthly fee */
														__( 'Ränta: %1$s | Uppläggningsavgift: %2$s | Månatlig aviavgift: %3$s', 'wc-bankgiro-split' ),
														$data['interest_rate'],
														$data['setup_fee'],
														$data['monthly_fee']
													)
												);
												?>
											</span>
										<?php endif; ?>
									</td>
								</tr>

								<!-- Status & Next Steps notice -->
								<tr>
									<td colspan="2" style="padding: 12px 0 10px 0;">
										<div style="font-size: 13px; color: #0369a1; background-color: #f0f9ff; border: 1px solid #bae6fd; border-radius: 6px; padding: 12px; line-height: 1.55;">
											<strong>ℹ️ <?php echo esc_html__( 'Status & Nästa steg:', 'wc-bankgiro-split' ); ?></strong><br />
											<?php echo esc_html__( 'Din ansökan om delbetalning behandlas i samarbete med LF Finans. Information och eventuella avtalshandlingar för digital signering via BankID skickas omgående till din angivna e-postadress. När avtalet är signerat sätts din order i hantering/produktion.', 'wc-bankgiro-split' ); ?>
										</div>
									</td>
								</tr>

								<!-- Terms ("Villkor") Section Heading & Text -->
								<?php if ( ! empty( $gw_settings['delbetalning_ovrig_info'] ) ) : ?>
									<tr>
										<td colspan="2" style="padding: 14px 0 6px 0; font-size: 13.5px; font-weight: 700; color: #0d4277; text-transform: uppercase; letter-spacing: 0.02em; border-top: 1px solid #e2e8f0;">
											<?php echo esc_html( $gw_settings['delbetalning_terms_heading'] ); ?>
										</td>
									</tr>
									<tr>
										<td colspan="2" style="padding: 0 0 10px 0; font-size: 12.5px; color: #475569; line-height: 1.55;">
											<?php echo wp_kses_post( $gw_settings['delbetalning_ovrig_info'] ); ?>
										</td>
									</tr>
								<?php endif; ?>

								<!-- Link to Full LF Finans Terms -->
								<?php if ( ! empty( $gw_settings['delbetalning_terms_url'] ) ) : ?>
									<tr>
										<td colspan="2" style="padding: 4px 0 12px 0; font-size: 13px;">
											<a href="<?php echo esc_url( $gw_settings['delbetalning_terms_url'] ); ?>" target="_blank" rel="noopener noreferrer" style="color: #0d4277; text-decoration: underline; font-weight: 600;">
												<?php echo esc_html__( 'Fullständiga villkor för LF Finans', 'wc-bankgiro-split' ); ?> &rarr;
											</a>
										</td>
									</tr>
								<?php endif; ?>

								<!-- Retention of Title / Terms Row -->
								<tr>
									<td colspan="2" style="padding: 12px 0 0 0; font-size: 12px; color: #64748b; line-height: 1.5; border-top: 1px dashed #cbd5e1;">
										<em><?php echo esc_html( $gw_settings['terms_text'] ); ?></em>
									</td>
								</tr>
							</tbody>
						</table>
					</td>
				</tr>
			</tbody>
		</table>
		<?php
		return ob_get_clean();
	}

	// SPLIT (50/50 Bankgiro Mode)
	$receiver_name   = $gw_settings['receiver_name'];
	$bankgiro_number = $gw_settings['bankgiro_number'];
	$terms_text      = $gw_settings['terms_text'];

	$price_args     = array( 'currency' => $order->get_currency() );
	$to_pay_html    = wc_price( $data['to_pay'], $price_args );
	$remaining_html = wc_price( $data['remaining'], $price_args );
	?>
	<table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="width: 100%; border-collapse: separate; border-spacing: 0; background-color: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; margin-top: 20px; margin-bottom: 20px; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; color: #1e293b;">
		<tbody>
			<tr>
				<td style="padding: 24px;">
					<table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="width: 100%; border-collapse: collapse;">
						<tbody>
							<!-- Heading -->
							<tr>
								<td colspan="2" style="padding: 0 0 14px 0; font-size: 20px; font-weight: 700; color: #0d4277; line-height: 1.3;">
									<?php echo esc_html__( 'Betalningsinstruktioner för Bankgiro', 'wc-bankgiro-split' ); ?>
								</td>
							</tr>

							<!-- Receiver -->
							<tr>
								<td style="padding: 6px 0; font-size: 14px; color: #334155; width: 40%; vertical-align: middle;">
									<strong><?php echo esc_html__( 'Mottagare:', 'wc-bankgiro-split' ); ?></strong>
								</td>
								<td style="padding: 6px 0; font-size: 14px; color: #0f172a; vertical-align: middle;">
									<?php echo esc_html( $receiver_name ); ?>
								</td>
							</tr>

							<!-- Bankgiro -->
							<tr>
								<td style="padding: 6px 0; font-size: 14px; color: #334155; vertical-align: middle;">
									<strong><?php echo esc_html__( 'Bankgiro:', 'wc-bankgiro-split' ); ?></strong>
								</td>
								<td style="padding: 6px 0; font-size: 14px; color: #0f172a; vertical-align: middle;">
									<?php echo esc_html( $bankgiro_number ); ?>
								</td>
							</tr>

							<!-- OCR Number (Order Number) -->
							<tr>
								<td style="padding: 6px 0; font-size: 14px; color: #334155; vertical-align: middle;">
									<strong><?php echo esc_html__( 'OCR-nummer:', 'wc-bankgiro-split' ); ?></strong>
								</td>
								<td style="padding: 6px 0; font-size: 14px; vertical-align: middle;">
									<span style="display: inline-block; font-size: 15px; font-family: Consolas, Monaco, monospace; background-color: #e0f2fe; color: #0369a1; padding: 3px 10px; border-radius: 4px; font-weight: 700;">
										<?php echo esc_html( $data['ocr'] ); ?>
									</span>
								</td>
							</tr>

							<!-- Selected Payment Plan -->
							<tr>
								<td style="padding: 6px 0; font-size: 14px; color: #334155; vertical-align: middle;">
									<strong><?php echo esc_html__( 'Vald betalningsplan:', 'wc-bankgiro-split' ); ?></strong>
								</td>
								<td style="padding: 6px 0; font-size: 14px; color: #0f172a; vertical-align: middle;">
									<?php echo esc_html( $data['plan_label'] ); ?>
								</td>
							</tr>

							<!-- Amount to Pay Now -->
							<tr>
								<td style="padding: 12px 0 6px 0; font-size: 15px; color: #0f172a; vertical-align: middle; border-top: 1px solid #e2e8f0;">
									<strong><?php echo esc_html__( 'Summa att betala nu:', 'wc-bankgiro-split' ); ?></strong>
								</td>
								<td style="padding: 12px 0 6px 0; font-size: 18px; font-weight: 700; color: #16a34a; vertical-align: middle; border-top: 1px solid #e2e8f0;">
									<strong><?php echo wp_kses_post( $to_pay_html ); ?></strong>
								</td>
							</tr>

							<?php if ( 'split' === $data['choice'] || '50%' === $data['plan_percentage'] ) : ?>
								<!-- Remaining 50% Due at Delivery -->
								<tr>
									<td colspan="2" style="padding: 4px 0 10px 0; font-size: 13px; color: #64748b; line-height: 1.5;">
										<?php
										echo wp_kses_post(
											sprintf(
												/* translators: %s: Formatted remaining 50% amount via wc_price() */
												__( '* Resterande 50%% (%s) betalas innan/vid leverans.', 'wc-bankgiro-split' ),
												$remaining_html
											)
										);
										?>
									</td>
								</tr>
							<?php endif; ?>

							<!-- Retention of Title / Terms Row -->
							<tr>
								<td colspan="2" style="padding: 12px 0 0 0; font-size: 12px; color: #64748b; line-height: 1.5; border-top: 1px dashed #cbd5e1;">
									<em><?php echo esc_html( $terms_text ); ?></em>
								</td>
							</tr>
						</tbody>
					</table>
				</td>
			</tr>
		</tbody>
	</table>
	<?php
	return ob_get_clean();
}
}

if ( ! function_exists( 'fg_display_partial_payment_email_box' ) ) {
/**
 * Render the payment instructions inside WooCommerce & YayMail emails.
 * Hooked to `woocommerce_email_before_order_table` with priority 20 and 4 arguments.
 *
 * @param WC_Order $order         WooCommerce Order instance.
 * @param bool     $sent_to_admin Whether the email is sent to an admin.
 * @param bool     $plain_text    Whether the email is plain-text format.
 * @param WC_Email $email         WooCommerce Email object.
 */
function fg_display_partial_payment_email_box( $order, $sent_to_admin = false, $plain_text = false, $email = null ) {
	// 1. Verify that $order is a valid WC_Order object.
	if ( ! is_a( $order, 'WC_Order' ) ) {
		return;
	}

	// 2. Verify that the order's payment method matches our gateway ID.
	if ( 'bankgiro_split' !== $order->get_payment_method() ) {
		return;
	}

	$data = wc_bankgiro_split_get_order_payment_data( $order );

	// Plain-text email output
	if ( $plain_text ) {
		$gw_settings = wc_bankgiro_split_get_gateway_settings();
		$price_args  = array( 'currency' => $order->get_currency() );

		echo "\n==================================================\n";
		if ( 'delbetalning' === $data['mode'] ) {
			$delb_label = rtrim( $gw_settings['delbetalning_title'], ':' ) . ':';
			echo esc_html__( 'Betalningsinformation – Delbetalning', 'wc-bankgiro-split' ) . "\n";
			echo esc_html__( 'Mottagare:', 'wc-bankgiro-split' ) . ' ' . esc_html( $gw_settings['receiver_name'] ) . "\n";
			echo esc_html( $delb_label ) . ' ' . esc_html( $gw_settings['delbetalning_subtitle'] ) . "\n";
			echo esc_html__( 'Ordernummer:', 'wc-bankgiro-split' ) . ' ' . esc_html( $order->get_order_number() ) . "\n";
			echo esc_html__( 'Vald betalningsplan:', 'wc-bankgiro-split' ) . ' ' . esc_html( $data['campaign_name'] ) . "\n";

			if ( ! empty( $data['months'] ) && $data['months'] > 0 ) {
				$monthly_str = number_format( $data['monthly_cost'], 0, ',', ' ' ) . ' kr/mån';
				$total_str   = number_format( $data['total'], 0, ',', ' ' ) . ' kr';
				echo esc_html__( 'Upplägg:', 'wc-bankgiro-split' ) . ' ' . sprintf( 'Ca %s (%s / %d mån)', $monthly_str, $total_str, $data['months'] ) . "\n";
			} else {
				echo esc_html__( 'Upplägg:', 'wc-bankgiro-split' ) . ' ' . esc_html( $data['campaign_name'] ) . "\n";
			}
			echo sprintf( 'Ränta: %s | Uppläggningsavgift: %s | Månatlig aviavgift: %s', $data['interest_rate'], $data['setup_fee'], $data['monthly_fee'] ) . "\n";
			echo esc_html__( 'Status & Nästa steg: Din ansökan om delbetalning behandlas i samarbete med LF Finans. Information och avtalshandlingar för digital signering via BankID skickas omgående till din e-post.', 'wc-bankgiro-split' ) . "\n\n";

			if ( ! empty( $gw_settings['delbetalning_ovrig_info'] ) ) {
				echo esc_html( $gw_settings['delbetalning_terms_heading'] ) . ":\n";
				echo wp_strip_all_tags( $gw_settings['delbetalning_ovrig_info'] ) . "\n";
			}
			if ( ! empty( $gw_settings['delbetalning_terms_url'] ) ) {
				echo esc_html__( 'Fullständiga villkor för LF Finans:', 'wc-bankgiro-split' ) . ' ' . esc_url( $gw_settings['delbetalning_terms_url'] ) . "\n";
			}
			echo "\n" . esc_html( $gw_settings['terms_text'] ) . "\n";
		} else {
			echo esc_html__( 'Betalningsinstruktioner för Bankgiro', 'wc-bankgiro-split' ) . "\n";
			echo esc_html__( 'Mottagare:', 'wc-bankgiro-split' ) . ' ' . esc_html( $gw_settings['receiver_name'] ) . "\n";
			echo esc_html__( 'Bankgiro:', 'wc-bankgiro-split' ) . ' ' . esc_html( $gw_settings['bankgiro_number'] ) . "\n";
			echo esc_html__( 'OCR-nummer:', 'wc-bankgiro-split' ) . ' ' . esc_html( $data['ocr'] ) . "\n";
			echo esc_html__( 'Vald betalningsplan:', 'wc-bankgiro-split' ) . ' ' . esc_html( $data['plan_label'] ) . "\n";
			echo esc_html__( 'Summa att betala nu:', 'wc-bankgiro-split' ) . ' ' . wp_strip_all_tags( wc_price( $data['to_pay'], $price_args ) ) . "\n";
			if ( 'split' === $data['choice'] || '50%' === $data['plan_percentage'] ) {
				echo sprintf(
					/* translators: %s: Remaining amount */
					esc_html__( '* Resterande 50%% (%s) betalas innan/vid leverans.', 'wc-bankgiro-split' ),
					wp_strip_all_tags( wc_price( $data['remaining'], $price_args ) )
				) . "\n";
			}
			echo esc_html( $gw_settings['terms_text'] ) . "\n";
		}
		echo "==================================================\n\n";
		return;
	}

	// 3. Output the email-safe, table-based HTML box.
	echo wc_bankgiro_split_get_payment_box_table_html( $order ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
}
}
add_action( 'woocommerce_email_before_order_table', 'fg_display_partial_payment_email_box', 20, 4 );

if ( ! function_exists( 'wc_bankgiro_split_shortcode_payment_info' ) ) {
/**
 * Shortcode [partial_payment_info] for YayMail or custom email/template builders.
 *
 * @param array $atts Shortcode attributes.
 * @return string
 */
function wc_bankgiro_split_shortcode_payment_info( $atts = array() ) {
	$atts = shortcode_atts(
		array(
			'order_id' => 0,
		),
		$atts,
		'partial_payment_info'
	);

	$order_id = absint( $atts['order_id'] );
	$order    = null;

	if ( $order_id > 0 ) {
		$order = wc_get_order( $order_id );
	} else {
		// Detect order from global query vars or URL context
		global $wp;
		if ( isset( $wp->query_vars['order-received'] ) ) {
			$order = wc_get_order( absint( $wp->query_vars['order-received'] ) );
		} elseif ( isset( $_GET['order_id'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$order = wc_get_order( absint( $_GET['order_id'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
	}

	if ( ! is_a( $order, 'WC_Order' ) || 'bankgiro_split' !== $order->get_payment_method() ) {
		return '';
	}

	return wc_bankgiro_split_get_payment_box_table_html( $order );
}
}
add_shortcode( 'partial_payment_info', 'wc_bankgiro_split_shortcode_payment_info' );

/**
 * ============================================================================
 * 5. RECEIPT UPLOAD ON THE THANK YOU ("ORDER RECEIVED") PAGE
 * ============================================================================
 */

if ( ! function_exists( 'wc_bankgiro_split_process_receipt_upload_for_order' ) ) {
/**
 * Handle receipt file upload submitted on the Thank You page (both standard POST and AJAX).
 *
 * @param int $order_id Target Order ID.
 * @return array
 */
function wc_bankgiro_split_process_receipt_upload_for_order( $order_id ) {
	$order = wc_get_order( $order_id );
	if ( ! $order ) {
		return array(
			'success' => false,
			'message' => __( 'Kunde inte hitta ordern.', 'wc-bankgiro-split' ),
		);
	}

	// Verify order key for security
	$provided_key = isset( $_REQUEST['bg_order_key'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['bg_order_key'] ) ) : '';
	if ( empty( $provided_key ) || ! hash_equals( $order->get_order_key(), $provided_key ) ) {
		return array(
			'success' => false,
			'message' => __( 'Ogiltig säkerhetsnyckel för ordern.', 'wc-bankgiro-split' ),
		);
	}

	if ( empty( $_FILES['bg_payment_receipt'] ) || empty( $_FILES['bg_payment_receipt']['name'] ) ) {
		return array(
			'success' => false,
			'message' => __( 'Vänligen välj en fil (bild eller PDF) att ladda upp.', 'wc-bankgiro-split' ),
		);
	}

	if ( UPLOAD_ERR_OK !== (int) $_FILES['bg_payment_receipt']['error'] ) {
		return array(
			'success' => false,
			'message' => __( 'Ett fel uppstod vid filöverföringen. Kontrollera att filen inte är för stor.', 'wc-bankgiro-split' ),
		);
	}

	$file_name     = sanitize_file_name( wp_unslash( $_FILES['bg_payment_receipt']['name'] ) );
	$allowed_mimes = array(
		'jpg|jpeg|jpe' => 'image/jpeg',
		'png'          => 'image/png',
		'gif'          => 'image/gif',
		'webp'         => 'image/webp',
		'pdf'          => 'application/pdf',
	);
	$filetype      = wp_check_filetype( $file_name, $allowed_mimes );

	if ( empty( $filetype['ext'] ) || empty( $filetype['type'] ) ) {
		return array(
			'success' => false,
			'message' => __( 'Endast bildfiler (JPG, PNG, GIF, WEBP) och PDF-filer är tillåtna.', 'wc-bankgiro-split' ),
		);
	}

	require_once ABSPATH . 'wp-admin/includes/image.php';
	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/media.php';

	$attachment_id = media_handle_upload( 'bg_payment_receipt', $order_id );

	if ( is_wp_error( $attachment_id ) ) {
		$order->add_order_note(
			sprintf(
				/* translators: %s: Error message */
				__( 'Misslyckat försök att ladda upp betalningskvitto på tacksidan: %s', 'wc-bankgiro-split' ),
				$attachment_id->get_error_message()
			)
		);
		$order->save();

		return array(
			'success' => false,
			'message' => sprintf(
				/* translators: %s: Error message */
				__( 'Uppladdningen misslyckades: %s', 'wc-bankgiro-split' ),
				$attachment_id->get_error_message()
			),
		);
	}

	$attachment_url = wp_get_attachment_url( $attachment_id );

	// Link the uploaded receipt to the order meta.
	$order->update_meta_data( '_bg_receipt_attachment_id', $attachment_id );
	$order->update_meta_data( '_bg_receipt_attachment_url', esc_url_raw( (string) $attachment_url ) );
	$order->add_order_note(
		sprintf(
			/* translators: %1$d: Attachment ID, %2$s: Attachment URL */
			__( '📎 Betalningskvitto uppladdat av kund på tacksidan (Media-ID: #%1$d - %2$s).', 'wc-bankgiro-split' ),
			$attachment_id,
			$attachment_url
		)
	);
	$order->save();

	return array(
		'success' => true,
		'message' => __( 'Tack! Ditt betalningskvitto har laddats upp och kopplats till din order.', 'wc-bankgiro-split' ),
		'url'     => (string) $attachment_url,
	);
}
}

if ( ! function_exists( 'wc_bankgiro_split_ajax_upload_receipt' ) ) {
/**
 * AJAX endpoint for uploading receipt on the Thank You page.
 */
function wc_bankgiro_split_ajax_upload_receipt() {
	check_ajax_referer( 'wc_bg_split_receipt_upload', 'bg_receipt_nonce' );

	$order_id = isset( $_POST['bg_order_id'] ) ? absint( $_POST['bg_order_id'] ) : 0;
	if ( ! $order_id ) {
		wp_send_json_error( array( 'message' => __( 'Ogiltigt ordernummer.', 'wc-bankgiro-split' ) ) );
	}

	$result = wc_bankgiro_split_process_receipt_upload_for_order( $order_id );
	if ( $result['success'] ) {
		wp_send_json_success( $result );
	} else {
		wp_send_json_error( $result );
	}
}
}
add_action( 'wp_ajax_wc_bg_upload_receipt', 'wc_bankgiro_split_ajax_upload_receipt' );
add_action( 'wp_ajax_nopriv_wc_bg_upload_receipt', 'wc_bankgiro_split_ajax_upload_receipt' );

if ( ! function_exists( 'wc_bankgiro_split_thankyou_page_output' ) ) {
/**
 * Render the Payment Confirmation + Receipt Upload (for Split mode) on the Thank You page.
 * Hooked into `woocommerce_thankyou`.
 *
 * @param int $order_id The WooCommerce Order ID.
 */
function wc_bankgiro_split_thankyou_page_output( $order_id ) {
	static $rendered_orders = array();

	$order_id = absint( $order_id );
	if ( ! $order_id || isset( $rendered_orders[ $order_id ] ) ) {
		return;
	}

	$order = wc_get_order( $order_id );
	if ( ! $order || 'bankgiro_split' !== $order->get_payment_method() ) {
		return;
	}

	$rendered_orders[ $order_id ] = true;
	$data                         = wc_bankgiro_split_get_order_payment_data( $order );

	// Handle synchronous POST upload fallback
	$upload_feedback = null;
	if (
		isset( $_POST['wc_bg_split_upload_submit'], $_POST['bg_receipt_nonce'], $_POST['bg_order_id'] ) &&
		absint( $_POST['bg_order_id'] ) === $order_id &&
		wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['bg_receipt_nonce'] ) ), 'wc_bg_split_receipt_upload' )
	) {
		$upload_feedback = wc_bankgiro_split_process_receipt_upload_for_order( $order_id );
		$order           = wc_get_order( $order_id );
	}

	// 1. Output the specific payment/campaign instructions box
	echo wc_bankgiro_split_get_payment_box_table_html( $order ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

	// 2. If mode is Split, display receipt upload form. In Delbetalning, receipt upload is omitted.
	if ( 'delbetalning' === $data['mode'] ) {
		return;
	}

	$receipt_id  = absint( $order->get_meta( '_bg_receipt_attachment_id' ) );
	$receipt_url = $receipt_id ? wp_get_attachment_url( $receipt_id ) : (string) $order->get_meta( '_bg_receipt_attachment_url' );
	?>
	<div id="wc-bg-split-receipt-box" style="background: #fffbeb; border: 1px solid #fde68a; padding: 24px; border-radius: 8px; margin-top: 20px; margin-bottom: 24px; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;">
		<h4 style="color: #92400e; margin-top: 0; margin-bottom: 8px; font-size: 18px;">
			<?php echo esc_html__( '🚀 Snabbare hantering? Ladda upp ditt betalningskvitto här', 'wc-bankgiro-split' ); ?>
		</h4>
		<p style="color: #b45309; font-size: 14px; margin-top: 0; margin-bottom: 16px;">
			<?php echo esc_html__( 'När du har genomfört betalningen via ditt bankkonto kan du ladda upp en skärmbild eller PDF på betalningskvittot nedan, så kopplas det direkt till din order.', 'wc-bankgiro-split' ); ?>
		</p>

		<div id="wc-bg-split-upload-status">
			<?php if ( is_array( $upload_feedback ) ) : ?>
				<div style="padding: 12px 16px; border-radius: 6px; margin-bottom: 16px; font-weight: 600; <?php echo $upload_feedback['success'] ? 'background: #dcfce7; color: #166534; border: 1px solid #86efac;' : 'background: #fee2e2; color: #991b1b; border: 1px solid #fca5a5;'; ?>">
					<?php echo esc_html( $upload_feedback['message'] ); ?>
				</div>
			<?php elseif ( ! empty( $receipt_url ) ) : ?>
				<div style="padding: 12px 16px; border-radius: 6px; margin-bottom: 16px; background: #dcfce7; color: #166534; border: 1px solid #86efac; font-weight: 600;">
					<?php echo esc_html__( '✅ Ett betalningskvitto är redan uppladdat för denna order.', 'wc-bankgiro-split' ); ?>
					<a href="<?php echo esc_url( $receipt_url ); ?>" target="_blank" rel="noopener noreferrer" style="color: #15803d; text-decoration: underline; margin-left: 8px;">
						<?php echo esc_html__( 'Visa uppladdat kvitto', 'wc-bankgiro-split' ); ?>
					</a>
				</div>
			<?php endif; ?>
		</div>

		<form id="wc-bg-split-receipt-form" method="post" enctype="multipart/form-data" style="margin: 0;">
			<?php wp_nonce_field( 'wc_bg_split_receipt_upload', 'bg_receipt_nonce' ); ?>
			<input type="hidden" name="bg_order_id" value="<?php echo esc_attr( (string) $order_id ); ?>" />
			<input type="hidden" name="bg_order_key" value="<?php echo esc_attr( $order->get_order_key() ); ?>" />
			<input type="hidden" name="wc_bg_split_upload_submit" value="1" />

			<div style="display: flex; flex-wrap: wrap; gap: 12px; align-items: center;">
				<input
					type="file"
					name="bg_payment_receipt"
					id="bg_payment_receipt"
					accept="image/*,application/pdf"
					required
					style="background: #ffffff; padding: 8px; border: 1px solid #fcd34d; border-radius: 6px; flex: 1; min-width: 220px;"
				/>
				<button
					type="submit"
					id="wc-bg-split-upload-btn"
					class="button alt"
					style="background-color: #d97706; color: #ffffff; border: none; padding: 10px 20px; border-radius: 6px; font-weight: 600; cursor: pointer;"
				>
					<?php echo esc_html__( 'Ladda upp betalningskvitto', 'wc-bankgiro-split' ); ?>
				</button>
			</div>
		</form>
	</div>

	<script type="text/javascript">
	(function() {
		var form = document.getElementById('wc-bg-split-receipt-form');
		if (!form || !window.FormData || !window.fetch) {
			return;
		}
		form.addEventListener('submit', function(e) {
			e.preventDefault();
			var fileInput = document.getElementById('bg_payment_receipt');
			var btn       = document.getElementById('wc-bg-split-upload-btn');
			var statusBox = document.getElementById('wc-bg-split-upload-status');

			if (!fileInput || !fileInput.files || !fileInput.files.length) {
				return;
			}

			var formData = new FormData(form);
			formData.append('action', 'wc_bg_upload_receipt');

			var originalBtnText = btn.textContent;
			btn.disabled = true;
			btn.textContent = '<?php echo esc_js( __( 'Laddar upp...', 'wc-bankgiro-split' ) ); ?>';

			fetch('<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>', {
				method: 'POST',
				body: formData,
				credentials: 'same-origin'
			})
			.then(function(response) { return response.json(); })
			.then(function(res) {
				btn.disabled = false;
				btn.textContent = originalBtnText;
				var msg = (res && res.data && res.data.message) ? res.data.message : '<?php echo esc_js( __( 'Ett oväntat fel uppstod.', 'wc-bankgiro-split' ) ); ?>';
				if (res && res.success) {
					var linkHtml = res.data.url
						? ' <a href="' + res.data.url + '" target="_blank" rel="noopener noreferrer" style="color:#15803d;text-decoration:underline;margin-left:8px;"><?php echo esc_js( __( 'Visa uppladdat kvitto', 'wc-bankgiro-split' ) ); ?></a>'
						: '';
					statusBox.innerHTML = '<div style="padding:12px 16px;border-radius:6px;margin-bottom:16px;font-weight:600;background:#dcfce7;color:#166534;border:1px solid #86efac;">✅ ' + msg + linkHtml + '</div>';
					form.reset();
				} else {
					statusBox.innerHTML = '<div style="padding:12px 16px;border-radius:6px;margin-bottom:16px;font-weight:600;background:#fee2e2;color:#991b1b;border:1px solid #fca5a5;">⚠️ ' + msg + '</div>';
				}
			})
			.catch(function() {
				form.submit();
			});
		});
	})();
	</script>
	<?php
}
}
add_action( 'woocommerce_thankyou', 'wc_bankgiro_split_thankyou_page_output', 10, 1 );

/**
 * ============================================================================
 * 6. VISIBILITY IN WOOCOMMERCE ORDER HISTORY (ADMIN & HPOS)
 * ============================================================================
 */

if ( ! function_exists( 'wc_bankgiro_split_display_admin_order_meta' ) ) {
/**
 * Display payment and campaign details directly inside the WooCommerce Admin Order Details panel.
 *
 * @param WC_Order $order The WooCommerce Order object.
 */
function wc_bankgiro_split_display_admin_order_meta( $order ) {
	if ( ! is_a( $order, 'WC_Order' ) ) {
		return;
	}

	$has_bg_meta = ( '' !== (string) $order->get_meta( '_bg_payment_choice' ) ) ||
	               ( '' !== (string) $order->get_meta( '_bg_campaign_name' ) ) ||
	               ( '' !== (string) $order->get_meta( '_bg_ocr_number' ) );

	if ( 'bankgiro_split' !== $order->get_payment_method() && ! $has_bg_meta ) {
		return;
	}

	$data       = wc_bankgiro_split_get_order_payment_data( $order );
	$price_args = array( 'currency' => $order->get_currency() );
	?>
	<div class="order_data_column_wide wc-bankgiro-admin-summary" style="clear: both; margin-top: 16px; padding: 14px 16px; background: #f8fafc; border: 1px solid #cbd5e1; border-left: 4px solid #0d4277; border-radius: 4px;">
		<h3 style="margin: 0 0 10px 0; color: #0d4277; font-size: 14px;">
			<?php if ( 'delbetalning' === $data['mode'] ) : ?>
				<?php echo esc_html__( '💳 Delbetalning (LF Finans) – Betalningsinformation', 'wc-bankgiro-split' ); ?>
			<?php else : ?>
				<?php echo esc_html__( '🏦 Bankgiro 50/50 – Betalningsinformation', 'wc-bankgiro-split' ); ?>
			<?php endif; ?>
		</h3>

		<?php if ( 'delbetalning' === $data['mode'] ) : ?>
			<p style="margin: 4px 0;">
				<strong><?php echo esc_html__( 'Ordernummer:', 'wc-bankgiro-split' ); ?></strong>
				<code style="background: #e0f2fe; color: #0369a1; font-weight: 700; padding: 2px 6px; border-radius: 3px;">
					<?php echo esc_html( $order->get_order_number() ); ?>
				</code>
			</p>

			<p style="margin: 4px 0;">
				<strong><?php echo esc_html__( 'Vald delbetalningsplan:', 'wc-bankgiro-split' ); ?></strong>
				<span style="font-weight: 600; color: #0d4277;">
					<?php echo esc_html( $data['campaign_name'] ); ?>
				</span>
			</p>

			<p style="margin: 4px 0;">
				<strong><?php echo esc_html__( 'Upplägg:', 'wc-bankgiro-split' ); ?></strong>
				<span style="font-weight: 700; color: #16a34a;">
					<?php
					if ( ! empty( $data['months'] ) && $data['months'] > 0 ) {
						$monthly_str = number_format( $data['monthly_cost'], 0, ',', ' ' ) . ' kr/mån';
						$total_str   = number_format( $data['total'], 0, ',', ' ' ) . ' kr';
						echo esc_html( sprintf( 'Ca %s (%s / %d mån)', $monthly_str, $total_str, $data['months'] ) );
					} else {
						echo esc_html( $data['campaign_name'] );
					}
					?>
				</span>
			</p>

			<p style="margin: 4px 0;">
				<strong><?php echo esc_html__( 'Villkor:', 'wc-bankgiro-split' ); ?></strong>
				<span>
					<?php
					echo esc_html(
						sprintf(
							/* translators: %1$s: Interest, %2$s: Setup fee, %3$s: Monthly fee */
							__( 'Ränta: %1$s | Uppläggningsavgift: %2$s | Aviavgift: %3$s', 'wc-bankgiro-split' ),
							$data['interest_rate'],
							$data['setup_fee'],
							$data['monthly_fee']
						)
					);
					?>
				</span>
			</p>
			<p style="margin: 4px 0;">
				<strong><?php echo esc_html__( 'Maxbelopp:', 'wc-bankgiro-split' ); ?></strong>
				<span><?php echo esc_html( $data['max_amount'] ); ?></span>
			</p>
			<p style="margin: 4px 0; color: #0369a1;">
				<strong><?php echo esc_html__( 'Finansieringsstatus:', 'wc-bankgiro-split' ); ?></strong>
				<span><?php echo esc_html__( 'Kreditansökan hanteras via LF Finans.', 'wc-bankgiro-split' ); ?></span>
			</p>
		<?php else : ?>
			<p style="margin: 4px 0;">
				<strong><?php echo esc_html__( 'OCR / Referensnummer (Ordernummer):', 'wc-bankgiro-split' ); ?></strong>
				<code style="background: #e0f2fe; color: #0369a1; font-weight: 700; padding: 2px 6px; border-radius: 3px;">
					<?php echo esc_html( $data['ocr'] ); ?>
				</code>
			</p>
			<p style="margin: 4px 0;">
				<strong><?php echo esc_html__( 'Vald betalningsplan:', 'wc-bankgiro-split' ); ?></strong>
				<span style="font-weight: 600; color: #0d4277;">
					<?php echo esc_html( $data['plan_percentage'] . ' (' . $data['plan_label'] . ')' ); ?>
				</span>
			</p>
			<p style="margin: 4px 0;">
				<strong><?php echo esc_html__( 'Att betala nu:', 'wc-bankgiro-split' ); ?></strong>
				<span style="font-weight: 700; color: #16a34a;">
					<?php echo wp_kses_post( wc_price( $data['to_pay'], $price_args ) ); ?>
				</span>
				<?php if ( 'split' === $data['choice'] || '50%' === $data['plan_percentage'] ) : ?>
					<span style="color: #64748b; font-size: 12px;">
						<?php
						echo wp_kses_post(
							sprintf(
								/* translators: %s: Remaining amount formatted with wc_price */
								__( '(Resterande 50%% vid leverans: %s)', 'wc-bankgiro-split' ),
								wc_price( $data['remaining'], $price_args )
							)
						);
						?>
					</span>
				<?php endif; ?>
			</p>
			<p style="margin: 6px 0 0 0;">
				<strong><?php echo esc_html__( 'Betalningskvitto:', 'wc-bankgiro-split' ); ?></strong>
				<?php if ( ! empty( $data['receipt_url'] ) ) : ?>
					<a href="<?php echo esc_url( $data['receipt_url'] ); ?>" target="_blank" rel="noopener noreferrer" class="button button-small" style="margin-left: 4px;">
						<?php echo esc_html__( '📎 Visa uppladdat kvitto', 'wc-bankgiro-split' ); ?>
					</a>
				<?php else : ?>
					<span style="color: #64748b; font-style: italic;">
						<?php echo esc_html__( 'Inget kvitto uppladdat ännu', 'wc-bankgiro-split' ); ?>
					</span>
				<?php endif; ?>
			</p>
		<?php endif; ?>

		<?php if ( ! empty( $data['ssn'] ) ) : ?>
			<p style="margin: 4px 0;">
				<strong><?php echo esc_html__( 'Personnummer / Org.nr:', 'wc-bankgiro-split' ); ?></strong>
				<?php echo esc_html( $data['ssn'] ); ?>
			</p>
		<?php endif; ?>
	</div>
	<?php
}
}
add_action( 'woocommerce_admin_order_data_after_billing_address', 'wc_bankgiro_split_display_admin_order_meta', 10, 1 );

if ( ! function_exists( 'wc_bankgiro_split_register_order_meta_box' ) ) {
/**
 * Register dedicated Meta Box in WooCommerce Admin Order screen (both legacy posts and HPOS).
 */
function wc_bankgiro_split_register_order_meta_box() {
	$screens = array( 'shop_order' );
	if ( function_exists( 'wc_get_page_screen_id' ) ) {
		$screens[] = wc_get_page_screen_id( 'shop-order' );
	}

	foreach ( array_unique( $screens ) as $screen ) {
		add_meta_box(
			'wc_bankgiro_split_order_meta_box',
			__( 'Delbetalning / Bankgiro 50/50', 'wc-bankgiro-split' ),
			'wc_bankgiro_split_render_order_meta_box',
			$screen,
			'side',
			'high'
		);
	}
}
}
add_action( 'add_meta_boxes', 'wc_bankgiro_split_register_order_meta_box' );

if ( ! function_exists( 'wc_bankgiro_split_render_order_meta_box' ) ) {
/**
 * Render the side Meta Box content inside WooCommerce Admin Order view.
 *
 * @param WP_Post|WC_Order $post_or_order_object Post or Order object.
 */
function wc_bankgiro_split_render_order_meta_box( $post_or_order_object ) {
	$order = is_a( $post_or_order_object, 'WC_Order' )
		? $post_or_order_object
		: ( is_object( $post_or_order_object ) && isset( $post_or_order_object->ID ) && function_exists( 'wc_get_order' ) ? wc_get_order( $post_or_order_object->ID ) : false );

	if ( ! $order ) {
		return;
	}

	$data       = wc_bankgiro_split_get_order_payment_data( $order );
	$price_args = array( 'currency' => $order->get_currency() );
	?>
	<div style="line-height: 1.6;">
		<p style="margin: 6px 0;">
			<strong><?php echo esc_html__( 'Läge / Betalningsmetod:', 'wc-bankgiro-split' ); ?></strong><br />
			<span style="font-weight: 700; color: #0d4277;">
				<?php echo ( 'delbetalning' === $data['mode'] ) ? esc_html__( 'Delbetalning (LF Finans)', 'wc-bankgiro-split' ) : esc_html__( 'Split (50/50 Bankgiro)', 'wc-bankgiro-split' ); ?>
			</span>
		</p>
		<?php if ( 'delbetalning' === $data['mode'] ) : ?>
			<p style="margin: 6px 0;">
				<strong><?php echo esc_html__( 'Ordernummer:', 'wc-bankgiro-split' ); ?></strong><br/>
				<code style="font-size: 14px; font-weight: bold; background: #e0f2fe; color: #0369a1; padding: 2px 6px; border-radius: 4px;">
					<?php echo esc_html( $order->get_order_number() ); ?>
				</code>
			</p>
			<p style="margin: 6px 0;">
				<strong><?php echo esc_html__( 'Vald kampanj:', 'wc-bankgiro-split' ); ?></strong><br/>
				<?php echo esc_html( $data['campaign_name'] ); ?>
			</p>
			<p style="margin: 6px 0;">
				<strong><?php echo esc_html__( 'Upplägg:', 'wc-bankgiro-split' ); ?></strong><br/>
				<span style="font-size: 15px; font-weight: bold; color: #16a34a;">
					<?php
					if ( ! empty( $data['months'] ) && $data['months'] > 0 ) {
						$monthly_str = number_format( $data['monthly_cost'], 0, ',', ' ' ) . ' kr/mån';
						$total_str   = number_format( $data['total'], 0, ',', ' ' ) . ' kr';
						echo esc_html( sprintf( 'Ca %s (%s / %d mån)', $monthly_str, $total_str, $data['months'] ) );
					} else {
						echo esc_html( $data['campaign_name'] );
					}
					?>
				</span>
			</p>
			<p style="margin: 6px 0; font-size: 12.5px; color: #475569;">
				<strong><?php echo esc_html__( 'Villkor:', 'wc-bankgiro-split' ); ?></strong><br/>
				<?php echo esc_html( 'Ränta: ' . $data['interest_rate'] . ' | Uppl.: ' . $data['setup_fee'] . ' | Avi: ' . $data['monthly_fee'] ); ?>
			</p>
		<?php else : ?>
			<p style="margin: 6px 0;">
				<strong><?php echo esc_html__( 'OCR-nummer (Ordernr):', 'wc-bankgiro-split' ); ?></strong><br/>
				<code style="font-size: 14px; font-weight: bold; background: #e0f2fe; color: #0369a1; padding: 2px 6px; border-radius: 4px;">
					<?php echo esc_html( $data['ocr'] ); ?>
				</code>
			</p>
			<p style="margin: 6px 0;">
				<strong><?php echo esc_html__( 'Betalningsplan:', 'wc-bankgiro-split' ); ?></strong><br/>
				<?php echo esc_html( $data['plan_percentage'] . ' – ' . $data['plan_label'] ); ?>
			</p>
			<p style="margin: 6px 0;">
				<strong><?php echo esc_html__( 'Att betala nu:', 'wc-bankgiro-split' ); ?></strong><br/>
				<span style="font-size: 15px; font-weight: bold; color: #16a34a;">
					<?php echo wp_kses_post( wc_price( $data['to_pay'], $price_args ) ); ?>
				</span>
			</p>
			<?php if ( 'split' === $data['choice'] || '50%' === $data['plan_percentage'] ) : ?>
				<p style="margin: 6px 0; color: #475569; font-size: 12px;">
					<strong><?php echo esc_html__( 'Resterande 50% (vid leverans):', 'wc-bankgiro-split' ); ?></strong>
					<?php echo wp_kses_post( wc_price( $data['remaining'], $price_args ) ); ?>
				</p>
			<?php endif; ?>
			<hr style="border: none; border-top: 1px solid #e2e8f0; margin: 10px 0;" />
			<p style="margin: 6px 0;">
				<strong><?php echo esc_html__( 'Uppladdat kvitto:', 'wc-bankgiro-split' ); ?></strong><br/>
				<?php if ( ! empty( $data['receipt_url'] ) ) : ?>
					<a href="<?php echo esc_url( $data['receipt_url'] ); ?>" target="_blank" rel="noopener noreferrer" class="button button-primary" style="margin-top: 4px;">
						<?php echo esc_html__( 'Öppna betalningskvitto', 'wc-bankgiro-split' ); ?>
					</a>
				<?php else : ?>
					<span style="color: #64748b;"><?php echo esc_html__( 'Inget kvitto uppladdat.', 'wc-bankgiro-split' ); ?></span>
				<?php endif; ?>
			</p>
		<?php endif; ?>

		<?php if ( ! empty( $data['ssn'] ) ) : ?>
			<p style="margin: 6px 0;">
				<strong><?php echo esc_html__( 'Personnr / Org.nr:', 'wc-bankgiro-split' ); ?></strong><br/>
				<?php echo esc_html( $data['ssn'] ); ?>
			</p>
		<?php endif; ?>
	</div>
	<?php
}
}

/**
 * ============================================================================
 * 7. WOOCOMMERCE PAYMENT GATEWAY REGISTRATION & CLASS
 * ============================================================================
 */

if ( ! function_exists( 'wc_bankgiro_split_plugin_action_links' ) ) {
/**
 * Add quick "Inställningar" link on WordPress Plugins page.
 *
 * @param array $links Existing action links.
 * @return array
 */
function wc_bankgiro_split_plugin_action_links( $links ) {
	if ( ! is_array( $links ) ) {
		$links = array();
	}
	$settings_url  = admin_url( 'admin.php?page=wc-settings&tab=checkout&section=bankgiro_split' );
	$settings_link = '<a href="' . esc_url( $settings_url ) . '">' . esc_html__( 'Inställningar', 'wc-bankgiro-split' ) . '</a>';
	array_unshift( $links, $settings_link );
	return $links;
}
}
add_filter( 'plugin_action_links_' . WC_BG_SPLIT_PLUGIN_BASENAME, 'wc_bankgiro_split_plugin_action_links' );

if ( ! function_exists( 'wc_bankgiro_split_add_gateway' ) ) {
/**
 * Register gateway class in WooCommerce.
 *
 * @param array $gateways Registered gateways.
 * @return array
 */
function wc_bankgiro_split_add_gateway( $gateways ) {
	if ( ! is_array( $gateways ) ) {
		return $gateways;
	}
	if ( ! in_array( 'WC_Gateway_Bankgiro_Split', $gateways, true ) ) {
		$gateways[] = 'WC_Gateway_Bankgiro_Split';
	}
	return $gateways;
}
}
add_filter( 'woocommerce_payment_gateways', 'wc_bankgiro_split_add_gateway' );

if ( ! function_exists( 'wc_bankgiro_split_init_gateway_class' ) ) {
/**
 * Initialize Gateway Class once WooCommerce is loaded.
 */
function wc_bankgiro_split_init_gateway_class() {
	if ( ! class_exists( 'WC_Payment_Gateway' ) || class_exists( 'WC_Gateway_Bankgiro_Split' ) ) {
		return;
	}

	/**
	 * Dual-Mode Gateway Class: Woo SplitPay & Delbetalning.
	 */
	class WC_Gateway_Bankgiro_Split extends WC_Payment_Gateway {

		/**
		 * Gateway icon URL.
		 *
		 * @var string
		 */
		public $icon = '';

		/**
		 * Minimum order amount required to show this gateway.
		 *
		 * @var float
		 */
		public $minimum_amount;

		/**
		 * Whether Split mode is enabled.
		 *
		 * @var string 'yes'|'no'
		 */
		public $enable_split;

		/**
		 * Whether Delbetalning mode is enabled.
		 *
		 * @var string 'yes'|'no'
		 */
		public $enable_delbetalning;

		/**
		 * Constructor.
		 */
		public function __construct() {
			$this->id                 = 'bankgiro_split';
			$this->icon               = '';
			$this->has_fields         = true;
			$this->method_title       = __( 'Woo SplitPay 50/50-manual', 'wc-bankgiro-split' );
			$this->method_description = __( 'Splits WooCommerce orders into a 50% deposit upon purchase and 50% final payment via manual bank transfer prior to delivery, with custom checkout and order confirmation instructions.', 'wc-bankgiro-split' );

			// Load form fields and settings.
			$this->init_form_fields();
			$this->init_settings();

			// Load options.
			$this->enabled             = $this->get_option( 'enabled', 'no' );
			$this->title               = $this->get_option( 'title', __( 'Bankgiro & Delbetalning', 'wc-bankgiro-split' ) );
			$this->description         = $this->get_option( 'description', __( 'Betala säkert via delbetalning eller manuell banköverföring.', 'wc-bankgiro-split' ) );
			$this->minimum_amount      = (float) $this->get_option( 'minimum_amount', 10000 );
			$this->enable_split        = $this->get_option( 'enable_split', 'yes' );
			$this->enable_delbetalning = $this->get_option( 'enable_delbetalning', 'yes' );

			// Save settings hook.
			add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
		}

		/**
		 * Initialize standard Gateway Settings Form Fields.
		 */
		public function init_form_fields() {
			$this->form_fields = array(
				'enabled' => array(
					'title'   => __( 'Aktivera/Inaktivera', 'wc-bankgiro-split' ),
					'type'    => 'checkbox',
					'label'   => __( 'Aktivera Woo SplitPay & Delbetalning', 'wc-bankgiro-split' ),
					'default' => 'no',
				),
				'title' => array(
					'title'       => __( 'Kassatitel (Huvudtitel)', 'wc-bankgiro-split' ),
					'type'        => 'text',
					'description' => __( 'Titel som kunden ser i listan över betalsätt i kassan.', 'wc-bankgiro-split' ),
					'default'     => __( 'Bankgiro & Delbetalning', 'wc-bankgiro-split' ),
					'desc_tip'    => true,
				),
				'description' => array(
					'title'       => __( 'Beskrivning', 'wc-bankgiro-split' ),
					'type'        => 'textarea',
					'description' => __( 'Introduktionstext som visas när betalningsmetoden väljs i kassan.', 'wc-bankgiro-split' ),
					'default'     => __( 'Betala säkert via delbetalning eller manuell banköverföring.', 'wc-bankgiro-split' ),
					'desc_tip'    => true,
				),
				'minimum_amount' => array(
					'title'             => __( 'Minsta ordervärde (SEK)', 'wc-bankgiro-split' ),
					'type'              => 'number',
					'description'       => __( 'Minsta totala varukorgsbelopp för att detta betalsätt ska visas i kassan.', 'wc-bankgiro-split' ),
					'default'           => '10000',
					'desc_tip'          => true,
					'custom_attributes' => array(
						'min'  => '0',
						'step' => '1',
					),
				),
				'enable_split' => array(
					'title'   => __( 'Aktivera Split (50/50)', 'wc-bankgiro-split' ),
					'type'    => 'checkbox',
					'label'   => __( 'Erbjud 50/50 förskott & slutbetalning via Bankgiro i kassan', 'wc-bankgiro-split' ),
					'default' => 'yes',
				),
				'enable_delbetalning' => array(
					'title'   => __( 'Aktivera Delbetalning', 'wc-bankgiro-split' ),
					'type'    => 'checkbox',
					'label'   => __( 'Erbjud delbetalningskampanjer via LF Finans i kassan', 'wc-bankgiro-split' ),
					'default' => 'yes',
				),

				// Split Specific Settings
				'split_title' => array(
					'title'       => __( 'Split: Fliktitel / Kortrubrik', 'wc-bankgiro-split' ),
					'type'        => 'text',
					'default'     => __( 'Dela upp betalningen (50/50)', 'wc-bankgiro-split' ),
				),
				'split_subtitle' => array(
					'title'       => __( 'Split: Undertitel', 'wc-bankgiro-split' ),
					'type'        => 'text',
					'default'     => __( '50% vid beställning, 50% innan leverans', 'wc-bankgiro-split' ),
				),
				'split_description' => array(
					'title'       => __( 'Split: Instruktionstext', 'wc-bankgiro-split' ),
					'type'        => 'textarea',
					'default'     => __( 'Betala 50% i förskott och resterande 50% innan leverans via Bankgiro.', 'wc-bankgiro-split' ),
					'description' => __( 'Instruktionstext som visas i kassan när Split är valt. HTML-formatering som &lt;strong&gt;, &lt;br&gt;, &lt;ul&gt;, &lt;li&gt;, &lt;a&gt; stöds.', 'wc-bankgiro-split' ),
				),
				'bankgiro_number' => array(
					'title'       => __( 'Bankgironummer', 'wc-bankgiro-split' ),
					'type'        => 'text',
					'default'     => '123-4567',
				),
				'receiver_name' => array(
					'title'       => __( 'Mottagare (Företagsnamn)', 'wc-bankgiro-split' ),
					'type'        => 'text',
					'default'     => 'Din Butik AB',
				),
				'terms_text' => array(
					'title'       => __( 'Villkorstext / Äganderättsförbehåll', 'wc-bankgiro-split' ),
					'type'        => 'textarea',
					'default'     => __( 'Äganderättsförbehåll: Fönstren/dörrarna förblir säljarens egendom tills full likvid har erhållits.', 'wc-bankgiro-split' ),
				),

				// Delbetalning Specific Settings
				'delbetalning_title' => array(
					'title'       => __( 'Delbetalning: Fliktitel / Kortrubrik', 'wc-bankgiro-split' ),
					'type'        => 'text',
					'default'     => __( 'Delbetalning', 'wc-bankgiro-split' ),
				),
				'delbetalning_subtitle' => array(
					'title'       => __( 'Delbetalning: Undertitel', 'wc-bankgiro-split' ),
					'type'        => 'text',
					'default'     => __( 'LF Finans / Månadsvis delbetalning', 'wc-bankgiro-split' ),
				),
				'delbetalning_description' => array(
					'title'       => __( 'Delbetalning: Instruktionstext', 'wc-bankgiro-split' ),
					'type'        => 'textarea',
					'default'     => __( 'Välj flexibel delbetalning upp till 36 månader via LF Finans.', 'wc-bankgiro-split' ),
					'description' => __( 'Instruktionstext som visas i kassan när Delbetalning är valt. HTML-formatering som &lt;strong&gt;, &lt;br&gt;, &lt;ul&gt;, &lt;li&gt;, &lt;a&gt; stöds.', 'wc-bankgiro-split' ),
				),
				'delbetalning_terms_heading' => array(
					'title'       => __( 'Delbetalning: Villkorsrubrik', 'wc-bankgiro-split' ),
					'type'        => 'text',
					'default'     => __( 'Villkor', 'wc-bankgiro-split' ),
					'description' => __( 'Rubrik som visas ovanför villkorstexten i kassan och i orderbekräftelsen.', 'wc-bankgiro-split' ),
					'desc_tip'    => true,
				),
				'delbetalning_terms_url' => array(
					'title'       => __( 'Länk till fullständiga LF Finans-villkor', 'wc-bankgiro-split' ),
					'type'        => 'text',
					'default'     => 'https://www.lansforsakringar.se',
				),
			);
		}

		/**
		 * Custom processor for saving repeater JSON fields, logo URLs, and Övrig information.
		 */
		public function process_admin_options() {
			parent::process_admin_options();

			$settings = get_option( $this->get_option_key(), array() );
			if ( ! is_array( $settings ) ) {
				$settings = array();
			}

			// 1. Save Split repeater options
			if ( isset( $_POST['wc_bg_split_options_json'] ) ) {
				$raw_split = wp_unslash( $_POST['wc_bg_split_options_json'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
				$decoded   = json_decode( $raw_split, true );
				if ( is_array( $decoded ) && ! empty( $decoded ) ) {
					$clean_split = array();
					foreach ( $decoded as $row ) {
						$clean_split[] = array(
							'option_key' => sanitize_key( isset( $row['option_key'] ) ? $row['option_key'] : '' ),
							'label'      => sanitize_text_field( isset( $row['label'] ) ? $row['label'] : '' ),
							'percentage' => max( 1, min( 100, floatval( isset( $row['percentage'] ) ? $row['percentage'] : 50 ) ) ),
						);
					}
					$settings['split_options'] = $clean_split;
				}
			}

			// 2. Save Delbetalning repeater campaigns
			if ( isset( $_POST['wc_bg_delbetalning_campaigns_json'] ) ) {
				$raw_camp = wp_unslash( $_POST['wc_bg_delbetalning_campaigns_json'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
				$decoded  = json_decode( $raw_camp, true );
				if ( is_array( $decoded ) && ! empty( $decoded ) ) {
					$clean_camp = array();
					foreach ( $decoded as $row ) {
						$clean_camp[] = array(
							'campaign_name' => sanitize_text_field( isset( $row['campaign_name'] ) ? $row['campaign_name'] : '' ),
							'interest_rate' => sanitize_text_field( isset( $row['interest_rate'] ) ? $row['interest_rate'] : '0 %' ),
							'setup_fee'     => sanitize_text_field( isset( $row['setup_fee'] ) ? $row['setup_fee'] : '' ),
							'monthly_fee'   => sanitize_text_field( isset( $row['monthly_fee'] ) ? $row['monthly_fee'] : '' ),
							'max_amount'    => sanitize_text_field( isset( $row['max_amount'] ) ? $row['max_amount'] : '' ),
						);
					}
					$settings['delbetalning_campaigns'] = $clean_camp;
				}
			}

			// 3. Save Logos
			if ( isset( $_POST['wc_bg_split_logo'] ) ) {
				$settings['split_logo'] = esc_url_raw( wp_unslash( $_POST['wc_bg_split_logo'] ) );
			}
			if ( isset( $_POST['wc_bg_delbetalning_logo'] ) ) {
				$settings['delbetalning_logo'] = esc_url_raw( wp_unslash( $_POST['wc_bg_delbetalning_logo'] ) );
			}

			// 4. Save Övrig information
			if ( isset( $_POST['wc_bg_ovrig_info'] ) ) {
				$settings['delbetalning_ovrig_info'] = wp_kses_post( wp_unslash( $_POST['wc_bg_ovrig_info'] ) );
			}

			// 5. Save HTML Descriptions with wp_kses_post
			$post_prefix = 'woocommerce_' . $this->id . '_';
			if ( isset( $_POST[ $post_prefix . 'split_description' ] ) ) {
				$settings['split_description'] = wp_kses_post( wp_unslash( $_POST[ $post_prefix . 'split_description' ] ) );
			}
			if ( isset( $_POST[ $post_prefix . 'delbetalning_description' ] ) ) {
				$settings['delbetalning_description'] = wp_kses_post( wp_unslash( $_POST[ $post_prefix . 'delbetalning_description' ] ) );
			}

			update_option( $this->get_option_key(), $settings );
		}

		/**
		 * Validate and sanitize split_description allowing safe HTML tags.
		 *
		 * @param string $key   Field key.
		 * @param string $value Field value.
		 * @return string Sanitized HTML.
		 */
		public function validate_split_description_field( $key, $value ) {
			return wp_kses_post( trim( wp_unslash( $value ) ) );
		}

		/**
		 * Validate and sanitize delbetalning_description allowing safe HTML tags.
		 *
		 * @param string $key   Field key.
		 * @param string $value Field value.
		 * @return string Sanitized HTML.
		 */
		public function validate_delbetalning_description_field( $key, $value ) {
			return wp_kses_post( trim( wp_unslash( $value ) ) );
		}

		/**
		 * Render the comprehensive tabbed Admin Options view.
		 */
		public function admin_options() {
			$gw_settings = wc_bankgiro_split_get_gateway_settings();
			?>
			<div class="wc-bg-admin-wrap">
				<!-- Header Banner -->
				<div class="wc-bg-admin-header">
					<div>
						<h2>
							<?php echo esc_html( $this->get_method_title() ); ?>
							<span class="wc-bg-version-pill">v<?php echo esc_html( WC_BG_SPLIT_VERSION ); ?></span>
						</h2>
						<p><?php echo esc_html__( 'Konfigurera delbetalning (LF Finans) och 50/50 Bankgiro-betalning med anpassade kassanivåer och automatiska e-postrutor.', 'wc-bankgiro-split' ); ?></p>
					</div>
				</div>

				<!-- Navigation Tabs -->
				<nav class="wc-bg-admin-tabs">
					<button type="button" class="wc-bg-admin-tab-btn is-active" data-target="all"><?php echo esc_html__( 'Alla inställningar', 'wc-bankgiro-split' ); ?></button>
					<button type="button" class="wc-bg-admin-tab-btn" data-target="general"><?php echo esc_html__( '1. Allmänt', 'wc-bankgiro-split' ); ?></button>
					<button type="button" class="wc-bg-admin-tab-btn" data-target="split"><?php echo esc_html__( '2. Split (50/50)', 'wc-bankgiro-split' ); ?></button>
					<button type="button" class="wc-bg-admin-tab-btn" data-target="delbetalning"><?php echo esc_html__( '3. Delbetalning', 'wc-bankgiro-split' ); ?></button>
					<button type="button" class="wc-bg-admin-tab-btn" data-target="guide"><?php echo esc_html__( '📖 Bruksanvisning & Guide', 'wc-bankgiro-split' ); ?></button>
				</nav>

				<!-- Section 1: Allmänt (General) -->
				<div class="wc-bg-admin-section" data-section="general">
					<div class="wc-bg-admin-section-header">
						<h3><?php echo esc_html__( '1. Allmänna inställningar & Aktivering', 'wc-bankgiro-split' ); ?></h3>
						<p><?php echo esc_html__( 'Hantera betalningsmetodens synlighet, standardtitel och minsta varukorgsvärde.', 'wc-bankgiro-split' ); ?></p>
					</div>
					<table class="form-table">
						<?php
						$this->generate_settings_html(
							array(
								'enabled'             => $this->form_fields['enabled'],
								'title'               => $this->form_fields['title'],
								'description'         => $this->form_fields['description'],
								'minimum_amount'      => $this->form_fields['minimum_amount'],
								'enable_split'        => $this->form_fields['enable_split'],
								'enable_delbetalning' => $this->form_fields['enable_delbetalning'],
							)
						);
						?>
					</table>
				</div>

				<!-- Section 2: Split (50/50) Settings & Repeater -->
				<div class="wc-bg-admin-section" data-section="split">
					<div class="wc-bg-admin-section-header">
						<h3><?php echo esc_html__( '2. Split-läge (50/50 Förskott via Bankgiro)', 'wc-bankgiro-split' ); ?></h3>
						<p><?php echo esc_html__( 'Anpassa texter, Bankgironummer, logotyp och anpassningsbara delningsalternativ.', 'wc-bankgiro-split' ); ?></p>
					</div>
					<table class="form-table">
						<?php
						$this->generate_settings_html(
							array(
								'split_title'       => $this->form_fields['split_title'],
								'split_subtitle'    => $this->form_fields['split_subtitle'],
								'split_description' => $this->form_fields['split_description'],
								'bankgiro_number'   => $this->form_fields['bankgiro_number'],
								'receiver_name'     => $this->form_fields['receiver_name'],
								'terms_text'        => $this->form_fields['terms_text'],
							)
						);
						?>
						<!-- Split Logo Uploader -->
						<tr valign="top">
							<th scope="row" class="titledesc">
								<label><?php echo esc_html__( 'Split Logotyp', 'wc-bankgiro-split' ); ?></label>
							</th>
							<td class="forminp">
								<div class="wc-bg-logo-uploader">
									<div class="wc-bg-logo-input-row">
										<input type="text" name="wc_bg_split_logo" class="wc-bg-logo-url-input regular-text" value="<?php echo esc_attr( $gw_settings['split_logo'] ); ?>" placeholder="https://..." />
										<button type="button" class="button wc-bg-upload-logo-btn"><?php echo esc_html__( 'Välj bild...', 'wc-bankgiro-split' ); ?></button>
										<button type="button" class="button wc-bg-remove-logo-btn"><?php echo esc_html__( 'Rensa', 'wc-bankgiro-split' ); ?></button>
									</div>
									<div class="wc-bg-logo-preview-box">
										<?php if ( ! empty( $gw_settings['split_logo'] ) ) : ?>
											<img src="<?php echo esc_url( $gw_settings['split_logo'] ); ?>" alt="Split Logo" />
										<?php else : ?>
											<span class="wc-bg-logo-preview-empty"><?php echo esc_html__( 'Ingen logotyp vald (standardikon visas i kassan)', 'wc-bankgiro-split' ); ?></span>
										<?php endif; ?>
									</div>
								</div>
							</td>
						</tr>

						<!-- Split Options Repeater -->
						<tr valign="top">
							<th scope="row" class="titledesc">
								<label><?php echo esc_html__( 'Betalningsalternativ (Split)', 'wc-bankgiro-split' ); ?></label>
								<p class="description"><?php echo esc_html__( 'Definiera procent och visningstext för de alternativ kunden kan välja i Split-läget.', 'wc-bankgiro-split' ); ?></p>
							</th>
							<td class="forminp">
								<div class="wc-bg-repeater-wrap">
									<div class="wc-bg-repeater-table-responsive">
										<table class="wc-bg-repeater-table">
											<thead>
												<tr>
													<th style="width: 140px;"><?php echo esc_html__( 'Nyckel (ID)', 'wc-bankgiro-split' ); ?></th>
													<th><?php echo esc_html__( 'Visningsetikett i dropdown', 'wc-bankgiro-split' ); ?></th>
													<th style="width: 120px;"><?php echo esc_html__( 'Procent (%)', 'wc-bankgiro-split' ); ?></th>
													<th><?php echo esc_html__( 'Åtgärder', 'wc-bankgiro-split' ); ?></th>
												</tr>
											</thead>
											<tbody id="wc-bg-split-repeater-tbody">
												<?php foreach ( $gw_settings['split_options'] as $split_opt ) : ?>
													<tr class="wc-bg-repeater-row">
														<td><input type="text" class="wc-bg-split-col-key" value="<?php echo esc_attr( $split_opt['option_key'] ); ?>" /></td>
														<td><input type="text" class="wc-bg-split-col-label" value="<?php echo esc_attr( $split_opt['label'] ); ?>" /></td>
														<td><input type="number" class="wc-bg-split-col-pct" value="<?php echo esc_attr( $split_opt['percentage'] ); ?>" min="1" max="100" /></td>
														<td>
															<div class="wc-bg-repeater-actions">
																<button type="button" class="wc-bg-row-btn wc-bg-move-up" title="Flytta upp">↑</button>
																<button type="button" class="wc-bg-row-btn wc-bg-move-down" title="Flytta ner">↓</button>
																<button type="button" class="wc-bg-row-btn is-delete wc-bg-remove-row" title="Ta bort rad">✕</button>
															</div>
														</td>
													</tr>
												<?php endforeach; ?>
											</tbody>
										</table>
									</div>
									<div class="wc-bg-repeater-footer">
										<button type="button" id="wc-bg-add-split-row" class="button button-secondary">+ <?php echo esc_html__( 'Lägg till rad', 'wc-bankgiro-split' ); ?></button>
										<button type="button" id="wc-bg-reset-split-rows" class="button" data-defaults="<?php echo esc_attr( wp_json_encode( wc_bankgiro_split_get_default_split_options() ) ); ?>">
											↺ <?php echo esc_html__( 'Återställ till standard (50/50 & 100%)', 'wc-bankgiro-split' ); ?>
										</button>
									</div>
									<input type="hidden" name="wc_bg_split_options_json" id="wc_bg_split_options_json" value="<?php echo esc_attr( wp_json_encode( $gw_settings['split_options'] ) ); ?>" />
								</div>
							</td>
						</tr>
					</table>
				</div>

				<!-- Section 3: Delbetalning Settings & Campaigns Repeater -->
				<div class="wc-bg-admin-section" data-section="delbetalning">
					<div class="wc-bg-admin-section-header">
						<h3><?php echo esc_html__( '3. Delbetalnings-läge (LF Finans Installment)', 'wc-bankgiro-split' ); ?></h3>
						<p><?php echo esc_html__( 'Ställ in kampanjtabell (5 kolumner), logotyp, villkorslänk och "Övrig information".', 'wc-bankgiro-split' ); ?></p>
					</div>
					<table class="form-table">
						<?php
						$this->generate_settings_html(
							array(
								'delbetalning_title'         => $this->form_fields['delbetalning_title'],
								'delbetalning_subtitle'      => $this->form_fields['delbetalning_subtitle'],
								'delbetalning_description'   => $this->form_fields['delbetalning_description'],
								'delbetalning_terms_heading' => $this->form_fields['delbetalning_terms_heading'],
								'delbetalning_terms_url'     => $this->form_fields['delbetalning_terms_url'],
							)
						);
						?>
						<!-- Delbetalning Logo Uploader -->
						<tr valign="top">
							<th scope="row" class="titledesc">
								<label><?php echo esc_html__( 'Delbetalning Logotyp', 'wc-bankgiro-split' ); ?></label>
							</th>
							<td class="forminp">
								<div class="wc-bg-logo-uploader">
									<div class="wc-bg-logo-input-row">
										<input type="text" name="wc_bg_delbetalning_logo" class="wc-bg-logo-url-input regular-text" value="<?php echo esc_attr( $gw_settings['delbetalning_logo'] ); ?>" placeholder="https://..." />
										<button type="button" class="button wc-bg-upload-logo-btn"><?php echo esc_html__( 'Välj bild...', 'wc-bankgiro-split' ); ?></button>
										<button type="button" class="button wc-bg-remove-logo-btn"><?php echo esc_html__( 'Rensa', 'wc-bankgiro-split' ); ?></button>
									</div>
									<div class="wc-bg-logo-preview-box">
										<?php if ( ! empty( $gw_settings['delbetalning_logo'] ) ) : ?>
											<img src="<?php echo esc_url( $gw_settings['delbetalning_logo'] ); ?>" alt="Delbetalning Logo" />
										<?php else : ?>
											<span class="wc-bg-logo-preview-empty"><?php echo esc_html__( 'Ingen logotyp vald (standardikon visas i kassan)', 'wc-bankgiro-split' ); ?></span>
										<?php endif; ?>
									</div>
								</div>
							</td>
						</tr>

						<!-- Delbetalning Campaigns 5-Column Repeater -->
						<tr valign="top">
							<th scope="row" class="titledesc">
								<label><?php echo esc_html__( 'Kampanjtabell (5 kolumner)', 'wc-bankgiro-split' ); ?></label>
								<p class="description"><?php echo esc_html__( 'Denna tabell visas i kassan för jämförelse. I orderbekräftelsen visas endast det valda alternativet.', 'wc-bankgiro-split' ); ?></p>
							</th>
							<td class="forminp">
								<div class="wc-bg-repeater-wrap">
									<div style="background: #eff6ff; border-left: 4px solid #3b82f6; padding: 12px 14px; margin-bottom: 14px; border-radius: 4px; font-size: 13px; color: #1e3a8a; line-height: 1.55;">
										<strong><?php echo esc_html__( 'OBS!', 'wc-bankgiro-split' ); ?></strong>
										<?php echo esc_html__( 'Börja alltid namnet på varje kampanjalternativ med en siffra som anger antal månader (t.ex. \'12 mån räntefritt\' eller \'24 månader\'). Systemet läser automatiskt av siffran i början av raden för att räkna ut kundens månadskostnad i kassan och i orderbekräftelsen.', 'wc-bankgiro-split' ); ?>
									</div>
									<div class="wc-bg-repeater-table-responsive">
										<table class="wc-bg-repeater-table">
											<thead>
												<tr>
													<th><?php echo esc_html__( 'Kampanjalternativ', 'wc-bankgiro-split' ); ?></th>
													<th style="width: 100px;"><?php echo esc_html__( 'Ränta', 'wc-bankgiro-split' ); ?></th>
													<th style="width: 130px;"><?php echo esc_html__( 'Uppläggningsavgift', 'wc-bankgiro-split' ); ?></th>
													<th style="width: 130px;"><?php echo esc_html__( 'Månatlig aviavgift', 'wc-bankgiro-split' ); ?></th>
													<th style="width: 120px;"><?php echo esc_html__( 'Maxbelopp', 'wc-bankgiro-split' ); ?></th>
													<th><?php echo esc_html__( 'Åtgärder', 'wc-bankgiro-split' ); ?></th>
												</tr>
											</thead>
											<tbody id="wc-bg-delbetalning-repeater-tbody">
												<?php foreach ( $gw_settings['delbetalning_campaigns'] as $camp ) : ?>
													<tr class="wc-bg-repeater-row">
														<td><input type="text" class="wc-bg-camp-col-name" value="<?php echo esc_attr( $camp['campaign_name'] ); ?>" /></td>
														<td><input type="text" class="wc-bg-camp-col-rate" value="<?php echo esc_attr( $camp['interest_rate'] ); ?>" /></td>
														<td><input type="text" class="wc-bg-camp-col-setup" value="<?php echo esc_attr( $camp['setup_fee'] ); ?>" /></td>
														<td><input type="text" class="wc-bg-camp-col-monthly" value="<?php echo esc_attr( $camp['monthly_fee'] ); ?>" /></td>
														<td><input type="text" class="wc-bg-camp-col-max" value="<?php echo esc_attr( $camp['max_amount'] ); ?>" /></td>
														<td>
															<div class="wc-bg-repeater-actions">
																<button type="button" class="wc-bg-row-btn wc-bg-move-up" title="Flytta upp">↑</button>
																<button type="button" class="wc-bg-row-btn wc-bg-move-down" title="Flytta ner">↓</button>
																<button type="button" class="wc-bg-row-btn is-delete wc-bg-remove-row" title="Ta bort rad">✕</button>
															</div>
														</td>
													</tr>
												<?php endforeach; ?>
											</tbody>
										</table>
									</div>
									<div class="wc-bg-repeater-footer">
										<button type="button" id="wc-bg-add-delbetalning-row" class="button button-secondary">+ <?php echo esc_html__( 'Lägg till kampanjrad', 'wc-bankgiro-split' ); ?></button>
										<button type="button" id="wc-bg-reset-delbetalning-rows" class="button" data-defaults="<?php echo esc_attr( wp_json_encode( wc_bankgiro_split_get_default_delbetalning_campaigns() ) ); ?>">
											↺ <?php echo esc_html__( 'Återställ till standardkampanjer (3, 6, 12, 24, 36 mån)', 'wc-bankgiro-split' ); ?>
										</button>
									</div>
									<input type="hidden" name="wc_bg_delbetalning_campaigns_json" id="wc_bg_delbetalning_campaigns_json" value="<?php echo esc_attr( wp_json_encode( $gw_settings['delbetalning_campaigns'] ) ); ?>" />
								</div>
							</td>
						</tr>

						<!-- Övrig information HTML textarea -->
						<tr valign="top">
							<th scope="row" class="titledesc">
								<label><?php echo esc_html__( 'Övrig information (Villkor & Information)', 'wc-bankgiro-split' ); ?></label>
								<p class="description"><?php echo esc_html__( 'Visas under kampanjtabellen i kassan samt i e-post.', 'wc-bankgiro-split' ); ?></p>
							</th>
							<td class="forminp">
								<textarea name="wc_bg_ovrig_info" rows="8" class="large-text code" style="width:100%;"><?php echo esc_textarea( $gw_settings['delbetalning_ovrig_info'] ); ?></textarea>
							</td>
						</tr>
					</table>
				</div>

				<!-- Section 4: Bruksanvisning & Guide (User Guide for Store Owner) -->
				<div class="wc-bg-admin-section" data-section="guide">
					<div class="wc-bg-admin-section-header">
						<h3><?php echo esc_html__( '📖 Bruksanvisning & Handbok för butiksägaren', 'wc-bankgiro-split' ); ?></h3>
						<p><?php echo esc_html__( 'En komplett svensk guide till hur tillägget fungerar, vilka inställningar som styr vad i kassan och hur bekräftelsemejl kopplas.', 'wc-bankgiro-split' ); ?></p>
					</div>

					<div class="wc-bg-guide-grid">
						<!-- Card 1: Översikt -->
						<div class="wc-bg-guide-card">
							<div class="wc-bg-guide-card-header">
								<div class="wc-bg-guide-icon">💡</div>
								<h4 class="wc-bg-guide-card-title"><?php echo esc_html__( '1. Så fungerar pluginet (Översikt)', 'wc-bankgiro-split' ); ?></h4>
							</div>
							<p><?php echo esc_html__( 'Det här tillägget kombinerar två flexibla betalningsformer under en och samma betalningsmetod i WooCommerce-kassan:', 'wc-bankgiro-split' ); ?></p>
							<ul>
								<li><strong>Split (50/50 Förskottsbetalning):</strong> <?php echo esc_html__( 'Kunden betalar 50% i förskott via Bankgiro vid beställning och resterande 50% innan eller vid leverans (alternativt 100% direktbetalning om kunden önskar).', 'wc-bankgiro-split' ); ?></li>
								<li><strong>Delbetalning (LF Finans):</strong> <?php echo esc_html__( 'Kunden erbjuds månadsvis delbetalning (räntefritt eller enligt kampanj) upp till 36 månader i samarbete med LF Finans.', 'wc-bankgiro-split' ); ?></li>
							</ul>
							<p><?php echo esc_html__( 'När kunden genomför köpet sätts ordern i status "Väntar på handläggning / betalning" (On-hold), och kundens specifika val och personnummer registreras i orderhistoriken.', 'wc-bankgiro-split' ); ?></p>
						</div>

						<!-- Card 2: Allmänna inställningar & Beloppsgräns -->
						<div class="wc-bg-guide-card">
							<div class="wc-bg-guide-card-header">
								<div class="wc-bg-guide-icon">⚙️</div>
								<h4 class="wc-bg-guide-card-title"><?php echo esc_html__( '2. Allmänna inställningar & Beloppsgräns', 'wc-bankgiro-split' ); ?></h4>
							</div>
							<ul>
								<li><strong><?php echo esc_html__( 'Titel & Beskrivning:', 'wc-bankgiro-split' ); ?></strong> <?php echo esc_html__( 'Visas i listan över tillgängliga betalsätt i kassan innan kunden klickar upp formuläret.', 'wc-bankgiro-split' ); ?></li>
								<li><strong><?php echo esc_html__( 'Minsta ordervärde (SEK):', 'wc-bankgiro-split' ); ?></strong> <?php echo esc_html__( 'Styr när betalmetoden överhuvudtaget ska vara synlig. Om varukorgens totala värde understiger denna summa (standard 10 000 kr) döljs betalningsmetoden automatiskt.', 'wc-bankgiro-split' ); ?></li>
								<li><strong><?php echo esc_html__( 'Aktivera Split / Delbetalning:', 'wc-bankgiro-split' ); ?></strong> <?php echo esc_html__( 'Om båda är aktiverade visas två klickbara lägeskort med logotyper högst upp i kassan. Om du bara vill erbjuda ett av alternativen inaktiverar du det andra, och då visas det aktiva direkt utan väljarkort.', 'wc-bankgiro-split' ); ?></li>
							</ul>
						</div>

						<!-- Card 3: 50/50 Split – Vad syns var? -->
						<div class="wc-bg-guide-card">
							<div class="wc-bg-guide-card-header">
								<div class="wc-bg-guide-icon">🏦</div>
								<h4 class="wc-bg-guide-card-title"><?php echo esc_html__( '3. 50/50 Split – Vad syns var?', 'wc-bankgiro-split' ); ?></h4>
							</div>
							<p><?php echo esc_html__( 'Här är en tydlig överblick över hur dina inställningar för Split används:', 'wc-bankgiro-split' ); ?></p>
							<div style="overflow-x: auto;">
								<table class="wc-bg-guide-table">
									<thead>
										<tr>
											<th><?php echo esc_html__( 'Fält i admin', 'wc-bankgiro-split' ); ?></th>
											<th><?php echo esc_html__( 'Syns i kassan (Checkout)', 'wc-bankgiro-split' ); ?></th>
											<th><?php echo esc_html__( 'Syns i orderbekräftelse & e-post', 'wc-bankgiro-split' ); ?></th>
										</tr>
									</thead>
									<tbody>
										<tr>
											<td><strong><?php echo esc_html__( 'Fliktitel & Undertitel', 'wc-bankgiro-split' ); ?></strong></td>
											<td><?php echo esc_html__( 'Ja, i det översta väljarkortet.', 'wc-bankgiro-split' ); ?></td>
											<td><?php echo esc_html__( 'Nej.', 'wc-bankgiro-split' ); ?></td>
										</tr>
										<tr>
											<td><strong><?php echo esc_html__( 'Split Logotyp', 'wc-bankgiro-split' ); ?></strong></td>
											<td><?php echo esc_html__( 'Ja, i väljarkortet med 58px höjd.', 'wc-bankgiro-split' ); ?></td>
											<td><?php echo esc_html__( 'Nej.', 'wc-bankgiro-split' ); ?></td>
										</tr>
										<tr>
											<td><strong><?php echo esc_html__( 'Bankgironummer & Mottagare', 'wc-bankgiro-split' ); ?></strong></td>
											<td><?php echo esc_html__( 'Nej (visas efter lagd order).', 'wc-bankgiro-split' ); ?></td>
											<td><?php echo esc_html__( 'Ja, tydligt i Bankgiro-rutan med mottagarnamn och bankgiro.', 'wc-bankgiro-split' ); ?></td>
										</tr>
										<tr>
											<td><strong><?php echo esc_html__( 'Betalningsalternativ (Repeater)', 'wc-bankgiro-split' ); ?></strong></td>
											<td><?php echo esc_html__( 'Ja, i dropdown (räknar ut 50% eller 100% belopp i kr).', 'wc-bankgiro-split' ); ?></td>
											<td><?php echo esc_html__( 'Ja, "Summa att betala nu" och resterande belopp.', 'wc-bankgiro-split' ); ?></td>
										</tr>
										<tr>
											<td><strong><?php echo esc_html__( 'Villkorstext / Äganderätt', 'wc-bankgiro-split' ); ?></strong></td>
											<td><?php echo esc_html__( 'Nej.', 'wc-bankgiro-split' ); ?></td>
											<td><?php echo esc_html__( 'Ja, längst ner i betalningsrutan i mejlet och på tacksidan.', 'wc-bankgiro-split' ); ?></td>
										</tr>
										<tr>
											<td><strong><?php echo esc_html__( 'OCR-nummer', 'wc-bankgiro-split' ); ?></strong></td>
											<td><?php echo esc_html__( 'Nej (skapas automatiskt).', 'wc-bankgiro-split' ); ?></td>
											<td><?php echo esc_html__( 'Ja, alltid identiskt med WooCommerce ordernummer.', 'wc-bankgiro-split' ); ?></td>
										</tr>
										<tr>
											<td><strong><?php echo esc_html__( 'Kvitto-uppladdning', 'wc-bankgiro-split' ); ?></strong></td>
											<td><?php echo esc_html__( 'Nej.', 'wc-bankgiro-split' ); ?></td>
											<td><?php echo esc_html__( 'Ja! På Tacksidan (Order Received) kan kunden direkt ladda upp PDF/bild på sitt kvitto.', 'wc-bankgiro-split' ); ?></td>
										</tr>
									</tbody>
								</table>
							</div>
						</div>

						<!-- Card 4: Delbetalning & Kampanjtabellen -->
						<div class="wc-bg-guide-card">
							<div class="wc-bg-guide-card-header">
								<div class="wc-bg-guide-icon">💳</div>
								<h4 class="wc-bg-guide-card-title"><?php echo esc_html__( '4. Delbetalning & Kampanjtabellen (Viktigt att tänka på)', 'wc-bankgiro-split' ); ?></h4>
							</div>
							<p><?php echo esc_html__( 'I kassan visas en överskådlig jämförelsetabell med alla dina aktiva delbetalningskampanjer. Kunden klickar på den rad de föredrar.', 'wc-bankgiro-split' ); ?></p>
							
							<div class="wc-bg-guide-highlight-box">
								<strong>⚡ <?php echo esc_html__( 'VIKTIG REGEL ("Siffra först"):', 'wc-bankgiro-split' ); ?></strong><br />
								<?php echo esc_html__( 'Börja alltid namnet på varje kampanjalternativ med en siffra som anger antal månader, till exempel:', 'wc-bankgiro-split' ); ?><br />
								&bull; <code>12 mån räntefritt</code><br />
								&bull; <code>24 månader</code><br />
								&bull; <code>36 mån räntefritt (LF Finans)</code><br /><br />
								<em><?php echo esc_html__( 'Systemet läser av siffran i början av raden och räknar automatiskt ut kundens månadskostnad i realtid (Varukorgsbelopp / antal månader). Både i kassan och i kundens orderbekräftelsemejl visas en snygg rad, t.ex: "Vald plan: 24 mån räntefritt — Uppskattad månadskostnad: ca 833 kr/mån (20 000 kr / 24 mån)".', 'wc-bankgiro-split' ); ?></em>
							</div>

							<p><strong><?php echo esc_html__( 'Villkor och länk för Delbetalning:', 'wc-bankgiro-split' ); ?></strong></p>
							<ul>
								<li><strong><?php echo esc_html__( 'Villkorsrubrik:', 'wc-bankgiro-split' ); ?></strong> <?php echo esc_html__( 'Rubriken som visas ovanför villkorsrutan i kassan och e-post (standard: "Villkor").', 'wc-bankgiro-split' ); ?></li>
								<li><strong><?php echo esc_html__( 'Övrig information:', 'wc-bankgiro-split' ); ?></strong> <?php echo esc_html__( 'Information om sedvanlig kreditprövning, BankID-signering och administration via LF Finans.', 'wc-bankgiro-split' ); ?></li>
								<li><strong><?php echo esc_html__( 'Länk till fullständiga villkor:', 'wc-bankgiro-split' ); ?></strong> <?php echo esc_html__( 'Klickbar länk (t.ex. till https://www.lansforsakringar.se) som visas längst ner i bekräftelserutan.', 'wc-bankgiro-split' ); ?></li>
							</ul>
						</div>

						<!-- Card 5: E-post & YayMail-koppling -->
						<div class="wc-bg-guide-card">
							<div class="wc-bg-guide-card-header">
								<div class="wc-bg-guide-icon">✉️</div>
								<h4 class="wc-bg-guide-card-title"><?php echo esc_html__( '5. E-post & YayMail-koppling (Enkel integration)', 'wc-bankgiro-split' ); ?></h4>
							</div>
							<p><?php echo esc_html__( 'Du behöver bara EN integration i dina bekräftelsemejl! Tillägget avgör automatiskt om kunden valde Split (50/50 Bankgiro) eller Delbetalning (LF Finans) och anpassar hela informationsrutan dynamiskt.', 'wc-bankgiro-split' ); ?></p>
							
							<p><strong><?php echo esc_html__( 'Metod A: Nativ WooCommerce-hook (Rekommenderas för YayMail & standardmallar):', 'wc-bankgiro-split' ); ?></strong></p>
							<div class="wc-bg-code-box">
								<span>woocommerce_email_before_order_table</span>
								<button type="button" class="wc-bg-copy-btn" data-copy="woocommerce_email_before_order_table"><?php echo esc_html__( 'Kopiera', 'wc-bankgiro-split' ); ?></button>
							</div>
							<p style="font-size: 13px; color: #64748b; margin-top: -4px;">
								<em><?php echo esc_html__( 'I YayMail: Dra in blocket "WooCommerce Hook" och klistra in hook-namnet ovan (Prioritet: 20, Argument: 4). I vanliga WooCommerce-mallar injiceras rutan automatiskt ovanför orderraderna.', 'wc-bankgiro-split' ); ?></em>
							</p>

							<p style="margin-top: 14px;"><strong><?php echo esc_html__( 'Metod B: Kortkod (Shortcode för textblock eller sidor):', 'wc-bankgiro-split' ); ?></strong></p>
							<div class="wc-bg-code-box">
								<span>[partial_payment_info]</span>
								<button type="button" class="wc-bg-copy-btn" data-copy="[partial_payment_info]"><?php echo esc_html__( 'Kopiera', 'wc-bankgiro-split' ); ?></button>
							</div>

							<div style="background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 6px; padding: 12px 14px; margin-top: 14px; font-size: 13px; color: #166534; line-height: 1.5;">
								<strong>🔒 <?php echo esc_html__( 'Sekretess & Dataläckage-skydd:', 'wc-bankgiro-split' ); ?></strong><br />
								<?php echo esc_html__( 'Hela tabellen med alla kampanjer visas endast i kassan för jämförelse. I kundens orderbekräftelsemejl och på tacksidan visas ENBART det specifika alternativet som kunden valde. Aldrig hela jämförelsetabellen!', 'wc-bankgiro-split' ); ?>
							</div>
						</div>
					</div>
				</div>
			</div>
			<?php
		}

		/**
		 * Render custom checkout UI for Dual-Mode Gateway.
		 */
		public function payment_fields() {
			$gw_settings = wc_bankgiro_split_get_gateway_settings();
			$cart_total  = 0.0;

			if ( function_exists( 'WC' ) && WC() && isset( WC()->cart ) && is_object( WC()->cart ) ) {
				$cart_total = (float) WC()->cart->get_total( 'edit' );
			}

			$both_enabled = ( 'yes' === $gw_settings['enable_split'] && 'yes' === $gw_settings['enable_delbetalning'] );
			$default_mode = ( 'yes' === $gw_settings['enable_split'] ) ? 'split' : 'delbetalning';

			if ( ! empty( $this->description ) ) {
				echo '<p class="wc-bg-gateway-intro">' . esc_html( $this->description ) . '</p>';
			}
			?>
			<fieldset id="wc-<?php echo esc_attr( $this->id ); ?>-form" class="wc-payment-form wc-bg-dual-checkout-wrap" style="background:transparent; padding:0; margin:0;">
				<?php wp_nonce_field( 'wc_bankgiro_split_checkout', 'wc_bankgiro_split_nonce' ); ?>

				<?php if ( $both_enabled ) : ?>
					<!-- Dual Mode Selection Radio Cards -->
					<div class="wc-bg-mode-selector">
						<!-- Card 1: Split -->
						<label class="wc-bg-mode-card is-selected" data-mode="split">
							<input type="radio" name="bg_payment_mode" class="wc-bg-mode-radio" value="split" checked />
							<div class="wc-bg-mode-top-row">
								<span class="wc-bg-mode-radio-indicator"></span>
								<div class="wc-bg-mode-text">
									<span class="wc-bg-mode-title"><?php echo esc_html( $gw_settings['split_title'] ); ?></span>
									<span class="wc-bg-mode-subtitle"><?php echo esc_html( $gw_settings['split_subtitle'] ); ?></span>
								</div>
							</div>
							<div class="wc-bg-mode-logo-row">
								<div class="wc-bg-mode-logo">
									<?php if ( ! empty( $gw_settings['split_logo'] ) ) : ?>
										<img src="<?php echo esc_url( $gw_settings['split_logo'] ); ?>" alt="Split" />
									<?php else : ?>
										<span class="wc-bg-mode-logo-fallback">½</span>
									<?php endif; ?>
								</div>
							</div>
						</label>

						<!-- Card 2: Delbetalning -->
						<label class="wc-bg-mode-card" data-mode="delbetalning">
							<input type="radio" name="bg_payment_mode" class="wc-bg-mode-radio" value="delbetalning" />
							<div class="wc-bg-mode-top-row">
								<span class="wc-bg-mode-radio-indicator"></span>
								<div class="wc-bg-mode-text">
									<span class="wc-bg-mode-title"><?php echo esc_html( $gw_settings['delbetalning_title'] ); ?></span>
									<span class="wc-bg-mode-subtitle"><?php echo esc_html( $gw_settings['delbetalning_subtitle'] ); ?></span>
								</div>
							</div>
							<div class="wc-bg-mode-logo-row">
								<div class="wc-bg-mode-logo">
									<?php if ( ! empty( $gw_settings['delbetalning_logo'] ) ) : ?>
										<img src="<?php echo esc_url( $gw_settings['delbetalning_logo'] ); ?>" alt="LF Finans" />
									<?php else : ?>
										<span class="wc-bg-mode-logo-fallback">LF</span>
									<?php endif; ?>
								</div>
							</div>
						</label>
					</div>
				<?php else : ?>
					<input type="hidden" name="bg_payment_mode" class="wc-bg-mode-radio" value="<?php echo esc_attr( $default_mode ); ?>" />
				<?php endif; ?>

				<!-- Shared Field: SSN / Org.nr -->
				<div class="wc-bg-shared-field">
					<label for="bg_ssn_orgnr">
						<?php echo esc_html__( 'Personnummer / Organisationsnummer', 'wc-bankgiro-split' ); ?>
						<span class="required">*</span>
					</label>
					<input
						type="text"
						class="input-text"
						name="bg_ssn_orgnr"
						id="bg_ssn_orgnr"
						placeholder="<?php echo esc_attr__( 'ÅÅÅÅMMDD-XXXX / XXXXXX-XXXX', 'wc-bankgiro-split' ); ?>"
						autocomplete="off"
						required
					/>
				</div>

				<?php if ( 'yes' === $gw_settings['enable_split'] ) : ?>
					<!-- Panel 1: Split (50/50) -->
					<div class="wc-bg-mode-panel <?php echo ( 'split' === $default_mode ) ? '' : 'is-hidden'; ?>" data-mode="split">
						<div class="wc-bg-mode-info-text"><?php echo wp_kses_post( wpautop( $gw_settings['split_description'] ) ); ?></div>

						<div class="wc-bg-field-group">
							<label for="bg_payment_choice">
								<?php echo esc_html__( 'Välj betalningsalternativ:', 'wc-bankgiro-split' ); ?>
								<span class="required">*</span>
							</label>
							<select name="bg_payment_choice" id="bg_payment_choice" class="wc-bg-select">
								<?php foreach ( $gw_settings['split_options'] as $s_opt ) : ?>
									<?php
									$pct = (float) $s_opt['percentage'];
									$amt = ( $cart_total > 0 ) ? round( $cart_total * ( $pct / 100 ), 2 ) : 0;
									$opt_label = esc_html( $s_opt['label'] );
									if ( $cart_total > 0 && $amt > 0 ) {
										$opt_label .= ' — ' . wp_strip_all_tags( wc_price( $amt ) ) . ' ' . esc_html__( 'nu', 'wc-bankgiro-split' );
									}
									?>
									<option value="<?php echo esc_attr( $s_opt['option_key'] ); ?>">
										<?php echo $opt_label; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
									</option>
								<?php endforeach; ?>
							</select>
						</div>
					</div>
				<?php endif; ?>

				<?php if ( 'yes' === $gw_settings['enable_delbetalning'] ) : ?>
					<!-- Panel 2: Delbetalning -->
					<div class="wc-bg-mode-panel <?php echo ( 'delbetalning' === $default_mode ) ? '' : 'is-hidden'; ?>" data-mode="delbetalning" data-cart-total="<?php echo esc_attr( (string) $cart_total ); ?>">
						<div class="wc-bg-mode-info-text"><?php echo wp_kses_post( wpautop( $gw_settings['delbetalning_description'] ) ); ?></div>

						<label class="wc-bg-table-heading" for="bg_delbetalning_campaign">
							<?php echo esc_html__( 'Välj önskad delbetalningsplan:', 'wc-bankgiro-split' ); ?>
							<span class="required">*</span>
						</label>

						<!-- Comparison Table (5 columns + radio selection) -->
						<div class="wc-bg-campaign-table-wrap">
							<table class="wc-bg-campaign-table">
								<thead>
									<tr>
										<th class="wc-bg-radio-header"></th>
										<th><?php echo esc_html__( 'Kampanjalternativ', 'wc-bankgiro-split' ); ?></th>
										<th><?php echo esc_html__( 'Ränta', 'wc-bankgiro-split' ); ?></th>
										<th><?php echo esc_html__( 'Uppläggningsavgift', 'wc-bankgiro-split' ); ?></th>
										<th><?php echo esc_html__( 'Månatlig aviavgift', 'wc-bankgiro-split' ); ?></th>
										<th><?php echo esc_html__( 'Maxbelopp', 'wc-bankgiro-split' ); ?></th>
									</tr>
								</thead>
								<tbody>
									<?php foreach ( $gw_settings['delbetalning_campaigns'] as $idx => $camp ) : ?>
										<tr data-campaign-index="<?php echo esc_attr( (string) $idx ); ?>"
											data-campaign-name="<?php echo esc_attr( $camp['campaign_name'] ); ?>"
											data-interest-rate="<?php echo esc_attr( $camp['interest_rate'] ); ?>"
											data-setup-fee="<?php echo esc_attr( $camp['setup_fee'] ); ?>"
											data-monthly-fee="<?php echo esc_attr( $camp['monthly_fee'] ); ?>"
											data-max-amount="<?php echo esc_attr( $camp['max_amount'] ); ?>"
											class="<?php echo ( 0 === $idx ) ? 'is-selected-row' : ''; ?>">
											<td class="wc-bg-radio-cell"><span class="wc-bg-table-radio-circle"></span></td>
											<td><?php echo esc_html( $camp['campaign_name'] ); ?></td>
											<td><?php echo esc_html( $camp['interest_rate'] ); ?></td>
											<td><?php echo esc_html( $camp['setup_fee'] ); ?></td>
											<td><?php echo esc_html( $camp['monthly_fee'] ); ?></td>
											<td><?php echo esc_html( $camp['max_amount'] ); ?></td>
										</tr>
									<?php endforeach; ?>
								</tbody>
							</table>
						</div>

						<!-- Hidden Input for Form Submission + Live Dynamic Summary Box (Zero reload) -->
						<input type="hidden" name="bg_delbetalning_campaign" id="bg_delbetalning_campaign" value="0" />
						<div id="wc-bg-delbetalning-summary-box" class="wc-bg-delbetalning-summary-box"></div>

						<!-- Övrig information terms box -->
						<?php if ( ! empty( $gw_settings['delbetalning_ovrig_info'] ) ) : ?>
							<div class="wc-bg-terms-wrapper">
								<h4 class="wc-bg-terms-heading"><?php echo esc_html( $gw_settings['delbetalning_terms_heading'] ); ?></h4>
								<div class="wc-bg-other-info-box">
									<?php echo wp_kses_post( $gw_settings['delbetalning_ovrig_info'] ); ?>
								</div>
							</div>
						<?php endif; ?>
					</div>
				<?php endif; ?>

				<div class="clear"></div>
			</fieldset>
			<?php
		}

		/**
		 * Validate checkout fields upon submission.
		 *
		 * @return bool
		 */
		public function validate_fields() {
			$ssn  = isset( $_POST['bg_ssn_orgnr'] ) ? sanitize_text_field( wp_unslash( $_POST['bg_ssn_orgnr'] ) ) : '';
			$mode = isset( $_POST['bg_payment_mode'] ) ? sanitize_key( wp_unslash( $_POST['bg_payment_mode'] ) ) : 'split';

			if ( empty( $ssn ) ) {
				wc_add_notice( __( 'Vänligen ange ditt personnummer eller organisationsnummer.', 'wc-bankgiro-split' ), 'error' );
				return false;
			}

			if ( ! in_array( $mode, array( 'split', 'delbetalning' ), true ) ) {
				wc_add_notice( __( 'Ogiltigt betalningsläge valt.', 'wc-bankgiro-split' ), 'error' );
				return false;
			}

			return true;
		}

		/**
		 * Process payment, snapshot campaign data or calculate partial amount, and redirect to Thank You page.
		 *
		 * @param int $order_id WooCommerce Order ID.
		 * @return array
		 */
		public function process_payment( $order_id ) {
			$order = wc_get_order( $order_id );
			if ( ! $order ) {
				wc_add_notice( __( 'Kunde inte hitta ordern.', 'wc-bankgiro-split' ), 'error' );
				return array( 'result' => 'failure' );
			}

			$gw_settings = wc_bankgiro_split_get_gateway_settings();
			$mode        = isset( $_POST['bg_payment_mode'] ) ? sanitize_key( wp_unslash( $_POST['bg_payment_mode'] ) ) : 'split';
			$ssn         = isset( $_POST['bg_ssn_orgnr'] ) ? sanitize_text_field( wp_unslash( $_POST['bg_ssn_orgnr'] ) ) : '';
			$ocr_number  = (string) $order->get_order_number();
			$total       = (float) $order->get_total();

			// Common order meta
			$order->update_meta_data( '_bg_payment_mode', $mode );
			$order->update_meta_data( '_bg_ocr_number', $ocr_number );
			$order->update_meta_data( '_bg_customer_ssn', $ssn );

			if ( 'delbetalning' === $mode ) {
				// Snapshot Delbetalning Campaign details
				$camp_idx   = isset( $_POST['bg_delbetalning_campaign'] ) ? absint( $_POST['bg_delbetalning_campaign'] ) : 0;
				$campaigns  = $gw_settings['delbetalning_campaigns'];
				$chosen_cmp = isset( $campaigns[ $camp_idx ] ) ? $campaigns[ $camp_idx ] : $campaigns[0];

				$camp_name = sanitize_text_field( $chosen_cmp['campaign_name'] );
				$months    = 0;
				if ( preg_match( '/^\s*(\d+)/', $camp_name, $m_matches ) ) {
					$months = (int) $m_matches[1];
				}
				$monthly_cost = ( $months > 0 && $total > 0 && is_numeric( $total ) ) ? round( $total / $months ) : 0.0;

				$order->update_meta_data( '_bg_campaign_index', $camp_idx );
				$order->update_meta_data( '_bg_campaign_name', $camp_name );
				$order->update_meta_data( '_bg_interest_rate', sanitize_text_field( $chosen_cmp['interest_rate'] ) );
				$order->update_meta_data( '_bg_setup_fee', sanitize_text_field( $chosen_cmp['setup_fee'] ) );
				$order->update_meta_data( '_bg_monthly_fee', sanitize_text_field( $chosen_cmp['monthly_fee'] ) );
				$order->update_meta_data( '_bg_max_amount', sanitize_text_field( $chosen_cmp['max_amount'] ) );
				$order->update_meta_data( '_bg_months', $months );
				$order->update_meta_data( '_bg_monthly_cost', number_format( $monthly_cost, 2, '.', '' ) );
				$order->update_meta_data( '_bg_amount_to_pay', '0.00' );
				$order->update_meta_data( '_bg_remaining_amount', '0.00' );

				$monthly_text = ( $months > 0 && $monthly_cost > 0 )
					? sprintf( ' | Ca %s kr/mån (%d mån)', number_format( $monthly_cost, 0, ',', ' ' ), $months )
					: '';

				$order_note_msg = sprintf(
					/* translators: %1$s: Campaign Name, %2$s: Interest, %3$s: Monthly text, %4$s: Order number */
					__( '💳 DELBETALNING (LF Finans): Vald plan: %1$s (Ränta: %2$s)%3$s | Ordernr: %4$s', 'wc-bankgiro-split' ),
					$chosen_cmp['campaign_name'],
					$chosen_cmp['interest_rate'],
					$monthly_text,
					$ocr_number
				);
			} else {
				// Split (50/50 or full)
				$choice = isset( $_POST['bg_payment_choice'] ) ? sanitize_key( wp_unslash( $_POST['bg_payment_choice'] ) ) : 'split';
				$pct    = ( 'full' === $choice ) ? 100.0 : 50.0;

				// Check configured split options for matched percentage
				foreach ( $gw_settings['split_options'] as $s_opt ) {
					if ( $s_opt['option_key'] === $choice ) {
						$pct = (float) $s_opt['percentage'];
						break;
					}
				}

				$amount_to_pay    = round( $total * ( $pct / 100.0 ), 2 );
				$remaining_amount = max( 0.0, round( $total - $amount_to_pay, 2 ) );
				$plan_percentage  = $pct . '%';

				$order->update_meta_data( '_bg_payment_choice', $choice );
				$order->update_meta_data( '_bg_payment_plan', $plan_percentage );
				$order->update_meta_data( '_bg_amount_to_pay', number_format( $amount_to_pay, 2, '.', '' ) );
				$order->update_meta_data( '_bg_remaining_amount', number_format( $remaining_amount, 2, '.', '' ) );

				$order_note_msg = sprintf(
					/* translators: %1$s: Plan %, %2$s: Amount to pay, %3$s: OCR */
					__( '🏦 BANKGIRO 50/50: Plan: %1$s | Att betala nu: %2$s kr | OCR: %3$s', 'wc-bankgiro-split' ),
					$plan_percentage,
					number_format( $amount_to_pay, 2, ',', ' ' ),
					$ocr_number
				);
			}

			$order->save();

			// Credit Check Simulation (SSN ending in '00' flagged as high risk)
			$credit_approved = ( substr( trim( $ssn ), -2 ) !== '00' );
			if ( ! $credit_approved ) {
				$order->update_status( 'on-hold', __( 'Flaggad för manuell granskning pga kreditkontroll.', 'wc-bankgiro-split' ) );
				$order->add_order_note( '⚠️ KREDITKONTROLL: Flaggad för granskning (Hög risk). ' . $order_note_msg );
			} else {
				$order->update_status( 'on-hold', __( 'Väntar på handläggning / betalning.', 'wc-bankgiro-split' ) );
				$order->add_order_note( '🟢 KREDITKONTROLL: Godkänd utan anmärkning. ' . $order_note_msg );
			}

			$order->save();

			if ( function_exists( 'WC' ) && WC() && isset( WC()->cart ) && is_object( WC()->cart ) && method_exists( WC()->cart, 'empty_cart' ) ) {
				WC()->cart->empty_cart();
			}

			return array(
				'result'   => 'success',
				'redirect' => $this->get_return_url( $order ),
			);
		}
	}
}
}
add_action( 'plugins_loaded', 'wc_bankgiro_split_init_gateway_class' );

/**
 * ============================================================================
 * 8. DYNAMIC CART THRESHOLD FILTER
 * ============================================================================
 */

if ( ! function_exists( 'wc_bankgiro_split_restrict_by_order_total' ) ) {
/**
 * Restrict gateway display based on minimum order total.
 *
 * @param array $available_gateways Available WooCommerce payment gateways.
 * @return array
 */
function wc_bankgiro_split_restrict_by_order_total( $available_gateways ) {
	if ( ! is_array( $available_gateways ) || is_admin() ) {
		return $available_gateways;
	}

	// Guard against background tasks, cron, and CLI without active frontend cart.
	if ( defined( 'DOING_CRON' ) && DOING_CRON ) {
		return $available_gateways;
	}

	if ( ! isset( $available_gateways['bankgiro_split'] ) || ! is_object( $available_gateways['bankgiro_split'] ) ) {
		return $available_gateways;
	}

	$gateway        = $available_gateways['bankgiro_split'];
	$minimum_amount = isset( $gateway->minimum_amount ) && '' !== (string) $gateway->minimum_amount
		? (float) $gateway->minimum_amount
		: ( method_exists( $gateway, 'get_option' ) ? (float) $gateway->get_option( 'minimum_amount', 10000 ) : 10000.0 );

	if ( function_exists( 'WC' ) && WC() && isset( WC()->cart ) && is_object( WC()->cart ) && method_exists( WC()->cart, 'get_total' ) ) {
		$cart_total = (float) WC()->cart->get_total( 'edit' );
		if ( $cart_total > 0 && $cart_total < $minimum_amount ) {
			unset( $available_gateways['bankgiro_split'] );
		}
	}

	return $available_gateways;
}
}
add_filter( 'woocommerce_available_payment_gateways', 'wc_bankgiro_split_restrict_by_order_total' );
