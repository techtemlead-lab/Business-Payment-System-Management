import assert from 'node:assert/strict';
import test from 'node:test';
import { blockingAdvisories } from '../../.github/scripts/npm-audit-gate.mjs';

const picomatch = { source: 1, name: 'picomatch', severity: 'high', title: 'ReDoS', url: 'https://github.com/advisories/GHSA-fc5q-39xc-vjpf' };
const other = { source: 2, name: 'left-pad', severity: 'high', title: 'Something else', url: 'https://github.com/advisories/GHSA-other' };
const allowed = [{ url: picomatch.url, until: '2026-12-31' }];

test('an allowed advisory, and packages that only depend on it, do not block', () => {
    const report = { vulnerabilities: { picomatch: { via: [picomatch] }, vite: { via: ['picomatch'] } } };
    assert.deepEqual(blockingAdvisories(report, allowed, '2026-11-18'), []);
});

test('an advisory that is not on the list blocks, even beside an allowed one', () => {
    const report = { vulnerabilities: { picomatch: { via: [picomatch] }, 'left-pad': { via: [other] } } };
    assert.deepEqual(blockingAdvisories(report, allowed, '2026-11-18'), [other]);
});

test('an allowance stops working after its last day', () => {
    const report = { vulnerabilities: { picomatch: { via: [picomatch] } } };
    assert.deepEqual(blockingAdvisories(report, allowed, '2027-01-01'), [picomatch]);
});

test('a clean report blocks nothing', () => {
    assert.deepEqual(blockingAdvisories({}, allowed, '2026-11-18'), []);
});
