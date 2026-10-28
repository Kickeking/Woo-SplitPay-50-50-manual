<?php
/**
 * Plugin Name: Woo SplitPay 50/50-manual
 * Description: Splits WooCommerce orders into a 50% deposit upon purchase and 50% final payment via manual bank transfer prior to delivery, with custom checkout and order confirmation instructions.
 * Version:     1.2.2
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
	define( 'WC_BG_SPLIT_VERSION', '1.2.2' );
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
	 * Handles both attached release .zip assets and GitHub's automatic source zipball_url.
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
 * 2. HELPER FUNCTIONS & NATIVE WOOCOMMERCE EMAIL HOOK (YAYMAIL COMPATIBLE)
 * ============================================================================
 */

if ( ! function_exists( 'wc_bankgiro_split_get_gateway_settings' ) ) {
/**
 * Retrieve the gateway settings with fallback defaults.
 *
 * @return array
 */
function wc_bankgiro_split_get_gateway_settings() {
	$settings     = get_option( 'woocommerce_bankgiro_split_settings', array() );
	if ( ! is_array( $settings ) ) {
		$settings = array();
	}
	$default_term = __( 'Äganderättsförbehåll: Fönstren/dörrarna förblir säljarens egendom tills full likvid har erhållits.', 'wc-bankgiro-split' );

	return array(
		'receiver_name'   => ! empty( $settings['receiver_name'] ) ? $settings['receiver_name'] : 'Din Butik AB',
		'bankgiro_number' => ! empty( $settings['bankgiro_number'] ) ? $settings['bankgiro_number'] : '123-4567',
		'terms_text'      => ! empty( $settings['terms_text'] ) ? $settings['terms_text'] : $default_term,
		'minimum_amount'  => isset( $settings['minimum_amount'] ) && '' !== $settings['minimum_amount'] ? (float) $settings['minimum_amount'] : 10000.0,
	);
}
}

if ( ! function_exists( 'wc_bankgiro_split_get_order_payment_data' ) ) {
/**
 * Extract or calculate the payment details for an order.
 *
 * @param WC_Order $order The WooCommerce Order object.
 * @return array {
 *     @type string $ocr             Exact WooCommerce order number.
 *     @type string $choice          'split' or 'full'.
 *     @type string $plan_percentage '50%' or '100%'.
 *     @type string $plan_label      Human-readable payment plan description.
 *     @type float  $total           Total order amount.
 *     @type float  $to_pay          Exact amount to pay now.
 *     @type float  $remaining       Remaining amount to pay on delivery.
 *     @type string $ssn             Customer SSN / Org.nr.
 *     @type int    $receipt_id      Attachment ID of uploaded receipt (0 if none).
 * }
 */
function wc_bankgiro_split_get_order_payment_data( $order ) {
	$order_number = (string) $order->get_order_number();
	$saved_ocr    = (string) $order->get_meta( '_bg_ocr_number' );
	$ocr          = ! empty( $saved_ocr ) ? $saved_ocr : $order_number;

	$choice = (string) $order->get_meta( '_bg_payment_choice' );
	if ( ! in_array( $choice, array( 'full', 'split' ), true ) ) {
		$choice = 'split';
	}

	$plan_percentage = ( 'split' === $choice ) ? '50%' : '100%';
	$plan_label      = ( 'split' === $choice )
		? __( '50% Förskott (50% vid leverans)', 'wc-bankgiro-split' )
		: __( '100% Direktbetalning', 'wc-bankgiro-split' );

	$total      = (float) $order->get_total();
	$saved_pay  = $order->get_meta( '_bg_amount_to_pay' );
	$to_pay     = ( '' !== $saved_pay && is_numeric( $saved_pay ) )
		? (float) $saved_pay
		: ( ( 'split' === $choice ) ? round( $total * 0.5, 2 ) : $total );
	$remaining  = ( 'split' === $choice ) ? max( 0.0, round( $total - $to_pay, 2 ) ) : 0.0;
	$ssn        = (string) $order->get_meta( '_bg_customer_ssn' );
	$receipt_id = absint( $order->get_meta( '_bg_receipt_attachment_id' ) );

	return array(
		'ocr'             => $ocr,
		'choice'          => $choice,
		'plan_percentage' => $plan_percentage,
		'plan_label'      => $plan_label,
		'total'           => $total,
		'to_pay'          => $to_pay,
		'remaining'       => $remaining,
		'ssn'             => $ssn,
		'receipt_id'      => $receipt_id,
	);
}
}

