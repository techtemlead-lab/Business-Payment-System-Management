<?php

namespace Tests\Unit;

use App\Jobs\ApplyDataImport;
use App\Jobs\BookShipmentCourier;
use App\Jobs\FetchCourierAreas;
use App\Jobs\RefreshShipmentTracking;
use App\Jobs\SendPartnerEventSms;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * The production worker retries a failed job up to three times. A job whose retry could do the thing twice
 * (book a second courier parcel, send and pay for a second SMS, apply an import twice, repeat an owner notice)
 * must keep its own single try; a job that only reads may be retried.
 */
class QueueRetryPolicyTest extends TestCase
{
    /**
     * @return array<string, array{class-string}>
     */
    public static function jobsThatMustNotRepeat(): array
    {
        return [
            'courier booking' => [BookShipmentCourier::class],
            'partner SMS' => [SendPartnerEventSms::class],
            'data import' => [ApplyDataImport::class],
            'courier area list' => [FetchCourierAreas::class],
        ];
    }

    /**
     * @param  class-string  $job
     */
    #[DataProvider('jobsThatMustNotRepeat')]
    public function test_a_job_with_an_outside_effect_keeps_a_single_try(string $job): void
    {
        $this->assertSame(1, (new ReflectionClass($job))->getProperty('tries')->getDefaultValue());
    }

    public function test_asking_a_courier_where_a_parcel_is_is_retried_with_a_pause(): void
    {
        $job = new ReflectionClass(RefreshShipmentTracking::class);

        $this->assertSame(3, $job->getProperty('tries')->getDefaultValue());
        $this->assertSame([60, 300], $job->getProperty('backoff')->getDefaultValue());
    }

    public function test_the_production_worker_retries_jobs_that_set_no_limit_of_their_own(): void
    {
        $compose = (string) file_get_contents(dirname(__DIR__, 2).'/compose.production.yaml');

        $this->assertStringContainsString('"--tries=3", "--backoff=60"', $compose);
    }
}
