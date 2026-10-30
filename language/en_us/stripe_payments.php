<?php
// Errors
$lang['StripePayments.!error.auth'] = 'The gateway could not authenticate.';
$lang['StripePayments.!error.publishable_key.empty'] = 'Please enter a Publishable Key.';
$lang['StripePayments.!error.secret_key.empty'] = 'Please enter a Secret Key.';
$lang['StripePayments.!error.secret_key.valid'] = 'Unable to connect to the Stripe API using the given Secret Key.';

$lang['StripePayments.!error.bank_account_unverified'] = 'You need to verify your bank account before you can use it to make a payment.';
$lang['StripePayments.!error.ach.invalid_account'] = 'The bank account could not be found for this customer.';
$lang['StripePayments.!error.ach.unverified'] = 'The bank account could not be verified. Please confirm the deposit amounts and try again.';
$lang['StripePayments.!error.invalid_request_error'] = 'The payment gateway returned an error when processing the request.';
$lang['StripePayments.!error.india_mandate_max_amount.format'] = 'Please enter a valid amount for the maximum recurring charge.';

$lang['StripePayments.!error.oauth.extras_invalid'] = 'Request not recognized. Reload the page and try again.';
$lang['StripePayments.!error.oauth.keys_stored'] = 'This gateway uses API keys, so nothing changed. Choose Switch to Stripe Connect or Use a Different Account.';
$lang['StripePayments.!error.oauth.convert_no_key'] = 'There are no API keys to switch from, so nothing changed. Use Connect with Stripe instead.';
$lang['StripePayments.!error.oauth.key_unrecognized'] = 'This key can\'t be switched in place, so nothing changed. Choose Use a Different Account instead.';
$lang['StripePayments.!error.oauth.mode_mismatch'] = 'That authorization doesn\'t match your API keys\' mode (live or test), so nothing changed.';
$lang['StripePayments.!error.oauth.account_check_failed'] = 'Couldn\'t verify the account your API keys belong to, so nothing changed. Please try again.';
$lang['StripePayments.!error.oauth.account_mismatch'] = 'That isn\'t the Stripe account your API keys use, so nothing changed. To move to it, choose Use a Different Account.';
$lang['StripePayments.!error.setup_intent_missing'] = 'The bank account verification could not be found. Please remove this payment account and add it again.';
$lang['StripePayments.!error.verification_incomplete'] = 'This bank account could not be verified. Please check the details you entered and try again.';
$lang['StripePayments.!error.account_customer_mismatch'] = 'This bank account is not associated with your account and cannot be used.';
$lang['StripePayments.!error.payment_method_microdeposit_verification_attempts_exceeded'] = 'You have exceeded the number of allowed verification attempts. Please remove this payment account and add it again.';
$lang['StripePayments.!error.payment_method_microdeposit_verification_timeout'] = 'This bank account was not verified within the allowed time. Please remove this payment account and add it again.';
$lang['StripePayments.!error.payment_method_microdeposit_failed'] = 'We could not send deposits to this bank account. Please check the account details and try again.';

$lang['StripePayments.name'] = 'Stripe Payments';
$lang['StripePayments.description'] = 'Uses Stripe Elements and the Payment Request API to automatically handle 3D Secure and SCA to send credit cards directly through Stripe';

// Form
$lang['StripePayments.ach_form.legacy_notice'] = 'This bank account was saved using an older integration that is no longer supported. Please connect your bank account again to continue paying with it.';
$lang['StripePayments.ach_form.current_account'] = 'Current bank account: %1$s ending in %2$s'; // %1$s is the account type, %2$s the last 4 digits
$lang['StripePayments.ach_form.replace_button'] = 'Use a Different Bank Account';
$lang['StripePayments.ach_form.collect_notice'] = 'Connect your bank account to authorize payments. You can sign in to your bank to connect instantly, or enter your account and routing numbers manually.';
$lang['StripePayments.ach_form.collect_button'] = 'Connect Bank Account';
$lang['StripePayments.ach_form.collect_requirements'] = 'Enter your name and billing address above to connect a bank account.';
$lang['StripePayments.ach_form.collect_cancelled'] = 'No bank account was connected. Please try again.';
$lang['StripePayments.ach_form.mandate_accept'] = 'Accept and Continue';
$lang['StripePayments.ach_form.working'] = 'Please wait...';
$lang['StripePayments.ach_form.confirm_failed'] = 'This bank account could not be authorized. Please try connecting it again.';
$lang['StripePayments.ach_form.verified'] = 'Your bank account has been connected and verified.';
$lang['StripePayments.ach_form.pending_microdeposits'] = 'Your bank account has been connected. We will send a small deposit to it within 1-2 business days, which you will need to confirm before the account can be used.';
$lang['StripePayments.ach_form.not_collected'] = 'Please connect your bank account and accept the authorization before continuing.';

// Verification form
$lang['StripePayments.ach_verification_form.notice'] = 'We sent a small deposit to your bank account. Enter the 6-digit code from your bank statement to verify the account.';
$lang['StripePayments.ach_verification_form.field_descriptor_code'] = 'Verification Code';
$lang['StripePayments.ach_verification_form.placeholder_descriptor_code'] = 'SM11AA';
$lang['StripePayments.ach_verification_form.tooltip_descriptor_code'] = 'This 6-character code begins with SM and appears in the description of a $0.01 deposit on your statement.';
$lang['StripePayments.ach_verification_form.amounts_notice'] = 'If your bank shows two small deposits instead of a verification code, leave the field above blank and enter the deposit amounts in cents below.';
$lang['StripePayments.ach_verification_form.field_first_deposit'] = 'First Deposit';
$lang['StripePayments.ach_verification_form.field_second_deposit'] = 'Second Deposit';

