<?php

namespace App\Http\Controllers;

use App\Domain\Commissions\CommissionWallet;
use App\Domain\Finance\DueAdjustments;
use App\Domain\Finance\FinanceSettings;
use App\Domain\Staff\StaffPermissions;
use App\Models\Order;
use App\Models\Product;
use App\Support\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class ModuleCompletionController extends Controller
{
    public function adjustment(Request $request, Order $order, DueAdjustments $adjustments): Response
    {
        $data = $request->validate(['kind' => 'required|in:credit,debit_correction', 'amount' => [...FinanceOperationsController::Amount, 'gt:0'],
            'reference' => 'required|string|max:100', 'reason' => FinanceOperationsController::Reason, 'request_key' => 'required|uuid']);

        return $this->done($request, ['id' => $adjustments->request($request->user(), $order, $data)]);
    }

    public function reviewAdjustment(Request $request, int $id, DueAdjustments $adjustments): Response
    {
        $data = $request->validate(['decision' => 'required|in:approved,rejected', 'reason' => FinanceOperationsController::Reason]);
        $adjustments->review($request->user(), $id, $data['decision'], $data['reason']);

        return $this->done($request);
    }

    public function minimum(Request $request, FinanceSettings $settings): Response
    {
        $data = $request->validate(['amount' => [...FinanceOperationsController::Amount, 'gt:0'], 'version' => 'required|integer|min:0', 'reason' => FinanceOperationsController::Reason]);
        $settings->saveMinimum($request->user(), $data['amount'], (int) $data['version'], $data['reason']);

        return $this->done($request);
    }

    public function role(Request $request, StaffPermissions $permissions): Response
    {
        abort_unless($request->user()->role === 'super_admin', 403);
        $data = $request->validate(['key' => 'required|string|max:60', 'name' => 'required|string|max:100', 'permissions' => 'present|array',
            'version' => 'required|integer|min:0', 'reason' => FinanceOperationsController::Reason]);
        $key = $permissions->saveRole($request->user(), $data['key'], $data['name'], $data['permissions'], (int) $data['version'], $data['reason']);

        return $this->done($request, ['key' => $key]);
    }

    public function staffAccess(Request $request, StaffPermissions $permissions): Response
    {
        abort_unless($request->user()->role === 'super_admin', 403);
        $data = $request->validate(['staff_id' => 'required|integer|exists:users,id', 'permissions' => 'present|array',
            'version' => 'required|string|size:64', 'role' => 'nullable|string|max:60', 'reason' => FinanceOperationsController::Reason]);
        $permissions->assign($request->user(), (int) $data['staff_id'], $data['permissions'], $data['version'], $data['reason'], $data['role'] ?? null);

        return $this->done($request);
    }

    public function homepageVideo(Request $request): Response
    {
        $data = $request->validate(['enabled' => 'required|boolean', 'product_id' => 'nullable|integer|exists:products,id', 'title' => 'required|string|max:150',
            'description' => 'nullable|string|max:1000', 'version' => 'required|integer|min:0', 'reason' => FinanceOperationsController::Reason]);
        DB::transaction(function () use ($request, $data): void {
            CommissionWallet::lock();
            $actor = $request->user()->fresh();
            abort_unless($actor->canAct('content', 'edit') || $actor->canAct('catalog', 'edit'), 403);
            $before = DB::table('business_settings')->where('key', 'homepage_video')->first();
            if (($before?->version ?? 0) !== (int) $data['version']) {
                throw ValidationException::withMessages(['version' => __('portal.ops_val_homepage_stale')]);
            }
            if ($data['enabled']) {
                $product = Product::where('active', true)->find($data['product_id']);
                if (! $product || ! ($product->media()['video_url'] || $product->media()['video_embed_url'])) {
                    throw ValidationException::withMessages(['product_id' => __('portal.ops_val_homepage_product')]);
                }
            }
            $value = collect($data)->only(['enabled', 'product_id', 'title', 'description'])->all();
            $value['enabled'] = (bool) $value['enabled'];
            DB::table('business_settings')->updateOrInsert(['key' => 'homepage_video'], ['value' => json_encode($value, JSON_THROW_ON_ERROR),
                'version' => (int) $data['version'] + 1, 'updated_by' => $actor->id, 'created_at' => $before?->created_at ?? now(), 'updated_at' => now()]);
            Audit::record($actor->id, 'homepage.video_saved', 'homepage_video', ['before' => $before ? json_decode($before->value, true, 512, JSON_THROW_ON_ERROR) : null, 'after' => $value, 'reason' => $data['reason']]);
        }, 3);

        return $this->done($request);
    }

    /** @param array<string, mixed> $data */
    private function done(Request $request, array $data = []): Response
    {
        return $request->expectsJson()
            ? response()->json(['data' => $data])
            : back()->with('success', __('portal.flash_changes_recorded'));
    }
}
