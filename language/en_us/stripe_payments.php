<?php
// Errors
$lang['StripePayments.!error.auth'] = 'The gateway could not authenticate.';
$lang['StripePayments.!error.publishable_key.empty'] = 'Please enter a Publishable Key.';
$lang['StripePayments.!error.secret_key.empty'] = 'Please enter a Secret Key.';
$lang['StripePayments.!error.secret_key.valid'] = 'Unable to connect to the Stripe API using the given Secret Key.';

$lang['StripePayments.!error.bank_account_unverified'] = 'You need to verify your bank account before you can use it to make a payment.';
$lang['StripePayments.!error.invalid_request_error'] = 'The payment gateway returned an error when processing the request.';
$lang['StripePayments.!error.india_mandate_max_amount.format'] = 'Please enter a valid amount for the maximum recurring charge.';

$lang['StripePayments.name'] = 'Stripe Payments';
$lang['StripePayments.description'] = 'Uses Stripe Elements and the Payment Request API to automatically handle 3D Secure and SCA to send credit cards directly through Stripe';

// Form
$lang['StripePayments.ach_form.field_type'] = 'Account Type';
$lang['StripePayments.ach_form.field_holder_type'] = 'Holder Type';
$lang['StripePayments.ach_form.field_holder_type_individual'] = 'Individual';
$lang['StripePayments.ach_form.field_holder_type_company'] = 'Company';
$lang['StripePayments.ach_form.field_account_number'] = 'Account Number';
$lang['StripePayments.ach_form.field_routing_number'] = 'Routing Number';

$lang['StripePayments.ach_form.verification_notice'] = 'We sent two small deposits to this bank account. To verify this account, please confirm the amounts of these deposits.';
$lang['StripePayments.ach_form.field_first_deposit'] = 'First Deposit';
$lang['StripePayments.ach_form.field_second_deposit'] = 'Second Deposit';

$lang['StripePayments.ach_form.mandate_authorization'] = 'By submitting this form, you authorize %1$s to debit the bank account specified above for any amount owed for charges arising from your use of %1$s services and/or purchase of products from %1$s, pursuant to %1$s website and terms, until this authorization is revoked. You may amend or cancel this authorization at any time by providing notice to %1$s with 30 (thirty) days notice.';
$lang['StripePayments.ach_form.mandate_future_usage'] = 'If you use %1$s services or purchase additional products periodically pursuant to %1$s terms, you authorize %1$s to debit your bank account periodically. Payments that fall outside of the regular debits authorized above will only be debited after your authorization is obtained.';

// Settings
$lang['StripePayments.publishable_key'] = 'API Publishable Key';
$lang['StripePayments.secret_key'] = 'API Secret Key';
$lang['StripePayments.request_three_d_secure'] = '3D Secure Authentication Flow';
$lang['StripePayments.request_three_d_secure_automatic'] = 'Allow Stripe to determine when to present a 3D Secure challenge';
$lang['StripePayments.request_three_d_secure_frictionless'] = 'Present 3D Secure challenge whenever a client saves a payment method or processes an unstored payment method';
$lang['StripePayments.request_three_d_secure_challenge'] = 'Present 3D Secure challenge whenever a client saves a payment method or processes a payment method (stored or unstored)';
$lang['StripePayments.request_three_d_secure_note'] = 'If you are a Stripe user based in India, before saving a new card with Stripe you must always perform 3D Secure (3DS) authentication.';

$lang['StripePayments.india_mandate_max_amount'] = 'Maximum Recurring Charge Amount (India)';
$lang['StripePayments.india_mandate_max_amount_note'] = 'Required to enable automatic recurring charges for cards issued in India. This is the maximum amount that may be charged to such a card in any single future off-session payment, in the currency being processed. Leave blank to allow Indian cards to be saved without registering for automatic recurring charges.';

$lang['StripePayments.tooltip_publishable_key'] = 'Your API Publishable Key is specific to either live or test mode. Be sure you are using the correct key.';
$lang['StripePayments.tooltip_secret_key'] = 'Your API Secret Key is specific to either live or test mode. Be sure you are using the correct key.';
$lang['StripePayments.tooltip_india_mandate_max_amount'] = 'Per RBI regulations, recurring (off-session) charges to Indian cards above this amount will require the customer to separately authenticate the payment.';