$lang['StripePayments.ach_form.mandate_authorization'] = 'By clicking Accept and Continue, you authorize %1$s to debit the bank account specified above for any amount owed for charges arising from your use of %1$s services and/or purchase of products from %1$s, pursuant to %1$s website and terms, until this authorization is revoked. You may amend or cancel this authorization at any time by providing notice to %1$s with 30 (thirty) days notice.';
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

$lang['StripePayments.heading_legacy_ach_accounts'] = 'Legacy Bank Accounts';
$lang['StripePayments.warning_legacy_ach_accounts'] = '%1$s bank account(s) are still stored using an integration Stripe has deprecated.'; // Where %1$s is the number of legacy bank accounts
$lang['StripePayments.text_legacy_ach_accounts'] = 'These accounts continue to be charged normally and require no immediate action. They cannot be converted automatically because Stripe does not allow a stored bank account to be re-saved on a client\'s behalf. To move a client onto the current integration, ask them to remove the bank account and add it again.';

// Charge description
$lang['StripePayments.charge_description_default'] = 'Charge for specified amount';
$lang['StripePayments.charge_description'] = 'Charge for %1$s'; // Where %1$s is a comma seperated list of invoice ID display codes

// Stripe Connect (OAuth)
$lang['StripePayments.oauth.pending'] = 'A Stripe authorization is in progress.';
$lang['StripePayments.oauth.pending_connected'] = 'A Stripe authorization is in progress. The current connection keeps working until it completes.';
$lang['StripePayments.oauth.pending_cancel'] = 'Cancel';
$lang['StripePayments.oauth.revocation_pending'] = 'Couldn\'t disconnect previously connected Stripe account %1$s. Retry, or remove it from your Stripe dashboard.'; // Where %1$s is a comma-separated list of Stripe account IDs
$lang['StripePayments.oauth.revocation_retry'] = 'Retry';
$lang['StripePayments.oauth.connected'] = 'Connected to Stripe account %1$s'; // Where %1$s is the Stripe account ID
$lang['StripePayments.oauth.badge_live'] = 'Live';
$lang['StripePayments.oauth.badge_test'] = 'Test';
$lang['StripePayments.oauth.connect'] = 'Connect with Stripe';
$lang['StripePayments.oauth.connect_test'] = 'Set up in sandbox/test mode instead';
$lang['StripePayments.oauth.connect_text'] = 'Connect your Stripe account to start accepting payments. Your credentials are managed automatically, so there are no API keys to copy or rotate.';
$lang['StripePayments.oauth.use_api_keys'] = 'Use API keys instead';
$lang['StripePayments.oauth.convert'] = 'Switch to Stripe Connect';
$lang['StripePayments.oauth.convert_text'] = 'Switch to Stripe Connect to stop managing keys. Your account and saved payment methods are kept.';
$lang['StripePayments.oauth.convert_restricted'] = 'Restricted keys can\'t be switched to Stripe Connect in place.';
$lang['StripePayments.oauth.recovery'] = 'Stripe is disconnected. Click Connect with Stripe to finish setup. Reconnecting to the same Stripe account will restore your clients\' saved payment methods.';
$lang['StripePayments.oauth.disconnect'] = 'Disconnect';
$lang['StripePayments.oauth.reconnect'] = 'Reconnect';

$lang['StripePayments.oauth.modal_cancel'] = 'Cancel';
$lang['StripePayments.oauth.modal_disconnect_title'] = 'Disconnect from Stripe';
$lang['StripePayments.oauth.modal_disconnect_text'] = 'This revokes Blesta\'s access to your Stripe account and leaves the gateway unconfigured, so no further payments can be processed until it is set up again. Your clients\' saved payment methods remain in your Stripe account and will work again if you reconnect to the same account.';
$lang['StripePayments.oauth.api_keys'] = 'Using API keys';
$lang['StripePayments.oauth.replace'] = 'Use a Different Account';
$lang['StripePayments.oauth.modal_replace_title'] = 'Use a Different Stripe Account';
$lang['StripePayments.oauth.modal_replace_text'] = 'Your API keys are replaced by whichever account you authorize. Saved payment methods stay with the current account and won\'t work with the new one.';
$lang['StripePayments.oauth.modal_replace_count'] = '%1$s saved payment methods will stop working.'; // Where %1$s is the number of saved payment methods
$lang['StripePayments.oauth.modal_replace_count_one'] = '1 saved payment method will stop working.';
$lang['StripePayments.oauth.modal_replace_submit'] = 'Continue to Stripe';
$lang['StripePayments.oauth.modal_reconnect_title'] = 'Reconnect to Stripe';
$lang['StripePayments.oauth.modal_reconnect_text'] = 'Choose an account on Stripe. The current connection keeps working until you finish.';
$lang['StripePayments.oauth.modal_reconnect_count'] = '%1$s saved payment methods only work with the current account.'; // Where %1$s is the number of saved payment methods
$lang['StripePayments.oauth.modal_reconnect_count_one'] = '1 saved payment method only works with the current account.';
$lang['StripePayments.oauth.modal_reconnect_test'] = 'Use test mode';
$lang['StripePayments.oauth.modal_reconnect_submit'] = 'Continue to Stripe';
