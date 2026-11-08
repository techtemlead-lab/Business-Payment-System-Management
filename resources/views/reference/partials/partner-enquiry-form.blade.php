{{-- Enquiry form on a product page opened from a partner's shared link. Reaches only that partner, as a portal notice. --}}
<section class="ref-content-card" id="partner-enquiry" style="margin-bottom:16px;">
    <h2>{{ __('dealer_tools.enquiry_title', ['name' => $partner->name]) }}</h2>
    @if(session('partner_enquiry_sent'))
        <p class="ref-green" role="status">{{ __('dealer_tools.enquiry_sent', ['name' => $partner->name]) }}</p>
    @else
        <p>{{ __('dealer_tools.enquiry_hint', ['name' => $partner->name]) }}</p>
        @if($errors->hasAny(['ref', 'name', 'phone', 'message', 'consent']))
            <ul class="ref-orange" role="alert">
                @foreach($errors->all() as $message)
                    <li>{{ $message }}</li>
                @endforeach
            </ul>
        @endif
        <form class="ref-form" action="/products/{{ $productId }}/partner-enquiry" method="post">
            @csrf
            <input type="hidden" name="ref" value="{{ $code }}">
            <input type="text" name="website" value="" tabindex="-1" autocomplete="off" aria-hidden="true" style="position:absolute;left:-9999px;height:1px;width:1px;opacity:0;">
            <label for="partner-enquiry-name">{{ __('dealer_tools.enquiry_name') }}</label>
            <input id="partner-enquiry-name" name="name" value="{{ old('name') }}" maxlength="150" required autocomplete="name">
            <label for="partner-enquiry-phone">{{ __('dealer_tools.enquiry_phone') }}</label>
            <input id="partner-enquiry-phone" type="tel" name="phone" value="{{ old('phone') }}" maxlength="30" required autocomplete="tel">
            <label for="partner-enquiry-message">{{ __('dealer_tools.enquiry_message') }}</label>
            <textarea id="partner-enquiry-message" name="message" rows="4" minlength="5" maxlength="1000" required>{{ old('message') }}</textarea>
            <label style="display:flex;gap:8px;align-items:flex-start;">
                <input type="checkbox" name="consent" value="1" required @checked(old('consent'))>
                <span>{{ __('dealer_tools.enquiry_consent', ['name' => $partner->name]) }}</span>
            </label>
            <button type="submit" class="ref-primary-button">{{ __('dealer_tools.enquiry_send') }}</button>
        </form>
    @endif
</section>
