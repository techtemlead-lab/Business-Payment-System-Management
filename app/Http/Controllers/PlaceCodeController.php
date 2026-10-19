<?php

namespace App\Http\Controllers;

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
            'code' => 'required|string|max:32',
            'name' => 'required|string|max:128',
            'parent_id' => 'nullable|integer|exists:place_codes,id',
            'base_place_id' => 'nullable|integer|exists:place_codes,id',
            'sort_order' => 'nullable|integer|min:0|max:999999',
            'is_active' => 'nullable|boolean',
        ]);
        $place = $catalog->create($data['level'], $data, $request->user()->id);
        if ($request->expectsJson()) {
            return response()->json(['data' => $place], 201);
        }

        return back()->with('success', 'Place code '.$place->code.' saved.');
    }

    public function update(Request $request, PlaceCode $placeCode, PlaceCodeCatalog $catalog): RedirectResponse|JsonResponse
    {
        $this->permit($request);
        $data = $request->validate([
            'code' => 'sometimes|required|string|max:32',
            'name' => 'sometimes|required|string|max:128',
            'parent_id' => 'nullable|integer|exists:place_codes,id',
            'base_place_id' => 'nullable|integer|exists:place_codes,id',
            'sort_order' => 'nullable|integer|min:0|max:999999',
            'is_active' => 'nullable|boolean',
        ]);
        $place = $catalog->update($placeCode, $data, $request->user()->id);
        if ($request->expectsJson()) {
            return response()->json(['data' => $place]);
        }

        return back()->with('success', 'Place code '.$place->code.' updated.');
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
            ->when(isset($data['parent_id']), fn ($builder) => $builder->where('parent_id', $data['parent_id']))
            ->when(($data['q'] ?? '') !== '', function ($builder) use ($data): void {
                $term = '%'.mb_strtolower(trim($data['q'])).'%';
                $builder->where(function ($inner) use ($term): void {
                    $inner->whereRaw('LOWER(code) LIKE ?', [$term])->orWhereRaw('LOWER(name) LIKE ?', [$term]);
                });
            })
            ->orderBy('sort_order')
            ->orderBy('code')
            ->limit(100)
            ->get(['id', 'code', 'name', 'level', 'parent_id']);

        return response()->json(['data' => $query]);
    }

    private function permit(Request $request): void
    {
        abort_unless($request->user()?->allows('accounts'), 403);
    }
}
