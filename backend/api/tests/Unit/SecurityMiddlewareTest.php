<?php

declare(strict_types=1);

namespace Rajdhani\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Rajdhani\Helpers\ApiError;
use Rajdhani\Helpers\ErrorCode;
use Rajdhani\Http\Request;
use Rajdhani\Middleware\GuardQueryParameters;
use Rajdhani\Middleware\RateLimit;
use Rajdhani\Middleware\ThrottleForm;
use Rajdhani\Support\Env;

/**
 * The §14.2 transport guards that can be tested without a database.
 *
 * The rate-limit *counting* is in RateLimitTest, which needs the shared table.
 * What is here is the routing around it: which requests are exempt, and the
 * parameter-pollution guard, neither of which touches storage.
 */
final class SecurityMiddlewareTest extends TestCase
{
    protected function setUp(): void
    {
        Env::load(TEST_ENV_PATH);
    }

    // ─── parameter pollution ────────────────────────────────────────────────

    public function testASingleValuedParameterPassesThrough(): void
    {
        $reached = (new GuardQueryParameters())->handle(
            $this->request('/public/layout', query: ['category' => 'green-tea']),
            static fn (): string => 'reached',
        );

        self::assertSame('reached', $reached);
    }

    /**
     * PHP turns `?status[]=A&status[]=B` into an array where every caller
     * expects a string. `(string) $array` emits "Array", `strlen()` throws, and
     * a reviewer reading `$request->query('status')` sees none of it.
     */
    public function testARepeatedParameterIsRejected(): void
    {
        try {
            (new GuardQueryParameters())->handle(
                $this->request('/public/layout', query: ['status' => ['A', 'B']]),
                static fn (): string => 'reached',
            );
            self::fail('An array-valued query parameter should be refused');
        } catch (ApiError $e) {
            self::assertSame(ErrorCode::VALIDATION_ERROR, $e->errorCode());
            self::assertSame('status', $e->details()[0]['field']);
        }
    }

    public function testNestedArraysAreRejectedToo(): void
    {
        $this->expectException(ApiError::class);

        (new GuardQueryParameters())->handle(
            $this->request('/public/layout', query: ['filter' => ['price' => ['min' => '1']]]),
            static fn (): string => 'reached',
        );
    }

    public function testAnEmptyQueryStringIsFine(): void
    {
        self::assertSame(
            'reached',
            (new GuardQueryParameters())->handle(
                $this->request('/public/layout'),
                static fn (): string => 'reached',
            ),
        );
    }

    // ─── what the global limiter skips ──────────────────────────────────────

    /**
     * An uptime monitor polls /health on a schedule from one address (§16.5).
     * Throttling it would produce exactly the alert it exists to avoid — and
     * these must be exempt *without* consulting the counter, so this passes
     * with no database at all.
     *
     * @param string $path
     */
    #[DataProvider('exemptRequests')]
    public function testExemptRequestsNeverReachTheCounter(string $method, string $path): void
    {
        // No database is configured in this test; if the middleware tried to
        // count, it would have to touch one. Reaching the handler is the proof.
        $reached = (new RateLimit())->handle(
            $this->request($path, method: $method),
            static fn (): string => 'reached',
        );

        self::assertSame('reached', $reached);
    }

    /** @return array<string,array{string,string}> */
    public static function exemptRequests(): array
    {
        return [
            'liveness probe'   => ['GET', '/health'],
            'dependency probe' => ['GET', '/health/db'],

            // A preflight is the browser asking permission, not a request the
            // client chose to make. Counting it would halve every cross-origin
            // client's budget.
            'CORS preflight'   => ['OPTIONS', '/public/layout'],
        ];
    }

    /**
     * The form throttle counts submissions, not page views: a GET of the form,
     * or its preflight, must not consume one of the visitor's five.
     *
     * @param string $method
     */
    #[DataProvider('nonSubmissions')]
    public function testTheFormThrottleIgnoresNonSubmissions(string $method): void
    {
        $reached = (new ThrottleForm('enquiry'))->handle(
            $this->request('/public/enquiries', method: $method),
            static fn (): string => 'reached',
        );

        self::assertSame('reached', $reached);
    }

    /** @return array<string,array{string}> */
    public static function nonSubmissions(): array
    {
        return [
            'reading the form' => ['GET'],
            'preflight'        => ['OPTIONS'],
        ];
    }

    /** @param array<string,mixed> $query */
    private function request(string $path, string $method = 'GET', array $query = []): Request
    {
        $_SERVER['REQUEST_METHOD'] = $method;
        $_SERVER['REQUEST_URI'] = "/api/v1{$path}";
        $_SERVER['REMOTE_ADDR'] = '203.0.113.99';
        $_GET = $query;
        $_POST = [];
        $_COOKIE = [];

        return Request::capture();
    }
}
