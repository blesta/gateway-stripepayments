<?php

use PHPUnit\Framework\TestCase;

// The stubbed PaymentIntent below extends the bundled SDK's, which the gateway otherwise loads on first use
require_once dirname(__DIR__, 2) . DS . 'vendor' . DS . 'stripe' . DS . 'stripe-php' . DS . 'init.php';

/**
 * Covers reporting the state of an authorization and capturing it, so that an authorization left
 * awaiting capture can be captured for what is still owed or voided
 */
class StripePaymentsAuthorizationTest extends TestCase
{
    /**
     * Builds a gateway whose API requests return the given responses in turn. Once none are left, the
     * request itself is made, which then only reaches the stubbed objects among those responses
     *
     * @param array $responses The responses to return
     * @return StripePayments The partially mocked gateway
     */
    private function gateway(array $responses)
    {
        $component = new class ($responses) {
            private $responses;

            public function __construct(array $responses)
            {
                $this->responses = $responses;
            }

            public function withAccessToken($type, $id, $provider, callable $callback)
            {
                if (!$this->responses) {
                    return $callback('sk_test_token');
                }

                $response = array_shift($this->responses);
                if ($response instanceof Throwable) {
                    throw $response;
                }

                return $response;
            }
        };

        $gateway = $this->getMockBuilder(StripePayments::class)
            ->onlyMethods(['isOauthConnected', 'getExtensionOauth', 'getGatewayId'])
            ->getMock();
        $gateway->method('isOauthConnected')->willReturn(true);
        $gateway->method('getExtensionOauth')->willReturn($component);
        $gateway->method('getGatewayId')->willReturn(1);
        $gateway->setCurrency('USD');

        $gateway->Invoices = new class {
            public function get($invoice_id)
            {
                return (object) ['id' => $invoice_id, 'due' => 30.00];
            }
        };
        $gateway->Session = new class {
            public function write($key, $value)
            {
            }
        };

        return $gateway;
    }

    /**
     * @param array $values The PaymentIntent's values
     * @return \Stripe\PaymentIntent The PaymentIntent
     */
    private function paymentIntent(array $values)
    {
        return CapturablePaymentIntentStub::constructFrom(array_merge(
            [
                'id' => 'pi_123',
                'capture_method' => 'manual',
                'currency' => 'usd',
                'latest_charge' => 'ch_123',
                'metadata' => ['invoices' => '10=25.00']
            ],
            $values
        ));
    }

    /**
     * @return \Stripe\Charge The Charge the PaymentIntent authorized
     */
    private function charge()
    {
        return \Stripe\Charge::constructFrom([
            'id' => 'ch_123',
            'payment_method_details' => [
                'card' => ['last4' => '4242', 'exp_month' => 12, 'exp_year' => 2030, 'brand' => 'visa']
            ]
        ]);
    }

    public function testGetCcAuthorizationReportsACapturableAuthorization()
    {
        $gateway = $this->gateway([
            $this->paymentIntent(['status' => 'requires_capture', 'amount_capturable' => 2500])
        ]);

        $this->assertSame(
            ['status' => 'capturable', 'amount' => 25.0, 'invoices' => [['invoice_id' => '10', 'amount' => '25.00']]],
            $gateway->getCcAuthorization('pi_123', null)
        );
    }

    /**
     * @dataProvider statusProvider
     */
    public function testGetCcAuthorizationMapsStatus($stripe_status, $status, $amount)
    {
        $gateway = $this->gateway([
            $this->paymentIntent(['status' => $stripe_status, 'amount_capturable' => 0, 'amount_received' => 2500])
        ]);

        $authorization = $gateway->getCcAuthorization('pi_123', null);

        $this->assertSame($status, $authorization['status']);
        $this->assertSame($amount, $authorization['amount']);
    }

    public function statusProvider()
    {
        return [
            ['succeeded', 'captured', 25.0],
            ['canceled', 'canceled', 0.0],
            ['requires_action', 'incomplete', 0.0],
            ['requires_payment_method', 'incomplete', 0.0],
            ['requires_confirmation', 'incomplete', 0.0]
        ];
    }

    public function testGetCcAuthorizationLeavesAProcessingPaymentIntent()
    {
        $gateway = $this->gateway([$this->paymentIntent(['status' => 'processing'])]);

        $this->assertNull($gateway->getCcAuthorization('pi_123', null));
    }

