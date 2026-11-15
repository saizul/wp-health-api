<?php
/**
 * Minimal WordPress stubs for unit tests.
 *
 * Only what the plugin's WordPress layer touches is implemented. State lives
 * in WpStubs so tests can reset and inspect it. Variadic forwarding uses
 * positional arrays only: unpacking arrays with string keys is an error on
 * PHP 7.4.
 *
 * @package Saizul\HealthApi
 */

declare(strict_types=1);

// phpcs:ignoreFile

final class WpStubs {
	/** @var array<string,array<int,array<int,callable>>> */
	public static $filters = array();
	/** @var array<int,array{0:string,1:array<int,mixed>}> */
	public static $actions = array();
	/** @var array<string,mixed> */
	public static $options = array();
	/** @var array<string,mixed> */
	public static $transients = array();
	/** @var array<string,mixed> */
	public static $site_transients = array();
	/** @var array<string,array<string,mixed>> */
	public static $routes = array();
	/** @var bool */
	public static $logged_in = false;
	/** @var bool */
	public static $can = false;
	/** @var bool */
	public static $doing_cron = false;
	/** @var array<string,array{0:int,1:string}> */
	public static $scheduled = array();
	/** @var array<string,mixed> */
	public static $cache = array();
	/** @var array<int,array{0:string,1:string,2:string}> */
	public static $settings_errors = array();

	public static function reset(): void {
		self::$filters         = array();
		self::$actions         = array();
		self::$options         = array();
		self::$transients      = array();
		self::$site_transients = array();
		self::$routes          = array();
		self::$logged_in       = false;
		self::$can             = false;
		self::$doing_cron      = false;
		self::$scheduled       = array();
		self::$cache           = array();
		self::$settings_errors = array();
	}
}

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}
if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
	define( 'MINUTE_IN_SECONDS', 60 );
	define( 'HOUR_IN_SECONDS', 3600 );
	define( 'DAY_IN_SECONDS', 86400 );
}

class WP_Error {
	/** @var array<string,string> */
	public $errors = array();
	/** @var mixed */
	public $error_data;

	public function __construct( $code = '', $message = '', $data = null ) {
		if ( '' !== $code ) {
			$this->errors[ (string) $code ] = (string) $message;
		}
		$this->error_data = $data;
	}

	public function get_error_code() {
		return (string) array_key_first( $this->errors );
	}

	public function get_error_message() {
		return (string) reset( $this->errors );
	}

	public function get_error_data() {
		return $this->error_data;
	}
}

class WP_REST_Server {
	const READABLE = 'GET';
}

class WP_REST_Request {
	/** @var string */
	private $method;
	/** @var string */
	private $route;
	/** @var array<string,mixed> */
	private $params = array();
	/** @var array<string,array<int,string>> */
	private $headers = array();

	public function __construct( $method = 'GET', $route = '' ) {
		$this->method = (string) $method;
		$this->route  = (string) $route;
	}

	public function get_method() {
		return $this->method;
	}

	public function get_route() {
		return $this->route;
	}

	public function set_param( $key, $value ) {
		$this->params[ $key ] = $value;
	}

	public function get_param( $key ) {
		return $this->params[ $key ] ?? null;
	}

	/** Same normalisation as WordPress: lower case, "-" becomes "_". */
	public function set_header( $key, $value ) {
		$this->headers[ str_replace( '-', '_', strtolower( (string) $key ) ) ] = (array) $value;
	}

	public function get_headers() {
		return $this->headers;
	}
}

class WP_REST_Response {
	/** @var mixed */
	public $data;
	/** @var int */
	public $status;
	/** @var array<string,string> */
	public $headers = array();

	public function __construct( $data = null, $status = 200, $headers = array() ) {
		$this->data    = $data;
		$this->status  = (int) $status;
		$this->headers = $headers;
	}

	public function get_data() {
		return $this->data;
	}

	public function get_status() {
		return $this->status;
	}

	public function header( $key, $value, $replace = true ) {
		$this->headers[ $key ] = (string) $value;
	}

	public function get_headers() {
		return $this->headers;
	}
}

function add_filter( $name, $callback, $priority = 10, $accepted_args = 1 ) {
	WpStubs::$filters[ $name ][ $priority ][] = $callback;
	return true;
}

function add_action( $name, $callback, $priority = 10, $accepted_args = 1 ) {
	return add_filter( $name, $callback, $priority, $accepted_args );
}

function apply_filters( $name, $value, ...$args ) {
	if ( empty( WpStubs::$filters[ $name ] ) ) {
		return $value;
	}
	ksort( WpStubs::$filters[ $name ] );
	foreach ( WpStubs::$filters[ $name ] as $callbacks ) {
		foreach ( $callbacks as $callback ) {
			$value = call_user_func_array( $callback, array_merge( array( $value ), array_values( $args ) ) );
		}
	}
	return $value;
}

function do_action( $name, ...$args ) {
	WpStubs::$actions[] = array( $name, array_values( $args ) );
	if ( empty( WpStubs::$filters[ $name ] ) ) {
		return;
	}
	foreach ( WpStubs::$filters[ $name ] as $callbacks ) {
		foreach ( $callbacks as $callback ) {
			call_user_func_array( $callback, array_values( $args ) );
		}
	}
}

function did_action( $name ) {
	return count( array_filter( WpStubs::$actions, static function ( $a ) use ( $name ) { return $a[0] === $name; } ) );
}

function __( $text, $domain = 'default' ) {
	return $text;
}

function esc_html__( $text, $domain = 'default' ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES );
}

function esc_attr__( $text, $domain = 'default' ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES );
}

function esc_html( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES );
}

function esc_attr( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES );
}

function esc_url( $url ) {
	return (string) $url;
}

