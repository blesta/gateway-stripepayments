<?php
/**
 * Test bootstrap.
 *
 * This repository ships without the Blesta framework, so the framework symbols the
 * gateway touches are stood in for here, guarded so the suite also runs unchanged when
 * the repo is checked out inside a Blesta install whose autoloader defines the real
 * ones first. The Stripe SDK is the real bundled one; no test makes a network call.
 */

namespace Blesta\Core\Util\Oauth {
    if (!interface_exists('Blesta\Core\Util\Oauth\CentralizedOauthExtension')) {
        interface CentralizedOauthExtension
        {
            public function getOauthProvider(): string;
            public function validateOauthGrant(array $grant): bool;
            public function onOauthGrantChanged(?array $grant): void;
            public function getOauthSettingsUrl(): ?string;
        }
    }

    if (!class_exists('Blesta\Core\Util\Oauth\OauthTokenRejectedException')) {
        class OauthTokenRejectedException extends \Exception
        {
        }
    }
}

namespace {
    if (!defined('DS')) {
        define('DS', DIRECTORY_SEPARATOR);
    }

    if (!class_exists('Loader')) {
        class Loader
        {
            public static function load($file)
            {
                require_once $file;
            }

            public static function loadComponents(&$object, array $components)
            {
                foreach ($components as $component) {
                    if ($component === 'Input') {
                        $object->Input = new Input();
                    }
                }
            }

            public static function loadModels(&$object, array $models)
            {
            }

            public static function loadHelpers(&$object, array $helpers)
            {
            }

            public static function toCamelCase($str)
            {
                return str_replace(' ', '', ucwords(str_replace('_', ' ', $str)));
            }
        }
    }

    if (!class_exists('Input')) {
        class Input
        {
            private $errors = [];

            public function setErrors(array $errors)
            {
                $this->errors = $errors;
            }

            public function errors()
            {
                return (empty($this->errors) ? false : $this->errors);
            }

            public function setRules(array $rules)
            {
            }

            public function validates(array &$vars)
            {
                return true;
            }
        }
    }

    if (!class_exists('Language')) {
        class Language
        {
            public static function loadLang($file, $language = null, $dir = null)
            {
            }

            public static function _($key, $return = false)
            {
                if ($return) {
                    return $key;
                }

                echo $key;
            }
        }
    }

    if (!class_exists('Configure')) {
        class Configure
        {
            private static $data = [];

            public static function load($file, $dir = null)
            {
            }

            public static function get($key)
            {
                return (self::$data[$key] ?? null);
            }

            public static function set($key, $value)
            {
                self::$data[$key] = $value;
            }
        }
    }

    if (!class_exists('MerchantGateway')) {
        abstract class MerchantGateway
        {
            public $Input;
            public $logs = [];
            protected $currency;
            protected $gateway_id;
            protected $client_id;
            protected $staff_id;

            public function setGatewayId($id)
            {
                $this->gateway_id = $id;
            }

            public function getName()
            {
                return 'Stripe Payments';
            }

            public function getVersion()
            {
                return '2.1.0';
            }

            protected function loadConfig($file)
            {
            }

            protected function getCommonError($type)
            {
                return ['common' => [$type => $type]];
            }

            protected function log($url, $data = null, $direction = 'input', $success = false)
            {
                $this->logs[] = [
                    'url' => $url,
                    'data' => $data,
                    'direction' => $direction,
                    'success' => $success
                ];
            }

            protected function maskDataRecursive(array $data, array $mask_fields, $mask_char = 'x', $unmask_length = 0)
            {
                foreach ($data as $key => $value) {
                    if (is_array($value)) {
                        $data[$key] = $this->maskDataRecursive($value, $mask_fields, $mask_char, $unmask_length);
                    } elseif (in_array($key, $mask_fields, true)) {
                        $data[$key] = str_repeat($mask_char, strlen((string) $value));
                    }
                }

                return $data;
            }
        }
    }

    foreach (['MerchantAch', 'MerchantAchOffsite', 'MerchantAchVerification', 'MerchantAchForm',
        'MerchantCc', 'MerchantCcOffsite', 'MerchantCcForm'] as $merchant_interface) {
        if (!interface_exists($merchant_interface)) {
            eval('interface ' . $merchant_interface . ' {}');
        }
    }

    require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'stripe_payments.php';
}
