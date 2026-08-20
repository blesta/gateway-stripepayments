<?php

use Blesta\Core\Util\Oauth\OauthTokenRejectedException;
use PHPUnit\Framework\TestCase;

/**
 * Covers the gateway's share of the centrally-brokered OAuth flow: grant acceptance,
 * publishable key sourcing, and the classification of Stripe's authentication error
 * inside the token-consuming callback
 */
class StripePaymentsOauthTest extends TestCase
{
    /**
     * @param array $methods The protected methods to stub
     * @return StripePayments The partially mocked gateway
     */
    private function gateway(array $methods)
    {
        return $this->getMockBuilder(StripePayments::class)
            ->onlyMethods($methods)
            ->getMock();
    }

    /**
     * Invokes a private/protected method
     *
     * @param object $object The object to invoke on
     * @param string $method The method name
     * @param array $args The arguments
     * @return mixed The method's return value
     */
    private function invoke($object, $method, array $args = [])
    {
        $reflection = new ReflectionMethod(StripePayments::class, $method);
        $reflection->setAccessible(true);

        return $reflection->invokeArgs($object, $args);
    }

    public function testGetOauthProvider()
    {
        $this->assertSame('stripe', (new StripePayments())->getOauthProvider());
    }

    public function testGetOauthSettingsUrlIsNull()
    {
        $this->assertNull((new StripePayments())->getOauthSettingsUrl());
    }

    private function freshGrant(array $overrides = [])
    {
        return array_merge(
            [
                'access_token' => 'sk_live_abc',
                'refresh_token' => 'rt_abc',
                'account_id' => 'acct_123',
                'publishable_key' => 'pk_live_abc',
                'mode' => 'live',
                'scope' => 'read_write',
                'provider_extras' => [],
                'expires_at' => null,
                'extras' => []
            ],
            $overrides
        );
    }

    public function testValidateOauthGrantAcceptsFreshConnect()
    {
        $gateway = $this->gateway(['getStripeAccountId']);
        $gateway->expects($this->never())->method('getStripeAccountId');

        $this->assertTrue($gateway->validateOauthGrant($this->freshGrant()));
    }

    public function testValidateOauthGrantRefusesMissingRequiredFields()
    {
        $gateway = new StripePayments();

        $this->assertFalse($gateway->validateOauthGrant($this->freshGrant(['access_token' => null])));
        $this->assertFalse($gateway->validateOauthGrant($this->freshGrant(['account_id' => ''])));
        $this->assertFalse($gateway->validateOauthGrant($this->freshGrant(['publishable_key' => null])));
    }

    public function testValidateOauthGrantAcceptsMatchingConvert()
    {
        $gateway = $this->gateway(['getStripeAccountId']);
        $gateway->setMeta(['secret_key' => 'sk_live_stored', 'publishable_key' => 'pk_live_stored']);
        $gateway->method('getStripeAccountId')->with('sk_live_stored')->willReturn('acct_123');

        $this->assertTrue(
            $gateway->validateOauthGrant($this->freshGrant(['extras' => ['intent' => 'convert']]))
        );
    }

    public function testValidateOauthGrantRefusesConvertAccountMismatch()
    {
        $gateway = $this->gateway(['getStripeAccountId']);
        $gateway->setMeta(['secret_key' => 'sk_live_stored']);
        $gateway->method('getStripeAccountId')->willReturn('acct_other');

        $this->assertFalse(
            $gateway->validateOauthGrant($this->freshGrant(['extras' => ['intent' => 'convert']]))
        );
    }

    public function testValidateOauthGrantRefusesConvertModeMismatch()
    {
        $gateway = $this->gateway(['getStripeAccountId']);
        $gateway->setMeta(['secret_key' => 'sk_test_stored']);
        // Refused on the mode prefix alone; the account is never fetched
        $gateway->expects($this->never())->method('getStripeAccountId');

        $this->assertFalse(
            $gateway->validateOauthGrant($this->freshGrant(['mode' => 'live', 'extras' => ['intent' => 'convert']]))
        );
    }

