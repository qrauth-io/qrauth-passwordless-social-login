<?php
/**
 * Plugin Name:       QRAuth – Passwordless & Social Login
 * Plugin URI:        https://qrauth.io
 * Description:       Passwordless and social login for WordPress powered by QRAuth web components.
 * Version:           0.1.0
 * Requires at least: 6.4
 * Requires PHP:      8.2
 * Author:            QRAuth
 * Author URI:        https://qrauth.io
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       qrauth-passwordless-social-login
 * Domain Path:       /languages
 *
 * @package QRAuth\PasswordlessSocialLogin
 */

defined( 'ABSPATH' ) || exit;

define( 'QRAUTH_PSL_VERSION', '0.1.0' );
define( 'QRAUTH_PSL_FILE', __FILE__ );
define( 'QRAUTH_PSL_DIR', plugin_dir_path( __FILE__ ) );
define( 'QRAUTH_PSL_URL', plugin_dir_url( __FILE__ ) );

require_once QRAUTH_PSL_DIR . 'vendor/autoload.php';

\QRAuth\PasswordlessSocialLogin\Plugin::instance()->boot();
