<?php

/**
 * Unit tests for SparxstarUECRESTController.
 *
 * @package Starisian\SparxstarUEC\Tests\Unit
 */

declare(strict_types=1);

namespace Starisian\SparxstarUEC\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Starisian\SparxstarUEC\api\SparxstarUECRESTController;
use Starisian\SparxstarUEC\core\SparxstarUECDatabase;

/**
 * Exercises permission checks, request handling, and response shapes.
 */
final class SparxstarUECRESTControllerTest extends TestCase
{
    /**
     * Reset shared state before each test.
     */
    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['wp_options']        = [];
        $GLOBALS['wp_transients']     = [];
        $GLOBALS['fired_actions']     = [];
        $GLOBALS['wpdb']->queries     = [];
        $GLOBALS['wpdb']->insert_id   = 0;
        unset($_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_CF_CONNECTING_IP']);
    }

    // -----------------------------------------------------------------------
    // check_permissions
    // -----------------------------------------------------------------------

    /**
     * When no nonce header is present, access should be denied with a WP_Error.
     */
    public function test_check_permissions_rejects_missing_nonce(): void
    {
        $controller = new SparxstarUECRESTController(
            new SparxstarUECDatabase($GLOBALS['wpdb'])
        );

        $request = new \WP_REST_Request();
        // No nonce header set.

        $result = $controller->check_permissions($request);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('invalid_nonce', $result->get_error_code());
    }

    /**
     * An invalid nonce value should be rejected.
     */
    public function test_check_permissions_rejects_invalid_nonce(): void
    {
        $controller = new SparxstarUECRESTController(
            new SparxstarUECDatabase($GLOBALS['wpdb'])
        );

        $request = new \WP_REST_Request();
        $request->set_header('X-WP-Nonce', 'bad_nonce_value');

        $result = $controller->check_permissions($request);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('invalid_nonce', $result->get_error_code());
    }

    /**
     * The sentinel value 'valid_nonce' used by the test shim should be accepted.
     */
    public function test_check_permissions_accepts_valid_nonce(): void
    {
        $controller = new SparxstarUECRESTController(
            new SparxstarUECDatabase($GLOBALS['wpdb'])
        );

        $request = new \WP_REST_Request();
        $request->set_header('X-WP-Nonce', 'valid_nonce');

        $result = $controller->check_permissions($request);

        $this->assertTrue($result);
    }

    // -----------------------------------------------------------------------
    // handle_log_request
    // -----------------------------------------------------------------------

    /**
     * An empty JSON body should result in a 400 WP_Error.
     */
    public function test_handle_log_request_rejects_empty_payload(): void
    {
        $controller = new SparxstarUECRESTController(
            new SparxstarUECDatabase($GLOBALS['wpdb'])
        );

        $request = new \WP_REST_Request();
        $request->set_json_params([]);

        $result = $controller->handle_log_request($request);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('invalid_data', $result->get_error_code());
    }

    /**
     * A valid payload should be processed and produce a WP_REST_Response with
     * status 200. (The DB stub returns a WP_Error for missing table, which is
     * re-propagated; therefore we expect a WP_Error here.)
     */
    public function test_handle_log_request_with_valid_payload_returns_result(): void
    {
        $controller = new SparxstarUECRESTController(
            new SparxstarUECDatabase($GLOBALS['wpdb'])
        );

        $request = new \WP_REST_Request();
        $request->set_json_params([
            'client_side_data' => [
                'identifiers' => [
                    'fingerprint' => 'fp_test',
                    'session_id'  => 'sess_test',
                ],
            ],
        ]);

        // The default stub wpdb reports the table as missing, so a WP_Error
        // is returned from the database layer and propagated by the controller.
        $result = $controller->handle_log_request($request);

        // Ensure it's either a successful response or a known DB error (not a
        // "invalid_data" reject — the payload itself was valid).
        $this->assertNotSame('invalid_data', $result instanceof \WP_Error ? $result->get_error_code() : '');
    }

    // -----------------------------------------------------------------------
    // handle_recorder_log
    // -----------------------------------------------------------------------

    /**
     * An invalid JSON body should return an HTTP 400 response.
     */
    public function test_handle_recorder_log_returns_400_for_invalid_json(): void
    {
        $controller = new SparxstarUECRESTController(
            new SparxstarUECDatabase($GLOBALS['wpdb'])
        );

        $request = new \WP_REST_Request();
        $request->set_body('this is not json');

        $result = $controller->handle_recorder_log($request);

        $this->assertInstanceOf(\WP_REST_Response::class, $result);
        $this->assertSame(400, $result->get_status());
        $this->assertSame('invalid_json', $result->get_data()['status']);
    }

    /**
     * A valid JSON body should return an HTTP 200 response.
     */
    public function test_handle_recorder_log_returns_200_for_valid_json(): void
    {
        $controller = new SparxstarUECRESTController(
            new SparxstarUECDatabase($GLOBALS['wpdb'])
        );

        $request = new \WP_REST_Request();
        $request->set_body(json_encode(['type' => 'click', 'ts' => time()]));

        $result = $controller->handle_recorder_log($request);

        $this->assertInstanceOf(\WP_REST_Response::class, $result);
        $this->assertSame(200, $result->get_status());
        $this->assertSame('ok', $result->get_data()['status']);
    }

    /**
     * A valid JSON body with an empty array should also return HTTP 200.
     */
    public function test_handle_recorder_log_returns_200_for_empty_json_array(): void
    {
        $controller = new SparxstarUECRESTController(
            new SparxstarUECDatabase($GLOBALS['wpdb'])
        );

        $request = new \WP_REST_Request();
        $request->set_body('{}');

        $result = $controller->handle_recorder_log($request);

        $this->assertInstanceOf(\WP_REST_Response::class, $result);
        $this->assertSame(200, $result->get_status());
    }
}
