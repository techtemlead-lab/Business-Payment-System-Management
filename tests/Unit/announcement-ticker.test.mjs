import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

import { TICKER_SPEED_PX_PER_SECOND, tickerSeconds } from '../../resources/js/shared/announcementTicker.ts';

const css = readFileSync(new URL('../../resources/css/reference-home.css', import.meta.url), 'utf8');
const home = readFileSync(new URL('../../resources/js/reference-home.ts', import.meta.url), 'utf8');

// Client report 2026-11-22: on a phone the moving news line stopped part-way through the text.
test('one pass takes the same time per pixel however long the text is', () => {
    const short = tickerSeconds(600);
    const long = tickerSeconds(6000);

    assert.ok(Math.abs(6000 / long - TICKER_SPEED_PX_PER_SECOND) < 0.5);
    assert.ok(Math.abs(600 / short - TICKER_SPEED_PX_PER_SECOND) < 0.5);
    assert.ok(long > short);
});

test('an unusable width gives no duration, so the first guess from the page stays', () => {
    assert.ok(Number.isNaN(tickerSeconds(0)));
    assert.ok(Number.isNaN(tickerSeconds(-5)));
    assert.ok(Number.isNaN(tickerSeconds(Number.NaN)));
});

test('the line slides by exactly one copy of the text, not by a share of the text width', () => {
    const keyframes = css.match(/@keyframes ref-marquee\{[^}]*\}[^}]*\}/g) ?? [];

    assert.equal(keyframes.length, 1, 'one ref-marquee keyframe set');
    assert.match(keyframes[0], /from\{transform:translateX\(0\)\}to\{transform:translateX\(-50%\)\}/);
    assert.doesNotMatch(css, /translateX\(-30%\)/);
});

test('the line pauses on hover and touch-and-hold, and shows still, wrapped text for reduced motion', () => {
    assert.match(css, /\.ref-announcement:hover \.ref-announcement-track\{animation-play-state:paused\}/);
    assert.match(css, /\.ref-announcement-track\.is-paused\{animation-play-state:paused\}/);
    assert.match(css, /\.ref-announcement-item\[aria-hidden="true"\]\{display:none\}/);
    assert.match(css, /\.ref-announcement-item\{min-width:0;padding-inline-end:0;white-space:normal/);
});

test('the home page script starts the news line', () => {
    assert.match(home, /import \{ initAnnouncementTickers \} from '\.\/shared\/announcementTicker'/);
    assert.equal((home.match(/initAnnouncementTickers\(\);/g) ?? []).length, 2);
});
