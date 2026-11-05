<?php

namespace App\Http\Portals\Shared\Sections;

use App\Models\User;
use App\Support\DashboardManagement;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Partner accounts: dealers, sub-dealers, their applications, and the dashboard settings screen.
 */
final class AccountsSection implements WorkspaceSection
{
    public function data(SectionContext $context, array $data): array
    {
        return match ($context->section) {
            'accounts' => $this->partnerAccounts($context->request, 'dealer'),
            'sub-dealers' => $this->partnerAccounts($context->request, 'sub_dealer'),
            'dashboards' => $this->dashboards($context->user),
            'partnerships' => $this->partnerApplications('dealer'),
            'sub-dealer-applications' => $this->partnerApplications('sub_dealer'),
            default => [],
        };
    }

    /**
     * Paged dealer or sub-dealer accounts, and the active dealers who can sponsor them.
     *
     * @return array<string, mixed>
     */
    private function partnerAccounts(Request $request, string $role): array
    {
        $props = [];
        $props['accounts'] = User::where('role', $role)
            ->with('sponsor:id,name,dealer_code')
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('q'), function ($q) use ($request) {
                $term = '%'.$request->string('q').'%';
                $q->where(function ($sub) use ($term) {
                    $sub->where('name', 'ilike', $term)
                        ->orWhere('dealer_code', 'ilike', $term)
                        ->orWhere('email', 'ilike', $term)
                        ->orWhere('phone', 'ilike', $term)
                        ->orWhere('profile->shop_name', 'ilike', $term);
                });
            })->orderByDesc('id')->paginate(20, ['id', 'name', 'email', 'phone', 'role', 'linked_staff_role', 'status', 'dealer_code', 'division', 'district', 'latitude', 'longitude', 'directory_visible', 'profile', 'sponsor_id', 'created_at']);
        $props['sponsors'] = User::where('role', 'dealer')->where('status', 'active')->orderBy('name')->get(['id', 'name', 'dealer_code', 'district']);

        return $props;
    }

    /**
     * Dashboard settings with account counts and the newest employees.
     *
     * @return array<string, mixed>
     */
    private function dashboards(User $user): array
    {
        abort_unless($user->role === 'super_admin' || $user->allows('accounts'), 403);
        $props = [];
        $props['dashboard_settings'] = DashboardManagement::get();
        $props['dashboard_defaults'] = DashboardManagement::defaults();
        $props['stats'] = [
            'dealers_count' => User::where('role', 'dealer')->count(),
            'active_dealers' => User::where('role', 'dealer')->where('status', 'active')->count(),
            'sub_dealers_count' => User::where('role', 'sub_dealer')->count(),
            'active_sub_dealers' => User::where('role', 'sub_dealer')->where('status', 'active')->count(),
            'employees_count' => User::where('role', 'employee')->count(),
            'active_employees' => User::where('role', 'employee')->where('status', 'active')->count(),
            'super_admins_count' => User::where('role', 'super_admin')->count(),
        ];
        $props['recent_employees'] = User::where('role', 'employee')->latest()->limit(6)->get(['id', 'name', 'email', 'status', 'profile', 'created_at']);

        return $props;
    }

    /**
     * @return array{applications: LengthAwarePaginator<int, array<string, mixed>>, application_counts: array{total: int, pending: int, approved: int, rejected: int}}
     */
    private function partnerApplications(string $role): array
    {
        $applications = DB::table('dealer_applications')
            ->select(['id', 'email', 'data', 'submitted_by', 'status', 'user_id', 'reason', 'source', 'created_at', 'reviewed_by'])
            ->whereRaw("coalesce(data->>'role', 'dealer') = ?", [$role])
            ->orderByDesc('created_at')
            ->paginate(20)
            ->withQueryString()
            ->through(function (object $row): array {
                $payload = json_decode((string) ($row->data ?? '{}'), true);
                if (! is_array($payload)) {
                    $payload = [];
                }

                return [
                    'id' => $row->id,
                    'email' => $row->email,
                    'name' => $payload['name'] ?? '—',
                    'phone' => $payload['phone'] ?? null,
                    'role' => $payload['role'] ?? 'dealer',
                    'division' => $payload['division'] ?? null,
                    'district' => $payload['district'] ?? null,
                    'upazila' => $payload['upazila'] ?? null,
                    'message' => $payload['message'] ?? null,
                    'status' => $row->status,
                    'source' => $row->source ?? 'staff',
                    'submitted_by' => $row->submitted_by,
                    'user_id' => $row->user_id,
                    'reason' => $row->reason,
                    'created_at' => $row->created_at,
                ];
            });
        $counts = DB::table('dealer_applications')
            ->selectRaw('count(*) as total')
            ->selectRaw("count(*) filter (where status = 'pending') as pending")
            ->selectRaw("count(*) filter (where status = 'approved') as approved")
            ->selectRaw("count(*) filter (where status = 'rejected') as rejected")
            ->whereRaw("coalesce(data->>'role', 'dealer') = ?", [$role])
            ->first();

        return [
            'applications' => $applications,
            'application_counts' => [
                'total' => (int) ($counts->total ?? 0),
                'pending' => (int) ($counts->pending ?? 0),
                'approved' => (int) ($counts->approved ?? 0),
                'rejected' => (int) ($counts->rejected ?? 0),
            ],
        ];
    }
}