$lang['StripePayments.webhook'] = 'Stripe Webhook';
$lang['StripePayments.webhook_note'] = 'It is recommended to configure the following url as a Webhook for "payment_intent" and "charge" events in your Stripe account.';


$lang['StripePayments.heading_migrate_accounts'] = 'Migrate Old Payment Accounts';
$lang['StripePayments.text_accounts_remaining'] = 'Accounts Remaining: %1$s'; // Where %1$s is the number of accounts yet to be migrated
$lang['StripePayments.text_migrate_accounts'] = 'You can automatically migrate payment accounts stored offsite by the old Stripe gateway over to this Stripe Payments gateway. Accounts that are not stored offsite must be migrated by manually creating new payment accounts. In order to prevent timeouts migrations will be done in batches of %1$s. Run this as many times as needed to migrate all payment accounts.'; // Where %1$s is the batch size
$lang['StripePayments.warning_migrate_accounts'] = 'Do not uninstall the old Stripe gateway until you finish using this migration tool. Doing so will make the tool inaccessible.';
$lang['StripePayments.migrate_accounts'] = 'Migrate Accounts';

// Charge description
$lang['StripePayments.charge_description_default'] = 'Charge for specified amount';
$lang['StripePayments.charge_description'] = 'Charge for %1$s'; // Where %1$s is a comma seperated list of invoice ID display codes

// Stripe Connect (OAuth)
$lang['StripePayments.oauth.pending'] = 'A Stripe authorization is in progress. If you did not complete it, you can safely start over by clicking Connect again.';
$lang['StripePayments.oauth.connected'] = 'Connected to Stripe account %1$s'; // Where %1$s is the Stripe account ID
$lang['StripePayments.oauth.badge_live'] = 'Live';
$lang['StripePayments.oauth.badge_test'] = 'Test';
$lang['StripePayments.oauth.connect'] = 'Connect with Stripe';
$lang['StripePayments.oauth.connect_test'] = 'Set up in sandbox/test mode instead';
$lang['StripePayments.oauth.connect_text'] = 'Connect your Stripe account to start accepting payments. Your credentials are managed automatically, so there are no API keys to copy or rotate.';
$lang['StripePayments.oauth.use_api_keys'] = 'Use API keys instead';
$lang['StripePayments.oauth.convert'] = 'Switch to Stripe Connect';
$lang['StripePayments.oauth.convert_text'] = 'This gateway is using API keys. Connect it to Stripe so credentials are managed automatically. Your Stripe account and your clients\' saved payment methods are kept.';
$lang['StripePayments.oauth.convert_restricted'] = 'This gateway is using a restricted API key, which cannot be switched to Stripe Connect in place. It will continue to operate using the stored key.';
$lang['StripePayments.oauth.recovery'] = 'Stripe is disconnected. Click Connect with Stripe to finish setup. Reconnecting to the same Stripe account will restore your clients\' saved payment methods.';
$lang['StripePayments.oauth.disconnect'] = 'Disconnect';
$lang['StripePayments.oauth.switch_to_live'] = 'Switch to Live';

$lang['StripePayments.oauth.modal_cancel'] = 'Cancel';
$lang['StripePayments.oauth.modal_switch_title'] = 'Switch to Live Mode';
$lang['StripePayments.oauth.modal_switch_text'] = 'This disconnects the current test-mode connection and sends you to Stripe to authorize in live mode. The test connection cannot be restored, and any payment methods saved under the test account will no longer resolve. If you leave before completing the live authorization, this gateway will remain disconnected until you connect again.';
$lang['StripePayments.oauth.modal_disconnect_title'] = 'Disconnect from Stripe';
$lang['StripePayments.oauth.modal_disconnect_text'] = 'This revokes Blesta\'s access to your Stripe account and leaves the gateway unconfigured, so no further payments can be processed until it is set up again. Your clients\' saved payment methods remain in your Stripe account and will work again if you reconnect to the same account.';
$lang['StripePayments.oauth.modal_convert_test_title'] = 'Convert in Test Mode';
$lang['StripePayments.oauth.modal_convert_test_text'] = 'The stored secret key is a test-mode key, so this gateway will connect to Stripe in TEST mode. Payments will not be processed for real. Proceed?';
