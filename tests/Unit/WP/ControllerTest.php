<?php
declare(strict_types=1);

namespace Saizul\HealthApi\Tests\Unit\WP;

use Saizul\HealthApi\Prometheus\Formatter;
use Saizul\HealthApi\Tests\WpTestCase;
use Saizul\HealthApi\WP\Rest\Controller;
use Saizul\HealthApi\WP\Services;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WpStubs;

final class ControllerTest extends WpTestCase {

	private function controller( ?Services $services = null ): Controller {
		return new Controller( $services ?? $this->services( array() ) );
	}

	/**
	 * @param array<string,string> $headers Headers.
	 * @param array<string,mixed>  $params Params.
	 */
	private function request( string $route, array $headers = array(), array $params = array(), string $method = 'GET' ): WP_REST_Request {
		$request = new WP_REST_Request( $method, '/health/v1/' . $route );
		foreach ( $headers as $name => $value ) {
			$request->set_header( $name, $value );
		}
		foreach ( $params as $name => $value ) {
			$request->set_param( $name, $value );
		}

		return $request;
	}

	public function test_routes(): void {
		$controller = $this->controller();
		$controller->register();
		do_action( 'rest_api_init' );

		$this->assertSame( array( '/health/v1/status', '/health/v1/details', '/health/v1/metrics' ), array_keys( WpStubs::$routes ) );
		$this->assertSame( '__return_true', WpStubs::$routes['/health/v1/status']['permission_callback'] );
		$this->assertSame( array( $controller, 'authorize' ), WpStubs::$routes['/health/v1/details']['permission_callback'] );
		$this->assertSame( array( $controller, 'authorize' ), WpStubs::$routes['/health/v1/metrics']['permission_callback'] );
	}

