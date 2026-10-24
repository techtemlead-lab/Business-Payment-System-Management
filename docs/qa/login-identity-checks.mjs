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

const portals = [
    {host: 'sadmin.localhost', account: 'admin', staff: true},
    {host: 'dealer.localhost', account: 'dealer', staff: false},
];

try {
    for (const portal of portals) {
        const context = await browser.newContext({viewport: {width: 390, height: 844}});
        await context.addInitScript(() => {
            try {
                localStorage.setItem('madina.reference.promotion.dismissed', String(Date.now()));
            } catch {}
        });
        const page = await context.newPage();
        page.on('pageerror', (e) => result.errors.push(e.message));
        const path = portal.host + '/login';

        await page.goto(origin(portal.host) + '/login');
        const identity = page.getByLabel('E-Mail / Phone Number', {exact: true});
        check(path + ' has text identity input', await identity.getAttribute('type') === 'text');
        check(path + ' no mobile overflow', await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth));
        await page.screenshot({
            animations: 'disabled',
            path: resolve(root, 'docs/screenshots/' + (portal.staff ? 'company' : 'dealer') + '-email-phone-login.png'),
        });

        await identity.fill('01000000099');
        await page.getByLabel('Password', {exact: true}).fill('SyntheticInvalidPassword123');
        await page.locator('form.action-form, form.ref-form').first().evaluate((form) => form.requestSubmit());
        await page.getByRole('alert').waitFor();
        check(
            path + ' phone submission reaches authentication',
            await page.getByRole('alert').textContent().then((t) => t.includes('credentials could not be verified')),
        );

        await page.getByLabel('E-Mail / Phone Number', {exact: true}).fill(credentials.accounts[portal.account]);
        await page.getByLabel('Password', {exact: true}).fill(credentials.password);
        await page.locator('form.action-form, form.ref-form').first().evaluate((form) => form.requestSubmit());
        await page.waitForURL((url) => url.host.startsWith(portal.host) && !url.pathname.includes('/login'));
        check(path + ' registered email still signs in', true);
        await context.close();
    }

    check('No login JavaScript errors', result.errors.length === 0);
    result.passed = true;
    await writeFile(resolve(root, 'docs/qa/login-identity-results.json'), JSON.stringify(result, null, 2));
    console.log(JSON.stringify({passed: true, checks: result.checks.length, consoleErrors: result.errors.length}));
} catch (e) {
    result.passed = false;
    result.failure = e.message;
    await writeFile(resolve(root, 'docs/qa/login-identity-results.json'), JSON.stringify(result, null, 2));
    throw e;
} finally {
    await browser.close();
}
