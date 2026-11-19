import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (path) => readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');

// R2: the purchase page's clear-all button asks before it empties the order list.
test('clearing the whole cart asks first', () => {
    const source = read('resources/js/components/ProductPurchaseMatrix.tsx');

    assert.doesNotMatch(source, /onClick=\{\(\) => setCart\(\[\]\)\}/, 'the button no longer empties the cart directly');
    assert.match(source, /onClick=\{\(\) => setClearAsk\(true\)\}/);
    assert.match(source, /data-testid="cart-clear-yes" onClick=\{\(\) => \{ setCart\(\[\]\); setClearAsk\(false\); \}\}/);
});

// R3: a sub-dealer's agreement page has the sub-dealer title in the browser tab.
test('the sub-dealer agreement page tab says sub-dealer agreement', () => {
    const source = read('resources/js/pages/Portal.tsx');

    assert.match(source, /section==='agreement'&&u\?\.role==='sub_dealer'\?translate\('joining','agreement_title_sub'\)/);
});