	public function test_public_status_is_minimal(): void {
		$this->probes['database']->ping = array( 'connected' => false );
		$response                       = $this->controller()->status( $this->request( 'status' ) );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( array( 'status' => 'ok' ), $response->get_data() );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 0, $this->probes['database']->calls, 'Alive mode runs no checks' );
	}

	public function test_public_status_in_overall_mode(): void {
		$this->probes['database']->ping = array( 'connected' => false );
		$response                       = $this->controller( $this->services( array( 'public_status' => 'overall' ) ) )->status( $this->request( 'status' ) );

		$this->assertSame( array( 'status' => 'critical' ), $response->get_data() );
		$this->assertSame( 503, $response->get_status() );
	}

	public function test_status_codes_are_configurable(): void {
		$this->probes['php']->version = '8.0.30';
		$services = $this->services(
			array(
				'public_status' => 'overall',
				'status_codes'  => array( 'warn' => '429' ),
			)
		);
		$this->assertSame( 429, $this->controller( $services )->status( $this->request( 'status' ) )->get_status() );
	}

	public function test_public_status_is_rate_limited(): void {
		$controller = $this->controller( $this->services( array( 'rate_limit_public' => 2 ) ) );
		$controller->status( $this->request( 'status' ) );
		$controller->status( $this->request( 'status' ) );
		$blocked = $controller->status( $this->request( 'status' ) );

		$this->assertInstanceOf( WP_Error::class, $blocked );
		$this->assertSame( 429, $blocked->get_error_data()['status'] );
		$response = $controller->add_headers( new WP_REST_Response( null, 429 ), null, $this->request( 'status' ) );
		$this->assertSame( '60', $response->get_headers()['Retry-After'] );

		$_SERVER['REMOTE_ADDR'] = '198.51.100.20';
		$this->assertInstanceOf( WP_REST_Response::class, $controller->status( $this->request( 'status' ) ), 'Other clients are not affected' );
	}

	public function test_details_without_configured_token(): void {
		$result = $this->controller()->authorize( $this->request( 'details', array( 'Authorization' => 'Bearer YOUR_TOKEN' ) ) );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'wp_health_api_no_token', $result->get_error_code() );
		$this->assertSame( 401, $result->get_error_data()['status'] );
	}

	public function test_token_in_bearer_or_custom_header(): void {
		$token      = $this->token();
		$controller = $this->controller();
		$this->assertTrue( $controller->authorize( $this->request( 'details', array( 'Authorization' => 'Bearer ' . $token ) ) ) );
		$this->assertTrue( $controller->authorize( $this->request( 'details', array( 'X-Health-Token' => $token ) ) ) );
	}

	public function test_wrong_or_missing_token(): void {
		$this->token();
		$controller = $this->controller();
		$missing    = $controller->authorize( $this->request( 'details' ) );
		$wrong      = $controller->authorize( $this->request( 'details', array( 'Authorization' => 'Bearer wrong-token' ) ) );
		$this->assertSame( 'wp_health_api_unauthorized', $missing->get_error_code() );
		$this->assertSame( 'wp_health_api_unauthorized', $wrong->get_error_code() );

		$response = $controller->add_headers( new WP_REST_Response( null, 401 ), null, $this->request( 'details' ) );
		$this->assertSame( 'Bearer realm="wp-health-api"', $response->get_headers()['WWW-Authenticate'] );
		$this->assertSame( 'no-store, max-age=0', $response->get_headers()['Cache-Control'] );
	}

	public function test_token_is_never_read_from_the_query_string(): void {
		$token = $this->token();
		$this->assertInstanceOf( WP_Error::class, $this->controller()->authorize( $this->request( 'details', array(), array( 'token' => $token ) ) ) );
	}

	public function test_constant_token(): void {
		$this->constant = 'constant-token-for-tests-42';
		$this->token(); // Ignored while the constant exists.
		$controller = $this->controller();
		$this->assertTrue( $controller->authorize( $this->request( 'details', array( 'X-Health-Token' => 'constant-token-for-tests-42' ) ) ) );
		$this->assertInstanceOf( WP_Error::class, $controller->authorize( $this->request( 'details', array( 'X-Health-Token' => 'test-token-0123456789abcdef' ) ) ) );
	}

	public function test_repeated_bad_tokens_lock_the_client_out(): void {
		$token      = $this->token();
		$controller = $this->controller( $this->services( array( 'auth_failures_limit' => 3, 'rate_limit_private' => 0 ) ) );
		for ( $i = 0; $i < 3; $i++ ) {
			$this->assertSame( 'wp_health_api_unauthorized', $controller->authorize( $this->request( 'details', array( 'X-Health-Token' => 'guess-' . $i ) ) )->get_error_code() );
		}
		$locked = $controller->authorize( $this->request( 'details', array( 'X-Health-Token' => $token ) ) );
		$this->assertSame( 'wp_health_api_locked', $locked->get_error_code(), 'Even the right token is refused during the lock-out' );
		$this->assertSame( 429, $locked->get_error_data()['status'] );

		$this->clock->advance( Controller::AUTH_FAIL_WINDOW );
		$this->assertTrue( $controller->authorize( $this->request( 'details', array( 'X-Health-Token' => $token ) ) ) );
	}

	public function test_missing_tokens_do_not_count_as_failures(): void {
		$token      = $this->token();
		$controller = $this->controller( $this->services( array( 'auth_failures_limit' => 1, 'rate_limit_private' => 0 ) ) );
		$controller->authorize( $this->request( 'details' ) );
		$controller->authorize( $this->request( 'details' ) );
		$this->assertTrue( $controller->authorize( $this->request( 'details', array( 'X-Health-Token' => $token ) ) ) );
	}

	public function test_private_rate_limit(): void {
		$token      = $this->token();
		$controller = $this->controller( $this->services( array( 'rate_limit_private' => 1 ) ) );
		$this->assertTrue( $controller->authorize( $this->request( 'details', array( 'X-Health-Token' => $token ) ) ) );
		$this->assertSame( 'wp_health_api_rate_limited', $controller->authorize( $this->request( 'details', array( 'X-Health-Token' => $token ) ) )->get_error_code() );
	}

	public function test_proxy_header_only_when_trusted(): void {
		$token                           = $this->token();
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '192.0.2.1';
		$controller                      = $this->controller( $this->services( array( 'rate_limit_private' => 1 ) ) );
		$controller->authorize( $this->request( 'details', array( 'X-Health-Token' => $token ) ) );
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '192.0.2.2';
		$this->assertInstanceOf( WP_Error::class, $controller->authorize( $this->request( 'details', array( 'X-Health-Token' => $token ) ) ), 'Forged header does not create a new bucket' );

		$trusting                        = $this->controller( $this->services( array( 'rate_limit_private' => 1, 'trusted_proxy_header' => 'HTTP_X_FORWARDED_FOR' ) ) );
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '192.0.2.3';
		$this->assertTrue( $trusting->authorize( $this->request( 'details', array( 'X-Health-Token' => $token ) ) ) );
	}

	public function test_admins_need_no_token(): void {
		WpStubs::$logged_in = true;
		WpStubs::$can       = true;
		$this->assertTrue( $this->controller()->authorize( $this->request( 'details' ) ) );
	}

	public function test_details_report(): void {
		$this->probes['database']->ping = array( 'connected' => false, 'error' => 'gone' );
		$response                       = $this->controller()->details( $this->request( 'details' ) );
		$data                           = $response->get_data();

		$this->assertSame( 503, $response->get_status() );
		$this->assertSame( 'critical', $data['status'] );
		$this->assertSame( 'https://shop.example.com/', $data['site'] );
		$this->assertSame( 'Database query failed: gone', $data['checks']['database']['message'] );
		$this->assertArrayNotHasKey( 'ssl', $data['checks'], 'Disabled by default' );
		$this->assertArrayNotHasKey( 'samples', $data['checks']['database'] );
	}

	public function test_details_subset_and_cache(): void {
		$controller = $this->controller();
		$first      = $controller->details( $this->request( 'details', array(), array( 'checks' => ' Cron, database,../evil' ) ) )->get_data();
		$this->assertSame( array( 'database', 'cron' ), array_keys( $first['checks'] ) );
		$this->assertFalse( $first['cached'] );

		$second = $this->controller()->details( $this->request( 'details', array(), array( 'checks' => 'database,cron' ) ) )->get_data();
		$this->assertTrue( $second['cached'] );
		$this->assertSame( 1, $this->probes['database']->calls );

		$fresh = $this->controller()->details( $this->request( 'details', array(), array( 'checks' => 'database,cron', 'fresh' => true ) ) )->get_data();
		$this->assertFalse( $fresh['cached'] );
		$this->assertSame( 2, $this->probes['database']->calls );
	}

	public function test_sanitize_check_list(): void {
		$this->assertSame( 'cron,database', Controller::sanitize_check_list( 'database, CRON,cron,1bad,da-tabase' ) );
		$this->assertSame( '', Controller::sanitize_check_list( array( 'x' ) ) );
	}

	public function test_metrics_response_and_serving(): void {
		$controller = $this->controller();
		$request    = $this->request( 'metrics' );
		$response   = $controller->metrics( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( Formatter::CONTENT_TYPE, $response->get_headers()['Content-Type'] );
		$this->assertIsString( $response->get_data() );
		$this->assertStringContainsString( "wp_health_up 1\n", $response->get_data() );
		$this->assertStringContainsString( 'wp_health_info{plugin_version="0.1.0",wordpress_version="6.7.1"} 1', $response->get_data() );

		ob_start();
		$served = $controller->serve_metrics( false, $response, $request, null );
		$body   = ob_get_clean();
		$this->assertTrue( $served );
		$this->assertSame( $response->get_data(), $body );

		ob_start();
		$this->assertTrue( $controller->serve_metrics( false, $response, $this->request( 'metrics', array(), array(), 'HEAD' ), null ) );
		$this->assertSame( '', ob_get_clean() );
	}

	public function test_metrics_status_code_stays_200_when_critical(): void {
		$this->probes['database']->ping = array( 'connected' => false );
		$this->assertSame( 200, $this->controller()->metrics( $this->request( 'metrics' ) )->get_status() );
	}

	public function test_serve_metrics_leaves_other_responses_alone(): void {
		$controller = $this->controller();
		$this->assertFalse( $controller->serve_metrics( false, new WP_REST_Response( 'x', 200 ), new WP_REST_Request( 'GET', '/wp/v2/posts' ), null ) );
		$this->assertTrue( $controller->serve_metrics( true, null, new WP_REST_Request( 'GET', '/wp/v2/posts' ), null ) );
		$this->assertFalse( $controller->serve_metrics( false, new WP_REST_Response( array( 'code' => 'x' ), 401 ), $this->request( 'metrics' ), null ), 'Errors stay JSON' );
	}

	public function test_custom_metric_labels(): void {
		add_filter(
			'wp_health_api_metric_labels',
			static function () {
				return array( 'site' => 'shop' );
			}
		);
		$this->assertStringContainsString( 'wp_health_up{site="shop"} 1', $this->controller()->metrics( $this->request( 'metrics' ) )->get_data() );
	}

	public function test_headers_only_on_our_routes(): void {
		$response = $this->controller()->add_headers( new WP_REST_Response( null, 200 ), null, new WP_REST_Request( 'GET', '/wp/v2/posts' ) );
		$this->assertSame( array(), $response->get_headers() );
	}
}