function get_option( $name, $default = false ) {
	return array_key_exists( $name, WpStubs::$options ) ? WpStubs::$options[ $name ] : $default;
}

function update_option( $name, $value, $autoload = null ) {
	$old                       = WpStubs::$options[ $name ] ?? null;
	WpStubs::$options[ $name ] = $value;
	do_action( 'update_option_' . $name, $old, $value, $name );
	return true;
}

function delete_option( $name ) {
	unset( WpStubs::$options[ $name ] );
	return true;
}

function get_transient( $name ) {
	return WpStubs::$transients[ $name ] ?? false;
}

function set_transient( $name, $value, $ttl = 0 ) {
	WpStubs::$transients[ $name ] = $value;
	return true;
}

function delete_transient( $name ) {
	unset( WpStubs::$transients[ $name ] );
	return true;
}

function get_site_transient( $name ) {
	return WpStubs::$site_transients[ $name ] ?? false;
}

function home_url( $path = '' ) {
	return 'https://shop.example.com' . $path;
}

function rest_url( $path = '' ) {
	return 'https://shop.example.com/wp-json/' . ltrim( (string) $path, '/' );
}

function admin_url( $path = '' ) {
	return 'https://shop.example.com/wp-admin/' . ltrim( (string) $path, '/' );
}

function register_rest_route( $namespace, $route, $args = array() ) {
	WpStubs::$routes[ '/' . $namespace . $route ] = $args;
	return true;
}

function is_user_logged_in() {
	return WpStubs::$logged_in;
}

function current_user_can( $capability ) {
	return WpStubs::$can;
}

function get_current_user_id() {
	return 7;
}

function wp_salt( $scheme = 'auth' ) {
	return 'unit-test-salt-' . $scheme;
}

function sanitize_text_field( $text ) {
	return trim( (string) preg_replace( '/[\r\n\t ]+/', ' ', strip_tags( (string) $text ) ) );
}

function sanitize_key( $key ) {
	return (string) preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
}

function wp_unslash( $value ) {
	return is_string( $value ) ? stripslashes( $value ) : $value;
}

function get_bloginfo( $show = '' ) {
	return 'version' === $show ? '6.7.1' : 'Example Shop';
}

function wp_json_encode( $data, $options = 0, $depth = 512 ) {
	return json_encode( $data, $options, $depth );
}

function wp_doing_cron() {
	return WpStubs::$doing_cron;
}

function wp_next_scheduled( $hook, $args = array() ) {
	return isset( WpStubs::$scheduled[ $hook ] ) ? WpStubs::$scheduled[ $hook ][0] : false;
}

function wp_schedule_event( $timestamp, $recurrence, $hook, $args = array() ) {
	WpStubs::$scheduled[ $hook ] = array( (int) $timestamp, (string) $recurrence );
	return true;
}

function wp_clear_scheduled_hook( $hook, $args = array() ) {
	unset( WpStubs::$scheduled[ $hook ] );
	return 1;
}

function add_settings_error( $setting, $code, $message, $type = 'error' ) {
	WpStubs::$settings_errors[] = array( $setting, $code, $message );
}


function wp_using_ext_object_cache( $using = null ) {
	return false;
}

function wp_generate_password( $length = 12, $special_chars = true, $extra_special_chars = false ) {
	return substr( bin2hex( random_bytes( 32 ) ), 0, (int) $length );
}

function wp_cache_set( $key, $data, $group = '', $expire = 0 ) {
	WpStubs::$cache[ $group . ':' . $key ] = $data;
	return true;
}

function wp_cache_get( $key, $group = '', $force = false, &$found = null ) {
	return WpStubs::$cache[ $group . ':' . $key ] ?? false;
}

function wp_cache_delete( $key, $group = '' ) {
	unset( WpStubs::$cache[ $group . ':' . $key ] );
	return true;
}

function is_admin() {
	return WpStubs::$can;
}

function plugin_basename( $file ) {
	return basename( dirname( (string) $file ) ) . '/' . basename( (string) $file );
}

function register_deactivation_hook( $file, $callback ) {
	WpStubs::$filters[ 'deactivate_' . plugin_basename( $file ) ][10][] = $callback;
}

function load_plugin_textdomain( $domain, $deprecated = false, $path = false ) {
	return true;
}

function settings_errors( $setting = '', $sanitize = false, $hide_on_update = false ) {
}

function settings_fields( $option_group ) {
	echo '<input type="hidden" name="option_page" value="' . esc_attr( $option_group ) . '">';
}

function submit_button( $text = null, $type = 'primary', $name = 'submit', $wrap = true, $other_attributes = null ) {
	echo '<button type="submit" class="button ' . esc_attr( (string) $type ) . '">' . esc_html( (string) ( $text ?? 'Save Changes' ) ) . '</button>';
}

function wp_nonce_field( $action = -1, $name = '_wpnonce', $referer = true, $display = true ) {
	echo '<input type="hidden" name="' . esc_attr( $name ) . '" value="nonce-' . esc_attr( md5( (string) $action ) ) . '">';
}

function checked( $checked, $current = true, $display = true ) {
	return (string) $checked === (string) $current ? ' checked=\'checked\'' : '';
}

function selected( $selected, $current = true, $display = true ) {
	return (string) $selected === (string) $current ? ' selected=\'selected\'' : '';
}

function esc_html_e( $text, $domain = 'default' ) {
	echo esc_html( $text );
}

function add_options_page( $page_title, $menu_title, $capability, $menu_slug, $callback = '' ) {
	WpStubs::$routes[ 'admin:' . $menu_slug ] = array( $page_title, $capability, $callback );
	return 'settings_page_' . $menu_slug;
}

function register_setting( $group, $name, $args = array() ) {
	WpStubs::$routes[ 'setting:' . $name ] = $args;
}
