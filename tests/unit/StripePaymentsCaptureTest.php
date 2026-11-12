<?php

use PHPUnit\Framework\TestCase;

// The recording HTTP client below implements the bundled SDK's interface, which the gateway
// otherwise loads on first use
require_once dirname(__DIR__, 2) . DS . 'vendor' . DS . 'stripe' . DS . 'stripe-php' . DS . 'init.php';

/**
 * Covers capturing a PaymentIntent once, however many times the capture is requested
 */
class StripePaymentsCaptureTest extends TestCase
{
    /**
     * @var CaptureRecordingHttpClient The HTTP client standing in for Stripe
     */
    private $http;

    /**
     * Replaces the Stripe HTTP client, so no request leaves the test
     */
    protected function setUp(): void
    {
        $this->http = new CaptureRecordingHttpClient();
        \Stripe\ApiRequestor::setHttpClient($this->http);
    }

    /**
     * Restores the SDK's default HTTP client, so later tests do not inherit the canned one
     */
    protected function tearDown(): void
    {
        \Stripe\ApiRequestor::setHttpClient(null);
    }

    /**
     * Queues the PaymentIntent and its Charge, as Stripe returns them before a capture
     *
     * @param string $status The PaymentIntent's status
     */
    private function queuePaymentIntent($status)
    {
        $this->http->respond('/v1/payment_intents/pi_123', [
            'id' => 'pi_123',
            'object' => 'payment_intent',
            'status' => $status,
            'latest_charge' => 'ch_123',
            'metadata' => ['invoices' => '']
        ]);
        $this->http->respond('/v1/charges/ch_123', [
            'id' => 'ch_123',
            'object' => 'charge',
            'payment_method_details' => [
                'card' => ['last4' => '4242', 'exp_month' => 12, 'exp_year' => 2030, 'brand' => 'visa']
            ]
        ]);
        $this->http->respond('/v1/payment_intents/pi_123/capture', [
            'id' => 'pi_123',
            'object' => 'payment_intent',
            'status' => 'succeeded',
            'latest_charge' => 'ch_123',
            'metadata' => ['invoices' => '']
        ]);
    }

    /**
     * @return StripePayments The gateway
     */
    private function gateway()
    {
        $gateway = new StripePayments();
        $gateway->setMeta(['secret_key' => 'sk_test_123']);
        $gateway->Session = new class {
            public function write($key, $value)
            {
            }
        };

        return $gateway;
    }

    /**
     * Requests that duplicate each other must share an idempotency key, so Stripe returns the first
     * capture's result to the others instead of rejecting them
     */
    public function testCaptureStoredCcSendsAnIdempotencyKeyForThePaymentIntent()
    {
        $this->queuePaymentIntent('requires_capture');

        $response = $this->gateway()->captureStoredCc(null, null, 'pi_123', null, 25.00);

        $this->assertSame(
            'capture_pi_123',
            $this->http->headerOf('/v1/payment_intents/pi_123/capture', 'Idempotency-Key')
        );
        $this->assertSame('approved', $response['status']);
        $this->assertSame('pi_123', $response['reference_id']);
        $this->assertSame('ch_123', $response['transaction_id']);
    }

    /**
     * A duplicate sent while the first capture is still running gets a 409 from Stripe, so nothing is
     * returned and the transaction is left for the first request to update
     */
    public function testCaptureStoredCcReturnsNothingWhileAnotherCaptureIsInProgress()
    {
        $this->queuePaymentIntent('requires_capture');
        $this->http->respond('/v1/payment_intents/pi_123/capture', [
            'error' => [
                'type' => 'idempotency_error',
                'message' => 'There is currently another in-progress request using this Stripe-Idempotency-Key.'
            ]
        ], 409);

        $gateway = $this->gateway();
        $response = $gateway->captureStoredCc(null, null, 'pi_123', null, 25.00);

        $this->assertNull($response);
        $this->assertSame(['common' => ['general' => 'general']], $gateway->errors());
    }

    /**
     * Any other capture failure is still reported as an error, so the transaction records it
     */
    public function testCaptureStoredCcReportsOtherCaptureFailuresAsAnError()
    {
        $this->queuePaymentIntent('requires_capture');
        $this->http->respond('/v1/payment_intents/pi_123/capture', [
            'error' => ['type' => 'api_error', 'message' => 'An unknown error occurred']
        ], 500);

        $gateway = $this->gateway();
        $response = $gateway->captureStoredCc(null, null, 'pi_123', null, 25.00);

        $this->assertSame('error', $response['status']);
        $this->assertSame(['common' => ['general' => 'general']], $gateway->errors());
    }

    /**
     * A PaymentIntent that an earlier request already captured is reported as approved, since Stripe
     * would reject capturing it again
     */
    public function testCaptureStoredCcReportsAnAlreadyCapturedPaymentIntentWithoutCapturingAgain()
    {
        $this->queuePaymentIntent('succeeded');

        $response = $this->gateway()->captureStoredCc(null, null, 'pi_123', null, 25.00);

        $this->assertFalse($this->http->requested('/v1/payment_intents/pi_123/capture'));
        $this->assertSame('approved', $response['status']);
        $this->assertSame('ch_123', $response['transaction_id']);
    }
}

/**
 * An HTTP client that answers Stripe requests from canned responses and records them
 */
class CaptureRecordingHttpClient implements \Stripe\HttpClient\ClientInterface
{
    /**
     * @var array The canned response bodies and HTTP statuses, by request path
     */
    private $responses = [];

    /**
     * @var array The recorded requests, each with its path and headers
     */
    private $requests = [];

    /**
     * @param string $path The request path
     * @param array $body The response body
     * @param int $status The HTTP status
     */
    public function respond($path, array $body, $status = 200)
    {
        $this->responses[$path] = [json_encode($body), $status];
    }

    /**
     * @param string $path The request path
     * @return bool Whether the path was requested
     */
    public function requested($path)
    {
        foreach ($this->requests as $request) {
            if ($request['path'] === $path) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param string $path The request path
     * @param string $name The header name
     * @return string|null The header's value in the request to the path, null if not sent
     */
    public function headerOf($path, $name)
    {
        foreach ($this->requests as $request) {
            if ($request['path'] === $path) {
                foreach ($request['headers'] as $header) {
                    if (stripos($header, $name . ':') === 0) {
                        return trim(substr($header, strlen($name) + 1));
                    }
                }
            }
        }

        return null;
    }

    /**
     * {@inheritdoc}
     */
    public function request($method, $absUrl, $headers, $params, $hasFile)
    {
        $path = parse_url($absUrl, PHP_URL_PATH);
        $this->requests[] = ['path' => $path, 'headers' => $headers];
        list($body, $status) = $this->responses[$path] ?? ['{}', 404];

        return [$body, $status, []];
    }
}