    public function testValidateOauthGrantRefusesConvertWhenAccountCheckErrors()
    {
        $gateway = $this->gateway(['getStripeAccountId']);
        $gateway->setMeta(['secret_key' => 'sk_live_stored']);
        // A convert check that errored must refuse, not accept
        $gateway->method('getStripeAccountId')->willReturn(null);

        $this->assertFalse(
            $gateway->validateOauthGrant($this->freshGrant(['extras' => ['intent' => 'convert']]))
        );
    }

    public function testValidateOauthGrantRefusesConvertWithUnrecognizedKeyPrefix()
    {
        $gateway = $this->gateway(['getStripeAccountId']);
        $gateway->setMeta(['secret_key' => 'rk_live_restricted']);
        $gateway->expects($this->never())->method('getStripeAccountId');

        $this->assertFalse(
            $gateway->validateOauthGrant($this->freshGrant(['extras' => ['intent' => 'convert']]))
        );
    }

    public function testValidateOauthGrantRefusesConvertWithoutStoredKey()
    {
        $gateway = $this->gateway(['getStripeAccountId']);
        $gateway->setMeta([]);
        $gateway->expects($this->never())->method('getStripeAccountId');

        $this->assertFalse(
            $gateway->validateOauthGrant($this->freshGrant(['extras' => ['intent' => 'convert']]))
        );
    }

    public function testGetPublishableKeyPrefersGrantWhenConnected()
    {
        $gateway = $this->gateway(['getEnrollment']);
        $gateway->setMeta(['publishable_key' => 'pk_live_meta']);
        $gateway->method('getEnrollment')->willReturn([
            'connected' => true,
            'pending' => false,
            'publishable_key' => 'pk_live_grant'
        ]);

        $this->assertSame('pk_live_grant', $this->invoke($gateway, 'getPublishableKey'));
    }

    public function testGetPublishableKeyFallsBackToMetaWhenNotConnected()
    {
        $gateway = $this->gateway(['getEnrollment']);
        $gateway->setMeta(['publishable_key' => 'pk_live_meta']);
        $gateway->method('getEnrollment')->willReturn(['connected' => false, 'pending' => false]);

        $this->assertSame('pk_live_meta', $this->invoke($gateway, 'getPublishableKey'));
    }

    /**
     * Builds a spy standing in for the ExtensionOauth component
     *
     * @return object The spy, exposing $used, $caught and $token
     */
    private function extensionOauthSpy()
    {
        return new class {
            public $used = false;
            public $caught = null;
            public $token = 'sk_test_granttoken123';

            public function withAccessToken($type, $id, $provider, callable $callback)
            {
                $this->used = true;

                try {
                    return $callback($this->token);
                } catch (OauthTokenRejectedException $e) {
                    // Core would refresh and retry once here; a terminal failure rethrows
                    $this->caught = $e;

                    throw $e;
                }
            }
        };
    }

    public function testHandleApiRequestClassifiesAuthenticationExceptionAsTokenRejection()
    {
        $component = $this->extensionOauthSpy();

        $gateway = $this->gateway(['isOauthConnected', 'getExtensionOauth', 'getGatewayId']);
        $gateway->method('isOauthConnected')->willReturn(true);
        $gateway->method('getExtensionOauth')->willReturn($component);
        $gateway->method('getGatewayId')->willReturn(1);

        $this->invoke($gateway, 'handleApiRequest', [
            function () use ($component) {
                throw \Stripe\Exception\AuthenticationException::factory(
                    'Invalid API Key provided: ' . $component->token
                );
            },
            [],
            'https://api.stripe.com/v1/test'
        ]);

        $this->assertTrue($component->used);
        $this->assertInstanceOf(OauthTokenRejectedException::class, $component->caught);
        $this->assertInstanceOf(\Stripe\Exception\AuthenticationException::class, $component->caught->getPrevious());
        $this->assertNotFalse($gateway->Input->errors());
    }

