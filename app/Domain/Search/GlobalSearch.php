<?php

namespace App\Domain\Search;

use App\Domain\Inventory\BranchOperations;
use App\Domain\Inventory\BranchScope;
use App\Domain\Orders\OrderListFilter;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Support\PhoneIdentity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * The search box in the office portal header (Ctrl+K). Each group is only searched when the
 * person may open that list, and orders and partners follow the branch picked in the top bar.
 *
 * It reads orders, dealer and sub-dealer accounts and products only: patients are private
 * to their own prescriber, so the clinic tables are never touched here.
 */
final class GlobalSearch
{
    private const PerGroup = 6;

    /** Fewest digits a search needs before it is compared with phone numbers. */
    private const MinimumPhoneDigits = 4;

    public function __construct(private readonly BranchOperations $branches) {}

    /**
     * @return array{
     *     orders: list<array{id: string, number: string, status: string, partner: string|null, partner_code: string|null}>,
     *     partners: list<array{id: int, name: string, code: string|null, role: string, status: string, phone: string}>,
     *     products: list<array{id: int, name: string, sku: string, category: string|null, active: bool}>
     * }
     */
    public function run(User $actor, Request $request, string $term): array
    {
        $term = self::withLatinDigits(trim($term));
        $scope = $this->branches->scopeFor($actor, $request);

        return [
            'orders' => $this->mayOpenOrders($actor) ? $this->orders($term, $scope) : [],
            'partners' => $this->mayOpenPartners($actor) ? $this->partners($term, $scope) : [],
            'products' => $this->mayOpenProducts($actor) ? $this->products($term) : [],
        ];
    }

    private function mayOpenOrders(User $actor): bool
    {
        return $actor->allows('orders') || $actor->allows('finance');
    }

    private function mayOpenPartners(User $actor): bool
    {
        return $actor->owner() || $actor->allows('accounts');
    }

    private function mayOpenProducts(User $actor): bool
    {
        foreach (['catalog', 'pricing', 'inventory', 'orders', 'finance'] as $capability) {
            if ($actor->allows($capability)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<array{id: string, number: string, status: string, partner: string|null, partner_code: string|null}>
     */
    private function orders(string $term, BranchScope $scope): array
    {
        $query = Order::query()->where('orders.number', 'ilike', $this->contains($term));
        $scope->restrict($query, 'orders.fulfillment_branch_id');

        return $query->with('user:id,name,dealer_code')
            ->latest()
            ->limit(self::PerGroup)
            ->get(['orders.id', 'orders.number', 'orders.status', 'orders.user_id', 'orders.created_at'])
            ->map(function (Order $order): array {
                /** @var User|null $buyer */
                $buyer = $order->user;

                return [
                    'id' => (string) $order->id,
                    'number' => (string) $order->number,
                    'status' => (string) $order->status,
                    'partner' => $buyer?->name,
                    'partner_code' => $buyer?->dealer_code,
                ];
            })->all();
    }

    /**
     * Phone numbers can be searched but are only ever returned masked, the owner included.
     *
     * @return list<array{id: int, name: string, code: string|null, role: string, status: string, phone: string}>
     */
    private function partners(string $term, BranchScope $scope): array
    {
        $like = $this->contains($term);
        $digits = preg_replace('/\D+/', '', $term) ?: '';
        $searchesPhone = strlen($digits) >= self::MinimumPhoneDigits && preg_match('/^[\d\s()+.\-]+$/D', $term) === 1;
        $query = User::query()
            ->whereIn('users.role', ['dealer', 'sub_dealer'])
            ->where(function (Builder $match) use ($like, $digits, $searchesPhone): void {
                $match->where('users.name', 'ilike', $like)->orWhere('users.dealer_code', 'ilike', $like);
                if ($searchesPhone) {
                    $match->orWhereRaw("regexp_replace(users.phone, '[^0-9]', '', 'g') LIKE ?", ['%'.$this->localNumber($digits).'%']);
                }
            });
        $scope->restrictPartners($query);

        return $query->orderBy('users.name')
            ->limit(self::PerGroup)
            ->get(['users.id', 'users.name', 'users.dealer_code', 'users.role', 'users.status', 'users.phone'])
            ->map(fn (User $partner): array => [
                'id' => (int) $partner->id,
                'name' => (string) $partner->name,
                'code' => $partner->dealer_code,
                'role' => (string) $partner->role,
                'status' => (string) $partner->status,
                'phone' => PhoneIdentity::masked($partner->phone),
            ])->all();
    }

    /**
     * @return list<array{id: int, name: string, sku: string, category: string|null, active: bool}>
     */
    private function products(string $term): array
    {
        $like = $this->contains($term);

        return Product::query()
            ->where(fn (Builder $match) => $match->where('name', 'ilike', $like)->orWhere('sku', 'ilike', $like))
            ->orderBy('name')
            ->limit(self::PerGroup)
            ->get(['id', 'name', 'sku', 'category', 'active'])
            ->map(fn (Product $product): array => [
                'id' => (int) $product->id,
                'name' => (string) $product->name,
                'sku' => (string) $product->sku,
                'category' => $product->category,
                'active' => (bool) $product->active,
            ])->all();
    }

    private function contains(string $term): string
    {
        return '%'.OrderListFilter::escapeLike($term).'%';
    }

    /** "8801712345678" also finds a number stored as "01712345678". */
    private function localNumber(string $digits): string
    {
        return str_starts_with($digits, '880') ? substr($digits, 2) : $digits;
    }

    /** Office staff may type a number in Bangla digits. */
    private static function withLatinDigits(string $term): string
    {
        return strtr($term, ['০' => '0', '১' => '1', '২' => '2', '৩' => '3', '৪' => '4', '৫' => '5', '৬' => '6', '৭' => '7', '৮' => '8', '৯' => '9']);
    }
}
