import assert from 'node:assert/strict';
import test from 'node:test';
import { shipmentArrivalText } from '../../resources/js/components/orderShipmentArrival.ts';

const translate = (group, key, replace = {}) => `${group}.${key}${replace.date ? `(${replace.date})` : ''}`;
const formatDate = (value) => `d:${value}`;

test('a parcel on the way shows the expected date', () => {
    assert.equal(shipmentArrivalText({ status: 'dispatched', eta: '2026-11-10' }, translate, formatDate), 'portal.expected d:2026-11-10');
});

test('a parcel on the way with no date says it will be told later', () => {
    assert.equal(shipmentArrivalText({ status: 'dispatched', eta: null }, translate, formatDate), 'portal.eta_to_be_confirmed');
});

test('a delivered parcel shows its delivery date, never an expected date or told later', () => {
    assert.equal(shipmentArrivalText({ status: 'delivered', eta: null, delivered_at: '2026-11-09' }, translate, formatDate), 'order_payment.delivered_on(d:2026-11-09)');
    assert.equal(shipmentArrivalText({ status: 'delivered', eta: '2026-11-10', delivered_at: '2026-11-09' }, translate, formatDate), 'order_payment.delivered_on(d:2026-11-09)');
});

test('a delivered parcel with no recorded date shows nothing', () => {
    assert.equal(shipmentArrivalText({ status: 'delivered', eta: null, delivered_at: null }, translate, formatDate), '');
});

test('a returned parcel shows nothing about arrival', () => {
    assert.equal(shipmentArrivalText({ status: 'returned', eta: null }, translate, formatDate), '');
});
