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
                'label' => __('portal.si_group_bdris_label'),
                'description' => __('portal.si_group_bdris_desc'),
                'providers' => [
                    'bdris' => [
                        'label' => __('portal.si_provider_bdris'),
                        'fields' => ['enabled', 'base_url', 'api_key', 'timeout'],
                        'defaults' => ['enabled' => false, 'base_url' => '', 'api_key' => '', 'timeout' => 12],
                    ],
                ],
            ],
            'sms' => [
                'label' => __('portal.si_group_sms_label'),
                'description' => __('portal.si_group_sms_desc'),
                'providers' => [
                    'ssl_wireless' => [
                        'label' => __('portal.si_provider_ssl_wireless'),
                        'fields' => ['enabled', 'base_url', 'api_key', 'api_secret', 'sender_id'],
                        'defaults' => ['enabled' => false, 'base_url' => 'https://smsplus.sslwireless.com/api/v3/send-sms', 'api_key' => '', 'api_secret' => '', 'sender_id' => ''],
                    ],
                    'bulk_sms_bd' => [
                        'label' => __('portal.si_provider_bulk_sms_bd'),
                        'fields' => ['enabled', 'base_url', 'api_key', 'sender_id'],
                        'defaults' => ['enabled' => false, 'base_url' => 'http://bulksmsbd.net/api/smsapi', 'api_key' => '', 'sender_id' => ''],
                    ],
                    'custom' => [
                        'label' => __('portal.si_provider_custom_sms'),
                        'fields' => ['enabled', 'base_url', 'api_key', 'sender_id'],
                        'defaults' => ['enabled' => false, 'base_url' => '', 'api_key' => '', 'sender_id' => ''],
                    ],
                ],
            ],
            'maps' => [
                'label' => __('portal.si_group_maps_label'),
                'description' => __('portal.si_group_maps_desc'),
                'providers' => [
                    'google' => [
                        'label' => __('portal.si_provider_google_maps'),
                        'fields' => ['enabled', 'api_key', 'map_embed_url'],
                        'defaults' => ['enabled' => false, 'api_key' => '', 'map_embed_url' => ''],
                    ],
                ],
            ],
            'couriers' => [
                'label' => __('portal.si_group_couriers_label'),
                'description' => __('portal.si_group_couriers_desc'),
                'providers' => [
                    'pathao' => [
                        'label' => __('portal.si_provider_pathao'),
                        'fields' => ['enabled', 'base_url', 'api_key', 'api_secret', 'store_id'],
                        'defaults' => ['enabled' => false, 'base_url' => 'https://api-hermes.pathao.com', 'api_key' => '', 'api_secret' => '', 'store_id' => ''],
                    ],
                    'steadfast' => [
                        'label' => __('portal.si_provider_steadfast'),
                        'fields' => ['enabled', 'base_url', 'api_key', 'api_secret'],
                        'defaults' => ['enabled' => false, 'base_url' => 'https://portal.packzy.com/api/v1', 'api_key' => '', 'api_secret' => ''],
                    ],
                    'redx' => [
                        'label' => __('portal.si_provider_redx'),
                        'fields' => ['enabled', 'base_url', 'api_key'],
                        'defaults' => ['enabled' => false, 'base_url' => 'https://openapi.redx.com.bd', 'api_key' => ''],
                    ],
                    'paperfly' => [
                        'label' => __('portal.si_provider_paperfly'),
                        'fields' => ['enabled', 'base_url', 'api_key', 'username', 'password'],
                        'defaults' => ['enabled' => false, 'base_url' => 'https://api.paperfly.com.bd', 'api_key' => '', 'username' => '', 'password' => ''],
                    ],
                    'ecourier' => [
                        'label' => __('portal.si_provider_ecourier'),
                        'fields' => ['enabled', 'base_url', 'api_key', 'api_secret', 'user_id'],
                        'defaults' => ['enabled' => false, 'base_url' => 'https://backoffice.ecourier.com.bd/api', 'api_key' => '', 'api_secret' => '', 'user_id' => ''],
                    ],
                ],
            ],
            'payments' => [
                'label' => __('portal.si_group_payments_label'),
                'description' => __('portal.si_group_payments_desc'),
                'providers' => [
                    'bkash' => [
                        'label' => __('portal.si_provider_bkash'),
                        'fields' => ['enabled', 'base_url', 'app_key', 'app_secret', 'username', 'password', 'mode'],
                        'defaults' => ['enabled' => false, 'base_url' => 'https://tokenized.sandbox.bka.sh/v1.2.0-beta', 'app_key' => '', 'app_secret' => '', 'username' => '', 'password' => '', 'mode' => 'sandbox'],
                    ],
                    'nagad' => [
                        'label' => __('portal.si_provider_nagad'),
                        'fields' => ['enabled', 'base_url', 'app_key', 'app_secret', 'mode'],
                        'defaults' => ['enabled' => false, 'base_url' => 'https://api.mynagad.com:10443', 'app_key' => '', 'app_secret' => '', 'mode' => 'sandbox'],
                    ],
                    'sslcommerz' => [
                        'label' => __('portal.si_provider_sslcommerz'),
                        'fields' => ['enabled', 'base_url', 'store_id', 'store_password', 'mode'],
                        'defaults' => ['enabled' => false, 'base_url' => 'https://sandbox.sslcommerz.com', 'store_id' => '', 'store_password' => '', 'mode' => 'sandbox'],
                    ],
                    'rocket' => [
                        'label' => __('portal.si_provider_rocket'),
                        'fields' => ['enabled', 'base_url', 'api_key', 'api_secret', 'mode'],
                        'defaults' => ['enabled' => false, 'base_url' => '', 'api_key' => '', 'api_secret' => '', 'mode' => 'sandbox'],
                    ],
                ],
            ],
        ];
    }
}
