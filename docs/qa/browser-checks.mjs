import {createRequire} from 'node:module';
import {readFile, writeFile, mkdir} from 'node:fs/promises';
import {resolve, dirname} from 'node:path';
import {fileURLToPath} from 'node:url';

const require = createRequire(import.meta.url);
const {chromium} = require(process.argv[2] || 'playwright');
const root = resolve(dirname(fileURLToPath(import.meta.url)), '../..');
const credentials = JSON.parse(await readFile(resolve(root, '.local/demo-access.json'), 'utf8'));
const port = Number(process.env.QA_PORT || 8088);
const origin = (host = 'localhost') => `http://${host}:${port}`;
await mkdir(resolve(root, 'docs/screenshots'), {recursive: true});

let browser;
try {
    browser = await chromium.launch({headless: true});
} catch {
    browser = await chromium.launch({headless: true, channel: 'msedge'});
}

const errors = [];
const results = [];
const context = await browser.newContext({viewport: {width: 1440, height: 1000}});
await context.addInitScript(() => {
    try {
        localStorage.setItem('madina.reference.promotion.dismissed', String(Date.now()));
    } catch {}
});
const page = await context.newPage();
page.on('pageerror', (e) => errors.push(e.message));

async function visit(host, path, heading) {
    const response = await page.goto(origin(host) + path);
    if (response.status() !== 200) {
        throw new Error(host + path + ': HTTP ' + response.status());
    }
    if (heading) {
        await page.getByRole('heading', {name: heading}).first().waitFor();
    }
    results.push({host, path, status: response.status()});
}

async function signIn(host, account, buttonName) {
    await visit(host, '/login');
    await page.getByLabel('E-Mail / Phone Number', {exact: true}).fill(credentials.accounts[account]);
    await page.getByLabel('Password', {exact: true}).fill(credentials.password);
    await page.locator('form.action-form, form.ref-form').first().evaluate((form) => form.requestSubmit());
    await page.waitForURL((url) => url.host.startsWith(host) && !url.pathname.includes('/login'));
    await page.getByRole('heading', {name: 'Overview', exact: true}).waitFor();
}

try {
    await visit('localhost', '/');
    await page.screenshot({path: resolve(root, 'docs/screenshots/home-desktop.png')});
    for (const path of ['/products', '/dealers', '/about', '/contact']) {
        await visit('localhost', path);
    }

    await page.setViewportSize({width: 390, height: 844});
    await visit('localhost', '/');
    if (await page.evaluate(() => document.documentElement.scrollWidth > innerWidth)) {
        throw new Error('Mobile public page overflows');
    }
    await page.screenshot({path: resolve(root, 'docs/screenshots/home-mobile.png'), fullPage: true});

    await page.setViewportSize({width: 1440, height: 1000});
    await signIn('sadmin.localhost', 'admin', 'Sign In');
    await page.screenshot({path: resolve(root, 'docs/screenshots/company-dashboard.png')});
    for (const section of ['accounts', 'catalog', 'orders', 'inventory', 'payments', 'deliveries', 'dues', 'policies', 'salary', 'withdrawals', 'ledger', 'reports', 'content', 'enquiries', 'audit', 'security']) {
        await visit('sadmin.localhost', '/' + section);
    }

    await page.getByRole('button', {name: 'Log out', exact: true}).click();
    await page.waitForURL('**/login');
    await signIn('dealer.localhost', 'dealer', 'Login');
    await page.screenshot({path: resolve(root, 'docs/screenshots/dealer-dashboard.png')});
    for (const section of ['catalog', 'orders', 'deliveries', 'dues', 'earnings', 'withdrawals', 'network', 'ranks', 'profile', 'security', 'notifications']) {
        await visit('dealer.localhost', '/' + section);
    }

    await page.setViewportSize({width: 390, height: 844});
    await visit('dealer.localhost', '/orders');
    await page.getByRole('button', {name: 'Open navigation'}).click();
    await page.getByRole('link', {name: 'Product collection', exact: true}).click();
    await page.getByRole('heading', {name: 'Product collection', exact: true}).waitFor();
    if (await page.evaluate(() => document.documentElement.scrollWidth > innerWidth)) {
        throw new Error('Mobile portal overflows');
    }
    await page.screenshot({path: resolve(root, 'docs/screenshots/dealer-mobile.png'), fullPage: true});

    await page.setViewportSize({width: 1440, height: 1000});
    await page.getByRole('spinbutton').first().fill('1');
    await page.getByRole('button', {name: 'Review request'}).click();
    await page.getByRole('button', {name: 'Submit purchase request'}).click();
    await page.waitForURL('**/orders/*');
    await page.getByRole('heading', {name: 'Products & fulfilment'}).waitFor();
    results.push({workflow: 'Dealer submits a real synthetic purchase request', passed: true});

    if (errors.length) {
        throw new Error('Browser errors: ' + errors.join('; '));
    }
    await writeFile(resolve(root, 'docs/qa/browser-results.json'), JSON.stringify({passed: true, checks: results, consoleErrors: errors}, null, 2));
    console.log(JSON.stringify({passed: true, pages: results.length, consoleErrors: errors.length}));
} catch (error) {
    await page.screenshot({path: resolve(root, 'docs/screenshots/qa-failure.png')});
    console.log(JSON.stringify({path: new URL(page.url()).pathname, alerts: await page.getByRole('alert').allTextContents(), consoleErrors: errors}));
    throw error;
} finally {
    await browser.close();
}
