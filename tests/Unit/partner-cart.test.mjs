import assert from 'node:assert/strict';
import test from 'node:test';
import {
    addLine, cartKey, cartTotal, clampQuantity, createCartStore, lineCount, lineFromProduct, linesFromItems,
    loadLines, orderLines, parseLines, refreshLines, removeLine, saveLines, setLineQuantity, unitCount,
} from '../../resources/js/shared/partnerCart.ts';

const syrup100 = { id: 11, name: 'Syrup X', size_label: '100 ml', unit: 'Bottle', mrp: 200, price: 150, image_url: '/a.jpg' };
const syrup200 = { id: 12, name: 'Syrup X', size_label: '200 ml', unit: 'Bottle', mrp: 360, price: 270 };
const plain = { id: 21, name: 'Zinc', unit: 'Box', mrp: '100.00', price: '75.00' };

/** A storage that behaves like the browser's: strings in, strings out. */
function memoryStorage() {
    const data = new Map();
    return {
        getItem: key => (data.has(key) ? data.get(key) : null),
        setItem: (key, value) => { data.set(key, String(value)); },
        removeItem: key => { data.delete(key); },
        data,
    };
}

test('a line keeps the partner price and falls back to the printed price', () => {
    assert.equal(lineFromProduct(syrup100, 2).price, 150);
    assert.equal(lineFromProduct({ id: 1, name: 'No price', mrp: 90 }, 1).price, 90);
    assert.equal(lineFromProduct(plain, 1).price, 75);
});

test('adding puts a new product at the end', () => {
    const lines = addLine(addLine([], lineFromProduct(syrup100, 2)), lineFromProduct(plain, 1));

    assert.deepEqual(lines.map(line => [line.productId, line.quantity]), [[11, 2], [21, 1]]);
    assert.equal(lineCount(lines), 2);
    assert.equal(unitCount(lines), 3);
});

test('the same product and size is one line with the quantities added up; another size is another line', () => {
    let lines = addLine([], lineFromProduct(syrup100, 2));
    lines = addLine(lines, lineFromProduct(syrup100, 3));
    lines = addLine(lines, lineFromProduct(syrup200, 1));

    assert.deepEqual(lines.map(line => [line.productId, line.quantity]), [[11, 5], [12, 1]]);
});

test('a quantity is a whole number from 1 to 10,000', () => {
    assert.equal(clampQuantity(0), 1);
    assert.equal(clampQuantity(-4), 1);
    assert.equal(clampQuantity('abc'), 1);
    assert.equal(clampQuantity(2.9), 2);
    assert.equal(clampQuantity(99999), 10000);
    assert.equal(addLine([lineFromProduct(plain, 9999)], lineFromProduct(plain, 5))[0].quantity, 10000);
});

test('changing a quantity keeps the other lines and zero removes the line', () => {
    const lines = [lineFromProduct(syrup100, 2), lineFromProduct(plain, 1)];

    assert.deepEqual(setLineQuantity(lines, 11, 7).map(line => line.quantity), [7, 1]);
    assert.deepEqual(setLineQuantity(lines, 11, 0).map(line => line.productId), [21]);
    assert.deepEqual(removeLine(lines, 21).map(line => line.productId), [11]);
});

test('the total counts the partner price of every line, in whole paisa', () => {
    const lines = [lineFromProduct(syrup100, 2), lineFromProduct({ ...plain, price: '0.10' }, 3)];

    assert.equal(cartTotal(lines), 300.3);
    assert.equal(cartTotal([]), 0);
});

test('the cart survives a refresh: it is saved, read back and dropped when empty', () => {
    const storage = memoryStorage();
    const key = cartKey(7);
    saveLines(storage, key, [lineFromProduct(syrup100, 2)]);

    assert.deepEqual(loadLines(storage, key), [lineFromProduct(syrup100, 2)]);
    saveLines(storage, key, []);
    assert.equal(storage.data.has(key), false);
    assert.deepEqual(loadLines(storage, key), []);
});

