<?php

namespace Tests\Feature;

use App\Domain\Geography\PlaceCodeCatalog;
use App\Models\PlaceCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PlaceCodeCatalogLocaleTest extends TestCase
{
    use RefreshDatabase;

    public function test_invalid_place_code_format_returns_bangla_validation(): void
    {
        app()->setLocale('bn');

        try {
            app(PlaceCodeCatalog::class)->create(PlaceCode::LevelManagement, [
                'code' => 'not-a-code',
                'name' => 'Test',
            ]);
            $this->fail('Expected ValidationException for invalid place code format');
        } catch (ValidationException $exception) {
            $this->assertSame(
                __('portal.err_pc_code_format', [], 'bn'),
                $exception->errors()['code'][0] ?? null,
            );
        }
    }

    public function test_module_payload_exposes_bangla_level_labels(): void
    {
        app()->setLocale('bn');

        $payload = app(PlaceCodeCatalog::class)->modulePayload(PlaceCode::LevelDivision);

        $this->assertSame(__('portal.pc_lvl_division', [], 'bn'), $payload['level_label']);
        $this->assertSame(__('portal.pc_lvl_management', [], 'bn'), $payload['submodules'][0]['label'] ?? null);
    }
}
