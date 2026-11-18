import assert from 'node:assert/strict';
import test from 'node:test';
import { menuHome } from '../../resources/js/shared/searchSelectHome.ts';

const element = (name, dialog = null) => ({ name, closest: (selector) => (selector === 'dialog' ? dialog : null) });

test('a list opened inside a dialog is placed inside that dialog, so the dialog does not lock it', () => {
    const body = element('body');
    const dialog = element('dialog');
    const trigger = element('trigger', dialog);
    assert.equal(menuHome(trigger, body), dialog);
});

test('a list opened outside any dialog is placed on the page body', () => {
    const body = element('body');
    assert.equal(menuHome(element('trigger'), body), body);
});

test('a list with no trigger yet falls back to the page body', () => {
    const body = element('body');
    assert.equal(menuHome(null, body), body);
    assert.equal(menuHome(undefined, body), body);
});
