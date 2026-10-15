<?php

namespace App\Http\Controllers;

use App\Domain\Finance\PolicyDraftService;
use App\Domain\Inventory\StockService;
use App\Domain\Orders\OrderService;
use App\Http\Requests\Backend\AdjustStockRequest;
use App\Http\Requests\Backend\ClaimPaymentRequest;
use App\Http\Requests\Backend\ConfirmDeliveryRequest;
use App\Http\Requests\Backend\DecideOrderRequest;
use App\Http\Requests\Backend\DispatchOrderRequest;
use App\Http\Requests\Backend\StoreOrderRequest;
use App\Http\Requests\Backend\VerifyPaymentRequest;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Support\Audit;
use App\Support\Money;
use App\Support\SiteCustomization;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CommandController extends Controller
{
    private function permit(Request $r, string $cap): void
    {
        abort_unless($r->user()->allows($cap), 403);
    }

    public function order(StoreOrderRequest $r, OrderService $service)
    {
        $o = $service->create($r->user(), $r->validated());

        return redirect('/workspace/orders/'.$o->id)->with('success', 'Order submitted for review.');
    }

    public function decision(DecideOrderRequest $r, Order $order, OrderService $s)
    {
        $d = $r->validated();
        $s->decision($r->user(), $order, $d['status'], $d['reason'], $r->boolean('allow_credit'));

        return back()->with('success', 'Order decision recorded.');
    }

    public function verify(VerifyPaymentRequest $r, int $claim, OrderService $s)
    {
        $d = $r->validated();
        $s->verify($r->user(), $claim, $d['approve'], $d['reason']);

        return back()->with('success', 'Payment review recorded.');
    }

    public function dispatch(DispatchOrderRequest $r, Order $order, OrderService $s)
    {
        $d = $r->validated();
        $s->dispatch($r->user(), $order, $d);

        return back()->with('success', 'Shipment recorded and stock updated.');
    }

    public function account(Request $r)
    {
        $this->permit($r, 'accounts');
        $roles = ['dealer', 'sub_dealer'];
        if ($r->user()->role === 'super_admin') {
            $roles = [...$roles, ...array_keys(config('madina.roles'))];
        }
        $isPartner = in_array($r->input('role'), ['dealer', 'sub_dealer'], true);
        $d = $r->validate([
            'role' => ['required', Rule::in($roles)],
            'dealer_code' => $isPartner ? ['required', 'string', 'max:40', 'unique:users,dealer_code'] : ['nullable', 'string', 'max:40', 'unique:users,dealer_code'],
            'name' => 'required|string|max:150',
            'email' => 'required|email|max:254|unique:users,email',
            'phone' => 'nullable|string|max:30',
            'division' => 'nullable|string|max:80',
            'district' => 'nullable|string|max:80',
            'latitude' => 'nullable|numeric|between:-90,90',
            'longitude' => 'nullable|numeric|between:-180,180',
            'directory_visible' => 'sometimes|boolean',
            'status' => 'sometimes|in:active,suspended',
            'sponsor_id' => 'nullable|integer|exists:users,id',
            'nid' => 'nullable|string|max:30',
            'reason' => 'nullable|string|max:1000',
            'profile' => 'sometimes|array',
            'profile.name_bn' => 'nullable|string|max:150',
            'profile.father_name' => 'nullable|string|max:150',
            'profile.mother_name' => 'nullable|string|max:150',
            'profile.village' => 'nullable|string|max:150',
            'profile.post_office' => 'nullable|string|max:150',
            'profile.upazila' => 'nullable|string|max:150',
            'profile.union' => 'nullable|string|max:150',
            'profile.pharmacy' => 'nullable|string|max:150',
            'profile.area_code' => 'nullable|string|max:80',
        ]);
        $profileKeys = ['name_bn', 'father_name', 'mother_name', 'village', 'post_office', 'upazila', 'union', 'pharmacy', 'area_code'];
        $profileData = $d['profile'] ?? [];
        foreach ($profileKeys as $key) {
            if ($r->filled($key)) {
                $profileData[$key] = trim((string) $r->string($key));
            }
        }
        $d['profile'] = $profileData;
        if (! empty($d['sponsor_id'])) {
            abort_unless(User::whereKey($d['sponsor_id'])->where('role', 'dealer')->where('status', 'active')->exists(), 422, 'Sponsor must be an active dealer.');
        }
        if (($d['directory_visible'] ?? false) && $d['role'] !== 'dealer') {
            throw ValidationException::withMessages(['directory_visible' => 'Only approved dealer business contacts may be published. Sub-dealer publication is not approved.']);
        }
        $u = DB::transaction(function () use ($r, $d, $isPartner) {
            $role = $d['role'];
            $dealerCode = $isPartner ? trim((string) $d['dealer_code']) : ($d['dealer_code'] ?? null);
            $reason = $d['reason'] ?? 'Account created by company staff';
            unset($d['reason']);
            $u = User::create([
                ...$d,
                'dealer_code' => $dealerCode,
                'password' => Str::random(48),
            ]);
            DB::table('wallets')->insert(['user_id' => $u->id, 'created_at' => now(), 'updated_at' => now()]);
            Audit::record($r->user()->id, 'account.created', (string) $u->id, [
                'role' => $role,
                'dealer_code' => $dealerCode,
                'reason' => $reason,
            ]);

            return $u;
        });
        Password::sendResetLink(['email' => $u->email]);

        return redirect('/company/accounts')->with('success', 'Partner account created. Unique code: '.($u->dealer_code ?? $u->name).'. A private password-setting invitation has been emailed.');
    }

    public function accountUpdate(Request $r, User $user)
    {
        $this->permit($r, 'accounts');
        abort_if($user->staff() && $r->user()->role !== 'super_admin', 403);
        abort_if($r->user()->id === $user->id, 422, 'Use your security page; self-suspension is not allowed.');
        if ($r->exists('nid')) {
            $this->permit($r, 'identity_write');
        }
        $d = $r->validate(['status' => 'sometimes|in:active,suspended', 'name' => 'sometimes|required|string|max:150', 'dealer_code' => 'sometimes|nullable|string|max:40|unique:users,dealer_code,'.$user->id, 'phone' => 'nullable|string|max:30', 'division' => 'nullable|string|max:80', 'district' => 'nullable|string|max:80', 'latitude' => 'sometimes|nullable|numeric|between:-90,90', 'longitude' => 'sometimes|nullable|numeric|between:-180,180', 'sponsor_id' => 'sometimes|nullable|integer|exists:users,id', 'directory_visible' => 'sometimes|boolean', 'reason' => 'required|string|max:1000',
            'profile' => 'sometimes|array', 'profile.name_bn' => 'nullable|string|max:150', 'profile.father_name' => 'nullable|string|max:150', 'profile.mother_name' => 'nullable|string|max:150', 'profile.village' => 'nullable|string|max:150', 'profile.post_office' => 'nullable|string|max:150', 'profile.upazila' => 'nullable|string|max:150', 'profile.union' => 'nullable|string|max:150', 'profile.pharmacy' => 'nullable|string|max:150', 'profile.area_code' => 'nullable|string|max:80', 'nid' => 'sometimes|nullable|string|max:30']);
        if (($d['directory_visible'] ?? false) && $user->role !== 'dealer') {
            throw ValidationException::withMessages(['directory_visible' => 'Only approved dealer business contacts may be published. Sub-dealer publication is not approved.']);
        }
        if (! empty($d['sponsor_id'])) {
            abort_unless(User::whereKey($d['sponsor_id'])->where('role', 'dealer')->where('status', 'active')->exists(), 422, 'Sponsor must be an active dealer.');
        }
        $profileKeys = ['name_bn', 'father_name', 'mother_name', 'village', 'post_office', 'upazila', 'union', 'pharmacy', 'area_code'];
        if ($r->has('profile') || $r->hasAny($profileKeys)) {
            $existing = $user->profile ?? [];
            $incoming = $d['profile'] ?? [];
            foreach ($profileKeys as $key) {
                if ($r->filled($key)) {
                    $incoming[$key] = trim((string) $r->string($key));
                }
            }
            $d['profile'] = array_merge($existing, $incoming);
        }
        DB::transaction(function () use ($r, $user, $d) {
            $reason = $d['reason'];
            unset($d['reason']);
            $locked = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $locked->update($d);
            if (($d['status'] ?? '') === 'suspended') {
                $locked->update(['remember_token' => Str::random(60)]);
                DB::table('sessions')->where('user_id', $user->id)->delete();
            } Audit::record($r->user()->id, 'account.updated', (string) $user->id, ['fields' => array_keys($d), 'reason' => $reason]);
        });

        return back()->with('success', 'Account updated.');
    }

    public function product(Request $r)
    {
        $this->permit($r, 'catalog');
        abort_unless($r->user()->role === 'super_admin', 403);
        $d = $r->validate(['name' => 'required|string|max:150', 'sku' => 'required|string|max:60|unique:products', 'category' => 'required|string|max:100', 'description' => 'nullable|string|max:3000',
            'mrp' => 'required|regex:/^\d{1,8}(\.\d{1,2})?$/', 'dealer_price' => 'nullable|regex:/^\d{1,8}(\.\d{1,2})?$/', 'unit' => 'required|string|max:30']);
        if (Money::cents($d['mrp']) <= 0 || (isset($d['dealer_price']) && (Money::cents($d['dealer_price']) <= 0 || Money::cents($d['dealer_price']) > Money::cents($d['mrp'])))) {
            throw ValidationException::withMessages(['mrp' => 'Enter positive prices; dealer price cannot exceed MRP.']);
        }
        DB::transaction(function () use ($r, $d) {
            $p = Product::create($d);
            Audit::record($r->user()->id, 'product.created', (string) $p->id);
        });

        return back()->with('success', 'Product created. Receive stock before dispatch.');
    }

    public function stock(AdjustStockRequest $r, StockService $service)
    {
        $service->adjust($r->user(), $r->validated());

        return back()->with('success', 'Stock movement recorded.');
    }

    public function policy(Request $r, PolicyDraftService $service)
    {
        $this->permit($r, 'finance');
        $d = $r->validate(['name' => 'required|string|max:150', 'account_type' => 'required|in:dealer,sub_dealer', 'components' => 'required|array|min:1|max:20', 'components.*.name' => 'required|string|max:80', 'components.*.rate' => 'required|numeric|min:0|max:100', 'notes' => 'required|string|max:4000']);
        $service->create($r->user(), $d);

        return back()->with('success', 'Draft saved. No earnings will be posted.');
    }

    public function content(Request $r)
    {
        $this->permit($r, 'content');
        $d = $r->validate(['slug' => 'required|in:about,contact,home,notice', 'title' => 'required|string|max:150', 'body' => 'required|string|max:10000', 'published' => 'required|boolean']);
        DB::transaction(function () use ($r, $d) {
            DB::table('content_pages')->updateOrInsert(['slug' => $d['slug']], [...$d, 'updated_at' => now()]);
            Audit::record($r->user()->id, 'content.updated', $d['slug']);
        });

        return back()->with('success', 'Content saved.');
    }

    public function customization(Request $r)
    {
        $this->permit($r, 'content');
        $payload = $r->input('customization', []);
        if (! is_array($payload)) {
            $payload = [];
        }
        SiteCustomization::save($payload);
        Audit::record($r->user()->id, 'customization.updated', 'frontend');

        return back()->with('success', 'Site customization saved successfully.');
    }

    public function resetCustomization(Request $r)
    {
        $this->permit($r, 'content');
        SiteCustomization::reset();
        Audit::record($r->user()->id, 'customization.reset', 'frontend');

        return back()->with('success', 'Customization reset to default placeholders.');
    }

    public function enquiry(Request $r, int $id)
    {
        $this->permit($r, 'content');
        $d = $r->validate(['status' => 'required|in:new,contacted,closed']);
        abort_unless(DB::table('enquiries')->where('id', $id)->exists(), 404);
        DB::table('enquiries')->where('id', $id)->update([...$d, 'updated_at' => now()]);
        Audit::record($r->user()->id, 'enquiry.updated', (string) $id);

        return back()->with('success', 'Enquiry updated.');
    }

    public function readNotice(Request $r, int $id)
    {
        abort_unless(DB::table('account_notices')->where('id', $id)->where('user_id', $r->user()->id)->update(['read_at' => now()]), 404);

        return back();
    }

    public function financialGate()
    {
        throw ValidationException::withMessages(['policy' => 'This action is unavailable until the financial policy is approved and verified.']);
    }

    public function claim(ClaimPaymentRequest $r, Order $order, OrderService $service)
    {
        $data = $r->validated();
        $service->claim($r->user(), $order, $data);

        return back()->with('success', 'Payment reference submitted for verification.');
    }

    public function delivered(ConfirmDeliveryRequest $r, Order $order, int $shipment, OrderService $service)
    {
        $data = $r->validated();
        $service->deliver($r->user(), $order, $shipment, $data['reason']);

        return back()->with('success', 'Delivery confirmation recorded.');
    }

    public function invite(Request $r, User $user)
    {
        $this->permit($r,'accounts');
        abort_if($user->staff() && $r->user()->role !== 'super_admin',403);
        abort_unless($user->status === 'active',422);
        Password::sendResetLink(['email' => $user->email]);
        Audit::record($r->user()->id,'account.invitation_sent',(string) $user->id);

        return back()->with('success','A private password-setting link has been emailed if the account is eligible.');
    }
}
