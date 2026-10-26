import assert from 'node:assert/strict';
import test from 'node:test';
import { partnerChromeEnabled } from '../../resources/js/shared/partnerChromeNav.ts';

test('hides dealer catalog orders and network when dashboard toggles are off', () => {
    const controls = {
        dealer_catalog_enabled: false,
        dealer_orders_enabled: false,
        dealer_network_tree_enabled: false,
    };
    assert.equal(partnerChromeEnabled('dealer', controls, 'catalog'), false);
    assert.equal(partnerChromeEnabled('dealer', controls, 'orders'), false);
    assert.equal(partnerChromeEnabled('dealer', controls, 'network'), false);
    assert.equal(partnerChromeEnabled('dealer', controls, 'earnings'), true);
    assert.equal(partnerChromeEnabled('dealer', controls, 'salary'), false);
});

test('defaults partner chrome enabled when controls are missing', () => {
    assert.equal(partnerChromeEnabled('dealer', null, 'catalog'), true);
    assert.equal(partnerChromeEnabled('sub_dealer', {}, 'salary'), true);
    assert.equal(partnerChromeEnabled('sub_dealer', { subdealer_salary_enabled: false }, 'salary'), false);
    assert.equal(partnerChromeEnabled('sub_dealer', { subdealer_catalog_enabled: false }, 'catalog'), false);
});
