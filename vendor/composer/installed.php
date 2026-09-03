<?php return array(
    'root' => array(
        'name' => 'blesta/stripe_payments',
        'pretty_version' => 'dev-master',
        'version' => 'dev-master',
        'reference' => '1c9990502bab73aed95b1cd272c6d33bc327c242',
        'type' => 'blesta-gateway-merchant',
        'install_path' => __DIR__ . '/../../',
        'aliases' => array(),
        'dev' => true,
    ),
    'versions' => array(
        'blesta/composer-installer' => array(
            'pretty_version' => '1.1.0',
            'version' => '1.1.0.0',
            'reference' => 'f0529f892e4cb0db5e9e949031965f212c7da269',
            'type' => 'composer-installer',
            'install_path' => __DIR__ . '/../blesta/composer-installer',
            'aliases' => array(),
            'dev_requirement' => false,
        ),
        'blesta/stripe_payments' => array(
            'pretty_version' => 'dev-master',
            'version' => 'dev-master',
            'reference' => '1c9990502bab73aed95b1cd272c6d33bc327c242',
            'type' => 'blesta-gateway-merchant',
            'install_path' => __DIR__ . '/../../',
            'aliases' => array(),
            'dev_requirement' => false,
        ),
        'composer/installers' => array(
            'pretty_version' => 'v1.12.0',
            'version' => '1.12.0.0',
            'reference' => 'd20a64ed3c94748397ff5973488761b22f6d3f19',
            'type' => 'composer-plugin',
            'install_path' => __DIR__ . '/./installers',
            'aliases' => array(),
            'dev_requirement' => false,
        ),
        'roundcube/plugin-installer' => array(
            'dev_requirement' => false,
            'replaced' => array(
                0 => '*',
            ),
        ),
        'shama/baton' => array(
            'dev_requirement' => false,
            'replaced' => array(
                0 => '*',
            ),
        ),
        'stripe/stripe-php' => array(
            'pretty_version' => 'v7.128.0',
            'version' => '7.128.0.0',
            'reference' => 'c704949c49b72985c76cc61063aa26fefbd2724e',
            'type' => 'library',
            'install_path' => __DIR__ . '/../stripe/stripe-php',
            'aliases' => array(),
            'dev_requirement' => false,
        ),
    ),
);
