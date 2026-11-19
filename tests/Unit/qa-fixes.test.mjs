import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const css = readFileSync(new URL('../../resources/css/office-feature-boxes.css', import.meta.url), 'utf8');

test('short groups on the Other modules page stand side by side and beat the 3-column media rule', () => {
    assert.match(css, /\.feature-boxes\[data-layout="sides"\]\{display:grid;grid-template-columns:repeat\(var\(--feature-sides,3\),minmax\(0,1fr\)\)/);
    // Same-or-higher specificity than `.feature-boxes[data-columns="4"] ul` so the narrow-screen rule cannot split a column again.
    assert.match(css, /\.feature-boxes\[data-layout="sides"\]\[data-columns\] ul\{grid-template-columns:minmax\(0,1fr\)/);
});
