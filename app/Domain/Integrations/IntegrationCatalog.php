<?php

namespace App\Domain\Integrations;

/**
 * Catalog of optional third-party integrations. Every provider is disabled until
 * an authorised administrator enables it and supplies credentials.
 */
final class IntegrationCatalog
{
    public const SecretFields = [
        'api_key', 'api_secret', 'app_key', 'app_secret', 'password', 'username', 'client_secret', 'store_password',
    ];

    /**
     * @return array<string, array{label: string, description: string, providers: array<string, array{label: string, fields: list<string>, defaults: array<string, mixed>}>}>
     */
    public static function groups(): array
    {
        return [
            'bdris' => [
                'label' => 'BDRIS identity lookup',
                'description' => 'Birth registration autofill for dealer onboarding. Disabled by default; leave off unless you have a live government or approved proxy endpoint.',
                'providers' => [
                    'bdris' => [
                        'label' => 'BDRIS / birth registration API',
                        'fields' => ['enabled', 'base_url', 'api_key', 'timeout'],
                        'defaults' => ['enabled' => false, 'base_url' => '', 'api_key' => '', 'timeout' => 12],
                    ],
                ],
            ],
            'sms' => [
                'label' => 'SMS gateway',
                'description' => 'Sends account-created and operational SMS. Log-only delivery remains available when SMS is disabled.',
                'providers' => [
                    'ssl_wireless' => [
                        'label' => 'SSL Wireless',
                        'fields' => ['enabled', 'base_url', 'api_key', 'api_secret', 'sender_id'],
                        'defaults' => ['enabled' => false, 'base_url' => 'https://smsplus.sslwireless.com/api/v3/send-sms', 'api_key' => '', 'api_secret' => '', 'sender_id' => ''],
                    ],
                    'bulk_sms_bd' => [
                        'label' => 'BulkSMSBD',
                        'fields' => ['enabled', 'base_url', 'api_key', 'sender_id'],
                        'defaults' => ['enabled' => false, 'base_url' => 'http://bulksmsbd.net/api/smsapi', 'api_key' => '', 'sender_id' => ''],
                    ],
                    'custom' => [
                        'label' => 'Custom HTTP SMS endpoint',
                        'fields' => ['enabled', 'base_url', 'api_key', 'sender_id'],
                        'defaults' => ['enabled' => false, 'base_url' => '', 'api_key' => '', 'sender_id' => ''],
                    ],
                ],
            ],
            'maps' => [
                'label' => 'Maps',
                'description' => 'Optional Google Maps embed and Places support on the public contact page. Nearest-dealer search always uses saved coordinates without a paid API.',
                'providers' => [
                    'google' => [
                        'label' => 'Google Maps Platform',
                        'fields' => ['enabled', 'api_key', 'map_embed_url'],
                        'defaults' => ['enabled' => false, 'api_key' => '', 'map_embed_url' => ''],
                    ],
                ],
            ],
            'couriers' => [
                'label' => 'Courier services',
                'description' => 'Optional consignment creation and tracking lookup when dispatching orders. Manual courier name and tracking remain supported.',
                'providers' => [
                    'pathao' => [
                        'label' => 'Pathao Courier',
                        'fields' => ['enabled', 'base_url', 'api_key', 'api_secret', 'store_id'],
                        'defaults' => ['enabled' => false, 'base_url' => 'https://api-hermes.pathao.com', 'api_key' => '', 'api_secret' => '', 'store_id' => ''],
                    ],
                    'steadfast' => [
                        'label' => 'Steadfast Courier',
                        'fields' => ['enabled', 'base_url', 'api_key', 'api_secret'],
                        'defaults' => ['enabled' => false, 'base_url' => 'https://portal.packzy.com/api/v1', 'api_key' => '', 'api_secret' => ''],
                    ],
                    'redx' => [
                        'label' => 'RedX',
                        'fields' => ['enabled', 'base_url', 'api_key'],
                        'defaults' => ['enabled' => false, 'base_url' => 'https://openapi.redx.com.bd', 'api_key' => ''],
                    ],
                    'paperfly' => [
                        'label' => 'Paperfly',
                        'fields' => ['enabled', 'base_url', 'api_key', 'username', 'password'],
                        'defaults' => ['enabled' => false, 'base_url' => 'https://api.paperfly.com.bd', 'api_key' => '', 'username' => '', 'password' => ''],
                    ],
                    'ecourier' => [
                        'label' => 'eCourier',
                        'fields' => ['enabled', 'base_url', 'api_key', 'api_secret', 'user_id'],
                        'defaults' => ['enabled' => false, 'base_url' => 'https://backoffice.ecourier.com.bd/api', 'api_key' => '', 'api_secret' => '', 'user_id' => ''],
                    ],
                ],
            ],
            'payments' => [
                'label' => 'Payment gateway APIs',
                'description' => 'Optional transaction lookup when verifying payment claims. Manual TrxID verification remains the default included workflow.',
                'providers' => [
                    'bkash' => [
                        'label' => 'bKash',
                        'fields' => ['enabled', 'base_url', 'app_key', 'app_secret', 'username', 'password', 'mode'],
                        'defaults' => ['enabled' => false, 'base_url' => 'https://tokenized.sandbox.bka.sh/v1.2.0-beta', 'app_key' => '', 'app_secret' => '', 'username' => '', 'password' => '', 'mode' => 'sandbox'],
                    ],
                    'nagad' => [
                        'label' => 'Nagad',
                        'fields' => ['enabled', 'base_url', 'app_key', 'app_secret', 'mode'],
                        'defaults' => ['enabled' => false, 'base_url' => 'https://api.mynagad.com:10443', 'app_key' => '', 'app_secret' => '', 'mode' => 'sandbox'],
                    ],
                    'sslcommerz' => [
                        'label' => 'SSLCommerz',
                        'fields' => ['enabled', 'base_url', 'store_id', 'store_password', 'mode'],
                        'defaults' => ['enabled' => false, 'base_url' => 'https://sandbox.sslcommerz.com', 'store_id' => '', 'store_password' => '', 'mode' => 'sandbox'],
                    ],
                    'rocket' => [
                        'label' => 'Rocket (Dutch-Bangla)',
                        'fields' => ['enabled', 'base_url', 'api_key', 'api_secret', 'mode'],
                        'defaults' => ['enabled' => false, 'base_url' => '', 'api_key' => '', 'api_secret' => '', 'mode' => 'sandbox'],
                    ],
                ],
            ],
        ];
    }
}
