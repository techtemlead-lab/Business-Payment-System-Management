import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

// Client, 2026-11-20: a stray click outside a pop-up (adding a product, a user, any form) closed it and wiped what was typed.
test('a click outside the shared dialog never closes it, but the close button and Esc still do', () => {
    const source = readFileSync(new URL('../../resources/js/components/UI.tsx', import.meta.url), 'utf8');
    const start = source.indexOf('export function Modal(');
    const end = source.indexOf('export type Field=', start);
    const modal = source.slice(start, end);

    assert.ok(start > 0 && end > start, 'the Modal component is still there');
    assert.doesNotMatch(modal, /onClick=\{e=>\{if\(e\.target===ref\.current\)/, 'no outside-click closer on the dialog');
    assert.doesNotMatch(modal, /<dialog[^>]*onClick=/, 'the dialog element has no click handler');
    assert.match(modal, /onCancel=\{e=>\{e\.preventDefault\(\);requestClose\(\)\}\}/, 'Esc still closes it');
    assert.match(modal, /onClick=\{requestClose\}/, 'the close button still closes it');
});
