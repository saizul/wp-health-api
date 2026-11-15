<?php
/**
 * Plugin bootstrap.
 *
 * @package Saizul\HealthApi
 */

namespace Saizul\HealthApi\WP;

use Saizul\HealthApi\WP\Admin\SettingsPage;
use Saizul\HealthApi\WP\Cli\HealthCommand;
use Saizul\HealthApi\WP\Cli\TokenCommand;
use Saizul\HealthApi\WP\Rest\Controller;

defined( 'ABSPATH' ) || exit;

/**
 * Wires the plugin into WordPress.
 */
final class Plugin {

	const VERSION = '0.1.0';

	/**
	 * Instance.
	 *
	 * @var Plugin|null
	 */
	private static $instance = null;

	/**
	 * Main file.
	 *
	 * @var string
	 */
	private $file;

	/**
	 * Services.
	 *
	 * @var Services
	 */
	private $services;

	/**
	 * Constructor.
	 *
	 * @param string   $file Main plugin file.
	 * @param Services $services Services.
	 */
	public function __construct( string $file, Services $services ) {
		$this->file     = $file;
		$this->services = $services;
	}

	/**
	 * Boot once.
	 *
	 * @param string $file Main plugin file.
	 */
	public static function boot( string $file ): self {
		if ( null === self::$instance ) {
			self::$instance = new self( $file, new Services() );
			self::$instance->register();
		}

		return self::$instance;
	}

	/**
	 * Running instance, if booted.
	 */
	public static function instance(): ?self {
		return self::$instance;
	}

	/**
	 * Services.
	 */
	public function services(): Services {
		return $this->services;
	}

	/**
	 * Register all hooks.
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'load_textdomain' ) );

		( new Controller( $this->services ) )->register();
		( new CronRecorder() )->register();
		( new SiteHealth( $this->services ) )->register();

		if ( is_admin() ) {
			( new SettingsPage( $this->services ) )->register( $this->file );
		}

		register_deactivation_hook( $this->file, array( CronRecorder::class, 'unschedule' ) );

		if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( 'WP_CLI' ) ) {
			\WP_CLI::add_command( 'health', new HealthCommand( $this->services ) );
			\WP_CLI::add_command( 'health token', new TokenCommand( $this->services->tokens() ) );
		}
	}

	/**
	 * Load translations.
	 */
	public function load_textdomain(): void {
		load_plugin_textdomain( 'wp-health-api', false, dirname( plugin_basename( $this->file ) ) . '/languages' );
	}
}
