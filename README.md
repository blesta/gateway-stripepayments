# Stripe Payments

[![Build Status](https://travis-ci.org/blesta/gateway-stripepayments.svg?branch=master)](https://travis-ci.org/blesta/gateway-stripepayments) [![Coverage Status](https://coveralls.io/repos/github/blesta/gateway-stripepayments/badge.svg?branch=master)](https://coveralls.io/github/blesta/gateway-stripepayments?branch=master)

This is a merchant gateway for Blesta that integrates with [Stripe](https://stripe.com/), using tools like Stripe.js, Elements, and PaymentIntents to ensure a secure process for storing and charging cards.

## Install the Gateway

1. You can install the gateway via composer:

    ```
    composer require blesta/stripe_payments
    ```

2. Upload the source code to a /components/gateways/merchant/stripe_payments/ directory within
your Blesta installation path.

    For example:

    ```
    /var/www/html/blesta/components/merchant/stripe_payments/
    ```

3. Log in to your admin Blesta account and navigate to
> Settings > Payment Gateways

4. Find the Stripe Payments gateway and click the "Install" button to install it

5. You're done!

## Connecting Stripe with OAuth (Stripe Connect)

As of v2.1.0 (Blesta 6.0+), the recommended way to configure the gateway is to connect
your Stripe account directly instead of copying API keys:

1. Navigate to
> Settings > Payment Gateways > Stripe Payments

2. Click **Connect with Stripe** and complete the authorization on Stripe's site. To use
a sandbox, choose **Set up in sandbox/test mode instead**.

3. On return, the gateway shows the connected Stripe account with a Live or Test badge.
Credentials are managed automatically from then on — there are no keys to copy or rotate.

If you prefer API keys, expand **Use API keys instead** and enter your publishable and
secret keys as before.

### Switching an existing gateway to Stripe Connect

A gateway already configured with API keys shows a **Switch to Stripe Connect** button.
The conversion only completes when the Stripe account you authorize is the same account
your existing secret key points at (and in the same live/test mode); otherwise it is
refused and nothing changes. On success the stored API keys are removed and your clients'
saved payment methods continue to work unchanged.

Gateways configured with a restricted key (`rk_...`) cannot be converted in place —
disconnect and set up with **Connect with Stripe** instead.

A test-mode connection offers a **Switch to Live** button. This disconnects the test
authorization first and then sends you to Stripe to authorize in live mode; if you abandon
the process partway, the gateway remains unconfigured until you connect again.

### Disconnecting and revoking

**Disconnect** revokes Blesta's access to your Stripe account and leaves the gateway
unconfigured — it does not fall back to previously stored API keys. Your clients' saved
payment methods remain in your Stripe account and resolve again if you reconnect to the
same account. You can also revoke access from the Stripe dashboard; the gateway discovers
this on its next API call and returns to the unconfigured state.

### Webhook setup is unchanged

Webhooks are not affected by how the gateway authenticates. Configure the webhook URL
shown on the gateway settings page in your own Stripe account for "payment_intent" and
"charge" events, exactly as before.

### Blesta Compatibility

|Blesta Version|Module Version|
|--------------|--------------|
|< v4.9.0|v1.1.0|
|>= v4.9.0|v1.2.0|
|>= v6.0.0|v2.1.0|
