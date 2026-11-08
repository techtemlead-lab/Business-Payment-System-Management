<?php

namespace App\Http\Controllers;

use App\Domain\Accounts\AccountPhoneRules;
use App\Domain\Audience\PartnerProductEnquiries;
use App\Models\Product;
use App\Support\SiteCustomization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The small enquiry form on a partner's shared product link.
 */
class PartnerProductEnquiryController extends Controller
{
    public function store(Request $request, Product $product, PartnerProductEnquiries $enquiries, AccountPhoneRules $phones): RedirectResponse
    {
        abort_unless(SiteCustomization::isPageEnabled('products') && $product->active, 404);
        $data = $request->validate([
            'ref' => 'required|string|max:40',
            'name' => 'required|string|max:150',
            'phone' => 'required|string|max:30',
            'message' => 'required|string|min:5|max:1000',
            'consent' => 'accepted',
            'website' => 'nullable|max:0',
        ]);
        $partner = $enquiries->partnerForCode($data['ref']);
        abort_unless($partner !== null, 404);
        $enquiries->submit($partner, $product, [
            'name' => trim($data['name']),
            'phone' => $phones->normalize($data['phone']),
            'message' => trim($data['message']),
        ]);

        return back()->with('partner_enquiry_sent', true);
    }
}
