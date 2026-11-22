import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const source = (path) => readFileSync(new URL(`../../resources/js/${path}`, import.meta.url), 'utf8');

// Money steps ask once more in the shared confirm sheet (amount, who gets it), never in the browser's plain box.
test('money screens no longer use the browser confirm box', () => {
    for (const file of [
        'pages/WithdrawalDetail.tsx',
        'components/PayoutDisbursementHub.tsx',
        'components/mymoney/WithdrawalRequests.tsx',
        'components/payouts/OfficePayoutList.tsx',
        'components/PrescriptionsHub.tsx',
        'components/DataCleanupHub.tsx',
        'components/AreaDealersHub.tsx',
        'components/BranchesHub.tsx',
    ]) {
        assert.doesNotMatch(source(file), /window\.confirm\(/, file);
    }
});

test('the confirm sheet is a modal dialog that Esc closes and that keeps typed text from a stray tap', () => {
    const sheet = source('components/ConfirmSheet.tsx');

    assert.match(sheet, /dialog\?\.showModal\(\)/, 'opens as a modal dialog (focus stays inside)');
    assert.match(sheet, /onCancel=\{\(event\) => \{\s*event\.preventDefault\(\);\s*cancel\(\);/, 'Esc closes it');
    assert.match(sheet, /event\.target === ref\.current && !hasTyping/, 'a tap outside closes it only when nothing is typed');
    assert.match(sheet, /disabled=\{busy\}/, 'the confirm button waits while the request is on its way');
    assert.match(sheet, /if \(busy\) \{\s*return;/, 'a second press does nothing');
});

test('a withdrawal is decided with two buttons that post the same fields as before', () => {
    const page = source('pages/WithdrawalDetail.tsx');

    assert.match(page, /const action = `\/commands\/withdrawals\/\$\{w\.id\}\/review`;/);
    assert.match(page, /decision: '',\s*\.\.\.\(payers\.length > 0 \? \{ payer_id: '' \} : \{\}\),\s*\.\.\.\(reason\.shown \? \{ reason: '' \} : \{\}\),/);
    assert.match(page, /useReasonField\('money\.withdrawal_review'\)/);
    assert.match(page, /open\('approved'\)/);
    assert.match(page, /open\('rejected'\)/);
    assert.match(page, /allowApprove=\{!\(facts\?\.blocker \|\| facts\?\.over_channel_limit \|\| w\.waits_for_owner\)\}/, 'no approve button while blocked, over the limit or waiting for the owner');
    assert.doesNotMatch(page, /name: 'decision'/, 'the old select is gone');
    assert.match(page, /form\.post\(`\/commands\/withdrawals\/\$\{w\.id\}\/record-payment`, \{ forceFormData: true/, 'recording a payment sends the same request after the sheet');
});

test('a payment claim is decided with two buttons that post approve, reason and the counted cash', () => {
    const claim = source('components/ClaimDecision.tsx');
    const order = source('pages/OrderDetail.tsx');

    assert.match(claim, /const action = `\/commands\/claims\/\$\{claim\.id\}\/verify`;/);
    assert.match(claim, /approve: '',\s*\.\.\.\(reason\.shown \? \{ reason: '' \} : \{\}\),\s*\.\.\.\(office \? \{ cash_counted: '' \} : \{\}\),/);
    assert.match(claim, /useReasonField\('payment\.verify'\)/);
    assert.match(claim, /open\('1'\)/);
    assert.match(claim, /open\('0'\)/);
    assert.match(order, /<ClaimDecision key=\{claim\.id\} claim=\{claim\} order=\{order\} \/>/);
});

test('the bank file is still sent by the browser form once the sheet is answered', () => {
    const hub = source('components/PayoutDisbursementHub.tsx');

    assert.match(hub, /fileForm\.current\?\.submit\(\);/);
    assert.match(hub, /<form ref=\{fileForm\} method="post" action="\/commands\/operations\/accounting\/payout-batch\/export"/);
    assert.match(hub, /window\.location\.assign\(href\)/);
});

test('phones get bottom sheets, computers keep the centred dialog', () => {
    const css = readFileSync(new URL('../../resources/css/screens-confirm-sheet.css', import.meta.url), 'utf8');
    const dashboard = readFileSync(new URL('../../resources/css/dashboard.css', import.meta.url), 'utf8');

    assert.match(dashboard, /@import '\.\/screens-confirm-sheet\.css';/);
    const phone = css.indexOf('@media (max-width:767px){');
    assert.ok(phone > 0, 'the sheet rules sit in the phone block');
    assert.ok(css.indexOf(':root dialog.portal-modal:not(.portal-modal-drawer){') > phone, 'the dialog docks to the bottom only on phones');
    assert.ok(css.indexOf('.app-shell .hub-overlay{align-items:flex-end') > phone, 'hub pop-ups dock to the bottom only on phones');
    assert.match(css, /max-height:min\(85dvh,calc\(100dvh - var\(--sheet-keyboard,0px\) - 12px\)\)/, 'keyboard-safe height');
});