if ( ! function_exists( 'wc_bankgiro_split_get_payment_box_table_html' ) ) {
/**
 * Render an email-safe, table-based HTML box with inline CSS styling containing the Bankgiro payment instructions.
 *
 * @param WC_Order $order The WooCommerce Order object.
 * @return string Email-safe HTML table markup.
 */
function wc_bankgiro_split_get_payment_box_table_html( $order ) {
	$gw_settings     = wc_bankgiro_split_get_gateway_settings();
	$receiver_name   = $gw_settings['receiver_name'];
	$bankgiro_number = $gw_settings['bankgiro_number'];
	$terms_text      = $gw_settings['terms_text'];

	$data           = wc_bankgiro_split_get_order_payment_data( $order );
	$price_args     = array( 'currency' => $order->get_currency() );
	$to_pay_html    = wc_price( $data['to_pay'], $price_args );
	$remaining_html = wc_price( $data['remaining'], $price_args );

	ob_start();
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

							<?php if ( 'split' === $data['choice'] ) : ?>
								<!-- Remaining 50% Due at Delivery -->
								<tr>
									<td colspan="2" style="padding: 4px 0 10px 0; font-size: 13px; color: #64748b; line-height: 1.5;">
										<?php
										echo wp_kses_post(
											sprintf(
												/* translators: %s: Formatted remaining 50% amount via wc_price() */
												__( '* Resterande 50%% (%s) betalas vid leverans.', 'wc-bankgiro-split' ),
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
 * Render the Bankgiro payment instructions inside WooCommerce & YayMail emails.
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

	// 2. Verify that the order's payment method matches our 50/50 Bankgiro gateway ID.
	if ( 'bankgiro_split' !== $order->get_payment_method() ) {
		return;
	}

	// Plain-text email fallback.
	if ( $plain_text ) {
		$gw_settings = wc_bankgiro_split_get_gateway_settings();
		$data        = wc_bankgiro_split_get_order_payment_data( $order );
		$price_args  = array( 'currency' => $order->get_currency() );

		echo "\n==================================================\n";
		echo esc_html__( 'Betalningsinstruktioner för Bankgiro', 'wc-bankgiro-split' ) . "\n";
		echo esc_html__( 'Mottagare:', 'wc-bankgiro-split' ) . ' ' . esc_html( $gw_settings['receiver_name'] ) . "\n";
		echo esc_html__( 'Bankgiro:', 'wc-bankgiro-split' ) . ' ' . esc_html( $gw_settings['bankgiro_number'] ) . "\n";
		echo esc_html__( 'OCR-nummer:', 'wc-bankgiro-split' ) . ' ' . esc_html( $data['ocr'] ) . "\n";
		echo esc_html__( 'Vald betalningsplan:', 'wc-bankgiro-split' ) . ' ' . esc_html( $data['plan_label'] ) . "\n";
		echo esc_html__( 'Summa att betala nu:', 'wc-bankgiro-split' ) . ' ' . wp_strip_all_tags( wc_price( $data['to_pay'], $price_args ) ) . "\n";
		if ( 'split' === $data['choice'] ) {
			echo sprintf(
				/* translators: %s: Remaining amount */
				esc_html__( '* Resterande 50%% (%s) betalas vid leverans.', 'wc-bankgiro-split' ),
				wp_strip_all_tags( wc_price( $data['remaining'], $price_args ) )
			) . "\n";
		}
		echo esc_html( $gw_settings['terms_text'] ) . "\n";
		echo "==================================================\n\n";
		return;
	}

	// 3. Output the email-safe, table-based HTML box.
	echo wc_bankgiro_split_get_payment_box_table_html( $order ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
}
}
add_action( 'woocommerce_email_before_order_table', 'fg_display_partial_payment_email_box', 20, 4 );

/**
 * ============================================================================
 * 3. RECEIPT UPLOAD ON THE THANK YOU ("ORDER RECEIVED") PAGE
 * ============================================================================
 */

if ( ! function_exists( 'wc_bankgiro_split_process_receipt_upload_for_order' ) ) {
/**
 * Handle receipt file upload submitted on the Thank You page (both standard POST and AJAX).
 *
 * @param int $order_id Target Order ID.
 * @return array {
 *     @type bool   $success Whether upload succeeded.
 *     @type string $message Status message for customer.
 *     @type string $url     Uploaded file URL (if successful).
 * }
 */
function wc_bankgiro_split_process_receipt_upload_for_order( $order_id ) {
	$order = wc_get_order( $order_id );
	if ( ! $order ) {
		return array(
			'success' => false,
			'message' => __( 'Kunde inte hitta ordern.', 'wc-bankgiro-split' ),
		);
	}

	// Verify order key for security so only the buyer of this order can upload a receipt.
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

	// Validate allowed file extensions and MIME types (images + PDF).
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
			/* translators: %1$s: Attachment ID, %2$s: Attachment URL */
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
 * AJAX endpoint for uploading the receipt on the Thank You page without reloading.
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
 * Render the Payment Instructions + Receipt Upload Form on the "Order Received" (Thank You) page.
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

	// Handle synchronous POST upload if submitted without AJAX.
	$upload_feedback = null;
	if (
		isset( $_POST['wc_bg_split_upload_submit'], $_POST['bg_receipt_nonce'], $_POST['bg_order_id'] ) &&
		absint( $_POST['bg_order_id'] ) === $order_id &&
		wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['bg_receipt_nonce'] ) ), 'wc_bg_split_receipt_upload' )
	) {
		$upload_feedback = wc_bankgiro_split_process_receipt_upload_for_order( $order_id );
		$order           = wc_get_order( $order_id );
	}

	// 1. Output the table-based Payment Instructions box.
	echo wc_bankgiro_split_get_payment_box_table_html( $order ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

	// 2. Output the Receipt Upload Form linked to this order.
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
 * 4. VISIBILITY IN WOOCOMMERCE ORDER HISTORY (ADMIN)
 * ============================================================================
 */

if ( ! function_exists( 'wc_bankgiro_split_display_admin_order_meta' ) ) {
/**
 * Display Bankgiro 50/50 Split payment details directly inside the WooCommerce Admin Order Details panel.
 *
 * @param WC_Order $order The WooCommerce Order object.
 */
function wc_bankgiro_split_display_admin_order_meta( $order ) {
	if ( ! is_a( $order, 'WC_Order' ) ) {
		return;
	}

	$has_bg_meta = ( '' !== (string) $order->get_meta( '_bg_payment_choice' ) ) || ( '' !== (string) $order->get_meta( '_bg_ocr_number' ) );
	if ( 'bankgiro_split' !== $order->get_payment_method() && ! $has_bg_meta ) {
		return;
	}

	$data        = wc_bankgiro_split_get_order_payment_data( $order );
	$price_args  = array( 'currency' => $order->get_currency() );
	$receipt_id  = $data['receipt_id'];
	$receipt_url = $receipt_id ? wp_get_attachment_url( $receipt_id ) : (string) $order->get_meta( '_bg_receipt_attachment_url' );
	?>
	<div class="order_data_column_wide wc-bankgiro-admin-summary" style="clear: both; margin-top: 16px; padding: 14px 16px; background: #f8fafc; border: 1px solid #cbd5e1; border-left: 4px solid #0d4277; border-radius: 4px;">
		<h3 style="margin: 0 0 10px 0; color: #0d4277; font-size: 14px;">
			<?php echo esc_html__( '🏦 Bankgiro 50/50 Betalningsinformation', 'wc-bankgiro-split' ); ?>
		</h3>

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
			<?php if ( 'split' === $data['choice'] ) : ?>
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

		<?php if ( ! empty( $data['ssn'] ) ) : ?>
			<p style="margin: 4px 0;">
				<strong><?php echo esc_html__( 'Personnummer / Org.nr:', 'wc-bankgiro-split' ); ?></strong>
				<?php echo esc_html( $data['ssn'] ); ?>
			</p>
		<?php endif; ?>

		<p style="margin: 6px 0 0 0;">
			<strong><?php echo esc_html__( 'Betalningskvitto:', 'wc-bankgiro-split' ); ?></strong>
			<?php if ( ! empty( $receipt_url ) ) : ?>
				<a href="<?php echo esc_url( $receipt_url ); ?>" target="_blank" rel="noopener noreferrer" class="button button-small" style="margin-left: 4px;">
					<?php echo esc_html__( '📎 Visa uppladdat kvitto', 'wc-bankgiro-split' ); ?>
				</a>
			<?php else : ?>
				<span style="color: #64748b; font-style: italic;">
					<?php echo esc_html__( 'Inget kvitto uppladdat ännu', 'wc-bankgiro-split' ); ?>
				</span>
			<?php endif; ?>
		</p>
	</div>
	<?php
}
}
add_action( 'woocommerce_admin_order_data_after_billing_address', 'wc_bankgiro_split_display_admin_order_meta', 10, 1 );

if ( ! function_exists( 'wc_bankgiro_split_register_order_meta_box' ) ) {
/**
 * Register a dedicated Meta Box in the WooCommerce Admin Order screen (supports both legacy posts and HPOS).
 */
function wc_bankgiro_split_register_order_meta_box() {
	$screens = array( 'shop_order' );
	if ( function_exists( 'wc_get_page_screen_id' ) ) {
		$screens[] = wc_get_page_screen_id( 'shop-order' );
	}

	foreach ( array_unique( $screens ) as $screen ) {
		add_meta_box(
			'wc_bankgiro_split_order_meta_box',
			__( 'Bankgiro 50/50 – Betalning & Kvitto', 'wc-bankgiro-split' ),
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
 * @param WP_Post|WC_Order $post_or_order_object Post or Order object depending on HPOS setting.
 */
function wc_bankgiro_split_render_order_meta_box( $post_or_order_object ) {
	$order = is_a( $post_or_order_object, 'WC_Order' )
		? $post_or_order_object
		: ( is_object( $post_or_order_object ) && isset( $post_or_order_object->ID ) && function_exists( 'wc_get_order' ) ? wc_get_order( $post_or_order_object->ID ) : false );

	if ( ! $order ) {
		return;
	}

	$data        = wc_bankgiro_split_get_order_payment_data( $order );
	$price_args  = array( 'currency' => $order->get_currency() );
	$receipt_id  = $data['receipt_id'];
	$receipt_url = $receipt_id ? wp_get_attachment_url( $receipt_id ) : (string) $order->get_meta( '_bg_receipt_attachment_url' );
	?>
	<div style="line-height: 1.6;">
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
		<?php if ( 'split' === $data['choice'] ) : ?>
			<p style="margin: 6px 0; color: #475569; font-size: 12px;">
				<strong><?php echo esc_html__( 'Resterande 50% (vid leverans):', 'wc-bankgiro-split' ); ?></strong>
				<?php echo wp_kses_post( wc_price( $data['remaining'], $price_args ) ); ?>
			</p>
		<?php endif; ?>
		<?php if ( ! empty( $data['ssn'] ) ) : ?>
			<p style="margin: 6px 0;">
				<strong><?php echo esc_html__( 'Personnr / Org.nr:', 'wc-bankgiro-split' ); ?></strong><br/>
				<?php echo esc_html( $data['ssn'] ); ?>
			</p>
		<?php endif; ?>
		<hr style="border: none; border-top: 1px solid #e2e8f0; margin: 10px 0;" />
		<p style="margin: 6px 0;">
			<strong><?php echo esc_html__( 'Uppladdat kvitto:', 'wc-bankgiro-split' ); ?></strong><br/>
			<?php if ( ! empty( $receipt_url ) ) : ?>
				<a href="<?php echo esc_url( $receipt_url ); ?>" target="_blank" rel="noopener noreferrer" class="button button-primary" style="margin-top: 4px;">
					<?php echo esc_html__( 'Öppna betalningskvitto', 'wc-bankgiro-split' ); ?>
				</a>
			<?php else : ?>
				<span style="color: #64748b;"><?php echo esc_html__( 'Inget kvitto uppladdat.', 'wc-bankgiro-split' ); ?></span>
			<?php endif; ?>
		</p>
	</div>
	<?php
}
}

/**
 * ============================================================================
 * 5. WOOCOMMERCE PAYMENT GATEWAY REGISTRATION & CLASS
 * ============================================================================
 */

if ( ! function_exists( 'wc_bankgiro_split_plugin_action_links' ) ) {
/**
 * Add quick "Inställningar" link on the WordPress Plugins page.
 *
 * @param array $links Existing plugin action links.
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
 * Register the payment gateway in WooCommerce.
 *
 * @param array $gateways Existing WooCommerce payment gateways.
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
 * Initialize the gateway class once plugins are loaded.
 */
function wc_bankgiro_split_init_gateway_class() {
	if ( ! class_exists( 'WC_Payment_Gateway' ) || class_exists( 'WC_Gateway_Bankgiro_Split' ) ) {
		return;
	}

	/**
	 * Woo SplitPay 50/50-manual Payment Gateway Class.
	 */
	class WC_Gateway_Bankgiro_Split extends WC_Payment_Gateway {

		/**
		 * Minimum order amount required to display the gateway.
		 *
		 * @var float
		 */
		public $minimum_amount;

		/**
		 * Bankgiro number displayed in payment instructions.
		 *
		 * @var string
		 */
		public $bankgiro_number;

		/**
		 * Receiver company name displayed in payment instructions.
		 *
		 * @var string
		 */
		public $receiver_name;

		/**
		 * Retention of title / terms text displayed in payment instructions.
		 *
		 * @var string
		 */
		public $terms_text;

		/**
		 * Constructor for the gateway.
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

			// Define user set variables from settings.
			$this->enabled         = $this->get_option( 'enabled', 'no' );
			$this->title           = $this->get_option( 'title', __( 'Bankgiro (Manuell betalning)', 'wc-bankgiro-split' ) );
			$this->description     = $this->get_option( 'description', __( 'Betala säkert via ditt bankkonto.', 'wc-bankgiro-split' ) );
			$this->minimum_amount  = (float) $this->get_option( 'minimum_amount', 10000 );
			$this->bankgiro_number = $this->get_option( 'bankgiro_number', '123-4567' );
			$this->receiver_name   = $this->get_option( 'receiver_name', 'Din Butik AB' );
			$this->terms_text      = $this->get_option(
				'terms_text',
				__( 'Äganderättsförbehåll: Fönstren/dörrarna förblir säljarens egendom tills full likvid har erhållits.', 'wc-bankgiro-split' )
			);

			// Save admin options.
			add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
		}

		/**
		 * Render the Admin Options page with plugin instructions and email/YayMail hook guide.
		 */
		public function admin_options() {
			?>
			<h2>
				<?php echo esc_html( $this->get_method_title() ); ?>
				<span style="font-size: 12px; font-weight: 600; background: #e0f2fe; color: #0369a1; padding: 2px 8px; border-radius: 999px; margin-left: 8px; vertical-align: middle;">
					v<?php echo esc_html( WC_BG_SPLIT_VERSION ); ?>
				</span>
			</h2>
			<?php echo wp_kses_post( wpautop( $this->get_method_description() ) ); ?>

			<div style="background: #f8fafc; border: 1px solid #cbd5e1; border-left: 4px solid #0d4277; border-radius: 6px; padding: 18px 22px; margin: 18px 0 24px 0; max-width: 920px;">
				<h3 style="margin: 0 0 10px 0; color: #0d4277; font-size: 16px;">
					<?php echo esc_html__( '📘 Instruktioner & Guide – Så fungerar tillägget och E-post/YayMail-hooken', 'wc-bankgiro-split' ); ?>
				</h3>

				<p style="margin: 0 0 10px 0; color: #334155; font-size: 13.5px; line-height: 1.6;">
					<strong><?php echo esc_html__( '1. Hur betalningen fungerar i kassan & på tacksidan:', 'wc-bankgiro-split' ); ?></strong><br />
					<?php echo esc_html__( '• Kunden anger Personnummer/Org.nr och väljer antingen 50% förskottsbetalning (resterande 50% vid leverans) eller 100% direktbetalning.', 'wc-bankgiro-split' ); ?><br />
					<?php echo esc_html__( '• Orderns OCR-nummer sätts automatiskt till exakt samma nummer som WooCommerce-ordernumret.', 'wc-bankgiro-split' ); ?><br />
					<?php echo esc_html__( '• På tacksidan ("Order mottagen") visas betalningsinstruktionerna tillsammans med ett uppladdningsformulär där kunden direkt kan ladda upp sitt betalningskvitto (bild eller PDF).', 'wc-bankgiro-split' ); ?><br />
					<?php echo esc_html__( '• Alla uppgifter (betalningsplan, belopp att betala nu, OCR-nummer och uppladdat kvitto) sparas på ordern och syns direkt i WooCommerce Admin under orderdetaljerna.', 'wc-bankgiro-split' ); ?>
				</p>

				<hr style="border: none; border-top: 1px solid #e2e8f0; margin: 12px 0;" />

				<p style="margin: 0 0 8px 0; color: #334155; font-size: 13.5px; line-height: 1.6;">
					<strong><?php echo esc_html__( '2. Hur E-post & YayMail-hooken fungerar:', 'wc-bankgiro-split' ); ?></strong><br />
					<?php echo esc_html__( 'Tillägget använder WooCommerce inbyggda e-post-hook för att automatiskt visa en tabellbaserad betalningsruta i orderbekräftelsen:', 'wc-bankgiro-split' ); ?>
				</p>

				<div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 4px; padding: 10px 14px; margin: 8px 0 10px 0; font-family: Consolas, Monaco, monospace; font-size: 12.5px; color: #0f172a;">
					<div><strong>Hook-namn:</strong> <code style="background: #e0f2fe; color: #0369a1; padding: 2px 6px; border-radius: 3px; font-weight: 700;">woocommerce_email_before_order_table</code></div>
					<div style="margin-top: 4px;"><strong>Funktion:</strong> <code>fg_display_partial_payment_email_box</code> (Prioritet: <code>20</code>, Argument: <code>4</code>)</div>
					<div style="margin-top: 4px;"><strong>Tacksida-hook:</strong> <code style="background: #f1f5f9; color: #334155; padding: 2px 6px; border-radius: 3px;">woocommerce_thankyou</code></div>
				</div>

				<p style="margin: 0; color: #475569; font-size: 13px; line-height: 1.6;">
					<?php echo esc_html__( '• I YayMail: Dra in blocket "WooCommerce Hook" i din e-postmall och välj hooken "woocommerce_email_before_order_table".', 'wc-bankgiro-split' ); ?><br />
					<?php echo esc_html__( '• I vanliga WooCommerce-mejl: Betalningsrutan visas helt automatiskt ovanför ordertabellen.', 'wc-bankgiro-split' ); ?><br />
					<?php echo esc_html__( '• Automatisk kontroll: Betalningsrutan visas ENDAST om ordern är lagd med denna betalningsmetod (bankgiro_split). Vid kort/Klarna visas ingenting.', 'wc-bankgiro-split' ); ?>
				</p>
			</div>

			<table class="form-table">
				<?php $this->generate_settings_html(); ?>
			</table>
			<?php
		}

		/**
		 * Initialize Gateway Settings Form Fields for WP Admin.
		 */
		public function init_form_fields() {
			$this->form_fields = array(
				'enabled'         => array(
					'title'   => __( 'Aktivera/Inaktivera', 'wc-bankgiro-split' ),
					'type'    => 'checkbox',
					'label'   => __( 'Aktivera Woo SplitPay 50/50-manual', 'wc-bankgiro-split' ),
					'default' => 'no',
				),
				'title'           => array(
					'title'       => __( 'Titel', 'wc-bankgiro-split' ),
					'type'        => 'text',
					'description' => __( 'Betalningsmetodens titel som kunden ser i kassan.', 'wc-bankgiro-split' ),
					'default'     => __( 'Bankgiro (Manuell betalning)', 'wc-bankgiro-split' ),
					'desc_tip'    => true,
				),
				'description'     => array(
					'title'       => __( 'Beskrivning', 'wc-bankgiro-split' ),
					'type'        => 'textarea',
					'description' => __( 'Beskrivning av betalningsmetoden som visas för kunden i kassan.', 'wc-bankgiro-split' ),
					'default'     => __( 'Betala säkert via ditt bankkonto.', 'wc-bankgiro-split' ),
					'desc_tip'    => true,
				),
				'minimum_amount'  => array(
					'title'             => __( 'Minsta ordervärde (SEK)', 'wc-bankgiro-split' ),
					'type'              => 'number',
					'description'       => __( 'Minsta varukorgsbelopp för att denna betalningsmetod ska visas i kassan.', 'wc-bankgiro-split' ),
					'default'           => '10000',
					'desc_tip'          => true,
					'custom_attributes' => array(
						'min'  => '0',
						'step' => '1',
					),
				),
				'bankgiro_number' => array(
					'title'       => __( 'Bankgironummer', 'wc-bankgiro-split' ),
					'type'        => 'text',
					'description' => __( 'Bankgironummer som visas i betalningsinstruktionerna på tacksidan och i e-post.', 'wc-bankgiro-split' ),
					'default'     => '123-4567',
					'desc_tip'    => true,
				),
				'receiver_name'   => array(
					'title'       => __( 'Mottagare (Företagsnamn)', 'wc-bankgiro-split' ),
					'type'        => 'text',
					'description' => __( 'Mottagarens företagsnamn som visas på tacksidan och i e-post.', 'wc-bankgiro-split' ),
					'default'     => 'Din Butik AB',
					'desc_tip'    => true,
				),
				'terms_text'      => array(
					'title'       => __( 'Villkorstext / Äganderättsförbehåll', 'wc-bankgiro-split' ),
					'type'        => 'textarea',
					'description' => __( 'Text som visas längst ner i betalningsrutan i e-post och på tacksidan.', 'wc-bankgiro-split' ),
					'default'     => __( 'Äganderättsförbehåll: Fönstren/dörrarna förblir säljarens egendom tills full likvid har erhållits.', 'wc-bankgiro-split' ),
					'desc_tip'    => true,
				),
			);
		}

		/**
		 * Render custom checkout fields (SSN/Org.nr and 100% vs 50% payment plan choice).
		 */
		public function payment_fields() {
			if ( ! empty( $this->description ) ) {
				echo wp_kses_post( wpautop( wptexturize( $this->description ) ) );
			}

			?>
			<fieldset id="wc-<?php echo esc_attr( $this->id ); ?>-form" class="wc-payment-form" style="background:transparent; padding:0; margin:0;">
				<?php wp_nonce_field( 'wc_bankgiro_split_checkout', 'wc_bankgiro_split_nonce' ); ?>

				<p class="form-row form-row-wide validate-required">
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
				</p>

				<p class="form-row form-row-wide validate-required">
					<label for="bg_payment_choice">
						<?php echo esc_html__( 'Välj betalningsalternativ', 'wc-bankgiro-split' ); ?>
						<span class="required">*</span>
					</label>
					<select name="bg_payment_choice" id="bg_payment_choice" class="select" style="width:100%;" required>
						<option value="full"><?php echo esc_html__( 'Betala 100% direkt (Order skickas när full likvid syns)', 'wc-bankgiro-split' ); ?></option>
						<option value="split"><?php echo esc_html__( 'Betala 50% i förskott (Resterande 50% vid leverans)', 'wc-bankgiro-split' ); ?></option>
					</select>
				</p>

				<div class="clear"></div>
			</fieldset>
			<?php
		}

		/**
		 * Validate checkout fields on submission.
		 *
		 * @return bool
		 */
		public function validate_fields() {
			$ssn    = isset( $_POST['bg_ssn_orgnr'] ) ? sanitize_text_field( wp_unslash( $_POST['bg_ssn_orgnr'] ) ) : '';
			$choice = isset( $_POST['bg_payment_choice'] ) ? sanitize_text_field( wp_unslash( $_POST['bg_payment_choice'] ) ) : '';

			if ( empty( $ssn ) ) {
				wc_add_notice( __( 'Vänligen ange ditt personnummer eller organisationsnummer.', 'wc-bankgiro-split' ), 'error' );
				return false;
			}

			if ( ! in_array( $choice, array( 'full', 'split' ), true ) ) {
				wc_add_notice( __( 'Vänligen välj ett giltigt betalningsalternativ (50% eller 100%).', 'wc-bankgiro-split' ), 'error' );
				return false;
			}

			return true;
		}

		/**
		 * Process the payment, calculate exact amount and OCR (Order Number), save metadata, and redirect to Thank You page.
		 *
		 * @param int $order_id WooCommerce Order ID.
		 * @return array
		 */
		public function process_payment( $order_id ) {
			$order = wc_get_order( $order_id );

			if ( ! $order ) {
				wc_add_notice( __( 'Kunde inte hitta ordern.', 'wc-bankgiro-split' ), 'error' );
				return array(
					'result' => 'failure',
				);
			}

			// 1. Sanitize checkout inputs.
			$choice = isset( $_POST['bg_payment_choice'] ) ? sanitize_text_field( wp_unslash( $_POST['bg_payment_choice'] ) ) : 'full';
			if ( ! in_array( $choice, array( 'full', 'split' ), true ) ) {
				$choice = 'full';
			}

			$ssn = isset( $_POST['bg_ssn_orgnr'] ) ? sanitize_text_field( wp_unslash( $_POST['bg_ssn_orgnr'] ) ) : '';

			// 2. OCR number is strictly the exact same as the WooCommerce order number.
			$ocr_number = (string) $order->get_order_number();

			// 3. Calculate exact amount to be paid based on the 50% / 100% choice.
			$total            = (float) $order->get_total();
			$amount_to_pay    = ( 'split' === $choice ) ? round( $total * 0.5, 2 ) : $total;
			$remaining_amount = ( 'split' === $choice ) ? max( 0.0, round( $total - $amount_to_pay, 2 ) ) : 0.0;
			$plan_percentage  = ( 'split' === $choice ) ? '50%' : '100%';

			// 4. Register and save all payment data to WooCommerce order meta BEFORE status transition (so emails receive the saved meta).
			$order->update_meta_data( '_bg_payment_choice', $choice );
			$order->update_meta_data( '_bg_payment_plan', $plan_percentage );
			$order->update_meta_data( '_bg_amount_to_pay', number_format( $amount_to_pay, 2, '.', '' ) );
			$order->update_meta_data( '_bg_remaining_amount', number_format( $remaining_amount, 2, '.', '' ) );
			$order->update_meta_data( '_bg_ocr_number', $ocr_number );
			$order->update_meta_data( '_bg_customer_ssn', $ssn );
			$order->save();

			// 5. Credit Check Simulation: Check if the last two characters of the SSN are strictly '00'.
			$credit_approved = ( substr( trim( $ssn ), -2 ) !== '00' );

			if ( ! $credit_approved ) {
				$order->update_status(
					'on-hold',
					__( 'Order lagd under manuell granskning pga kreditkontroll.', 'wc-bankgiro-split' )
				);
				$order->add_order_note(
					sprintf(
						/* translators: %1$s: Payment plan, %2$s: Amount to pay, %3$s: OCR number */
						__( '⚠️ KREDITKONTROLL: Flaggad för manuell granskning (Hög risk). Plan: %1$s | Att betala nu: %2$s kr | OCR: %3$s', 'wc-bankgiro-split' ),
						$plan_percentage,
						number_format( $amount_to_pay, 2, ',', ' ' ),
						$ocr_number
					)
				);
			} else {
				$order->update_status(
					'on-hold',
					__( 'Väntar på Bankgiro-betalning.', 'wc-bankgiro-split' )
				);
				$order->add_order_note(
					sprintf(
						/* translators: %1$s: Payment plan, %2$s: Amount to pay, %3$s: OCR number */
						__( '🟢 KREDITKONTROLL: Godkänd utan anmärkningar. Plan: %1$s | Att betala nu: %2$s kr | OCR: %3$s', 'wc-bankgiro-split' ),
						$plan_percentage,
						number_format( $amount_to_pay, 2, ',', ' ' ),
						$ocr_number
					)
				);
			}

			$order->save();

			// 6. Empty WooCommerce cart and redirect to Thank You page.
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
 * 6. DYNAMIC CART THRESHOLD FILTER
 * ============================================================================
 */

if ( ! function_exists( 'wc_bankgiro_split_restrict_by_order_total' ) ) {
/**
 * Restrict the Bankgiro Split payment gateway based on the minimum cart total.
 *
 * @param array $available_gateways Available WooCommerce payment gateways.
 * @return array
 */
function wc_bankgiro_split_restrict_by_order_total( $available_gateways ) {
	if ( ! is_array( $available_gateways ) || is_admin() ) {
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

		if ( $cart_total < $minimum_amount ) {
			unset( $available_gateways['bankgiro_split'] );
		}
	}

	return $available_gateways;
}
}
add_filter( 'woocommerce_available_payment_gateways', 'wc_bankgiro_split_restrict_by_order_total' );
