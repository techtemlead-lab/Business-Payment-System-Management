import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { keywordKeyFor, matchesAny, shownActions, squash, wordList } from '../../resources/js/shared/menuSearch.ts';
import { buildNavMothers, buildPartnerAvailable, buildStaffAvailable } from '../../resources/js/shared/portalNav.ts';

const lang = (locale) => readFileSync(new URL(`../../lang/${locale}/findability.php`, import.meta.url), 'utf8');
const keysOf = (source) => [...source.matchAll(/^\s*'([a-z0-9_]+)' =>/gm)].map((match) => match[1]);
const valueOf = (source, key) => source.match(new RegExp(`'${key}' => '(.*)',`))?.[1] ?? '';

const staffContext = (capabilities, available, stockCreate = false) => ({ staff: true, role: 'account_manager', capabilities, available, stockCreate });
const partnerContext = (role = 'dealer') => ({ staff: false, role, capabilities: [], available: buildPartnerAvailable(role, {}, false), stockCreate: false });
const ids = (ctx) => shownActions(ctx).map((action) => action.id);

test('search ignores letter case, spaces, hyphens, Bangla digits and joiners', () => {
    assert.equal(squash('Sign  Out'), 'signout');
    assert.equal(squash('ফলো-আপ'), squash('ফলো আপ'));
    assert.equal(squash('২-ধাপ'), '2ধাপ');
    assert.equal(matchesAny(['টাকা তোলা', 'withdraw'], 'টাকাতোলা'), true);
    assert.equal(matchesAny(['Log out'], 'LOGOUT'), true);
    assert.equal(matchesAny(['anything'], '   '), false);
});

test('every action has a name and its words in both languages, and every word list has words', () => {
    const everyone = [partnerContext('dealer'), partnerContext('sub_dealer'), staffContext(['accounts', 'catalog', 'pricing', 'inventory', 'finance'], buildStaffAvailable({ capabilities: ['accounts', 'catalog', 'pricing', 'inventory', 'finance', 'reports'], role: 'super_admin', ownerPortal: true, stockWorker: true }), true)];
    const wanted = new Set(everyone.flatMap((ctx) => ids(ctx)));
    for (const locale of ['bn', 'en']) {
        const keys = new Set(keysOf(lang(locale)));
        for (const id of wanted) {
            assert.ok(keys.has(`act_${id}`), `${locale}: act_${id}`);
            assert.ok(keys.has(`kw_act_${id}`), `${locale}: kw_act_${id}`);
            assert.ok(wordList(valueOf(lang(locale), `kw_act_${id}`)).length >= 3, `${locale}: words of ${id}`);
        }
    }
});

test('every page that has a word list is a real menu item and the lists are not empty', () => {
    const bn = lang('bn');
    const pageKeys = keysOf(bn).filter((key) => key.startsWith('kw_') && !key.startsWith('kw_act_'));
    assert.ok(pageKeys.length >= 30);
    for (const key of pageKeys) {
        assert.ok(wordList(valueOf(bn, key)).length >= 2, key);
    }
    for (const item of ['partner-money', 'my-income', 'profile', 'security', 'help', 'customers', 'financial-reports', 'treasury', 'settlements', 'my-payroll', 'inventory', 'catalog']) {
        assert.ok(pageKeys.includes(keywordKeyFor(item)), `${item} has a word list`);
    }
});

test('the typed words from the audit find their entries', () => {
    const bn = lang('bn');
    const find = (ctx, query) => shownActions(ctx).filter((action) => matchesAny([valueOf(bn, `act_${action.id}`), ...wordList(valueOf(bn, `kw_act_${action.id}`))], query)).map((action) => action.id);

    assert.ok(find(partnerContext(), 'টাকা তোলা').includes('withdraw'));
    assert.ok(find(partnerContext(), 'আয়ের সনদ').includes('income_certificate'));
    assert.ok(find(partnerContext(), 'পাসওয়ার্ড বদলান').includes('change_password'));
    assert.ok(find(partnerContext(), 'সাইন আউট').includes('sign_out'));
    assert.ok(find(partnerContext(), 'লগআউট').includes('sign_out'));
    assert.ok(find(partnerContext(), 'বের হন').includes('sign_out'));
    assert.ok(find(partnerContext(), 'ফোন করুন').includes('customers_due'));
    assert.ok(find(partnerContext(), 'Sign Out').includes('sign_out'));
});

