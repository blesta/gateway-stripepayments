# Stripe 3DS

[![Build Status](https://travis-ci.org/blesta/gateway-stripe3ds.svg?branch=master)](https://travis-ci.org/blesta/gateway-stripe3ds) [![Coverage Status](https://coveralls.io/repos/github/blesta/gateway-stripe3ds/badge.svg?branch=master)](https://coveralls.io/github/blesta/gateway-stripe3ds?branch=master)

This is a non-merchant gateway for Blesta that integrates with [Stripe 3DS](https://stripe3ds.com/).

## Install the Gateway

1. You can install the gateway via composer:

    ```
    composer require blesta/stripe3ds
    ```

2. Upload the source code to a /components/gateways/nonmerchant/stripe3ds/ directory within
your Blesta installation path.

    For example:

    ```
    /var/www/html/blesta/components/nonmerchant/stripe3ds/
    ```

3. Log in to your admin Blesta account and navigate to
> Settings > Payment Gateways

4. Find the Stripe 3DS gateway and click the "Install" button to install it

5. You're done!
