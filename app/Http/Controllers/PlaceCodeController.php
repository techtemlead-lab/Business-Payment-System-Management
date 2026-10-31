<?php

namespace App\Http\Controllers;

use App\Domain\Geography\ManagementReferral;
use App\Domain\Geography\PlaceCodeCatalog;
use App\Models\PlaceCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class PlaceCodeController extends Controller
{
    public function store(Request $request, PlaceCodeCatalog $catalog): RedirectResponse|JsonResponse
    {
        $this->permit($request);
        $data = $request->validate([
            'level' => 'required|in:'.implode(',', PlaceCode::Levels),
            'code' => 'nullable|string|max:32',
            'name' => 'nullable|string|max:128',
            'parent_id' => 'nullable|integer|exists:place_codes,id',
            'base_place_id' => 'nullable|integer|exists:place_codes,id',
            'sort_order' => 'nullable|integer|min:0|max:999999',
            'is_active' => 'nullable|boolean',
        ]);
        $place = $catalog->create($data['level'], $data, $request->user()->id);
        $label = PlaceCode::displayLabel($place->code);
        if ($request->expectsJson()) {
            return response()->json(['data' => $place], 201);
        }

        return back()->with('success', __('portal.flash_place_code_saved', ['code' => $label]));
    }

    public function update(Request $request, PlaceCode $placeCode, PlaceCodeCatalog $catalog): RedirectResponse|JsonResponse
    {
        $this->permit($request);
        $data = $request->validate([
            'code' => 'sometimes|nullable|string|max:32',
            'name' => 'sometimes|nullable|string|max:128',
            'parent_id' => 'nullable|integer|exists:place_codes,id',
            'base_place_id' => 'nullable|integer|exists:place_codes,id',
            'sort_order' => 'nullable|integer|min:0|max:999999',
            'is_active' => 'nullable|boolean',
        ]);
        $place = $catalog->update($placeCode, $data, $request->user()->id);
        $label = PlaceCode::displayLabel($place->code);
        if ($request->expectsJson()) {
            return response()->json(['data' => $place]);
        }

        return back()->with('success', __('portal.flash_place_code_updated', ['code' => $label]));
    }

    public function destroy(Request $request, PlaceCode $placeCode, PlaceCodeCatalog $catalog): RedirectResponse|JsonResponse
    {
        $this->permit($request);
        $data = $request->validate([
            'reason' => 'required|string|min:3|max:1000',
        ]);
        $label = PlaceCode::displayLabel($placeCode->code);
        $catalog->delete($placeCode, $data['reason'], $request->user()->id);
        if ($request->expectsJson()) {
            return response()->json(['data' => ['deleted' => true]]);
        }

        return back()->with('success', __('portal.flash_place_code_deleted', ['code' => $label]));
    }

    public function updateManagementReferral(Request $request, ManagementReferral $referral): RedirectResponse|JsonResponse
    {
        abort_unless($request->user()?->owner(), 403);
        $data = $request->validate([
            'enabled' => 'required|boolean',
            'auto_blank_reference' => 'required|boolean',
            'route_referral_slices' => 'required|boolean',
            'place_code_id' => 'nullable|integer|exists:place_codes,id',
            'version' => 'required|integer|min:0',
        ]);
        $placeId = $data['place_code_id'] ?? null;
        $referral->save($request->user(), [
            'enabled' => $request->boolean('enabled'),
            'auto_blank_reference' => $request->boolean('auto_blank_reference'),
            'route_referral_slices' => $request->boolean('route_referral_slices'),
            'place_code_id' => $placeId !== null ? (int) $placeId : null,
            'version' => (int) $data['version'],
        ]);
        if ($request->expectsJson()) {
            return response()->json(['data' => $referral->settings()]);
        }

        return back()->with('success', __('portal.flash_management_referral_saved'));
    }

    public function index(Request $request, PlaceCodeCatalog $catalog): JsonResponse
    {
        $this->permit($request);
        $level = (string) $request->query('level', PlaceCode::LevelDivision);
        $query = $request->query('q');

        return response()->json([
            'data' => $catalog->modulePayload($level, is_string($query) ? $query : null),
        ]);
    }

    public function children(Request $request): JsonResponse
    {
        $this->permit($request);
        $data = $request->validate([
            'parent_id' => 'nullable|integer|exists:place_codes,id',
            'level' => 'required|in:'.implode(',', PlaceCode::Levels),
            'q' => 'nullable|string|max:80',
        ]);
        $query = PlaceCode::query()
            ->where('level', $data['level'])
            ->where('is_active', true)
            ->whereNotNull('code')
            ->when(isset($data['parent_id']), fn ($builder) => $builder->where('parent_id', $data['parent_id']))
            ->when(($data['q'] ?? '') !== '', function ($builder) use ($data): void {
                $term = '%'.mb_strtolower(trim($data['q'])).'%';
                $builder->where(function ($inner) use ($term): void {
                    $inner->whereRaw('LOWER(COALESCE(code, \'\')) LIKE ?', [$term])
                        ->orWhereRaw('LOWER(name) LIKE ?', [$term]);
                });
            })
            ->orderBy('sort_order')
            ->orderBy('code')
            ->limit(100)
            ->get(['id', 'code', 'name', 'level', 'parent_id', 'is_active'])
            ->filter(fn (PlaceCode $place): bool => $place->isReadyForAssignment())
            ->values()
            ->map(fn (PlaceCode $place): array => [
                'id' => $place->id,
                'code' => PlaceCode::displayLabel($place->code),
                'name' => PlaceCode::displayLabel($place->name),
                'level' => $place->level,
                'parent_id' => $place->parent_id,
            ]);

        return response()->json(['data' => $query]);
    }

    private function permit(Request $request): void
    {
        abort_unless($request->user()?->allows('accounts'), 403);
    }
}
