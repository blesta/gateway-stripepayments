# Stripe Payments

[![Build Status](https://travis-ci.org/blesta/gateway-stripepayments.svg?branch=master)](https://travis-ci.org/blesta/gateway-stripepayments) [![Coverage Status](https://coveralls.io/repos/github/blesta/gateway-stripepayments/badge.svg?branch=master)](https://coveralls.io/github/blesta/gateway-stripepayments?branch=master)

This is a non-merchant gateway for Blesta that integrates with [Stripe Payments](https://stripe.com/).

## Install the Gateway

1. You can install the gateway via composer:

    ```
    composer require blesta/stripe_payments
    ```

2. Upload the source code to a /components/gateways/nonmerchant/stripe_payments/ directory within
your Blesta installation path.

    For example:

    ```
    /var/www/html/blesta/components/nonmerchant/stripe_payments/
    ```

3. Log in to your admin Blesta account and navigate to
> Settings > Payment Gateways

4. Find the Stripe Payments gateway and click the "Install" button to install it

5. You're done!
