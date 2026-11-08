<?php

namespace Tests\Feature;

use App\Domain\Orders\CourierOptions;
use Tests\TestCase;

class CourierPublicTrackingLinksTest extends TestCase
{
    public function test_paperfly_links_to_the_tracking_form_on_its_own_website(): void
    {
        $this->assertSame(
            'https://paperfly.com.bd/track?q=Z-010125-1000000-A1-A1',
            CourierOptions::trackingUrl('paperfly', 'Z-010125-1000000-A1-A1'),
        );
    }

    public function test_ecourier_links_to_the_tracking_form_on_its_own_website(): void
    {
        $this->assertSame(
            'https://ecourier.com.bd/tracking?tracking_no=ECR1234567890',
            CourierOptions::trackingUrl('ecourier', 'ECR1234567890'),
        );
    }

    public function test_the_tracking_number_cannot_break_out_of_the_address(): void
    {
        $this->assertSame(
            'https://ecourier.com.bd/tracking?tracking_no=ECR%26x%3D1',
            CourierOptions::trackingUrl('ecourier', 'ECR&x=1'),
        );
    }

    public function test_no_link_is_shown_without_a_number_or_for_an_unknown_courier(): void
    {
        $this->assertNull(CourierOptions::trackingUrl('paperfly', ''));
        $this->assertNull(CourierOptions::trackingUrl('ecourier', '   '));
        $this->assertNull(CourierOptions::trackingUrl('some-other-courier', 'ABC123'));
    }
}
