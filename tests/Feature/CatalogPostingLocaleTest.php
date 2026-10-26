<?php

namespace Tests\Feature;

use App\Domain\Inventory\StockService;
use App\Domain\Staff\StaffPermissions;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class CatalogPostingLocaleTest extends TestCase
{
    use RefreshDatabase;

    public function test_duplicate_stock_reference_returns_bangla_validation(): void
    {
        app()->setLocale('bn');
        $actor = User::factory()->staff('inventory')->create();
        $product = Product::factory()->create(['stock' => 10, 'active' => true]);
        $reference = 'LOCALE-STOCK-'.Str::upper(Str::random(6));
        $stock = app(StockService::class);
        $stock->adjust($actor, [
            'product_id' => $product->id,
            'quantity' => 1,
            'reference' => $reference,
            'reason' => 'First locale stock op',
        ]);

        try {
            $stock->adjust($actor, [
                'product_id' => $product->id,
                'quantity' => 1,
                'reference' => $reference,
                'reason' => 'Duplicate locale stock op',
            ]);
            $this->fail('Expected ValidationException for duplicate stock reference');
        } catch (ValidationException $exception) {
            $this->assertSame(
                __('portal.err_stock_ref_dup', [], 'bn'),
                $exception->errors()['reference'][0] ?? null,
            );
        }
    }

    public function test_unknown_permission_module_returns_bangla_validation(): void
    {
        app()->setLocale('bn');

        try {
            app(StaffPermissions::class)->validateMatrix([
                'not_a_real_module' => ['view' => true],
            ]);
            $this->fail('Expected ValidationException for unknown permission module');
        } catch (ValidationException $exception) {
            $this->assertSame(
                __('portal.err_perm_unknown_module', [], 'bn'),
                $exception->errors()['permissions'][0] ?? null,
            );
        }
    }
}
