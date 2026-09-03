<?php
/**
 * Stripe Credit Card processing gateway. Supports offsite payment
 * processing for Credit Cards using the latest secure methods from Stripe.
 *
 * The Stripe API can be found at: https://stripe.com/docs/api
 *
 * @package blesta
 * @subpackage blesta.components.gateways.stripe_payments
 * @copyright Copyright (c) 2019, Phillips Data, Inc.
 * @license http://www.blesta.com/license/ The Blesta License Agreement
 * @link http://www.blesta.com/ Blesta
 */
class StripePayments extends MerchantGateway implements MerchantAch, MerchantAchOffsite, MerchantAchVerification, MerchantAchStatus, MerchantAchForm, MerchantCc, MerchantCcOffsite, MerchantCcForm
{
    /**
     * @var array An array of meta data for this gateway
     */
    private $meta;

    /**
     * @var string The base URL of API requests
     */
    private $base_url = 'https://api.stripe.com/v1/';

    /**
     * Construct a new merchant gateway
     */
    public function __construct()
    {
        $this->loadConfig(dirname(__FILE__) . DS . 'config.json');

        // Load components required by this module
        Loader::loadComponents($this, ['Input']);

        // Load the language required by this module
        Language::loadLang('stripe_payments', null, dirname(__FILE__) . DS . 'language' . DS);

        // Load product configuration required by this module
        Configure::load('stripe_payments', dirname(__FILE__) . DS . 'config' . DS);

        // Check if Stripe.js is already loaded
        if ($this->global('stripe_js') == null) {
            $this->global('stripe_js', false);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function setCurrency($currency)
    {
        $this->currency = $currency;
    }

    /**
     * {@inheritdoc}
     */
    public function getSettings(array $meta = null)
    {
        // Load the view into this object, so helpers can be automatically added to the view
        $this->view = new View('settings', 'default');
        $this->view->setDefaultView('components' . DS . 'gateways' . DS . 'merchant' . DS . 'stripe_payments' . DS);

        // Load the helpers required for this view
        Loader::loadHelpers($this, ['Form', 'Html']);
        Loader::loadModels($this, ['GatewayManager']);

        // Check if the old Stripe gateway is installed and see how many cc accounts are linked to it
        $legacy_stripe_installed = false;
        $gateways = $this->GatewayManager->getByClass('stripe_gateway', Configure::get('Blesta.company_id'));
        if (!empty($gateways)) {
            $legacy_stripe_installed = true;

            $record = new Record;
            $accounts_remaining = $record->select()->
                from('accounts_cc')->
                where('gateway_id', '=', $gateways[0]->id)->
                where('reference_id', '!=', null)->
                where('status', '=', 'active')->
                numResults();

            $this->view->set('accounts_remaining', $accounts_remaining);
            $this->view->set('batch_size', Configure::get('StripePayments.migration_batch_size'));
        }

        $this->view->set('legacy_stripe_installed', $legacy_stripe_installed);
        $this->view->set('legacy_ach_accounts', $this->countLegacyAchAccounts());
        $this->view->set('meta', $meta);

        return $this->view->fetch();
    }

    /**
     * Counts the bank accounts still stored through Stripe's deprecated Sources API. These continue
     * to charge normally, but cannot be converted to PaymentMethods without the client re-entering
     * their bank account, so the count is reported to the admin rather than migrated automatically
     *
     * @return int The number of bank accounts stored as legacy sources
     */
    private function countLegacyAchAccounts()
    {
        $gateways = $this->GatewayManager->getByClass('stripe_payments', Configure::get('Blesta.company_id'));
        if (empty($gateways)) {
            return 0;
        }

        $record = new Record;

        return $record->select()->
            from('accounts_ach')->
            where('gateway_id', '=', $gateways[0]->id)->
            where('reference_id', '!=', null)->
            notLike('reference_id', 'pm_%')->
            where('status', '!=', 'inactive')->
            numResults();
    }

    /**
     * {@inheritdoc}
     */
    public function editSettings(array $meta)
    {
        // Validate the given meta data to ensure it meets the requirements
        $rules = [
            'publishable_key' => [
                'empty' => [
                    'rule' => 'isEmpty',
                    'negate' => true,
                    'message' => Language::_('StripePayments.!error.publishable_key.empty', true)
                ]
            ],
            'secret_key' => [
                'empty' => [
                    'rule' => 'isEmpty',
                    'negate' => true,
                    'message' => Language::_('StripePayments.!error.secret_key.empty', true)
                ],
                'valid' => [
                    'rule' => [[$this, 'validateConnection']],
                    'message' => Language::_('StripePayments.!error.secret_key.valid', true)
                ]
            ],
            'india_mandate_max_amount' => [
                'format' => [
                    'if_set' => true,
                    'rule' => ['matches', '/^\d+(\.\d{1,2})?$/'],
                    'message' => Language::_('StripePayments.!error.india_mandate_max_amount.format', true)
                ]
            ]
        ];

        $this->Input->setRules($rules);

        // Migrate accounts
        if ($this->Input->validates($meta) && isset($meta['migrate_accounts'])) {
            $this->migrateLegacyAccounts($meta);
        }

        unset($meta['migrate_accounts']);
        return $meta;
    }

    /**
     * Migrates payment accounts from the old Stripe gateway to the new Stripe Payments gateway
     *
     * @param array $meta An array of meta (settings) data for this gateway
     */
    private function migrateLegacyAccounts(array $meta)
    {
        Loader::loadModels($this, ['GatewayManager']);

        // Get the old Stripe gateway
        $legacy_stripe = $this->GatewayManager->getByClass('stripe_gateway', Configure::get('Blesta.company_id'));
        // Get the new Stripe Payments gateway
        $stripe_payments = $this->GatewayManager->getByClass('stripe_payments', Configure::get('Blesta.company_id'));
        if (!empty($legacy_stripe) && !empty($stripe_payments)) {
            // Get the offsite accounts linked to the old gateway
            $record = new Record;
            $legacy_stripe_accounts = $record->select()->
                from('accounts_cc')->
                where('gateway_id', '=', $legacy_stripe[0]->id)->
                where('reference_id', '!=', null)->
                where('status', '=', 'active')->
                getStatement();

            // Set the meta data for this gateway
            $this->setMeta($meta);
            // Set the ID of the gateway (for logging purposes)
            $this->setGatewayId($stripe_payments[0]->id);

            // Collect reference IDs for all of the old accounts by fetching the customer from stripe
            $accounts_references = [];
            $accounts_collected = 0;
            $batch_size = Configure::get('StripePayments.migration_batch_size');
            foreach ($legacy_stripe_accounts as $legacy_stripe_account) {
                if ($accounts_collected >= $batch_size) {
                    break;
                }

                // Fetch the customer
                $customer = $this->handleApiRequest(
                    ['Stripe\Customer', 'retrieve'],
                    [$legacy_stripe_account->reference_id],
                    $this->base_url . 'customers - retrieve'
                );

                // Determine the customer's card reference ID.
                // The Stripe API has changed over time, so the reference ID may be in any of the following fields
                $card_id = null;
                if (!empty($customer->default_source)) {
                    $card_id = $customer->default_source;
                } elseif (!empty($customer->default_card)) {
                    $card_id = $customer->default_card;
                } elseif (isset($customer->active_card) && isset($customer->active_card->id)) {
                    $card_id = $customer->active_card->id;
                }

                if ($card_id !== null) {
                    // Store the reference IDs
                    $accounts_references[$legacy_stripe_account->id] = [
                        'gateway_id' => $stripe_payments[0]->id,
                        'reference_id' => $card_id,
                        'client_reference_id' => $customer->id,
                    ];
                    $accounts_collected++;
                }
            }
            $record->reset();

            // Update the reference and gateway IDs in Blesta
            foreach ($accounts_references as $account_id => $account_references) {
                $record->where('id', '=', $account_id)->update('accounts_cc', $account_references);
            }
        }
    }

    /**
     * {@inheritdoc}
     */
    public function encryptableFields()
    {
        return ['secret_key'];
    }

    /**
     * {@inheritdoc}
     */
    public function setMeta(array $meta = null)
    {
        $this->meta = $meta;
    }

    /**
     * {@inheritdoc}
     */
    public function requiresCustomerPresent()
    {
        return false;
    }

    /**
     * {@inheritdoc}
     */
    public function buildCcForm()
    {
        // Load the view into this object, so helpers can be automatically added to the view
        $this->view = $this->makeView(
            'cc_form',
            'default',
            str_replace(ROOTWEBDIR, '', dirname(__FILE__) . DS)
        );

        // Load the helpers required for this view
        Loader::loadHelpers($this, ['Form', 'Html']);

        // Set 3DS authentication method
        $this->meta['request_three_d_secure'] = $this->meta['request_three_d_secure'] ?? 'automatic';
        if (isset($this->staff_id)) {
            $this->meta['request_three_d_secure'] = 'automatic';
        } elseif ($this->meta['request_three_d_secure'] == 'frictionless') {
            $this->meta['request_three_d_secure'] = 'challenge';
        }

        // Charge the given PaymentMethod through Stripe
        $vars = [[
            'payment_method_options' => ['card' => ['request_three_d_secure' => $this->meta['request_three_d_secure']]],
        ]];

        // Attach the SetupIntent to a Stripe Customer so that the 3DS/OTP authentication the
        // customer performs is credited to that Customer. Indian regulations forbid attaching a
        // PaymentMethod to a Customer separately from an authenticated SetupIntent/PaymentIntent,
        // so without this the PaymentMethod attach in storeCc() would be rejected for Indian cards
        $customer_id = $this->getClientCustomerId();
        if ($customer_id) {
            $vars[0]['customer'] = $customer_id;
            $vars[0]['usage'] = 'off_session';

            // Register an eMandate for India-issued cards so future off-session charges do not
            // require a fresh authentication each time. Only added once the merchant has configured
            // a maximum per-charge amount; Stripe applies this only to cards that require it
            $mandate_options = $this->getCardMandateOptions();
            if ($mandate_options) {
                $vars[0]['payment_method_options']['card']['mandate_options'] = $mandate_options;
            }
        }

        // Declare to Stripe the possibility of us creating a card PaymentMethod through this page
        // This is confirmed in the view using stripe.handleCardSetup
        $setup_intent = $this->handleApiRequest(
            ['Stripe\SetupIntent', 'create'],
            $vars,
            $this->base_url . 'setup_intents - create'
        );

        // Check if Stripe.js is already loaded
        $load_stripe = false;
        if (!$this->global('stripe_js')) {
            $this->global('stripe_js', true);
            $load_stripe = true;
        }

        $this->view->set('load_stripe', $load_stripe);
        $this->view->set('setup_intent', $setup_intent);
        $this->view->set('meta', $this->meta);
        $this->view->set('app_info', $this->getAppInfo());

        return $this->view->fetch();
    }

    /**
     * Fetches the Stripe Customer ID for the current client, creating one from the client's
     * primary contact if none exists yet. Used to tie SetupIntent authentication to a Customer,
     * which Indian regulations require in order to save the resulting PaymentMethod
     *
     * @return string|null The Stripe Customer ID, or null if no client context is available
     */
    private function getClientCustomerId()
    {
        if (empty($this->client_id)) {
            return null;
        }

        Loader::loadComponents($this, ['Record', 'Session']);

        // Reuse the Customer already on file for this client and gateway, if any. Both payment account
        // types are checked so a client that only has a bank account on file still resolves to their
        // existing Customer rather than having a second one created
        foreach (['accounts_cc', 'accounts_ach'] as $table) {
            $account = $this->Record->select([$table . '.client_reference_id'])
                ->from($table)
                ->innerJoin('contacts', 'contacts.id', '=', $table . '.contact_id', false)
                ->where('contacts.client_id', '=', $this->client_id)
                ->where($table . '.gateway_id', '=', $this->gateway_id)
                ->where($table . '.client_reference_id', '!=', null)
                ->where($table . '.status', '!=', 'inactive')
                ->order([$table . '.id' => 'desc'])
                ->fetch();

            if (!empty($account->client_reference_id)) {
                return $account->client_reference_id;
            }
        }

        // Reuse a Customer created earlier this session for this client, to avoid creating an
        // orphan Stripe Customer on every form render before the first successful card save
        $session_key = $this->customerSessionKey($this->client_id);
        $cached_customer_id = $this->Session->read($session_key);
        if (!empty($cached_customer_id)) {
            return $cached_customer_id;
        }

        // No Customer yet, create one from the client's primary contact
        $contact = $this->Record->select()
            ->from('contacts')
            ->where('client_id', '=', $this->client_id)
            ->where('contact_type', '=', 'primary')
            ->fetch();

        if (!$contact) {
            return null;
        }

        $fields = [
            'email' => $contact->email ?? null,
            'name' => (!empty($contact->first_name) && !empty($contact->last_name))
                ? $contact->first_name . ' ' . $contact->last_name
                : ''
        ];
        if (!empty($contact->address1)) {
            $fields['address'] = [
                'line1' => $contact->address1,
                'line2' => $contact->address2,
                'city' => $contact->city,
                'state' => $contact->state,
                'country' => $contact->country,
                'postal_code' => $contact->zip
            ];
        }

        $customer = $this->handleApiRequest(
            ['Stripe\Customer', 'create'],
            [$fields],
            $this->base_url . 'customers - create'
        );

        if (empty($customer->id)) {
            return null;
        }

        $this->Session->write($session_key, $customer->id);

        return $customer->id;
    }

    /**
     * Builds the session key under which the Stripe Customer created for a client this
     * session is cached
     *
     * @param int $client_id The ID of the client
     * @return string The session key
     */
    private function customerSessionKey($client_id)
    {
        return 'stripe_payments_customer_' . $this->gateway_id . '_' . $client_id;
    }

    /**
     * Determines whether the given Stripe Customer belongs to the given client. A Customer is the
     * client's when it is the one Blesta passed for this request, is on file for any of the
     * client's payment accounts under this gateway, or was created for the client earlier this
     * session. Used to reject browser-posted references to another customer's payment details
     *
     * @param int $client_id The ID of the client
     * @param string $customer_id The ID of the Stripe Customer to check
     * @param string $client_reference_id The reference ID Blesta has on record for the client
     *  making this request, if any
     * @return bool True if the Stripe Customer belongs to the client
     */
    private function isClientCustomer($client_id, $customer_id, $client_reference_id = null)
    {
        if (empty($customer_id)) {
            return false;
        }

        if (!empty($client_reference_id) && $customer_id == $client_reference_id) {
            return true;
        }

        if (empty($client_id)) {
            return false;
        }

        Loader::loadComponents($this, ['Record', 'Session']);

        // The Customer stored against any of the client's payment accounts under this gateway.
        // Both account types are checked because the Customer may have been created for one type
        // and reused for the other
        foreach (['accounts_cc', 'accounts_ach'] as $table) {
            $account = $this->Record->select([$table . '.id'])
                ->from($table)
                ->innerJoin('contacts', 'contacts.id', '=', $table . '.contact_id', false)
                ->where('contacts.client_id', '=', $client_id)
                ->where($table . '.gateway_id', '=', $this->gateway_id)
                ->where($table . '.client_reference_id', '=', $customer_id)
                ->where($table . '.status', '!=', 'inactive')
                ->fetch();

            if ($account) {
                return true;
            }
        }

        // The Customer created for this client earlier this session, before its first payment
        // account has been stored
        $session_customer_id = $this->Session->read($this->customerSessionKey($client_id));

        return !empty($session_customer_id) && $customer_id == $session_customer_id;
    }

    /**
     * Builds the mandate_options for the SetupIntent's card, registering an eMandate for
     * India-issued cards so subsequent off-session charges do not each require fresh
     * authentication. Stripe applies this only to cards that require it, so it is safe to
     * include regardless of the card's actual country of issuance
     *
     * @return array|null The mandate_options to attach to payment_method_options.card,
     *  or null if the merchant has not configured a maximum charge amount
     */
    private function getCardMandateOptions()
    {
        $max_amount = $this->meta['india_mandate_max_amount'] ?? null;
        if (!is_numeric($max_amount) || $max_amount <= 0) {
            return null;
        }

        return [
            'amount' => $this->formatAmount((float) $max_amount, ($this->currency ?? null)),
            'amount_type' => 'maximum',
            'currency' => strtolower($this->currency ?? ''),
            'interval' => 'sporadic',
            'reference' => substr('blesta-client-' . $this->client_id . '-' . time(), 0, 80),
            'start_date' => time(),
            'supported_types' => ['india']
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function buildPaymentConfirmation($reference_id, $transaction_id, $amount)
    {
        // Load the view into this object, so helpers can be automatically added to the view
        $this->view = $this->makeView(
            'payment_confirmation',
            'default',
            str_replace(ROOTWEBDIR, '', dirname(__FILE__) . DS)
        );

        // Load the helpers required for this view
        Loader::loadHelpers($this, ['Form', 'Html']);

        $payment_intent = $this->handleApiRequest(
            ['Stripe\PaymentIntent', 'retrieve'],
            [$reference_id],
            $this->base_url . 'payment_intents - retrieve'
        );

        $this->view->set('payment_intent', $payment_intent);
        $this->view->set('meta', $this->meta);
        $this->view->set('app_info', $this->getAppInfo());

        return $this->view->fetch();
    }

    /**
     * {@inheritdoc}
     */
    public function processCc(array $card_info, $amount, array $invoice_amounts = null)
    {
        // The process is the same since both use payment methods and payment intents
        return $this->processStoredCc(
            null,
            $card_info['reference_id'],
            $amount,
            $invoice_amounts,
            true
        );
    }

    /**
     * {@inheritdoc}
     */
    public function authorizeCc(array $card_info, $amount, array $invoice_amounts = null)
    {
        return $this->authorizeStoredCc(null, $card_info['reference_id'], $amount, $invoice_amounts);
    }

    /**
     * {@inheritdoc}
     */
    public function captureCc($reference_id, $transaction_id, $amount, array $invoice_amounts = null)
    {
        return $this->captureStoredCc(null, null, $reference_id, $transaction_id, $amount, $invoice_amounts);
    }

    /**
     * {@inheritdoc}
     */
    public function voidCc($reference_id, $transaction_id)
    {
        return $this->voidTransaction($reference_id, $transaction_id);
    }

    /**
     * Void a charge
     *
     * @param string $reference_id The reference ID for the previously authorized transaction
     * @param string $transaction_id The transaction ID for the previously authorized transaction
     * @return array An array of transaction data including:
     *
     *  - status The status of the transaction (approved, declined, void, pending, reconciled, refunded, returned)
     *  - reference_id The reference ID for gateway-only use with this transaction (optional)
     *  - transaction_id The ID returned by the remote gateway to identify this transaction
     *  - message The message to be displayed in the interface in addition to the standard message for
     *      this transaction status (optional)
     */
    public function voidTransaction($reference_id, $transaction_id)
    {
        // Cancel the PaymentIntent if we don't have a Charge ID yet
        if ($reference_id && !$transaction_id) {
            $payment_intent = $this->handleApiRequest(
                ['Stripe\PaymentIntent', 'retrieve'],
                [$reference_id],
                $this->base_url . 'payment_intents - retrieve'
            );

            // Make sure we actually fetched a valid PaymentIntent
            if ($this->Input->errors()) {
                return;
            }

            // Cancel the PaymentIntent
            $this->handleApiRequest(
                function ($payment_intent) {
                    return $payment_intent->cancel();
                },
                [$payment_intent],
                $this->base_url . 'payment_intents - cancel'
            );

            // Void must be successful
            if ($this->Input->errors()) {
                return;
            }

            // TODO make sure we don't need to do a check on $canceled_payment_intent->status
            // or $canceled_payment_intent->error like we do on card payments

            $response = [
                'status' => 'void',
                'reference_id' => $reference_id,
                'transaction_id' => $transaction_id
            ];
        } else {
            // Refund a previous charge
            $response = $this->refundTransaction($reference_id, $transaction_id, null);
            $response['status'] = 'void';

            // refund must be successful
            if ($this->Input->errors()) {
                return;
            }
        }

        // Set status to void
        return $response;
    }

    /**
     * {@inheritdoc}
     */
    public function refundCc($reference_id, $transaction_id, $amount)
    {
        return $this->refundTransaction($reference_id, $transaction_id, $amount);
    }

    /**
     * Refund a charge
     *
     * @param string $reference_id The reference ID for the previously authorized transaction
     * @param string $transaction_id The transaction ID for the previously authorized transaction
     * @param float $amount The amount to refund this card
     * @return array An array of transaction data including:
     *
     *  - status The status of the transaction (approved, declined, void, pending, reconciled, refunded, returned)
     *  - reference_id The reference ID for gateway-only use with this transaction (optional)
     *  - transaction_id The ID returned by the remote gateway to identify this transaction
     *  - message The message to be displayed in the interface in addition to the standard message for
     *      this transaction status (optional)
     */
    public function refundTransaction($reference_id, $transaction_id, $amount)
    {
        $refund_params = ['charge' => $transaction_id];
        if ($amount) {
            $refund_params['amount'] = $this->formatAmount($amount, $this->currency);
        }

        $refund = $this->handleApiRequest(
            ['Stripe\Refund', 'create'],
            [$refund_params],
            $this->base_url . 'refunds - create'
        );
        $errors = $this->Input->errors();

        // Get the status from the refund response
        if ($errors || isset($refund->error)) {
            if (empty($errors)) {
                $this->Input->setErrors(
                    ['stripe_error' => ['refund' => (isset($refund->error->message) ? $refund->error->message : null)]]
                );
            }

            return false;
        }

        // Return formatted response
        return [
            'status' => 'refunded',
            'reference_id' => $reference_id,
            'transaction_id' => $transaction_id
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function storeCc(array $card_info, array $contact, $client_reference_id = null)
    {
        // Get the PaymentMethod from Stripe
        $card = $this->handleApiRequest(
            ['Stripe\PaymentMethod', 'retrieve'],
            [(isset($card_info['reference_id']) ? $card_info['reference_id'] : null)],
            $this->base_url . 'payment_methods - retrieve'
        );

        if ($this->Input->errors()) {
            return false;
        }

        if (!empty($card->customer)) {
            // Stripe already attached this PaymentMethod to a Customer itself, as part of a
            // successful SetupIntent confirmation (see buildCcForm()/getClientCustomerId()).
            // Attaching it again here would be the bare attach()/Customer::create() operation
            // that Indian regulations forbid once 3DS has already happened without a Customer,
            // so simply adopt the Customer Stripe already assigned
            $customer = (object) ['id' => $card->customer];
        } else {
            // Attach the PaymentMethod to an existing Stripe customer if we have one on record
            $attached = false;
            if ($client_reference_id) {
                // Get the Customer from Stripe
                $customer = $this->handleApiRequest(
                    ['Stripe\Customer', 'retrieve'],
                    [$client_reference_id],
                    $this->base_url . 'customers - retrieve'
                );

                if ($customer && (!isset($customer->deleted) || !$customer->deleted)) {
                    $attached = $this->handleApiRequest(
                        function ($customer_id, $card) {
                            return $card->attach(['customer' => $customer_id]);
                        },
                        [(isset($client_reference_id) ? $client_reference_id : null), $card],
                        $this->base_url . 'payment_methods - attach'
                    );
                }
            }

            // If we were not able to attach the PaymentMethod to an existing customer then create a new one
            if (!$attached) {
                // Reset errors so that if attaching failed we can still create a new customer and not show errors
                $this->Input->setErrors([]);

                // Set fields for the new customer profile
                $fields = [
                    'payment_method' => (isset($card_info['reference_id']) ? $card_info['reference_id'] : null),
                    'email' => (isset($contact['email']) ? $contact['email'] : null),
                    'name' => (!empty($contact['first_name']) && !empty($contact['last_name'])
                        ? (isset($contact['first_name']) ? $contact['first_name'] : null) . ' ' . (isset($contact['last_name']) ? $contact['last_name'] : null)
                        : '')
                ];
                if (!empty($contact['address1'])) {
                    $fields['address'] = [
                        'line1' => (isset($contact['address1']) ? $contact['address1'] : null),
                        'line2' => (isset($contact['address2']) ? $contact['address2'] : null),
                        'city' => (isset($contact['city']) ? $contact['city'] : null),
                        'state' => (isset($contact['state']) ? $contact['state'] : null),
                        'country' => (isset($contact['country']) ? $contact['country'] : null),
                        'postal_code' => (isset($contact['zip']) ? $contact['zip'] : null)
                    ];
                }

                $customer = $this->handleApiRequest(
                    ['Stripe\Customer', 'create'],
                    [$fields],
                    $this->base_url . 'customers - create'
                );
            }
        }

        if ($this->Input->errors()) {
            return false;
        }

        // Return the reference IDs and card information
        return [
            'client_reference_id' => (isset($customer->id) ? $customer->id : $client_reference_id),
            'reference_id' => (isset($card_info['reference_id']) ? $card_info['reference_id'] : null),
            'last4' => (isset($card->card->last4) ? $card->card->last4 : null),
            'expiration' => (isset($card->card->exp_year) ? $card->card->exp_year : null)
                . str_pad((isset($card->card->exp_month) ? $card->card->exp_month : null), 2, 0, STR_PAD_LEFT),
            'type' => $this->mapCardType((isset($card->card->brand) ? $card->card->brand : null))
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function updateCc(array $card_info, array $contact, $client_reference_id, $account_reference_id)
    {
        // Add a new payment account to the same client
        $card_data = $this->storeCc($card_info, $contact, $client_reference_id);

        if ($this->Input->errors()) {
            return false;
        }

        // Remove the old payment account if possible
        if (false === $this->removeCc($client_reference_id, $account_reference_id)) {
            // Ignore any errors caused by attempting to remove the old account
            $this->Input->setErrors([]);
        }

        return $card_data;
    }

    /**
     * Executes a given action using the API, handling errors and logging
     *
     * @param callable $api_method The function to execute
     * @param array $params The parameters to pass to the function
     * @param string $log_url The url to associate with the logs for this request
     * @param bool $detailed_errors True to surface the error Stripe returned rather than a generic
     *  failure message. Use for requests whose specific failure the client must act on, such as a
     *  mismatched microdeposit amount that reports how many attempts remain
     * @return mixed False on error, other wise an object representing the Stripe response
     */
    private function handleApiRequest($api_method, array $params, $log_url, $detailed_errors = false)
    {
        $this->loadApi();

        // Attempt to update the customer's card
        $errors = [];
        $loggable_response = [];
        try {
            $response = call_user_func_array($api_method, $params);

            // Convert the response to a loggable array
            $loggable_response = $response->jsonSerialize();
        } catch (\Stripe\Exception\InvalidRequestException $exception) {
            if (!empty($exception->getJsonBody())) {
                $loggable_response = $exception->getJsonBody();
                $errors = [
                    $loggable_response['error']['type'] => [
                        'error' => $this->formatErrorMessage($loggable_response['error'])
                    ]
                ];
            } else {
                // Gateway returned an invalid response
                $errors = $this->getCommonError('general');
            }
        } catch (\Stripe\Exception\CardException $exception) {
            if (!empty($exception->getJsonBody())) {
                $loggable_response = $exception->getJsonBody();
                $errors = [
                    $loggable_response['error']['type'] => [
                        $loggable_response['error']['code'] => $this->formatErrorMessage($loggable_response['error'])
                    ]
                ];
            } else {
                // Gateway returned an invalid response
                $errors = $this->getCommonError('general');
            }
        } catch (\Stripe\Exception\AuthenticationException $exception) {
            if (!empty($exception->getJsonBody())) {
                // Don't use the actual error (as it may contain an API key, albeit invalid),
                // rather a general auth error
                $loggable_response = $exception->getJsonBody();
                $errors = [
                    $loggable_response['error']['type'] => [
                        'auth_error' => Language::_('StripePayments.!error.auth', true)
                    ]
                ];
            } else {
                // Gateway returned an invalid response
                $errors = $this->getCommonError('general');
            }
        } catch (Throwable $e) {
            // Any other exception, including Stripe_ApiError
            $errors = $this->getCommonError('general');
            $loggable_response = ['error' => $e->getMessage()];
        }

        // Set any errors
        if (!empty($errors)) {
            $this->Input->setErrors($detailed_errors ? $errors : $this->getCommonError('general'));
        }

        // Log the request
        $this->logRequest($log_url, $params, $loggable_response);

        if (empty($response)) {
            $response = (object) $loggable_response;
            $response->status = 'error';

            if (is_string($loggable_response['error'] ?? null)) {
                $response->error = (object) ['message' => $loggable_response['error']];
            }
        }

        return $response;
    }

    /**
     * {@inheritdoc}
     */
    public function removeCc($client_reference_id, $account_reference_id)
    {
        // Get the PaymentMethod from Stripe
        $card = $this->handleApiRequest(
            ['Stripe\PaymentMethod', 'retrieve'],
            [$account_reference_id],
            $this->base_url . 'payment_methods - retrieve'
        );

        if ($this->Input->errors()) {
            return false;
        }

        // Detach the PaymentMethod from it's associated Stripe customer
        $this->handleApiRequest(
            function ($card) {
                return $card->detach();
            },
            [$card],
            $this->base_url . 'payment_methods - detach'
        );

        if ($this->Input->errors()) {
            return false;
        }

        return ['client_reference_id' => $client_reference_id, 'reference_id' => $account_reference_id];
    }

    /**
     * {@inheritdoc}
     */
    public function processStoredCc(
        $client_reference_id,
        $account_reference_id,
        $amount, array
        $invoice_amounts = null,
        $customer_present = false
    )
    {
        // Set 3DS authentication method
        $this->meta['request_three_d_secure'] = $this->meta['request_three_d_secure'] ?? 'automatic';
        if ($this->meta['request_three_d_secure'] == 'frictionless') {
            $this->meta['request_three_d_secure'] = is_null($client_reference_id) ? 'challenge' : 'automatic';
        }

        if (isset($this->staff_id)) {
            $this->meta['request_three_d_secure'] = 'automatic';
        }

        // Charge the given PaymentMethod through Stripe
        $charge = [
            'amount' => $this->formatAmount($amount, ($this->currency ?? null)),
            'currency' => ($this->currency ?? null),
            'customer' => $client_reference_id,
            'payment_method' => $account_reference_id,
            'payment_method_options' => ['card' => ['request_three_d_secure' => $this->meta['request_three_d_secure']]],
            'automatic_payment_methods' => ['enabled' => true, 'allow_redirects' => 'never'],
            'description' => $this->getChargeDescription($invoice_amounts),
            'metadata' => ['invoices' => $this->serializeInvoices($invoice_amounts)],
            'confirm' => true,
            'off_session' => true
        ];

        if ($customer_present) {
            unset($charge['off_session']);
        } else {
            unset($charge['payment_method_options']);
        }

        $payment = $this->handleApiRequest(
            ['Stripe\PaymentIntent', 'create'],
            [$charge],
            $this->base_url . 'payment_intents - create'
        );
        $errors = $this->Input->errors();

        // Set whether there was an error
        $status = 'error';
        $payment = json_decode(json_encode($payment), true);
        if (isset($payment['error']) && (($payment['error']['code'] ?? null) === 'card_declined')) {
            $status = 'declined';
        } elseif (!isset($payment['error']) && empty($errors)) {
            switch ($payment['status'] ?? null) {
                case 'succeeded':
                    $status = 'approved';
                    break;
                case 'requires_action':
                case 'requires_confirmation':
                case 'processing':
                    // For India-issued cards with a registered eMandate, Stripe pauses here to send
                    // the customer a pre-debit notification (required above the AFA-exempt threshold)
                    // and waits for their approval. The webhook validate() handler reconciles the
                    // final outcome once Stripe resolves it
                    $status = 'pending';
                    break;
                default:
                    $message = $payment['error']['message'] ?? null;
            }
        } else {
            $message = $payment['error']['message'] ?? null;
        }

        return [
            'status' => $status,
            'reference_id' => $payment['id'] ?? null,
            'transaction_id' => $payment['latest_charge'] ?? null,
            'message' => ($message ?? null)
        ];
    }
    /**
     * Serializes an array of invoice info into a string.
     *
     * @param array A numerically indexed array invoices info including:
     *  - id The ID of the invoice
     *  - amount The amount relating to the invoice
     * @return string A serialized string of invoice info in the format of key1=value1|key2=value2
     */
    private function serializeInvoices(array $invoices)
    {
        $str = '';
        foreach ($invoices as $i => $invoice) {
            $str .= ($i > 0 ? '|' : '') . $invoice['invoice_id'] . '=' . $invoice['amount'];
        }

        return $str;
    }


    /**
     * Unserializes a string of invoice info into an array.
     *
     * @param string $invoices_string A serialized string of invoice info in the format of key1=value1|key2=value2
     * @return array A numerically indexed array invoices info including:
     *  - id The ID of the invoice
     *  - amount The amount relating to the invoice
     */
    private function parseInvoiceAmounts($invoices_string)
    {
        if (!isset($this->Invoices)) {
            Loader::loadModels($this, ['Invoices']);
        }


        $invoices = [];
        $temp = explode('|', $invoices_string);
        foreach ($temp as $pair) {
            $pairs = explode('=', $pair, 2);
            if (count($pairs) != 2) {
                continue;
            }
            $invoice_id = $pairs[0];
            $amount = $pairs[1];

            if (($invoice = $this->Invoices->get($invoice_id))) {
                $invoices[] = ['invoice_id' => $invoice_id, 'amount' => min($amount, $invoice->due)];
            }
        }

        return $invoices;
    }

    /**
     * {@inheritdoc}
     */
    public function authorizeStoredCc(
        $client_reference_id,
        $account_reference_id,
        $amount,
        array $invoice_amounts = null
    ) {
        // Set 3DS authentication method
        $this->meta['request_three_d_secure'] = $this->meta['request_three_d_secure'] ?? 'automatic';
        if ($this->meta['request_three_d_secure'] == 'frictionless') {
            $this->meta['request_three_d_secure'] = is_null($client_reference_id) ? 'challenge' : 'automatic';
        }

        if (isset($this->staff_id)) {
            $this->meta['request_three_d_secure'] = 'automatic';
        }

        // Create a PaymentIntent through Stripe
        $payment = [
            'amount' => $this->formatAmount($amount, ($this->currency ?? null)),
            'currency' => ($this->currency ?? null),
            'description' => $this->getChargeDescription($invoice_amounts),
            'metadata' => ['invoices' => $this->serializeInvoices($invoice_amounts)],
            'payment_method' => $account_reference_id,
            'payment_method_options' => ['card' => ['request_three_d_secure' => $this->meta['request_three_d_secure']]],
            'automatic_payment_methods' => ['enabled' => true, 'allow_redirects' => 'never'],
            'capture_method' => 'manual',
            'setup_future_usage' => 'off_session'
        ];
        if ($client_reference_id) {
            $payment['customer'] = $client_reference_id;
        }

        // Declare to Stripe the possibility of us creating a payment through this page
        $payment_intent = $this->handleApiRequest(
            ['Stripe\PaymentIntent', 'create'],
            [$payment],
            $this->base_url . 'payment_intents - create'
        );

        if ($this->Input->errors()) {
            return false;
        }

        $status = 'error';
        if (isset($payment_intent->status)) {
            switch ($payment_intent->status) {
                case 'requires_confirmation':
                case 'requires_action':
                case 'requires_source_action':
                case 'processing':
                    $status = 'pending';
                    break;
                case 'canceled':
                    $status = 'declined';
                    break;
                case 'succeeded':
                    $status = 'approved';
                    break;
                case 'requires_payment_method':
                case 'requires_source':
                default:
                    $message = isset($payment_intent->error) ? (isset($payment_intent->error->message) ? $payment_intent->error->message : null) : '';
            }
        }

        return [
            'status' => $status,
            'reference_id' => $payment_intent->id,
            'transaction_id' => null, // This should eventually be filled by the Charge ID
            'message' => (isset($message) ? $message : null)
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function captureStoredCc(
        $client_reference_id,
        $account_reference_id,
        $transaction_reference_id,
        $transaction_id,
        $amount,
        array $invoice_amounts = null
    )
    {
        $payment_intent = $this->handleApiRequest(
            ['Stripe\PaymentIntent', 'retrieve'],
            [$transaction_reference_id],
            $this->base_url . 'payment_intents - retrieve'
        );

        $latest_charge = $this->handleApiRequest(
            ['Stripe\Charge', 'retrieve'],
            [$payment_intent->latest_charge],
            $this->base_url . 'charge - retrieve'
        );
        if (!empty($latest_charge->failure_code)) {
            return [
                'status' => in_array(
                    $latest_charge->failure_code, ['card_declined', 'bank_account_declined']
                ) ? 'declined' : 'error',
                'reference_id' => ($payment_intent->id ?? null),
                'transaction_id' => ($payment_intent->latest_charge ?? null),
                'message' => $latest_charge->failure_message
            ];
        }

        $captured_payment_intent = $this->handleApiRequest(
            function ($payment_intent) {
                return $payment_intent->capture();
            },
            [$payment_intent],
            $this->base_url . 'payment_intent - capture'
        );

        $status = 'error';
        if (isset($captured_payment_intent->status)) {
            switch ($captured_payment_intent->status) {
                case 'requires_confirmation':
                case 'requires_action':
                case 'requires_source_action':
                case 'processing':
                    $status = 'pending';
                    break;
                case 'canceled':
                case 'requires_payment_method':
                    $status = 'declined';
                    break;
                case 'succeeded':
                    $status = 'approved';
                    break;
                case 'requires_source':
                default:
                    $message =
                        isset($captured_payment_intent->error) ? ($captured_payment_intent->error->message ?? null) :
                            '';
            }
        }

        // Set capture account on current session
        if ($status == 'approved') {
            $charge = json_decode(json_encode($latest_charge), true);
            $card = $charge['payment_method_details']['card'] ?? [];

            $account = [
                'last4' => ($card['last4'] ?? null),
                'expiration' => ($card['exp_year'] ?? null)
                    . str_pad(($card['exp_month'] ?? null), 2, 0, STR_PAD_LEFT),
                'type' => $this->mapCardType(($card['brand'] ?? null))
            ];

            Loader::loadComponents($this, ['Session']);
            $this->Session->write('capture_cc_account', $account);
        }

        return [
            'status' => $status,
            'reference_id' => ($captured_payment_intent->error->payment_intent->id ?? $captured_payment_intent->id ?? null),
            'transaction_id' => ($captured_payment_intent->latest_charge ?? null),
            'message' => ($message ?? null),
            'invoices' => $this->parseInvoiceAmounts($captured_payment_intent->metadata->invoices ?? '')
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function voidStoredCc(
        $client_reference_id,
        $account_reference_id,
        $transaction_reference_id,
        $transaction_id
    ) {
        // Void or refund a previous charge
        $response = $this->voidTransaction($transaction_reference_id, $transaction_id);

        // Operation must be successful
        if ($this->Input->errors()) {
            return;
        }

        // Set status to void
        $response['status'] = 'void';
        return $response;
    }

    /**
     * {@inheritdoc}
     */
    public function refundStoredCc(
        $client_reference_id,
        $account_reference_id,
        $transaction_reference_id,
        $transaction_id,
        $amount
    ) {
        // Return formatted response
        return $this->refundCc($transaction_reference_id, $transaction_id, $amount);
    }

    /**
     * {@inheritdoc}
     */
    public function requiresCcStorage()
    {
        return true;
    }

    /**
     * Retrieves identifying information about this gateway to register with Stripe
     * on both server-side and client-side (Stripe.js) requests
     *
     * @return array An array containing the application name, version, and url
     */
    private function getAppInfo()
    {
        return [
            'name' => 'Blesta ' . $this->getName(),
            'version' => $this->getVersion(),
            'url' => 'https://blesta.com'
        ];
    }

    /**
     * Loads the API if not already loaded
     */
    private function loadApi()
    {
        Loader::load(dirname(__FILE__) . DS . 'vendor' . DS . 'stripe' . DS . 'stripe-php' . DS . 'init.php');
        Stripe\Stripe::setApiKey((isset($this->meta['secret_key']) ? $this->meta['secret_key'] : null));

        // Include identifying information about this being a gateway for Blesta
        $app_info = $this->getAppInfo();
        Stripe\Stripe::setAppInfo($app_info['name'], $app_info['version'], $app_info['url']);

        // Set API version
        Stripe\Stripe::setApiVersion('2023-10-16');
    }

    /**
     * Log the request
     *
     * @param string $url The URL of the API request to log
     * @param array The input parameters sent to the gateway
     * @param array The response from the gateway
     */
    private function logRequest($url, array $params, array $response)
    {
        // Define all fields to mask when logging
        $mask_fields = [
            'number', // CC number
            'exp_month',
            'exp_year',
            'cvc'
        ];

        // Determine success or failure for the response
        $success = false;
        if (!(($errors = $this->Input->errors()) || isset($response['error']))) {
            $success = true;
        }

        // Log data sent to the gateway
        $this->log(
            $url,
            serialize($params),
            'input',
            (isset($params['error']) ? false : true)
        );

        // Log response from the gateway
        $this->log($url, serialize($this->maskDataRecursive($response, $mask_fields)), 'output', $success);
    }

    /**
     * Casts multi-dimensional objects to arrays
     *
     * @param mixed $object An object
     * @return array All objects cast to array
     */
    private function objectToArray($object)
    {
        if (is_object($object)) {
            $object = get_object_vars($object);
        }

        // Recurse over object to convert all object keys in $object to array
        if (is_array($object)) {
            return array_map([$this, __FUNCTION__], $object);
        }

        return $object;
    }

    /**
     * Convert amount between decimal value and integer representation of cents
     *
     * @param float $amount
     * @param string $currency
     * @param string $direction 'to' converts dollars to cents, 'from' converts cents to dollars
     * @return int|float The amount in cents (int) when direction is 'to', or dollars (float) when 'from'
     */
    private function formatAmount($amount, $currency, $direction = 'to')
    {
        $non_decimal_currencies = ['BIF', 'CLP', 'DJF', 'GNF', 'JPY',
            'KMF', 'KRW', 'MGA', 'PYG', 'RWF', 'VUV', 'XAF', 'XOF', 'XPF'];

        if (is_numeric($amount) && !in_array($currency, $non_decimal_currencies)) {
            if ($direction == 'to') {
                $amount *= 100;
            } else {
                $amount /= 100;
            }
        }

        return $direction == 'to' ? (int) round($amount) : round($amount, 2);
    }

    /**
     * Converts the card type from Stripe to the equivalent in Blesta
     *
     * @param string $stripe_card_type The card type from Stripe
     * @return string The card type for Blesta
     */
    private function mapCardType($stripe_card_type)
    {
        $card_type_map = [
            'amex' => 'amex',
            'diners' => 'dc-int',
            'discover' => 'disc',
            'jcb' => 'jcb',
            'mastercard' => 'mc',
            'unionpay' => 'cup',
            'visa' => 'visa',
            'unknown' => 'other'
        ];

        return array_key_exists($stripe_card_type, $card_type_map) ? $card_type_map[$stripe_card_type] : 'other';
    }

    /**
     * Checks whether a key can be used to connect to the Stripe API
     *
     * @param string $secret_key The API to connect with
     * @return boolean True if a successful API call was made, false otherwise
     */
    public function validateConnection($secret_key)
    {
        $success = true;
        try {
            // Attempt to make an API request
            Loader::load(dirname(__FILE__) . DS . 'vendor' . DS . 'stripe' . DS . 'stripe-php' . DS . 'init.php');
            Stripe\Stripe::setApiKey($secret_key);
            Stripe\Balance::retrieve();
        } catch (Exception $e) {
            $success = false;
        }

        return $success;
    }

    /**
     * Retrieves the description for CC charges
     *
     * @param array|null $invoice_amounts An array of invoice amounts (optional)
     * @return string The charge description
     */
    private function getChargeDescription(array $invoice_amounts = null)
    {
        // No invoice amounts, set a default description
        if (empty($invoice_amounts)) {
            return Language::_('StripePayments.charge_description_default', true);
        }

        Loader::loadModels($this, ['Invoices']);
        Loader::loadComponents($this, ['DataStructure']);
        $string = $this->DataStructure->create('string');

        // Create a list of invoices being paid
        $id_codes = [];
        foreach ($invoice_amounts as $invoice_amount) {
            if (($invoice = $this->Invoices->get($invoice_amount['invoice_id']))) {
                $id_codes[] = $invoice->id_code;
            }
        }

        // Use the default description if there are no valid invoices
        if (empty($id_codes)) {
            return Language::_('StripePayments.charge_description_default', true);
        }

        // Truncate the description to a max of 1000 characters since that is Stripe's limit for the description field
        $description = Language::_('StripePayments.charge_description', true, implode(', ', $id_codes));
        if (strlen($description) > 1000) {
            $description = $string->truncate($description, ['length' => 997]) . '...';
        }

        return $description;
    }

    /**
     * {@inheritdoc}
     */
    public function buildAchForm($account_info = null)
    {
        // Load the view into this object, so helpers can be automatically added to the view
        $this->view = $this->makeView(
            'ach_form',
            'default',
            str_replace(ROOTWEBDIR, '', dirname(__FILE__) . DS)
        );

        // Load the models and helpers required for this view
        Loader::loadModels($this, ['Accounts', 'Companies']);
        Loader::loadHelpers($this, ['Form', 'Html']);

        // An account already on file means this is an edit, where the stored bank account is
        // shown instead of the collection flow until the customer asks to replace it
        $account_info = (array) $account_info;
        $editing = !empty($account_info['reference_id']);

        // A bank account held as a legacy source cannot be carried forward, so the customer is
        // asked to connect it again rather than being offered the option to keep it
        $legacy = $editing && !$this->isPaymentMethod($account_info['reference_id']);

        // Declare to Stripe the possibility of us creating a us_bank_account PaymentMethod through this
        // page. The view collects the account with stripe.collectBankAccountForSetup() and completes it
        // with stripe.confirmUsBankAccountSetup(), both of which need this SetupIntent's client secret.
        // Omitting payment_method_options.us_bank_account.verification_method leaves Stripe on its
        // default, which offers instant verification with manual entry and microdeposits as a fallback
        $params = [
            'payment_method_types' => ['us_bank_account'],
            'payment_method_options' => [
                'us_bank_account' => [
                    'financial_connections' => ['permissions' => ['payment_method']]
                ]
            ]
        ];

        // Attach the SetupIntent to a Stripe Customer so the resulting PaymentMethod is saved against it
        // once the setup succeeds. Without a Customer there is nothing for Stripe to attach the account to
        $customer_id = $this->getClientCustomerId();
        if ($customer_id) {
            $params['customer'] = $customer_id;
            $params['usage'] = 'off_session';
        }

        $setup_intent = $this->handleApiRequest(
            ['Stripe\SetupIntent', 'create'],
            [$params],
            $this->base_url . 'setup_intents - create'
        );

        // Check if Stripe.js is already loaded
        $load_stripe = false;
        if (!$this->global('stripe_js')) {
            $this->global('stripe_js', true);
            $load_stripe = true;
        }

        $this->view->set('load_stripe', $load_stripe);
        $this->view->set('setup_intent', $setup_intent);
        $this->view->set('meta', $this->meta);
        $this->view->set('app_info', $this->getAppInfo());
        $this->view->set('billing_email', $this->getClientEmail());
        $this->view->set('editing', $editing);
        $this->view->set('legacy', $legacy);
        $this->view->set('account_info', $account_info);
        $this->view->set('types', $this->Accounts->getAchTypes());
        $this->view->set('company', $this->Companies->get(Configure::get('Blesta.company_id')));

        return $this->view->fetch();
    }

    /**
     * Fetches the email address of the current client's primary contact. Stripe requires a billing
     * email on ACH Direct Debit PaymentMethods in order to send the mandate confirmation and, where
     * needed, the microdeposit notification
     *
     * @return string The client's primary contact email, or an empty string if unavailable
     */
    private function getClientEmail()
    {
        if (empty($this->client_id)) {
            return '';
        }

        Loader::loadComponents($this, ['Record']);

        $contact = $this->Record->select(['email'])
            ->from('contacts')
            ->where('client_id', '=', $this->client_id)
            ->where('contact_type', '=', 'primary')
            ->fetch();

        return $contact->email ?? '';
    }

    /**
     * {@inheritdoc}
     */
    public function requiresAchStorage()
    {
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function getAchAccountStatus($client_reference_id, $account_reference_id)
    {
        if (empty($account_reference_id)) {
            return null;
        }

        // A bank account collected as a us_bank_account PaymentMethod is verified through its
        // SetupIntent, and is only attached to a Customer once that verification succeeds, so
        // either signal is proof the account is usable for debits
        if ($this->isPaymentMethod($account_reference_id)) {
            $account = $this->handleApiRequest(
                ['Stripe\PaymentMethod', 'retrieve'],
                [$account_reference_id],
                $this->base_url . 'payment_methods - retrieve'
            );

            // The status cannot be determined when the PaymentMethod could not be fetched. Discard
            // the error so the caller keeps the status it already has rather than failing
            if ($this->Input->errors()) {
                $this->Input->setErrors([]);

                return null;
            }

            if (!empty($account->customer)) {
                return 'active';
            }

            $intent_status = $this->getSetupIntentStatus($account_reference_id);

            if ($this->Input->errors()) {
                $this->Input->setErrors([]);

                return null;
            }

            return ($intent_status === null ? null : ($intent_status === 'succeeded' ? 'active' : 'unverified'));
        }

        // Bank account stored through Stripe's deprecated Sources API
        if (empty($client_reference_id)) {
            return null;
        }

        $account = $this->handleApiRequest(
            ['Stripe\Customer', 'retrieveSource'],
            [$client_reference_id, $account_reference_id],
            $this->base_url . 'customers - retrieveSource'
        );

        // The status cannot be determined when the bank account could not be fetched. Discard the
        // error so the caller keeps the status it already has rather than treating this as a failure
        if ($this->Input->errors()) {
            $this->Input->setErrors([]);

            return null;
        }

        return $this->getAchStatus($account);
    }

    /**
     * Determines whether the given Stripe bank account has completed micro-deposit verification.
     * Stripe only considers a bank account usable for debits once its status is "verified", every
     * other status ("new", "validated", "verification_failed", "errored") still requires verification
     *
     * @param mixed $account The Stripe bank account object, or the error response from a failed request
     * @return bool True if the bank account has been verified with Stripe, false otherwise
     */
    private function achVerified($account)
    {
        return ($account->status ?? null) === Stripe\BankAccount::STATUS_VERIFIED;
    }

    /**
     * Maps the verification status of the given Stripe bank account to the payment account status
     * used by Blesta, so the locally stored status always mirrors Stripe
     *
     * @param mixed $account The Stripe bank account object, or the error response from a failed request
     * @return string The payment account status, either "active" or "unverified"
     */
    private function getAchStatus($account)
    {
        return $this->achVerified($account) ? 'active' : 'unverified';
    }

    /**
     * {@inheritdoc}
     */
    public function buildAchVerificationForm(array $vars = [])
    {
        // Load the view into this object, so helpers can be automatically added to the view
        $this->view = $this->makeView(
            'ach_verification_form',
            'default',
            str_replace(ROOTWEBDIR, '', dirname(__FILE__) . DS)
        );

        // Load the helpers required for this view
        Loader::loadHelpers($this, ['Form', 'Html']);

        $this->view->set('vars', (object) $vars);

        return $this->view->fetch();
    }

    /**
     * {@inheritdoc}
     */
    public function processAch(array $account_info, $amount, array $invoice_amounts = null)
    {
        $this->Input->setErrors($this->getCommonError('unsupported'));
    }

    /**
     * {@inheritdoc}
     */
    public function voidAch($reference_id, $transaction_id)
    {
        $this->Input->setErrors($this->getCommonError('unsupported'));
    }

    /**
     * {@inheritdoc}
     */
    public function refundAch($reference_id, $transaction_id, $amount)
    {
        return $this->refundTransaction($reference_id, $transaction_id, $amount);
    }

    /**
     * {@inheritdoc}
     */
    public function storeAch(array $account_info, array $contact, $client_reference_id = null)
    {
        $reference_id = ($account_info['reference_id'] ?? null);

        // Get the us_bank_account PaymentMethod created by the SetupIntent in the ACH form
        $account = $this->handleApiRequest(
            ['Stripe\PaymentMethod', 'retrieve'],
            [$reference_id],
            $this->base_url . 'payment_methods - retrieve'
        );

        if ($this->Input->errors()) {
            return false;
        }

        // An account that still needs microdeposit verification cannot be attached to a customer yet,
        // so the SetupIntent's status decides both what to do about a failed attach below and what
        // status to report back to Blesta
        $intent = $this->getSetupIntent($reference_id);
        $verified = (($intent->status ?? null) === 'succeeded');

        // This lookup is informational. Failing it must not fail the whole store, and reporting the
        // account as unverified is the safe default because verification can still be completed
        $this->Input->setErrors([]);

        // A SetupIntent created against a Customer attaches the PaymentMethod itself once the setup
        // succeeds, so prefer whichever Customer Stripe already associated with it. While
        // microdeposits are pending the PaymentMethod is not attached yet, so fall back to the
        // Customer the SetupIntent will attach it to once verification completes
        $customer_id = ($account->customer ?? null) ?: ($intent->customer ?? null);

        // The reference ID is posted from the browser, so the Customer Stripe associates with it must
        // belong to this client. Without this check a crafted PaymentMethod ID could graft another
        // customer's bank account onto this client and debit it through processStoredAch()
        if (!empty($customer_id)
            && !$this->isClientCustomer(($contact['client_id'] ?? null), $customer_id, $client_reference_id)
        ) {
            $this->Input->setErrors(
                ['store' => ['error' => Language::_('StripePayments.!error.account_customer_mismatch', true)]]
            );

            return false;
        }

        $customer_id = $customer_id ?: $client_reference_id;

        if (empty($customer_id)) {
            // No customer on record for this client yet, create one from the billing contact
            $fields = [
                'email' => ($contact['email'] ?? null),
                'name' => (!empty($contact['first_name']) && !empty($contact['last_name']) ?
                    ($contact['first_name'] ?? null) . ' ' . ($contact['last_name'] ?? null) : '')
            ];
            if (!empty($contact['address1'])) {
                $fields['address'] = [
                    'line1' => ($contact['address1'] ?? null),
                    'line2' => ($contact['address2'] ?? null),
                    'city' => ($contact['city'] ?? null),
                    'state' => ($contact['state'] ?? null),
                    'country' => ($contact['country'] ?? null),
                    'postal_code' => ($contact['zip'] ?? null)
                ];
            }

            $customer = $this->handleApiRequest(
                ['Stripe\Customer', 'create'],
                [$fields],
                $this->base_url . 'customers - create'
            );

            if ($this->Input->errors()) {
                return false;
            }

            $customer_id = ($customer->id ?? null);
        }

        if (empty($customer_id)) {
            $this->Input->setErrors($this->getCommonError('general'));

            return false;
        }

        // Attach the PaymentMethod unless the SetupIntent already did so
        if (empty($account->customer)) {
            $this->handleApiRequest(
                function ($customer_id, $account) {
                    return $account->attach(['customer' => $customer_id]);
                },
                [$customer_id, $account],
                $this->base_url . 'payment_methods - attach'
            );

            if ($this->Input->errors()) {
                if ($verified) {
                    return false;
                }

                // Expected while microdeposits are pending. verifyAch() attaches the account once
                // the customer confirms the deposit and the SetupIntent succeeds
                $this->Input->setErrors([]);
            }
        }

        // Return the reference IDs and bank account information. The status tells Blesta whether the
        // account still needs microdeposit verification; instantly verified accounts are usable now
        return [
            'client_reference_id' => $customer_id,
            'reference_id' => $reference_id,
            'last4' => ($account->us_bank_account->last4 ?? null),
            'type' => ($account->us_bank_account->account_type ?? 'checking'),
            'status' => ($verified ? 'active' : 'unverified')
        ];
    }

    /**
     * Fetches the SetupIntent that collected the given PaymentMethod
     *
     * @param string $payment_method_id The ID of the us_bank_account PaymentMethod
     * @return Stripe\SetupIntent|null The most recent SetupIntent for this PaymentMethod, if any
     */
    private function getSetupIntent($payment_method_id)
    {
        $intents = $this->handleApiRequest(
            ['Stripe\SetupIntent', 'all'],
            [['payment_method' => $payment_method_id, 'limit' => 1]],
            $this->base_url . 'setup_intents - all'
        );

        return $intents->data[0] ?? null;
    }

    /**
     * Fetches the status of the SetupIntent that collected the given PaymentMethod
     *
     * @param string $payment_method_id The ID of the us_bank_account PaymentMethod
     * @return string|null The SetupIntent status, or null if no SetupIntent was found
     */
    private function getSetupIntentStatus($payment_method_id)
    {
        $intent = $this->getSetupIntent($payment_method_id);

        return $intent->status ?? null;
    }

    /**
     * {@inheritdoc}
     */
    public function updateAch(array $account_info, array $contact, $client_reference_id, $account_reference_id)
    {
        // No new bank account was collected when the reference ID is missing, or is still the ID of
        // the bank account already stored off site, so the customer is only changing their contact
        // details. Keep the account already on file rather than asking them to re-enter it
        $reference_id = ($account_info['reference_id'] ?? null);
        if (empty($reference_id) || $reference_id == $account_reference_id) {
            return $this->refreshStoredAch($account_info, $contact, $client_reference_id, $account_reference_id);
        }

        // Add a new bank account to the same client
        $account_data = $this->storeAch($account_info, $contact, $client_reference_id);

        if ($this->Input->errors()) {
            return false;
        }

        // Remove the old payment account if possible
        if (false === $this->removeAch($client_reference_id, $account_reference_id)) {
            // Ignore any errors caused by attempting to remove the old account
            $this->Input->setErrors([]);
        }

        return $account_data;
    }

    /**
     * Updates the Stripe Customer's contact details while leaving the bank account already on
     * file in place. Used when a payment account is edited without collecting a new one
     *
     * @param array $account_info An array of bank account info
     * @param array $contact An array of contact information for the billing contact
     * @param string $client_reference_id The reference ID for the client on the remote gateway
     * @param string $account_reference_id The reference ID for the stored account on the remote gateway
     * @return mixed False on failure or an array containing:
     *
     *  - client_reference_id The reference ID for this client
     *  - reference_id The reference ID for this payment account
     *  - last4 The last 4 digits of the account number
     *  - type The bank account type
     *  - status Whether the account is usable or still awaiting verification
     */
    private function refreshStoredAch(
        array $account_info,
        array $contact,
        $client_reference_id,
        $account_reference_id
    ) {
        if (empty($account_reference_id)) {
            $this->Input->setErrors($this->getCommonError('general'));

            return false;
        }

        // Keep the Customer's billing details in step with the contact
        if ($client_reference_id) {
            $fields = [
                'email' => ($contact['email'] ?? null),
                'name' => (!empty($contact['first_name']) && !empty($contact['last_name']) ?
                    ($contact['first_name'] ?? null) . ' ' . ($contact['last_name'] ?? null) : '')
            ];
            if (!empty($contact['address1'])) {
                $fields['address'] = [
                    'line1' => ($contact['address1'] ?? null),
                    'line2' => ($contact['address2'] ?? null),
                    'city' => ($contact['city'] ?? null),
                    'state' => ($contact['state'] ?? null),
                    'country' => ($contact['country'] ?? null),
                    'postal_code' => ($contact['zip'] ?? null)
                ];
            }

            $this->handleApiRequest(
                ['Stripe\Customer', 'update'],
                [$client_reference_id, $fields],
                $this->base_url . 'customers - update'
            );

            // Failing to mirror the address must not stop Blesta recording the contact change
            $this->Input->setErrors([]);
        }

        // Re-read the account so the details Blesta stores stay authoritative. Blesta overwrites
        // last4 with whatever is returned here, so it has to come back even though it is unchanged
        $last4 = ($account_info['last4'] ?? null);
        $type = ($account_info['type'] ?? null);

        if ($this->isPaymentMethod($account_reference_id)) {
            $account = $this->handleApiRequest(
                ['Stripe\PaymentMethod', 'retrieve'],
                [$account_reference_id],
                $this->base_url . 'payment_methods - retrieve'
            );

            $last4 = ($account->us_bank_account->last4 ?? $last4);
            $type = ($account->us_bank_account->account_type ?? $type);
            $verified = ($this->getSetupIntentStatus($account_reference_id) === 'succeeded');
        } else {
            // Bank account stored through Stripe's deprecated Sources API
            $account = $this->handleApiRequest(
                ['Stripe\Customer', 'retrieveSource'],
                [$client_reference_id, $account_reference_id],
                $this->base_url . 'customers - retrieveSource'
            );

            $last4 = ($account->last4 ?? $last4);
            $verified = $this->achVerified($account);
        }

        if ($this->Input->errors()) {
            return false;
        }

        return [
            'client_reference_id' => $client_reference_id,
            'reference_id' => $account_reference_id,
            'last4' => $last4,
            'type' => $type,
            'status' => ($verified ? 'active' : 'unverified')
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function verifyAch(array $vars, $client_reference_id = null, $account_reference_id = null)
    {
        if (!$this->isPaymentMethod($account_reference_id)) {
            return $this->verifyLegacyAch($vars, $client_reference_id, $account_reference_id);
        }

        $intent = $this->getSetupIntent($account_reference_id);

        if ($this->Input->errors()) {
            return false;
        }

        if (empty($intent)) {
            $this->Input->setErrors(
                ['verify' => ['error' => Language::_('StripePayments.!error.setup_intent_missing', true)]]
            );

            return false;
        }

        // Nothing to do if the account was already verified, otherwise submit whichever of the two
        // microdeposit proofs the customer was given. Stripe sends a descriptor code by default and
        // only falls back to a pair of amounts, so the code takes precedence when both are present
        if (($intent->status ?? null) !== 'succeeded') {
            $params = !empty($vars['descriptor_code'])
                ? ['descriptor_code' => trim($vars['descriptor_code'])]
                : ['amounts' => [(int) ($vars['first_deposit'] ?? 0), (int) ($vars['second_deposit'] ?? 0)]];

            $intent = $this->handleApiRequest(
                function ($intent, $params) {
                    return $intent->verifyMicrodeposits($params);
                },
                [$intent, $params],
                $this->base_url . 'setup_intents - verify_microdeposits',
                true
            );

            if ($this->Input->errors()) {
                return false;
            }
        }

        if (($intent->status ?? null) !== 'succeeded') {
            $this->Input->setErrors(
                ['verify' => ['error' => Language::_('StripePayments.!error.verification_incomplete', true)]]
            );

            return false;
        }

        // The PaymentMethod could not be attached while the account was unverified, so attach it now
        $account = $this->handleApiRequest(
            ['Stripe\PaymentMethod', 'retrieve'],
            [$account_reference_id],
            $this->base_url . 'payment_methods - retrieve'
        );

        if ($this->Input->errors()) {
            return false;
        }

        // Never report success for a PaymentMethod that Stripe attached to a Customer other than
        // the one on record for this client, which would mark another customer's bank account as
        // verified and usable on this client
        if (!empty($account->customer) && $account->customer != $client_reference_id) {
            $this->Input->setErrors(
                ['verify' => ['error' => Language::_('StripePayments.!error.account_customer_mismatch', true)]]
            );

            return false;
        }

        if (empty($account->customer) && $client_reference_id) {
            $this->handleApiRequest(
                function ($customer_id, $account) {
                    return $account->attach(['customer' => $customer_id]);
                },
                [$client_reference_id, $account],
                $this->base_url . 'payment_methods - attach'
            );

            if ($this->Input->errors()) {
                return false;
            }
        }

        return [
            'client_reference_id' => $client_reference_id,
            'reference_id' => $account_reference_id,
            'status' => 'active'
        ];
    }

    /**
     * Verifies a bank account stored through Stripe's deprecated Sources API. Retained so accounts
     * created before the PaymentMethod flow can still complete verification
     *
     * @param array $vars An array including:
     *
     *  - first_deposit The first deposit amount
     *  - second_deposit The second deposit amount
     * @param string $client_reference_id The reference ID for the client on the remote gateway
     * @param string $account_reference_id The reference ID for the stored account on the remote gateway
     * @return mixed False on failure or an array containing:
     *
     *  - client_reference_id The reference ID for this client
     *  - reference_id The reference ID for this payment account
     *  - status The verification status of the account
     */
    private function verifyLegacyAch(array $vars, $client_reference_id = null, $account_reference_id = null)
    {
        // Get bank account
        $account = $this->handleApiRequest(
            ['Stripe\Customer', 'retrieveSource'],
            [$client_reference_id, $account_reference_id],
            $this->base_url . 'customers - retrieveSource'
        );

        if ($this->Input->errors()) {
            return false;
        }

        // The deposit amounts can only be submitted for a bank account belonging to this customer.
        // Never report success without confirming the ownership of the account
        if (($account->customer ?? null) != $client_reference_id) {
            $this->Input->setErrors([
                'verify' => ['invalid' => Language::_('StripePayments.!error.ach.invalid_account', true)]
            ]);

            return false;
        }

        // Verify bank account, an account already verified with Stripe requires no further action
        if (!$this->achVerified($account)) {
            try {
                // Stripe expects the deposit amounts in cents and refreshes the account in place
                $account->verify(
                    ['amounts' => [(int) ($vars['first_deposit'] ?? 0), (int) ($vars['second_deposit'] ?? 0)]]
                );
            } catch (Throwable $e) {
                $this->Input->setErrors(['verify' => ['error' => $e->getMessage()]]);

                return false;
            }
        }

        // Only report success when Stripe considers the bank account verified, otherwise the account
        // would be marked active locally while it remains unusable for debits
        if (!$this->achVerified($account)) {
            $this->Input->setErrors([
                'verify' => ['unverified' => Language::_('StripePayments.!error.ach.unverified', true)]
            ]);

            return false;
        }

        return [
            'client_reference_id' => $client_reference_id,
            'reference_id' => $account_reference_id,
            'status' => 'active'
        ];
    }

    /**
     * Determines whether a stored payment account reference is a PaymentMethod rather than a
     * bank account stored through Stripe's deprecated Sources API
     *
     * @param string $account_reference_id The reference ID for the stored account on the remote gateway
     * @return bool True if the reference is a PaymentMethod
     */
    private function isPaymentMethod($account_reference_id)
    {
        return strpos((string) $account_reference_id, 'pm_') === 0;
    }

    /**
     * {@inheritdoc}
     */
    public function removeAch($client_reference_id, $account_reference_id)
    {
        if ($this->isPaymentMethod($account_reference_id)) {
            // Get the PaymentMethod from Stripe
            $account = $this->handleApiRequest(
                ['Stripe\PaymentMethod', 'retrieve'],
                [$account_reference_id],
                $this->base_url . 'payment_methods - retrieve'
            );

            if ($this->Input->errors()) {
                return false;
            }

            // Detach the PaymentMethod from its associated Stripe customer
            $this->handleApiRequest(
                function ($account) {
                    return $account->detach();
                },
                [$account],
                $this->base_url . 'payment_methods - detach'
            );
        } else {
            // Bank account stored through Stripe's deprecated Sources API
            $this->handleApiRequest(
                ['Stripe\Customer', 'deleteSource'],
                [$client_reference_id, $account_reference_id],
                $this->base_url . 'customers - deleteSource'
            );
        }

        if ($this->Input->errors()) {
            return false;
        }

        return [
            'client_reference_id' => $client_reference_id,
            'reference_id' => $account_reference_id
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function processStoredAch(
        $client_reference_id,
        $account_reference_id,
        $amount,
        array $invoice_amounts = null
    )
    {
        // Charge the given PaymentMethod through Stripe using Payment Intents API
        $charge = [
            'amount' => $this->formatAmount($amount, ($this->currency ?? null)),
            'currency' => ($this->currency ?? null),
            'customer' => $client_reference_id,
            'payment_method' => $account_reference_id,
            'payment_method_types' => ['us_bank_account'],
            'description' => $this->getChargeDescription($invoice_amounts),
            'metadata' => ['invoices' => $this->serializeInvoices($invoice_amounts)],
            'confirm' => true,
            'off_session' => true,
            'mandate_data' => [
                'customer_acceptance' => [
                    'type' => 'offline'
                ]
            ]
        ];

        $payment = $this->handleApiRequest(
            ['Stripe\PaymentIntent', 'create'],
            [$charge],
            $this->base_url . 'payment_intents - create'
        );
        $errors = $this->Input->errors();

        if ($errors) {
            return false;
        }

        // Convert to array for consistent access
        $payment = json_decode(json_encode($payment), true);

        // Set whether there was an error
        $status = 'error';
        if (isset($payment['error']) && (($payment['error']['code'] ?? null) === 'card_declined')) {
            $status = 'declined';
        } elseif ((!isset($payment['error'])) && empty($errors)) {
            // Map PaymentIntent statuses to Blesta statuses
            switch ($payment['status'] ?? null) {
                case 'succeeded':
                    $status = 'approved';
                    break;
                case 'processing':
                    // ACH payments go through 'processing' state during bank clearing (2-5 days).
                    // Optimistically approve so the invoice closes and cron doesn't re-charge.
                    // If the bank transfer ultimately fails, the webhook will update to declined.
                    $status = 'approved';
                    break;
                case 'requires_action':
                case 'requires_confirmation':
                case 'requires_capture':
                    $status = 'pending';
                    break;
                case 'canceled':
                case 'requires_payment_method':
                    $status = 'declined';
                    break;
                default:
                    // Unknown status, treat as error
                    $message = $payment['error']['message'] ?? 'Unknown payment status: ' . ($payment['status'] ?? 'none');
                    break;
            }
        } else {
            $message = $payment['error']['message'] ?? null;
        }

        return [
            'status' => $status,
            'reference_id' => null,
            'transaction_id' => $payment['latest_charge'] ?? null,
            'message' => ($message ?? null)
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function voidStoredAch(
        $client_reference_id,
        $account_reference_id,
        $transaction_reference_id,
        $transaction_id
    )
    {
        $this->Input->setErrors($this->getCommonError('unsupported'));
    }

    /**
     * {@inheritdoc}
     */
    public function refundStoredAch(
        $client_reference_id,
        $account_reference_id,
        $transaction_reference_id,
        $transaction_id,
        $amount
    )
    {
        return $this->refundTransaction($transaction_reference_id, $transaction_id, $amount);
    }

    /**
     * Formats an error message returned by the API
     *
     * @param array $loggable_response A key/value array containing:
     *
     *  - code The error code
     *  - message The error message
     *  - type The type of the error
     * @return string The formatted error message
     */
    private function formatErrorMessage($loggable_response)
    {
        // Check if a language definition exists for this error message
        $lang = Language::_(
            'StripePayments.!error.' . ($loggable_response['code'] ?? $loggable_response['type'] ?? ''),
            true
        );

        if (!empty($lang)) {
            return $lang;
        }

        // If the message contains a URL to Stripe, remove it
        $message_lines = explode('. ', str_replace("\n", '. ', $loggable_response['message']));
        foreach ($message_lines as $line => $message) {
            if (str_contains($message, 'stripe.com')) {
                unset($message_lines[$line]);
            }
        }
        $loggable_response['message'] = trim(implode('. ', $message_lines), '.') . '.';

        return $loggable_response['message'];
    }

    /**
     * Defines or retrieves a global variable
     *
     * @param string $key The name of the global variable
     * @param string $value The value of the global variable (optional)
     * @return mixed The value of the global variable, null if undefined
     */
    private function global($key, $value = null)
    {
        $class = Loader::toCamelCase(get_class($this));
        $key = $class . '.' . $key;

        if (is_null($value)) {
            return $GLOBALS[$key] ?? null;
        } else {
            $GLOBALS[$key] = $value;
        }
    }

    /**
     * Validates the incoming POST/GET response from the gateway to ensure it is
     * legitimate and can be trusted.
     *
     * @param array $get The GET data for this request
     * @param array $post The POST data for this request
     * @return array An array of transaction data, sets any errors using Input if the data fails to validate
     *  - client_id The ID of the client that attempted the payment
     *  - amount The amount of the payment
     *  - currency The currency of the payment
     *  - invoices An array of invoices and the amount the payment should be applied to (if any) including:
     *      - id The ID of the invoice to apply to
     *      - amount The amount to apply to the invoice
     *  - status The status of the transaction (approved, declined, void, pending, reconciled, refunded, returned)
     *  - reference_id The reference ID for gateway-only use with this transaction (optional)
     *  - transaction_id The ID returned by the gateway to identify this transaction
     *  - parent_transaction_id The ID returned by the gateway to identify this
     *      transaction's original transaction (in the case of refunds)
     */
    public function validate(array $get, array $post)
    {
        // Get event payload
        $payload = @file_get_contents('php://input');
        if (!empty($payload)) {
            $payload = json_decode($payload);
        } else {
            $payload = (object) [];
        }

        // Validate only charge and payment intent events
        $object_type = $payload->data->object->object ?? null;
        if (!in_array($object_type, ['charge', 'payment_intent'])) {
            return false;
        }

        // Fetch client
        Loader::loadComponents($this, ['Record']);

        // Extract identifiers to correlate this event with a locally stored transaction. For
        // payment_intent events this is the resulting Charge ID (once one exists) and the
        // PaymentIntent's own ID; the latter matches the reference_id stored for India eMandate
        // charges still pending customer approval on the pre-debit notification, which have no
        // Charge yet. For charge events this is the Charge's own ID and the PaymentIntent it belongs to
        if ($object_type === 'payment_intent') {
            $charge_id = $payload->data->object->latest_charge ?? null;
            $payment_intent_id = $payload->data->object->id ?? null;
        } else {
            $charge_id = $payload->data->object->id ?? null;
            $payment_intent_id = $payload->data->object->payment_intent ?? null;
        }

        $identifiers = array_filter([$charge_id, $payment_intent_id]);
        if (empty($identifiers)) {
            return false;
        }

        $transaction = $this->Record->select()
            ->from('transactions')
                ->open()
                    ->where('transactions.transaction_id', 'in', $identifiers)
                    ->orWhere('transactions.reference_id', 'in', $identifiers)
                ->close()
            ->fetch();

        $latest_charge = null;
        if (!empty($charge_id)) {
            $latest_charge = $this->handleApiRequest(
                ['Stripe\Charge', 'retrieve'],
                [$charge_id],
                $this->base_url . 'charge - retrieve'
            );
        }

        if (empty($transaction->client_id)) {
            return false;
        }

        // Get event status
        $status = 'error';
        $stripe_status = $latest_charge->status ?? $payload->data->object->status ?? 'failed';

        // Check if charge was refunded before mapping status
        if ($latest_charge->refunded ?? $payload->data->object->refunded ?? false) {
            $status = 'refunded';
        } elseif (isset($stripe_status)) {
            switch ($stripe_status) {
                case 'requires_capture':
                case 'requires_confirmation':
                case 'requires_action':
                case 'requires_payment_method':
                    $status = 'pending';
                    break;
                case 'processing':
                case 'pending':
                    // ACH payments clearing through the bank (PaymentIntent 'processing' or Charge 'pending').
                    // Optimistically approve to match processStoredAch() behavior and prevent
                    // re-charging. If the transfer fails, webhooks will update to declined.
                    $status = 'approved';
                    break;
                case 'canceled':
                case 'failed':
                    $status = 'declined';
                    break;
                case 'succeeded':
                    $status = 'approved';
                    break;
            }
        }

        return [
            'client_id' => $transaction->client_id,
            'amount' => $this->formatAmount(
                $payload->data->object->amount ?? $payload->data->object->amount_captured ?? 0,
                strtoupper($payload->data->object->currency ?? ''),
                'from'
            ),
            'invoices' => $this->parseInvoiceAmounts($payload->data->object->metadata->invoices ?? ''),
            'currency' => strtoupper($payload->data->object->currency) ?? null,
            'status' => $status,
            'reference_id' => $transaction->reference_id,
            'transaction_id' => $transaction->transaction_id,
            'message' => $payload->data->object->failure_message ?? null
        ];
    }
}
