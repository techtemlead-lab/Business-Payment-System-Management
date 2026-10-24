import {createRequire} from 'node:module';
import {readFile, writeFile} from 'node:fs/promises';
import {resolve, dirname} from 'node:path';
import {fileURLToPath} from 'node:url';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '../..');
const credentials = JSON.parse(await readFile(resolve(root, '.local/demo-access.json'), 'utf8'));
const {chromium} = createRequire(import.meta.url)(process.argv[2] || 'playwright');
const port = Number(process.env.QA_PORT || 8088);
const origin = (host) => `http://${host}:${port}`;

let browser;
try {
    browser = await chromium.launch({headless: true});
} catch {
    browser = await chromium.launch({headless: true, channel: 'msedge'});
}

const result = {checks: [], errors: []};
function check(name, value) {
    result.checks.push({name, passed: !!value});
    if (!value) {
        throw new Error(name);
    }
}

const portals = {
    admin: {host: 'sadmin.localhost', account: 'admin', staff: true, label: 'Sign In'},
    dealer: {host: 'dealer.localhost', account: 'dealer', staff: false, label: 'Login'},
    subdealer: {host: 'subdealer.localhost', account: 'subdealer', staff: false, label: 'Login'},
};

try {
    for (const [name, portal] of Object.entries(portals)) {
        const context = await browser.newContext({viewport: {width: 1440, height: 1000}});
        const page = await context.newPage();
        page.on('pageerror', (e) => result.errors.push(e.message));

        await page.goto(origin(portal.host) + '/login');
        await page.getByLabel('E-Mail / Phone Number', {exact: true}).fill(credentials.accounts[portal.account]);
        await page.getByLabel('Password', {exact: true}).fill(credentials.password);
        await page.locator('form.action-form, form.ref-form').first().evaluate((form) => form.requestSubmit());
        await page.waitForURL((url) => url.origin === origin(portal.host) && !url.pathname.includes('/login'));
        await page.getByRole('heading', {name: 'Overview', exact: true}).waitFor();

        check(name + ' profile summary', await page.locator('.account-profile-card').isVisible());
        check(name + ' five summary figures', await page.locator('.account-profile-stats>div').count() === 5);
        check(name + ' desktop no overflow', await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth));
        check(name + ' correct website separation', await page.locator('.portal-site-header').count() === (portal.staff ? 0 : 1));
        check(name + ' correct action links', await page.locator('.account-action-card').evaluateAll((links) => links.every((a) => {
            const path = new URL(a.href).pathname;
            return path.startsWith('/') && !path.startsWith('/company/') && !path.startsWith('/workspace/') && !path.startsWith('/owner/');
        })));

        if (name === 'subdealer') {
            check('Sub-dealer salary action', await page.locator('.account-action-card[href="/salary"]').count() === 1);
            check('Sub-dealer has no unapproved network action', await page.locator('.account-action-card[href="/network"]').count() === 0);
        }

        await page.screenshot({animations: 'disabled', path: resolve(root, 'docs/screenshots/' + name + '-dashboard-desktop.png')});
        await page.setViewportSize({width: 390, height: 844});
        check(name + ' mobile no overflow', await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth));
        await page.screenshot({animations: 'disabled', path: resolve(root, 'docs/screenshots/' + name + '-dashboard-mobile.png')});

        await page.getByRole('button', {name: 'Open navigation', exact: true}).click();
        check(name + ' drawer opens', await page.locator('#workspace-navigation').getAttribute('aria-hidden') === 'false');
        check(name + ' background inert', await page.locator('.workspace').getAttribute('inert') !== null);
        await page.getByRole('button', {name: 'Close navigation', exact: true}).press('Escape');
        check(name + ' Escape closes drawer', await page.locator('#workspace-navigation').getAttribute('aria-hidden') === 'true');

        await page.getByRole('button', {name: 'Open navigation', exact: true}).click();
        await page.getByRole('link', {name: 'Account security', exact: true}).click();
        await page.waitForURL('**/security');
        check(name + ' drawer navigation works', await page.getByRole('heading', {name: 'Change password', exact: true}).isVisible());

        await page.getByRole('button', {name: 'Log out', exact: true}).click();
        await page.waitForURL('**/login');
        check(name + ' logout reaches correct login', true);
        await context.close();
    }

    check('No dashboard JavaScript errors', result.errors.length === 0);
    result.passed = true;
    await writeFile(resolve(root, 'docs/qa/dashboard-results.json'), JSON.stringify(result, null, 2));
    console.log(JSON.stringify({passed: true, checks: result.checks.length, consoleErrors: result.errors.length}));
} catch (e) {
    result.passed = false;
    result.failure = e.message;
    await writeFile(resolve(root, 'docs/qa/dashboard-results.json'), JSON.stringify(result, null, 2));
    throw e;
} finally {
    await browser.close();
}
