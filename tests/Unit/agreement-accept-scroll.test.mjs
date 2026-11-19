import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

// Accepting the partner agreement redirects to the dashboard. Keeping the agreement page's scroll there
// opened the dashboard far down, and a refresh restored that place (client report, 2026-11-19).
test('accepting the agreement does not carry the agreement page scroll onto the dashboard', () => {
    const source = readFileSync(new URL('../../resources/js/components/AgreementHub.tsx', import.meta.url), 'utf8');
    const call = source.match(/form\.post\('\/commands\/agreement\/accept',[^\n]*/);

    assert.ok(call, 'the accept call is still there');
    assert.doesNotMatch(call[0], /preserveScroll:\s*true/);
    assert.match(call[0], /preserveScroll:\s*\(page\)\s*=>\s*Object\.keys\(page\.props\.errors/);
});
