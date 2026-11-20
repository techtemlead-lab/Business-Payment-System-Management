import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

// Client request 2026-11-20: choosing a category on /products applies the filter at once.
test('the product filter form submits itself when a radio or checkbox changes', () => {
    const source = readFileSync(new URL('../../resources/js/reference-home.ts', import.meta.url), 'utf8');

    assert.match(source, /\[data-filter-autosubmit\]/);
    assert.match(source, /target\.type==='radio'\|\|target\.type==='checkbox'/);
    assert.match(source, /form\.requestSubmit\(\)/);
});
