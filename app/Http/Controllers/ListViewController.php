<?php

namespace App\Http\Controllers;

use App\Domain\Lists\ListViews;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Saved filters and the column chooser on the office lists, always for the signed-in person.
 */
class ListViewController extends Controller
{
    public function __construct(private readonly ListViews $views) {}

    public function index(Request $request, string $list): JsonResponse
    {
        ListViews::assertMayUse($request->user(), $list);

        return $this->current($request, $list);
    }

    public function store(Request $request, string $list): JsonResponse
    {
        ListViews::assertMayUse($request->user(), $list);
        $data = $request->validate(['name' => 'required|string|max:60', 'filters' => 'required|array']);
        $this->views->save($request->user(), $list, $data['name'], $data['filters']);

        return $this->current($request, $list);
    }

    public function rename(Request $request, string $list, int $view): JsonResponse
    {
        ListViews::assertMayUse($request->user(), $list);
        $data = $request->validate(['name' => 'required|string|max:60']);
        $this->views->rename($request->user(), $list, $view, $data['name']);

        return $this->current($request, $list);
    }

    public function destroy(Request $request, string $list, int $view): JsonResponse
    {
        ListViews::assertMayUse($request->user(), $list);
        $this->views->delete($request->user(), $list, $view);

        return $this->current($request, $list);
    }

    public function columns(Request $request, string $list): JsonResponse
    {
        ListViews::assertMayUse($request->user(), $list);
        $data = $request->validate(['hidden' => 'present|array|max:30', 'hidden.*' => ['string', 'max:40', 'regex:/^[a-z_]+$/D']]);
        $this->views->saveHiddenColumns($request->user(), $list, $data['hidden']);

        return $this->current($request, $list);
    }

    private function current(Request $request, string $list): JsonResponse
    {
        return response()->json(['data' => $this->views->forUser($request->user(), $list)])
            ->header('Cache-Control', 'private, no-store');
    }
}
