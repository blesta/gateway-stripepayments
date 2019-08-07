<?php
// Errors
$lang['Stripe3ds.!error.auth'] = 'The gateway could not authenticate.';
$lang['Stripe3ds.!error.publishable_key.empty'] = 'Please enter a Publishable Key.';
$lang['Stripe3ds.!error.secret_key.empty'] = 'Please enter a Secret Key.';
$lang['Stripe3ds.!error.json_required'] = 'The JSON extension is required for this gateway.';
$lang['Stripe3ds.!error.setup_not_created'] = 'Unable to declare intent to store a card with Stripe.';

$lang['Stripe3ds.name'] = 'Stripe 3DS';

// Settings
$lang['Stripe3ds.publishable_key'] = 'API Publishable Key';
$lang['Stripe3ds.secret_key'] = 'API Secret Key';
$lang['Stripe3ds.tooltip_publishable_key'] = 'Your API Publishable Key is specific to either live or test mode. Be sure you are using the correct key.';
$lang['Stripe3ds.tooltip_secret_key'] = 'Your API Secret Key is specific to either live or test mode. Be sure you are using the correct key.';

// Charge description
$lang['Stripe3ds.charge_description'] = 'Charge for %1$s'; // Where %1$s is a comma seperated list of invoice ID display codes
