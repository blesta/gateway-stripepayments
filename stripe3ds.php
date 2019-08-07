<?php
/**
 * Stripe Credit Card processing gateway. Supports offsite payment
 * processing for Credit Cards using the latest secure methods from Stripe.
 *
 * The Stripe API can be found at: https://stripe.com/docs/api
 *
 * @package blesta
 * @subpackage blesta.components.gateways.stripe3ds
 * @copyright Copyright (c) 2019, Phillips Data, Inc.
 * @license http://www.blesta.com/license/ The Blesta License Agreement
 * @link http://www.blesta.com/ Blesta
 */
class Stripe3ds extends MerchantGateway implements MerchantCc, MerchantCcOffsite, MerchantCcForm
{
    /**
     * @var string The version of this gateway
     */
    private static $version = '1.0.0';
    /**
     * @var string The authors of this gateway
     */
    private static $authors = [['name' => 'Phillips Data, Inc.', 'url' => 'http://www.blesta.com']];
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
        // Load components required by this module
        Loader::loadComponents($this, ['Input']);

        // Load the language required by this module
        Language::loadLang('stripe3ds', null, dirname(__FILE__) . DS . 'language' . DS);
    }

    /**
     * Attempt to install this gateway
     */
    public function install()
    {
        // Ensure that the system has support for the JSON extension
        if (!function_exists('json_decode')) {
            $errors = [
                'json' => [
                    'required' => Language::_('Stripe3ds.!error.json_required', true)
                ]
            ];
            $this->Input->setErrors($errors);
        }
    }

    /**
     * Returns the name of this gateway
     *
     * @return string The common name of this gateway
     */
    public function getName()
    {
        return Language::_('Stripe3ds.name', true);
    }

    /**
     * Returns the version of this gateway
     *
     * @return string The current version of this gateway
     */
    public function getVersion()
    {
        return self::$version;
    }

    /**
     * Returns the name and URL for the authors of this gateway
     *
     * @return array The name and URL of the authors of this gateway
     */
    public function getAuthors()
    {
        return self::$authors;
    }

    /**
     * Return all currencies supported by this gateway
     *
     * @return array A numerically indexed array containing all currency codes (ISO 4217 format) this gateway supports
     */
    public function getCurrencies()
    {
        return ['AED', 'AFN', 'ALL', 'AMD', 'ANG', 'AOA', 'ARS', 'AUD',
            'AWG', 'AZN', 'BAM', 'BBD', 'BDT', 'BGN', 'BIF', 'BMD', 'BND',
            'BOB', 'BRL', 'BSD', 'BWP', 'BZD', 'CAD', 'CDF', 'CHF', 'CLP',
            'CNY', 'COP', 'CRC', 'CVE', 'CZK', 'DJF', 'DKK', 'DOP', 'DZD',
            'EEK', 'EGP', 'ETB', 'EUR', 'FJD', 'FKP', 'GBP', 'GEL', 'GIP',
            'GMD', 'GNF', 'GTQ', 'GYD', 'HKD', 'HNL', 'HRK', 'HTG', 'HUF',
            'IDR', 'ILS', 'INR', 'ISK', 'JMD', 'JPY', 'KES', 'KGS', 'KHR',
            'KMF', 'KRW', 'KYD', 'KZT', 'LAK', 'LBP', 'LKR', 'LRD', 'LSL',
            'LTL', 'LVL', 'MAD', 'MDL', 'MGA', 'MKD', 'MNT', 'MOP', 'MRO',
            'MUR', 'MVR', 'MWK', 'MXN', 'MYR', 'MZN', 'NAD', 'NGN', 'NIO',
            'NOK', 'NPR', 'NZD', 'PAB', 'PEN', 'PGK', 'PHP', 'PKR', 'PLN',
            'PYG', 'QAR', 'RON', 'RSD', 'RUB', 'RWF', 'SAR', 'SBD', 'SCR',
            'SEK', 'SGD', 'SHP', 'SLL', 'SOS', 'SRD', 'STD', 'SVC', 'SZL',
            'THB', 'TJS', 'TOP', 'TRY', 'TTD', 'TWD', 'TZS', 'UAH', 'UGX',
            'USD', 'UYI', 'UZS', 'VEF', 'VND', 'VUV', 'WST', 'XAF', 'XCD',
            'XOF', 'XPF', 'YER', 'ZAR', 'ZMW'];
    }

    /**
     * Sets the currency code to be used for all subsequent payments
     *
     * @param string $currency The ISO 4217 currency code to be used for subsequent payments
     */
    public function setCurrency($currency)
    {
        $this->currency = $currency;
    }

    /**
     * Create and return the view content required to modify the settings of this gateway
     *
     * @param array $meta An array of meta (settings) data belonging to this gateway
     * @return string HTML content containing the fields to update the meta data for this gateway
     */
    public function getSettings(array $meta = null)
    {
        // Load the view into this object, so helpers can be automatically added to the view
        $this->view = new View('settings', 'default');
        $this->view->setDefaultView('components' . DS . 'gateways' . DS . 'merchant' . DS . 'stripe3ds' . DS);
        // Load the helpers required for this view
        Loader::loadHelpers($this, ['Form', 'Html']);

        $this->view->set('meta', $meta);

        return $this->view->fetch();
    }

    /**
     * {@inheritdoc}
     */
    public function editSettings(array $meta)
    {
        // Verify meta data is valid
        $rules = [
            'publishable_key' => [
                'empty' => [
                    'rule' => 'isEmpty',
                    'negate' => true,
                    'message' => Language::_('Stripe3ds.!error.publishable_key.empty', true)
                ]
            ],
            'secret_key' => [
                'empty' => [
                    'rule' => 'isEmpty',
                    'negate' => true,
                    'message' => Language::_('Stripe3ds.!error.secret_key.empty', true)
                ]
            ]
        ];

        $this->Input->setRules($rules);

        // Validate the given meta data to ensure it meets the requirements
        $this->Input->validates($meta);
        // Return the meta data, no changes required regardless of success or failure for this gateway
        return $meta;
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
        $this->view = $this->makeView('cc_form', 'default', str_replace(ROOTWEBDIR, '', dirname(__FILE__) . DS));

        // Load the helpers required for this view
        Loader::loadHelpers($this, ['Form', 'Html']);

        // Declare to Stripe the possibility of us creating a card Payment Method through this page
        // This is confirmed in the view using stripe.handleCardSetup
        $setup_intent = $this->handleApiRequest(
            ['Stripe\SetupIntent', 'create'],
            [],
            $this->base_url . 'setup_intents - create'
        );

        ###
        # TODO update this to use payment_intents
        ###

        $this->view->set('setup_intent', $setup_intent);
        $this->view->set('meta', $this->meta);

        return $this->view->fetch();
    }

    /**
     * {@inheritdoc}
     */
    public function processCc(array $card_info, $amount, array $invoice_amounts = null)
    {
        Loader::loadModels($this, ['Invoices']);

        // Create a list of IDs for the invoices being paid
        $id_codes = [];
        foreach ($invoice_amounts as $invoice_amount) {
            $invoice = $this->Invoices->get($invoice_amount['invoice_id']);
            $id_codes[] = $invoice->id_code;
        }
        $description = Language::_('Stripe_gateway.charge_description', true, implode(', ', $id_codes));

        // Charge the given Payment Method through Stripe
        $charge = [
            'amount' => $this->formatAmount($amount, $this->ifSet($this->currency, 'usd')),
            'currency' => $this->ifSet($this->currency, 'usd'),
            'payment_method' => $card_info['stripe_payment_method'],
            'description' => $description,
            'confirm' => true,
            'off_session' => false
        ];
        ###
        # TODO Figure out a good way to set this off_session parameter
        ###

        $payment = $this->handleApiRequest(
            ['Stripe\PaymentIntent', 'create'],
            [$charge],
            $this->base_url . 'payment_intents - create'
        );
        $errors = $this->Input->errors();

        // Get the status from the payment response
        $status = 'error';
        if (isset($payment->error) && $this->ifSet($payment->error->code) == 'card_declined') {
            $status = 'declined';
        } elseif (!isset($payment->error) && empty($errors)) {
            $status = 'approved';
        } else {
            $message = $this->ifSet($payment->error->message);
        }

        return [
            'status' => $status,
            'reference_id' => null,
            'transaction_id' => $this->ifSet($payment->id, null),
            'message' => $this->ifSet($message)
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function authorizeCc(array $card_info, $amount, array $invoice_amounts = null)
    {
        // Gateway does not support this action
        $this->Input->setErrors($this->getCommonError('unsupported'));
    }

    /**
     * {@inheritdoc}
     */
    public function captureCc($reference_id, $transaction_id, $amount, array $invoice_amounts = null)
    {
        // Gateway does not support this action
        $this->Input->setErrors($this->getCommonError('unsupported'));
    }

    /**
     * {@inheritdoc}
     */
    public function voidCc($reference_id, $transaction_id)
    {
        // Refund a previous charge
        $response = $this->refundCc($reference_id, $transaction_id, null);

        // Refund must be successful
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
    public function refundCc($reference_id, $transaction_id, $amount)
    {
        $refund = $this->handleApiRequest(
            ['Stripe\Refund', 'create'],
            [['charge' => $transaction_id, 'amount' => $this->formatAmount($amount, $this->currency)]],
            $this->base_url . 'refunds - create'
        );
        $errors = $this->Input->errors();

        // Get the status from the refund response
        $status = 'error';
        if (!isset($refund->error) && empty($errors)) {
            $status = 'refunded';
        } else {
            $message = $this->ifSet($refund->error->message);
        }

        // Return formatted response
        return [
            'status' => $status,
            'reference_id' => null,
            'transaction_id' => $this->ifSet($refund->id, null),
            'message' => $this->ifSet($message)
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function storeCc(array $card_info, array $contact, $client_reference_id = null)
    {
        // Get the Payment Method from Stripe
        $card = $this->handleApiRequest(
            ['Stripe\PaymentMethod', 'retrieve'],
            [$this->ifSet($card_info['stripe_payment_method'])],
            $this->base_url . 'payment_methods - retrieve'
        );

        if ($this->Input->errors()) {
            return false;
        }

        // Attach the Payment Method to an existing Stripe customer if we have one on record
        $attached = false;
        if ($client_reference_id) {
            $attached = $this->handleApiRequest(
                function ($customer_id, $card) {
                    return $card->attach(['customer' => $customer_id]);
                },
                [$this->ifSet($client_reference_id), $card],
                $this->base_url . 'payment_methods - attach'
            );
        }

        // If we were not able to attach the Payment Method to an existing customer then create a new one
        if (!$attached) {
            $this->Input->setErrors([]);
            $customer = $this->handleApiRequest(
                ['Stripe\Customer', 'create'],
                [['payment_method' => $this->ifSet($card_info['stripe_payment_method'])]],
                $this->base_url . 'customers - create'
            );
        }

        if ($this->Input->errors()) {
            return false;
        }

        // Return the reference IDs and card information
        return [
            'client_reference_id' => $this->ifSet($customer->id, $client_reference_id),
            'reference_id' => $this->ifSet($card_info['stripe_payment_method']),
            'last4' => $this->ifSet($card->card->last4),
            'expiration' => $this->ifSet($card->card->exp_year)
                . str_pad($this->ifSet($card->card->exp_month), 2, 0, STR_PAD_LEFT),
            'type' => $this->mapCardType($this->ifSet($card->card->brand))
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function updateCc(array $card_info, array $contact, $client_reference_id, $account_reference_id)
    {
        // Add a new payment account to the same client
        $card_data = $this->storeCc($card_info, $contact, $client_reference_id);

        // Remove the old payment account
        $this->removeCc($client_reference_id, $account_reference_id);

        return $card_data;
    }

    private function handleApiRequest($api_method, $params, $log_url)
    {
        $this->loadApi();

        // Attempt to update the customer's card
        $errors = [];
        $response = new stdClass();
        try {
            $response = call_user_func_array($api_method, $params);
        } catch (Stripe_InvalidRequestError $exception) {
            if (isset($exception->json_body)) {
                $response = $exception->json_body;
                $errors = [
                    $response['error']['type'] => [
                        'error' => $response['error']['message']
                    ]
                ];
            } else {
                // Gateway returned an invalid response
                $errors = $this->getCommonError('general');
            }
        } catch (Stripe_CardError $exception) {
            if (isset($exception->json_body)) {
                $response = $exception->json_body;
                $errors = [
                    $response['error']['type'] => [
                        $response['error']['code'] => $response['error']['message']
                    ]
                ];
            } else {
                // Gateway returned an invalid response
                $errors = $this->getCommonError('general');
            }
        } catch (Stripe_AuthenticationError $exception) {
            if (isset($exception->json_body)) {
                // Don't use the actual error (as it may contain an API key, albeit invalid),
                // rather a general auth error
                $response = $exception->json_body;
                $errors = [
                    $response->error['type'] => [
                        'auth_error' => Language::_('Stripe_gateway.!error.auth', true)
                    ]
                ];
            } else {
                // Gateway returned an invalid response
                $errors = $this->getCommonError('general');
            }
        } catch (Exception $e) {
            // Any other exception, including Stripe_ApiError
            var_dump($e->getMessage());
            ###
            # TODO Store this error somewhere visible for debbuging
            ###
            $errors = $this->getCommonError('general');
        }

        // Set any errors
        if (!empty($errors)) {
            $this->Input->setErrors($errors);
        }

        // Log the request
        $this->logRequest($log_url, is_array($params) ? $params : [$params], $this->objectToArray($response));

        return empty($errors) ? $response : false;
    }

    /**
     * {@inheritdoc}
     */
    public function removeCc($client_reference_id, $account_reference_id)
    {
        // Get the Payment Method from Stripe
        $card = $this->handleApiRequest(
            ['Stripe\PaymentMethod', 'retrieve'],
            [$account_reference_id],
            $this->base_url . 'payment_methods - retrieve'
        );

        if ($this->Input->errors()) {
            return false;
        }

        // Detach the Payment Method from it's associated Stripe customer
        $this->handleApiRequest(
            function ($customer_id, $card) {
                return $card->detach();
            },
            [$this->ifSet($client_reference_id), $card],
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
    public function processStoredCc($client_reference_id, $account_reference_id, $amount, array $invoice_amounts = null)
    {
        Loader::loadModels($this, ['Invoices']);

        // Create a list of IDs for the invoices being paid
        $id_codes = [];
        foreach ($invoice_amounts as $invoice_amount) {
            $invoice = $this->Invoices->get($invoice_amount['invoice_id']);
            $id_codes[] = $invoice->id_code;
        }
        $description = Language::_('Stripe_gateway.charge_description', true, implode(', ', $id_codes));

        // Charge the given Payment Method through Stripe
        $charge = [
            'amount' => $this->formatAmount($amount, $this->ifSet($this->currency, 'usd')),
            'currency' => $this->ifSet($this->currency, 'usd'),
            'customer' => $client_reference_id,
            'payment_method' => $account_reference_id,
            'description' => $description,
            'confirm' => true,
            'off_session' => false
        ];
        ###
        # TODO Figure out a good way to set this off_session parameter
        ###

        $payment = $this->handleApiRequest(
            ['Stripe\PaymentIntent', 'create'],
            [$charge],
            $this->base_url . 'payment_intents - create'
        );
        $errors = $this->Input->errors();

        // Set whether there was an error
        $status = 'error';
        if (isset($payment->error) && $this->ifSet($payment->error->code) == 'card_declined') {
            $status = 'declined';
        } elseif (!isset($payment->error) && empty($errors)) {
            $status = 'approved';
        } else {
            $message = $this->ifSet($payment->error->message);
        }

        return [
            'status' => $status,
            'reference_id' => null,
            'transaction_id' => $this->ifSet($payment->charges->data[0]->id, null),
            'message' => $this->ifSet($message)
        ];
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
        // Gateway does not support this action
        $this->Input->setErrors($this->getCommonError('unsupported'));
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
    ) {
        // Gateway does not support this action
        $this->Input->setErrors($this->getCommonError('unsupported'));
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
        // Refund a previous charge
        $response = $this->refundCc($transaction_reference_id, $transaction_id, null);

        // Refund must be successful
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
        return $this->refundCc($reference_id, $transaction_id, $amount);
    }

    /**
     * {@inheritdoc}
     */
    public function requiresCcStorage()
    {
        return true;
    }

    /**
     * Loads the API if not already loaded
     */
    private function loadApi()
    {
        Loader::load(dirname(__FILE__) . DS . 'vendor' . DS . 'stripe' . DS . 'stripe-php' . DS . 'init.php');
        Stripe\Stripe::setApiKey($this->ifSet($this->meta['secret_key']));
    }

    /**
     * Log the request
     *
     * @param string $url The URL of the API request to log
     * @param array The input parameters sent to the gateway
     * @param array The response from the gateway
     */
    private function logRequest($url, $params, array $response)
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
        if (!($errors = $this->Input->errors()) || isset($response['error'])) {
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
     * Convert amount from decimal value to integer representation of cents
     *
     * @param float $amount
     * @param string $currency
     * @return int The amount in cents
     */
    private function formatAmount($amount, $currency)
    {
        $non_decimal_currencies = ['BIF', 'CLP', 'DJF', 'GNF', 'JPY',
            'KMF', 'KRW', 'MGA', 'PYG', 'RWF', 'VUV', 'XAF', 'XOF', 'XPF'];

        if (is_numeric($amount) && !in_array($currency, $non_decimal_currencies)) {
            $amount *= 100;
        }
        return (int)round($amount);
    }

    /**
     * Converts the card type from Stripe to the equivalent in Blesta
     *
     * @param string $stripe_card_type The card type from Stripe
     * @return string The card type for Blesta
     */
    private function mapCardType($stripe_card_type)
    {
        $blesta_card_type = 'other';
        switch ($stripe_card_type) {
            case 'amex':
                $blesta_card_type = 'amex';
                break;
            case 'diners':
                $blesta_card_type = 'dc-int';
                break;
            case 'discover':
                $blesta_card_type = 'disc';
                break;
            case 'jcb':
                $blesta_card_type = 'jcb';
                break;
            case 'mastercard':
                $blesta_card_type = 'mc';
                break;
            case 'unionpay':
                $blesta_card_type = 'cup';
                break;
            case 'visa':
                $blesta_card_type = 'visa';
                break;
            case 'unknown':
                break;
        }

        return $blesta_card_type;
    }
}
