// CI gate for `npm audit`: fails on every advisory except the ones listed in ALLOWED, and an
// allowance stops working on its expiry date so it cannot be forgotten.
import { spawnSync } from 'node:child_process';
import { pathToFileURL } from 'node:url';

/**
 * Advisories accepted for now, each with a reason and a last day.
 * picomatch has no patched release yet; it is a regular-expression slowdown in build tools
 * (vite, laravel-vite-plugin) that never run in production.
 */
export const ALLOWED = [
    { url: 'https://github.com/advisories/GHSA-fc5q-39xc-vjpf', until: '2026-12-31' },
];

/**
 * The advisories in an `npm audit --json` report that are not allowed on the given day.
 * Entries in `via` that are plain strings only point at another package, so they are skipped.
 */
export function blockingAdvisories(report, allowed = ALLOWED, today = new Date().toISOString().slice(0, 10)) {
    const found = new Map();
    for (const vulnerability of Object.values(report.vulnerabilities ?? {})) {
        for (const via of vulnerability.via ?? []) {
            if (typeof via === 'object' && via !== null) {
                found.set(via.url ?? String(via.source), via);
            }
        }
    }

    return [...found.values()].filter(advisory => !allowed.some(rule => rule.url === advisory.url && today <= rule.until));
}

function main() {
    const run = spawnSync('npm', ['audit', '--json'], { encoding: 'utf8', shell: process.platform === 'win32', maxBuffer: 64 * 1024 * 1024 });
    let report;
    try {
        report = JSON.parse(run.stdout);
    } catch {
        console.error('npm audit gave no readable report:', run.stderr || run.stdout);
        process.exit(1);
    }
    if (report.error) {
        console.error('npm audit failed:', report.error.summary ?? JSON.stringify(report.error));
        process.exit(1);
    }
    const blocking = blockingAdvisories(report);
    if (blocking.length > 0) {
        for (const advisory of blocking) {
            console.error(`${advisory.severity}: ${advisory.name} - ${advisory.title} (${advisory.url})`);
        }
        console.error(`${blocking.length} advisory(ies) not on the allowed list.`);
        process.exit(1);
    }
    const total = Object.keys(report.vulnerabilities ?? {}).length;
    console.log(`npm audit: no blocking advisories (${total} package(s) affected by allowed ones, see ALLOWED in .github/scripts/npm-audit-gate.mjs).`);
}

if (process.argv[1] && import.meta.url === pathToFileURL(process.argv[1]).href) {
    main();
}