test('an entry shows only to a person who may open it', () => {
    const partner = partnerContext('dealer');
    assert.ok(ids(partner).includes('withdraw'));
    assert.ok(ids(partner).includes('customers_due'));
    for (const hidden of ['stock_in', 'stock_out', 'expense', 'report_files', 'my_salary', 'new_dealer', 'new_product']) {
        assert.ok(!ids(partner).includes(hidden), `a partner must not see ${hidden}`);
    }

    const inventoryOnly = staffContext(['inventory'], buildStaffAvailable({ capabilities: ['inventory'], role: 'inventory', ownerPortal: false, stockWorker: true }), true);
    assert.ok(ids(inventoryOnly).includes('stock_in') && ids(inventoryOnly).includes('stock_out'));
    assert.ok(!ids(inventoryOnly).includes('expense') && !ids(inventoryOnly).includes('report_files') && !ids(inventoryOnly).includes('new_dealer') && !ids(inventoryOnly).includes('withdraw'));
    assert.ok(ids(inventoryOnly).includes('my_salary'), 'non-owner staff have a salary page');

    const viewOnly = staffContext(['inventory'], buildStaffAvailable({ capabilities: ['inventory'], role: 'inventory', ownerPortal: false }), false);
    assert.ok(!ids(viewOnly).includes('stock_in'), 'a person who may only view stock gets no stock buttons');

    const owner = staffContext(['finance', 'accounts', 'catalog', 'pricing', 'inventory'], buildStaffAvailable({ capabilities: ['finance', 'accounts', 'catalog', 'pricing', 'inventory'], role: 'super_admin', ownerPortal: true, stockWorker: true }), true);
    for (const shown of ['stock_in', 'stock_out', 'expense', 'budget', 'report_files', 'new_dealer', 'new_sub_dealer', 'new_product', 'sign_out', 'change_password', 'help']) {
        assert.ok(ids(owner).includes(shown), `the owner sees ${shown}`);
    }
    assert.ok(!ids(owner).includes('my_salary'), 'owners read payroll elsewhere');
    assert.ok(!ids(owner).includes('withdraw'));
});

test('the report files tab is open to everyone who may download the files', () => {
    const open = (caps, role = 'account_manager') => buildStaffAvailable({ capabilities: caps, role, ownerPortal: false }).has('financial-reports');
    assert.equal(open(['finance']), true);
    assert.equal(open(['reports']), true);
    assert.equal(open(['field_sales']), true);
    assert.equal(open(['inventory']), false);
    assert.equal(open(['orders']), false);
});

test('best sellers, contests and the contract each have one home in the partner menu', () => {
    const rows = (role) => buildNavMothers({ staff: false, role, staffAvailable: new Set(), partnerAvailable: buildPartnerAvailable(role, {}, false) });
    const itemsOf = (mothers, id) => mothers.find((entry) => entry.id === id)?.items ?? [];

    const dealer = rows('dealer');
    assert.match(itemsOf(dealer, 'partner-team')[0], /^partner-team@network,customers,ranks,top-sellers,contests$/);
    assert.match(itemsOf(dealer, 'partner-account')[0], /^partner-account@profile,security,agreement$/);
    assert.doesNotMatch(itemsOf(dealer, 'partner-more')[0], /top-sellers|contests|agreement/);

    const subDealer = rows('sub_dealer');
    assert.equal(itemsOf(subDealer, 'partner-team').length, 0);
    assert.match(itemsOf(subDealer, 'partner-customers')[0], /^partner-customers@customers,top-sellers,contests$/);
});
