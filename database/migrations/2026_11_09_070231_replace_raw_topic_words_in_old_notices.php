<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const TopicLabels = [
        'partnership_enquiry' => 'portal.enquiry_topic_partnership',
        'dealer_enquiry' => 'portal.enquiry_topic_dealer',
        'product_enquiry' => 'portal.enquiry_topic_product',
    ];

    /**
     * Notices written before topics were translated still show words such as
     * partnership_enquiry. New notices are already written with the topic's name.
     */
    public function up(): void
    {
        foreach (self::TopicLabels as $rawTopic => $labelKey) {
            DB::table('account_notices')
                ->where('body', 'like', '%'.$rawTopic.'%')
                ->orderBy('id')
                ->chunkById(500, function (Collection $notices) use ($rawTopic, $labelKey): void {
                    foreach ($notices as $notice) {
                        $locale = preg_match('/\p{Bengali}/u', (string) $notice->body) === 1 ? 'bn' : 'en';
                        DB::table('account_notices')->where('id', $notice->id)->update([
                            'body' => str_replace($rawTopic, __($labelKey, [], $locale), (string) $notice->body),
                        ]);
                    }
                });
        }
    }

    public function down(): void
    {
        // The old raw words are not worth restoring.
    }
};
