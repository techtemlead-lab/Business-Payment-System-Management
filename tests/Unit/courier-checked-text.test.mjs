import assert from 'node:assert/strict';
import test from 'node:test';
import { courierCheckedText } from '../../resources/js/components/courierCheckedText.ts';

const translate = (group, key, replace = {}) => `${group}.${key}${replace.count !== undefined ? `(${replace.count})` : ''}`;
const formatDateTime = (value) => `d:${value}`;
const checked = (minutesAgo) => courierCheckedText(minutesAgo, '2026-11-09 08:00:00', translate, formatDateTime);

test('an answer from this minute reads as just now', () => {
    assert.equal(checked(0), 'courier.checked_just_now');
});

test('one minute and one hour read in the singular', () => {
    assert.equal(checked(1), 'courier.checked_one_minute_ago');
    assert.equal(checked(60), 'courier.checked_one_hour_ago');
    assert.equal(checked(119), 'courier.checked_one_hour_ago');
});

test('minutes and hours are counted', () => {
    assert.equal(checked(2), 'courier.checked_minutes_ago(2)');
    assert.equal(checked(59), 'courier.checked_minutes_ago(59)');
    assert.equal(checked(120), 'courier.checked_hours_ago(2)');
    assert.equal(checked(1439), 'courier.checked_hours_ago(23)');
});

test('from a day old it shows the date and time instead', () => {
    assert.equal(checked(1440), 'd:2026-11-09 08:00:00');
});
