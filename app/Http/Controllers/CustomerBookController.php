<?php

namespace App\Http\Controllers;

use App\Domain\Accounts\AccountPhoneRules;
use App\Domain\Accounts\CustomerBook;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class CustomerBookController extends Controller
{
    public function __construct(private CustomerBook $book, private AccountPhoneRules $phones) {}

    public function store(Request $request): RedirectResponse
    {
        CustomerBook::assertPartner($request->user());
        $this->book->add($request->user(), $this->validated($request));

        return back()->with('success', __('dealer_tools.customer_saved'));
    }

    public function update(Request $request, int $customer): RedirectResponse
    {
        CustomerBook::assertPartner($request->user());
        $this->book->change($request->user(), $customer, $this->validated($request));

        return back()->with('success', __('dealer_tools.customer_saved'));
    }

    public function followUpDone(Request $request, int $customer): RedirectResponse
    {
        $this->book->markFollowUpDone($request->user(), $customer);

        return back()->with('success', __('dealer_tools.customer_follow_up_cleared'));
    }

    public function destroy(Request $request, int $customer): RedirectResponse
    {
        $this->book->remove($request->user(), $customer);

        return back()->with('success', __('dealer_tools.customer_removed'));
    }

    /** @return array{name: string, phone: string|null, notes: string|null, follow_up_on: string|null} */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => 'required|string|max:150',
            'phone' => 'nullable|string|max:30',
            'notes' => 'nullable|string|max:2000',
            'follow_up_on' => 'nullable|date_format:Y-m-d',
        ]);

        return [
            'name' => $data['name'],
            'phone' => filled($data['phone'] ?? null) ? $this->phones->normalize($data['phone']) : null,
            'notes' => $data['notes'] ?? null,
            'follow_up_on' => $data['follow_up_on'] ?? null,
        ];
    }
}
