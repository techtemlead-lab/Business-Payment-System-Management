<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\PaymentClaim;
use App\Models\Receipt;
use App\Models\Shipment;
use App\Support\SiteCustomization;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as RegisteredRoute;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class OpenApiTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test GET /api/v1/openapi.json returns 200 OK and valid OpenAPI 3.0.3 specification.
     */
    public function test_openapi_spec_endpoint_returns_ok_and_valid_structure(): void
    {
        $response = $this->get('/api/v1/openapi.json');

        $response->assertOk()
            ->assertHeader('Content-Type', 'application/json')
            ->assertJsonPath('openapi', '3.0.3')
            ->assertJsonStructure([
                'openapi',
                'info' => ['title', 'description', 'version', 'contact'],
                'servers',
                'components' => [
                    'securitySchemes',
                    'schemas' => [
                        'ErrorResponse',
                        'Order',
                        'Withdrawal',
                    ],
                ],
                'security',
                'paths',
            ]);
    }

    /**
     * Test Order schema uses 'number' instead of 'order_number' and contains valid database status enums.
     */
    public function test_openapi_order_schema_uses_number_and_valid_status_enum(): void
    {
        $response = $this->get('/api/v1/openapi.json');

        $orderProperties = $response->json('components.schemas.Order.properties');

        $this->assertIsArray($orderProperties);
        $this->assertArrayHasKey('number', $orderProperties);
        $this->assertArrayNotHasKey('order_number', $orderProperties);
        $this->assertSame('string', $orderProperties['number']['type']);

        $expectedStatuses = [
            'submitted',
            'held',
            'approved',
            'partially_dispatched',
            'dispatched',
            'delivered',
            'rejected',
            'cancelled',
        ];

        $actualStatuses = $orderProperties['status']['enum'] ?? [];

        $this->assertSame($expectedStatuses, $actualStatuses);
        $this->assertNotContains('pending', $actualStatuses);
    }

    /**
     * Test POST /api/v1/withdrawals response is defined as 201 Created to match WithdrawalController.
     */
    public function test_openapi_withdrawals_post_response_specifies_201_created(): void
    {
        $response = $this->get('/api/v1/openapi.json');

        $withdrawalResponses = $response->json('paths./api/v1/withdrawals.post.responses');

        $this->assertIsArray($withdrawalResponses);
        $this->assertArrayHasKey('201', $withdrawalResponses);
        $this->assertArrayNotHasKey('200', $withdrawalResponses);
        $this->assertStringContainsString('Withdrawal requested', $withdrawalResponses['201']['description']);
    }

    /**
     * The contact is the company's own website and the email the website shows, never an invented mailbox.
     */
    public function test_openapi_contact_shows_the_same_email_as_the_website(): void
    {
        SiteCustomization::forgetCache();

        $withDefaultEmail = $this->get('/api/v1/openapi.json');
        $this->assertStringNotContainsString('madinanhac', (string) $withDefaultEmail->getContent());
        $this->assertSame('madinanaturalhealthcare@gmail.com', $withDefaultEmail->json('info.contact.email'));
        $this->assertStringEndsWith('/contact', (string) $withDefaultEmail->json('info.contact.url'));

        SiteCustomization::save(['contact_email' => 'office@example.test']);

        $this->get('/api/v1/openapi.json')->assertJsonPath('info.contact.email', 'office@example.test');
    }

    /**
     * Every address the document lists must really exist, with the same request method.
     */
    public function test_every_address_in_the_openapi_document_is_a_real_route(): void
    {
        $known = collect(Route::getRoutes()->getRoutes())
            ->flatMap(fn (RegisteredRoute $route) => collect($route->methods())->map(fn (string $method): string => $method.' /'.$route->uri()))
            ->flip();

        foreach ($this->get('/api/v1/openapi.json')->json('paths') as $path => $operations) {
            foreach (array_keys($operations) as $method) {
                $this->assertTrue($known->has(strtoupper($method).' '.$path), strtoupper($method).' '.$path.' is listed but has no route.');
            }
        }
    }

    /**
     * Test standalone PSR-4 models autoload correctly and define expected Eloquent relationships.
     */
    public function test_standalone_psr4_models_autoload_and_relations_work(): void
    {
        $this->assertTrue(class_exists(PaymentClaim::class));
        $this->assertTrue(class_exists(Receipt::class));
        $this->assertTrue(class_exists(Shipment::class));

        $claim = new PaymentClaim;
        $this->assertInstanceOf(BelongsTo::class, $claim->order());
        $this->assertSame([], $claim->getGuarded());

        $receipt = new Receipt;
        $this->assertInstanceOf(BelongsTo::class, $receipt->order());
        $this->assertSame([], $receipt->getGuarded());

        $shipment = new Shipment;
        $this->assertInstanceOf(BelongsTo::class, $shipment->order());
        $this->assertSame([], $shipment->getGuarded());

        $order = new Order;
        $this->assertInstanceOf(HasMany::class, $order->paymentClaims());
        $this->assertInstanceOf(HasMany::class, $order->receipts());
        $this->assertInstanceOf(HasMany::class, $order->shipments());
    }
}
