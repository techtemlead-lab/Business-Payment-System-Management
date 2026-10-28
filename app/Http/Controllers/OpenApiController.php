<?php

namespace App\Http\Controllers;

use App\Support\PortalHost;
use Illuminate\Http\JsonResponse;

class OpenApiController extends Controller
{
    /**
     * Return OpenAPI 3.0.3 schema specification for enterprise integrations.
     */
    public function spec(): JsonResponse
    {
        $domain = PortalHost::domain() ?: 'example.test';

        $spec = [
            'openapi' => '3.0.3',
            'info' => [
                'title' => 'Madina Platform Core API',
                'description' => 'Enterprise payment, commission calculation, and territorial dealer distribution management API for Madina Natural Health Care.',
                'version' => '1.0.0',
                'contact' => [
                    'name' => 'Madina Enterprise Engineering',
                    'email' => 'tech@madinanhac.com',
                ],
            ],
            'servers' => [
                ['url' => 'https://'.$domain, 'description' => 'Current Environment Server'],
            ],
            'components' => [
                'securitySchemes' => [
                    'BearerAuth' => [
                        'type' => 'http',
                        'scheme' => 'bearer',
                        'bearerFormat' => 'JWT / Session Token',
                    ],
                    'CorrelationId' => [
                        'type' => 'apiKey',
                        'in' => 'header',
                        'name' => 'X-Correlation-ID',
                        'description' => 'Unique request tracking ID across enterprise services',
                    ],
                ],
                'schemas' => [
                    'ErrorResponse' => [
                        'type' => 'object',
                        'properties' => [
                            'message' => ['type' => 'string'],
                            'errors' => ['type' => 'object'],
                        ],
                    ],
                    'Order' => [
                        'type' => 'object',
                        'properties' => [
                            'id' => ['type' => 'string', 'format' => 'uuid'],
                            'order_number' => ['type' => 'string'],
                            'total' => ['type' => 'string', 'example' => '12500.00'],
                            'status' => ['type' => 'string', 'enum' => ['pending', 'approved', 'dispatched', 'delivered', 'cancelled']],
                            'created_at' => ['type' => 'string', 'format' => 'date-time'],
                        ],
                    ],
                    'Withdrawal' => [
                        'type' => 'object',
                        'properties' => [
                            'id' => ['type' => 'string', 'format' => 'uuid'],
                            'amount' => ['type' => 'string', 'example' => '5000.00'],
                            'channel' => ['type' => 'string', 'enum' => ['bkash', 'nagad', 'rocket', 'bank']],
                            'status' => ['type' => 'string', 'enum' => ['requested', 'approved', 'rejected', 'paid']],
                            'idempotency_key' => ['type' => 'string'],
                        ],
                    ],
                ],
            ],
            'security' => [
                ['BearerAuth' => []],
                ['CorrelationId' => []],
            ],
            'paths' => [
                '/api/v1/health' => [
                    'get' => [
                        'summary' => 'System Health & Readiness Status',
                        'tags' => ['Observability'],
                        'responses' => [
                            '200' => ['description' => 'System is healthy and operational.'],
                            '503' => ['description' => 'Critical service degradation detected.'],
                        ],
                    ],
                ],
                '/api/v1/me' => [
                    'get' => [
                        'summary' => 'Get Authenticated User Profile & Capabilities',
                        'tags' => ['Identity'],
                        'responses' => [
                            '200' => ['description' => 'Current user profile'],
                            '401' => ['description' => 'Unauthenticated'],
                        ],
                    ],
                ],
                '/api/v1/catalog' => [
                    'get' => [
                        'summary' => 'List Product Catalog with Partner Wholesale Pricing',
                        'tags' => ['Catalog'],
                        'responses' => [
                            '200' => ['description' => 'Active product catalog with stock status'],
                        ],
                    ],
                ],
                '/api/v1/orders' => [
                    'get' => [
                        'summary' => 'List Orders',
                        'tags' => ['Orders'],
                        'responses' => [
                            '200' => ['description' => 'Paginated order history'],
                        ],
                    ],
                    'post' => [
                        'summary' => 'Create a New Wholesale Order',
                        'tags' => ['Orders'],
                        'responses' => [
                            '201' => ['description' => 'Order created successfully.'],
                            '422' => ['description' => 'Validation error'],
                        ],
                    ],
                ],
                '/api/v1/orders/{order}' => [
                    'get' => [
                        'summary' => 'Get Order Details & Shipment Tracking',
                        'tags' => ['Orders'],
                        'parameters' => [
                            ['name' => 'order', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']],
                        ],
                        'responses' => [
                            '200' => ['description' => 'Full order details'],
                            '404' => ['description' => 'Order not found'],
                        ],
                    ],
                ],
                '/api/v1/orders/{order}/payments' => [
                    'post' => [
                        'summary' => 'Submit Payment Claim for an Order',
                        'tags' => ['Orders'],
                        'parameters' => [
                            ['name' => 'order', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']],
                        ],
                        'responses' => [
                            '200' => ['description' => 'Payment claim registered.'],
                        ],
                    ],
                ],
                '/api/v1/withdrawals' => [
                    'post' => [
                        'summary' => 'Request Payout / Commission Withdrawal (Idempotent)',
                        'tags' => ['Payouts'],
                        'responses' => [
                            '200' => ['description' => 'Withdrawal requested or returned from idempotency key.'],
                            '422' => ['description' => 'Validation failure (minimum amount, insufficient balance, or clawback debt)'],
                        ],
                    ],
                ],
                '/api/v1/earnings' => [
                    'get' => [
                        'summary' => 'Retrieve Wallet Balance & Matured Earnings Statement',
                        'tags' => ['Commissions'],
                        'responses' => [
                            '200' => ['description' => 'Available balance, held commissions, and recent settlement payouts.'],
                        ],
                    ],
                ],
                '/api/v1/network' => [
                    'get' => [
                        'summary' => 'Territorial Partner Network Tree & Downline Volume',
                        'tags' => ['Network'],
                        'responses' => [
                            '200' => ['description' => 'Partner referral network and generation volumes.'],
                        ],
                    ],
                ],
                '/api/v1/finance/policy' => [
                    'get' => [
                        'summary' => 'Current Commission Distribution Policy Parameters',
                        'tags' => ['Finance'],
                        'responses' => [
                            '200' => ['description' => 'Immutable policy schedules and percentage splits.'],
                        ],
                    ],
                ],
            ],
        ];

        return response()->json($spec, 200, [
            'Content-Type' => 'application/json',
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }
}