test('two people on one computer never share a cart', () => {
    const storage = memoryStorage();
    saveLines(storage, cartKey(1), [lineFromProduct(plain, 1)]);

    assert.deepEqual(loadLines(storage, cartKey(2)), []);
    assert.notEqual(cartKey(1), cartKey(2));
});

test('a damaged or hand-edited saved cart is cleaned, never trusted', () => {
    assert.deepEqual(parseLines('not json'), []);
    assert.deepEqual(parseLines('{"a":1}'), []);
    const lines = parseLines(JSON.stringify([
        { productId: 11, quantity: 2, name: 'Ok', price: 5 },
        { productId: 'x', quantity: 2 },
        { productId: 12, quantity: 0 },
        { productId: 11, quantity: 9 },
        { productId: 13, quantity: 999999 },
    ]));

    assert.deepEqual(lines.map(line => [line.productId, line.quantity]), [[11, 2], [13, 10000]]);
});

test('a storage that fails does not break the cart', () => {
    const broken = { getItem() { throw new Error('off'); }, setItem() { throw new Error('full'); }, removeItem() { throw new Error('off'); } };

    assert.deepEqual(loadLines(broken, 'k'), []);
    assert.doesNotThrow(() => saveLines(broken, 'k', [lineFromProduct(plain, 1)]));
    const store = createCartStore(broken, 'k');
    store.set([lineFromProduct(plain, 1)]);
    assert.equal(store.get().length, 1);
});

test('the store tells every listener, saves, and a new store reads the saved cart', () => {
    const storage = memoryStorage();
    const store = createCartStore(storage, 'k');
    let heard = 0;
    const stop = store.subscribe(() => { heard += 1; });
    store.set([lineFromProduct(plain, 4)]);
    stop();
    store.set([]);

    assert.equal(heard, 1);
    store.set([lineFromProduct(plain, 4)]);
    assert.equal(createCartStore(storage, 'k').get()[0].quantity, 4);
});

test('the cart is empty after a successful order', () => {
    const storage = memoryStorage();
    const store = createCartStore(storage, 'k');
    store.set([lineFromProduct(plain, 4), lineFromProduct(syrup100, 1)]);
    store.set([]);

    assert.equal(store.get().length, 0);
    assert.equal(storage.data.has('k'), false);
});

test('the hand-off gives the order page every line on its live product row', () => {
    const lines = [lineFromProduct(syrup100, 2), lineFromProduct(plain, 5)];
    const live = [{ ...syrup100, price: 160 }, { ...plain }];
    const items = orderLines(lines, live);

    assert.deepEqual(items.map(item => [item.product.id, item.quantity]), [[11, 2], [21, 5]]);
    assert.equal(items[0].product.price, 160);
});

test('a product no longer on the order page is left out of the hand-off', () => {
    const items = orderLines([lineFromProduct(syrup100, 2), lineFromProduct(plain, 1)], [plain]);

    assert.deepEqual(items.map(item => item.product.id), [21]);
});

test('"Order now" is one product with its chosen size and quantity, added to the same cart', () => {
    const cart = addLine([lineFromProduct(plain, 1)], lineFromProduct(syrup200, 3));
    const items = orderLines(cart, [syrup200, plain]);

    assert.deepEqual(items.map(item => [item.product.id, item.quantity]), [[21, 1], [12, 3]]);
});

test('the order page items turn back into lines, so its changes reach the cart icon', () => {
    const lines = linesFromItems([{ product: syrup100, quantity: 6 }]);

    assert.deepEqual(lines.map(line => [line.productId, line.quantity, line.price]), [[11, 6, 150]]);
});

test('names and prices follow the product list when the page loads', () => {
    const lines = refreshLines([lineFromProduct(syrup100, 2), lineFromProduct(plain, 1)], [{ ...syrup100, price: 155, name: 'Syrup X new' }]);

    assert.equal(lines[0].price, 155);
    assert.equal(lines[0].name, 'Syrup X new');
    assert.equal(lines[0].quantity, 2);
    assert.equal(lines[1].price, 75);
});