    /**
     * A PaymentIntent captured by Stripe, such as an India eMandate charge awaiting the client's
     * approval, is not an authorization and must never be voided
     */
    public function testGetCcAuthorizationIgnoresAutomaticCapture()
    {
        $gateway = $this->gateway([
            $this->paymentIntent(['status' => 'requires_action', 'capture_method' => 'automatic'])
        ]);

        $this->assertNull($gateway->getCcAuthorization('pi_123', null));
    }

    public function testGetCcAuthorizationReturnsNullOnError()
    {
        $gateway = $this->gateway([
            \Stripe\Exception\InvalidRequestException::factory(
                'No such payment_intent',
                404,
                null,
                ['error' => ['type' => 'invalid_request_error', 'message' => 'No such payment_intent']]
            )
        ]);

        $this->assertNull($gateway->getCcAuthorization('pi_123', null));
        $this->assertNotFalse($gateway->errors());
    }

    public function testCaptureStoredCcCapturesLessThanAuthorized()
    {
        $payment_intent = $this->paymentIntent(['status' => 'requires_capture', 'amount_capturable' => 2500]);
        $gateway = $this->gateway([$payment_intent, $this->charge()]);

        $response = $gateway->captureStoredCc(null, null, 'pi_123', null, 20.00);

        $this->assertSame(['amount_to_capture' => 2000], $payment_intent->captured_with);
        $this->assertSame('approved', $response['status']);
    }

    public function testCaptureStoredCcCapturesTheFullAuthorization()
    {
        $payment_intent = $this->paymentIntent(['status' => 'requires_capture', 'amount_capturable' => 2500]);
        $gateway = $this->gateway([$payment_intent, $this->charge()]);

        $gateway->captureStoredCc(null, null, 'pi_123', null, 25.00);

        $this->assertSame([], $payment_intent->captured_with);
    }

    /**
     * A webhook may have recorded the uncaptured Charge's ID, which must not turn the void into a refund
     */
    public function testVoidTransactionCancelsAnUncapturedAuthorizationWithAChargeId()
    {
        $payment_intent = $this->paymentIntent(['status' => 'requires_capture']);
        $gateway = $this->gateway([$payment_intent]);

        $response = $gateway->voidTransaction('pi_123', 'ch_123');

        $this->assertTrue($payment_intent->canceled);
        $this->assertSame('void', $response['status']);
    }

    public function testVoidTransactionCancelsAnAuthorizationWithoutAChargeId()
    {
        $payment_intent = $this->paymentIntent(['status' => 'requires_action']);
        $gateway = $this->gateway([$payment_intent]);

        $response = $gateway->voidTransaction('pi_123', null);

        $this->assertTrue($payment_intent->canceled);
        $this->assertSame('void', $response['status']);
    }

    public function testVoidTransactionRefundsACapturedPayment()
    {
        $payment_intent = $this->paymentIntent(['status' => 'succeeded']);
        $gateway = $this->gateway([$payment_intent, \Stripe\Refund::constructFrom(['id' => 're_123'])]);

        $response = $gateway->voidTransaction('pi_123', 'ch_123');

        $this->assertFalse($payment_intent->canceled);
        $this->assertSame('void', $response['status']);
        $this->assertSame('ch_123', $response['transaction_id']);
    }

    public function testCaptureStoredCcReportsAnAlreadyCapturedPaymentIntent()
    {
        $payment_intent = $this->paymentIntent(['status' => 'succeeded', 'amount_received' => 2500]);
        $gateway = $this->gateway([$payment_intent, $this->charge()]);

        $response = $gateway->captureStoredCc(null, null, 'pi_123', null, 25.00);

        $this->assertNull($payment_intent->captured_with);
        $this->assertSame('approved', $response['status']);
        $this->assertSame('ch_123', $response['transaction_id']);
    }
}

/**
 * A PaymentIntent that records its capture and cancelation rather than sending them to Stripe
 */
class CapturablePaymentIntentStub extends \Stripe\PaymentIntent
{
    /**
     * @var array|null The parameters the PaymentIntent was captured with, null if never captured
     */
    public $captured_with = null;

    /**
     * @var bool Whether the PaymentIntent was canceled
     */
    public $canceled = false;

    /**
     * {@inheritdoc}
     */
    public function cancel($params = null, $opts = null)
    {
        $this->canceled = true;

        return \Stripe\PaymentIntent::constructFrom(['id' => $this->id, 'status' => 'canceled']);
    }

    /**
     * {@inheritdoc}
     */
    public function capture($params = null, $opts = null)
    {
        $this->captured_with = $params;

        return \Stripe\PaymentIntent::constructFrom([
            'id' => $this->id,
            'status' => 'succeeded',
            'latest_charge' => $this->latest_charge,
            'metadata' => ['invoices' => '']
        ]);
    }
}