    public function testHandleApiRequestLeaksNoTokenMaterialOnAuthFailure()
    {
        $component = $this->extensionOauthSpy();

        $gateway = $this->gateway(['isOauthConnected', 'getExtensionOauth', 'getGatewayId']);
        $gateway->method('isOauthConnected')->willReturn(true);
        $gateway->method('getExtensionOauth')->willReturn($component);
        $gateway->method('getGatewayId')->willReturn(1);

        $response = $this->invoke($gateway, 'handleApiRequest', [
            function () use ($component) {
                throw \Stripe\Exception\AuthenticationException::factory(
                    'Invalid API Key provided: ' . $component->token
                );
            },
            [],
            'https://api.stripe.com/v1/test'
        ]);

        $this->assertNotEmpty($gateway->logs);
        foreach ($gateway->logs as $log) {
            $this->assertStringNotContainsString($component->token, (string) $log['data']);
        }

        // The response object handed back to the caller is built from the same error
        // array and must be just as clean as the logs
        $this->assertStringNotContainsString($component->token, serialize((array) $response));
    }

    public function testHandleApiRequestPassesNonAuthExceptionsThroughUnclassified()
    {
        $component = $this->extensionOauthSpy();

        $gateway = $this->gateway(['isOauthConnected', 'getExtensionOauth', 'getGatewayId']);
        $gateway->method('isOauthConnected')->willReturn(true);
        $gateway->method('getExtensionOauth')->willReturn($component);
        $gateway->method('getGatewayId')->willReturn(1);

        $this->invoke($gateway, 'handleApiRequest', [
            function () {
                throw \Stripe\Exception\CardException::factory(
                    'Your card was declined.',
                    402,
                    null,
                    ['error' => ['type' => 'card_error', 'code' => 'card_declined', 'message' => 'Your card was declined.']]
                );
            },
            [],
            'https://api.stripe.com/v1/test'
        ]);

        $this->assertTrue($component->used);
        $this->assertNull($component->caught);
        $this->assertNotFalse($gateway->Input->errors());
    }

    public function testHandleApiRequestReturnsCallbackResultWhenConnected()
    {
        $component = $this->extensionOauthSpy();

        $gateway = $this->gateway(['isOauthConnected', 'getExtensionOauth', 'getGatewayId']);
        $gateway->method('isOauthConnected')->willReturn(true);
        $gateway->method('getExtensionOauth')->willReturn($component);
        $gateway->method('getGatewayId')->willReturn(1);

        $response = new class {
            public function jsonSerialize()
            {
                return ['id' => 'pi_123', 'status' => 'succeeded'];
            }
        };

        $result = $this->invoke($gateway, 'handleApiRequest', [
            function () use ($response) {
                return $response;
            },
            [],
            'https://api.stripe.com/v1/test'
        ]);

        $this->assertTrue($component->used);
        $this->assertSame($response, $result);
        $this->assertFalse($gateway->Input->errors());
    }

    public function testHandleApiRequestDoesNotUseOauthWhenNotConnected()
    {
        $component = $this->extensionOauthSpy();

        $gateway = $this->gateway(['isOauthConnected', 'getExtensionOauth', 'getGatewayId']);
        $gateway->setMeta(['secret_key' => 'sk_test_meta']);
        $gateway->method('isOauthConnected')->willReturn(false);
        $gateway->method('getExtensionOauth')->willReturn($component);
        $gateway->method('getGatewayId')->willReturn(1);

        $response = new class {
            public function jsonSerialize()
            {
                return ['id' => 'pi_123'];
            }
        };

        $result = $this->invoke($gateway, 'handleApiRequest', [
            function () use ($response) {
                return $response;
            },
            [],
            'https://api.stripe.com/v1/test'
        ]);

        $this->assertFalse($component->used);
        $this->assertSame($response, $result);
    }
}
