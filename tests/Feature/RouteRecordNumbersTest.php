<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use App\Providers\AppServiceProvider;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route;
use Illuminate\Support\Str;
use ReflectionNamedType;
use Tests\TestCase;

/**
 * Bots try addresses like /products/madinanhc.com/image. A record is found by its number, so text in its place
 * must be "not found", never a database error (a 500 page and an error report for every scan).
 */
class RouteRecordNumbersTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_scanner_address_with_text_for_a_product_number_is_not_found(): void
    {
        $this->get('/products/madinanhc.com/image')->assertNotFound();
        $this->get('/products/sms:/video')->assertNotFound();
        $this->get('/products/madinanhc.com')->assertNotFound();
    }

    public function test_a_real_product_number_still_opens_the_product_page(): void
    {
        $this->withoutVite();
        $product = Product::factory()->create(['active' => true]);

        $this->get('/products/'.$product->id)->assertOk();
    }

    public function test_every_record_looked_up_from_an_address_only_accepts_a_number(): void
    {
        $unguarded = [];
        /** @var Route $route */
        foreach (app('router')->getRoutes() as $route) {
            foreach ($route->signatureParameters() as $parameter) {
                $type = $parameter->getType();
                $name = $parameter->getName();
                if (! $type instanceof ReflectionNamedType
                    || $type->isBuiltin()
                    || ! is_subclass_of($type->getName(), Model::class)
                    || ! in_array($name, $route->parameterNames(), true)
                    || $route->bindingFieldFor($name) !== null) {
                    continue;
                }
                $usesUuid = in_array(HasUuids::class, class_uses_recursive($type->getName()), true);
                $allowed = $usesUuid ? [AppServiceProvider::UuidPattern] : ['[0-9]+'];
                if (! in_array($route->wheres[$name] ?? null, $allowed, true)) {
                    $unguarded[] = $route->uri().' {'.$name.'}';
                }
            }
        }

        $this->assertSame([], array_values(array_unique($unguarded)), 'Add these names to AppServiceProvider::NumberedRouteRecords (or UuidRouteRecords).');
    }

    public function test_an_order_address_still_takes_its_uuid_and_text_is_not_found(): void
    {
        $dealer = User::factory()->create(['role' => 'dealer', 'status' => 'active']);
        $orderPage = collect(app('router')->getRoutes()->getRoutes())
            ->first(fn (Route $route): bool => $route->uri() === 'orders/{order}' && in_array('GET', $route->methods(), true));

        $this->assertNotNull($orderPage);
        $this->assertMatchesRegularExpression($orderPage->toSymfonyRoute()->compile()->getRegex(), '/orders/'.Str::uuid());
        $this->actingAs($dealer)->onPortal('dealer')->get('/orders/madinanhc.com')->assertNotFound();
    }
}
