import assert from 'node:assert/strict';
import test from 'node:test';
import { businessDate } from '../../resources/js/business-date.ts';

for (const timezone of ['America/Los_Angeles', 'Asia/Dhaka', 'UTC']) {
    test('raw database and ISO timestamps use Dhaka dates from ' + timezone, () => {
        const previousTimezone = process.env.TZ;
        process.env.TZ = timezone;
        try {
            assert.equal(businessDate('2026-10-19 13:38:30'), '19 Oct 2026');
            assert.equal(businessDate('2026-10-19T13:38:30.123456Z'), '19 Oct 2026');
            assert.equal(businessDate('2026-10-19T18:00:00Z'), '20 Oct 2026');
            assert.equal(businessDate('2026-10-19 13:38:30+00'), '19 Oct 2026');
            assert.equal(businessDate('2026-10-19T19:38:30+0600'), '19 Oct 2026');
            assert.equal(businessDate('2026-10-19'), '19 Oct 2026');
        } finally {
            if (previousTimezone === undefined) delete process.env.TZ;
            else process.env.TZ = previousTimezone;
        }
    });
}

test('missing and invalid dates have a stable empty state', () => {
    assert.equal(businessDate(null), '—');
    assert.equal(businessDate(''), '—');
    assert.equal(businessDate('not-a-date'), '—');
});
