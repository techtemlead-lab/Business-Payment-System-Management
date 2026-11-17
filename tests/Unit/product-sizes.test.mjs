import assert from 'node:assert/strict';
import test from 'node:test';
import { choiceName, oneCardPerGroup, sizesByGroup } from '../../resources/js/shared/productSizes.ts';

const small = { id: 1, name: 'Syrup X 100 ml', size_group_id: 7, size_position: 1, stock: 0 };
const medium = { id: 2, name: 'Syrup X 200 ml', size_group_id: 7, size_position: 2, stock: 9 };
const large = { id: 3, name: 'Syrup X 450 ml', size_group_id: 7, size_position: 3, stock: 4, is_stock_paused: true };
const plain = { id: 4, name: 'Zinc Plain', stock: 3 };
const lonely = { id: 5, name: 'Lonely 50 ml', size_group_id: 8, size_position: 1, stock: 2 };

test('the sizes of a group are listed smallest first and plain products are left out', () => {
    const groups = sizesByGroup([large, plain, small, medium]);

    assert.deepEqual(groups.get(7)?.map(size => size.id), [1, 2, 3]);
    assert.equal(groups.size, 1);
});

test('every size of a group goes by the name of the first size', () => {
    const groups = sizesByGroup([small, medium, large, plain]);

    assert.equal(choiceName(medium, groups), 'Syrup X 100 ml');
    assert.equal(choiceName(plain, groups), 'Zinc Plain');
});

test('a group with one visible size is an ordinary product', () => {
    const groups = sizesByGroup([lonely]);

    assert.equal(choiceName(lonely, groups), 'Lonely 50 ml');
    assert.deepEqual(oneCardPerGroup([lonely], groups).map(card => card.key), ['p5']);
});

test('a search that matches several sizes shows the product once', () => {
    const everything = [small, medium, large, plain];
    const cards = oneCardPerGroup(everything, sizesByGroup(everything));

    assert.deepEqual(cards.map(card => card.key), ['g7', 'p4']);
    assert.equal(cards[0].sizes.length, 3);
});

test('the card opens on the first matching size that is in stock and not paused', () => {
    const everything = [small, medium, large];
    const groups = sizesByGroup(everything);

    assert.equal(oneCardPerGroup(everything, groups)[0].first.id, 2);
    assert.equal(oneCardPerGroup([large, small], groups)[0].first.id, 1, 'with nothing in stock it opens on the first match');
});

test('the other sizes of the group stay on the card even when only one size matched', () => {
    const groups = sizesByGroup([small, medium, large]);
    const [card] = oneCardPerGroup([medium], groups);

    assert.equal(card.first.id, 2);
    assert.deepEqual(card.sizes.map(size => size.id), [1, 2, 3]);
});
