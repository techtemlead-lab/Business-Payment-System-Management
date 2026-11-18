import assert from 'node:assert/strict';
import test from 'node:test';
import { cardStock, filterChoices, partnerPrice, priceRange, searchProducts, stockState } from '../../resources/js/shared/orderSearch.ts';
import { sizesByGroup } from '../../resources/js/shared/productSizes.ts';

const small = { id: 1, name: 'Hajmi Syrup 100 ml', size_group_id: 7, size_position: 1, size_label: '100 ml', stock: 0, price: '90', mrp: '100', category: 'Syrup', sub_category: 'Digestive', sku: 'HS-100' };
const large = { id: 2, name: 'Hajmi Syrup 200 ml', size_group_id: 7, size_position: 2, size_label: '200 ml', stock: 5, price: '170', mrp: '190', category: 'Syrup', sub_category: 'Digestive', sku: 'HS-200' };
const bangla = { id: 3, name: 'মধু বিশুদ্ধ', stock: 4, price: '300', mrp: '320', category: 'Food', sku: 'MD-1' };
const paused = { id: 4, name: 'Neem Oil', stock: 9, is_stock_paused: true, mrp: '50', category: 'Oil', sku: 'NO-1' };
const all = [small, large, bangla, paused];
const groups = sizesByGroup(all);
const names = cards => cards.map(card => card.first.id);

test('nothing typed and no filter lists nothing, a filter alone lists that part of the range', () => {
    assert.deepEqual(searchProducts(all, '   ', groups), []);
    assert.deepEqual(names(searchProducts(all, '', groups, { category: 'Oil' })), [4]);
});

test('letter case and extra spaces do not matter', () => {
    assert.deepEqual(names(searchProducts(all, '  NEEM   oil ', groups)), [4]);
});

test('Bangla names are found, including with Bangla or English digits typed', () => {
    assert.deepEqual(names(searchProducts(all, 'মধু', groups)), [3]);
    assert.deepEqual(names(searchProducts(all, 'বিশুদ্ধ মধু', groups)), [3]);
});

test('the code, the pack size and the category are searchable without choosing a category', () => {
    assert.deepEqual(names(searchProducts(all, 'no-1', groups)), [4]);
    assert.deepEqual(names(searchProducts(all, '200 ml', groups)), [2]);
    assert.deepEqual(names(searchProducts(all, 'food', groups)), [3]);
});

test('sizes of one product are one card that opens on the size in stock', () => {
    const cards = searchProducts(all, 'hajmi', groups);

    assert.equal(cards.length, 1);
    assert.equal(cards[0].sizes.length, 2);
    assert.equal(cards[0].first.id, 2);
});

test('a name that starts with the typed text comes before one that only contains it', () => {
    const other = { id: 9, name: 'Super Neem Wash', stock: 1, mrp: '10', category: 'Wash' };
    const list = [other, paused];

    assert.deepEqual(names(searchProducts(list, 'neem', sizesByGroup(list))), [4, 9]);
});

test('filters narrow the list and never need the other filter', () => {
    assert.deepEqual(names(searchProducts(all, 'o', groups, { category: 'Oil' })), [4]);
    assert.deepEqual(searchProducts(all, 'hajmi', groups, { category: 'Oil' }), []);
    assert.deepEqual(names(searchProducts(all, '', groups, { category: 'Syrup', subCategory: 'Digestive' })), [2]);
});

test('stock states: paused wins over stock, zero stock is out', () => {
    assert.equal(stockState(paused), 'paused');
    assert.equal(stockState(small), 'out');
    assert.equal(stockState(large), 'in');
    assert.equal(cardStock({ key: 'x', sizes: [small, large], first: large }), 'in');
    assert.equal(cardStock({ key: 'x', sizes: [small, paused], first: small }), 'out');
    assert.equal(cardStock({ key: 'x', sizes: [paused], first: paused }), 'paused');
});

test('a product sent without a stock figure, as to dealers, is orderable', () => {
    const unknown = { id: 99, name: 'No figure', mrp: 100 };
    assert.equal(stockState(unknown), 'in');
    assert.equal(cardStock({ key: 'x', sizes: [unknown], first: unknown }), 'in');
});

test('the partner price is his price, else the printed price; a range spans the sizes', () => {
    assert.equal(partnerPrice(large), 170);
    assert.equal(partnerPrice(paused), 50);
    assert.deepEqual(priceRange({ key: 'g7', sizes: [small, large], first: large }), { low: 90, high: 170 });
});

test('filter choices list categories A to Z and sub-categories only for the chosen category', () => {
    assert.deepEqual(filterChoices(all).categories, ['Food', 'Oil', 'Syrup']);
    assert.deepEqual(filterChoices(all).subCategories, []);
    assert.deepEqual(filterChoices(all, 'Syrup').subCategories, ['Digestive']);
});

test('the list is capped', () => {
    const many = Array.from({ length: 50 }, (_, index) => ({ id: 100 + index, name: `Tonic ${index}`, stock: 1, mrp: '1' }));

    assert.equal(searchProducts(many, 'tonic', sizesByGroup(many), {}, 10).length, 10);
});
