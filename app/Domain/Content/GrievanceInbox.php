<?php

namespace App\Domain\Content;

use App\Support\Audit;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Complaints and product-problem reports from the public website. They are kept in the
 * enquiries table so office staff read them in the existing enquiry inbox.
 */
final class GrievanceInbox
{
    public const TopicComplaint = 'complaint';

    public const TopicProductProblem = 'product_problem';

    public const Topics = [self::TopicComplaint, self::TopicProductProblem];

    /** Letters and digits that are hard to confuse when read over the phone. */
    private const TrackingAlphabet = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';

    private const TrackingPattern = '/^GRV-\d{6}-[2-9A-HJKMNP-Z]{6}$/D';

    /**
     * Save a complaint or report and tell the office.
     *
     * @param  array{name: string, phone: string, topic: string, message: string, product_name?: ?string, batch_number?: ?string}  $data
     * @return string The tracking number for the person who sent it.
     */
    public function submit(array $data): string
    {
        $trackingNumber = $this->insertWithUniqueNumber($data);

        Audit::notifyAdminsAndStaff(
            ['content', 'accounts'],
            fn (string $locale): string => __('portal.notice_admin_enquiry_title', [], $locale),
            fn (string $locale): string => __('portal.notice_admin_enquiry_body', [
                'name' => $data['name'],
                'phone' => $data['phone'],
                'topic' => __('public_trust.topic_'.$data['topic'], [], $locale).' ('.$trackingNumber.')',
            ], $locale)
        );

        return $trackingNumber;
    }

    /**
     * The status of a complaint, or null when the number is wrong. Nothing else about
     * the complaint is returned, because anyone can type a number here.
     */
    public function statusOf(string $trackingNumber): ?string
    {
        $normalized = strtoupper(trim($trackingNumber));
        if (preg_match(self::TrackingPattern, $normalized) !== 1) {
            return null;
        }

        $status = DB::table('enquiries')->where('tracking_number', $normalized)->value('status');

        return is_string($status) ? $status : null;
    }

    /**
     * @param  array{name: string, phone: string, topic: string, message: string, product_name?: ?string, batch_number?: ?string}  $data
     */
    private function insertWithUniqueNumber(array $data): string
    {
        for ($attempt = 1; ; $attempt++) {
            $trackingNumber = $this->newTrackingNumber();

            try {
                DB::table('enquiries')->insert([
                    'name' => $data['name'],
                    'phone' => $data['phone'],
                    'topic' => $data['topic'],
                    'message' => $data['message'],
                    'product_name' => $data['product_name'] ?? null,
                    'batch_number' => $data['batch_number'] ?? null,
                    'tracking_number' => $trackingNumber,
                    'status' => 'new',
                    // Staff status changes text this number, and every SMS must be in Bangla.
                    'locale' => 'bn',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                return $trackingNumber;
            } catch (UniqueConstraintViolationException $exception) {
                if ($attempt >= 3) {
                    throw $exception;
                }
            }
        }
    }

    private function newTrackingNumber(): string
    {
        $code = '';
        for ($index = 0; $index < 6; $index++) {
            $code .= self::TrackingAlphabet[random_int(0, strlen(self::TrackingAlphabet) - 1)];
        }

        return 'GRV-'.now('Asia/Dhaka')->format('ymd').'-'.$code;
    }
}
