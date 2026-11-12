import assert from 'node:assert/strict';
import test from 'node:test';
import { matchesSearch, normalizeSearch } from '../../resources/js/shared/search-match.ts';

test('nothing typed matches every row', () => {
    assert.equal(matchesSearch('Napa 500', ''), true);
    assert.equal(matchesSearch('Napa 500', '   '), true);
});

test('letter case and extra spaces do not matter', () => {
    assert.equal(matchesSearch('Madina Herbal Syrup', 'HERBAL'), true);
    assert.equal(matchesSearch('Madina Herbal Syrup', '  madina   syrup '), true);
});

test('several words match in any order, and every word must be there', () => {
    assert.equal(matchesSearch('Madina Herbal Syrup 200ml', 'syrup madina'), true);
    assert.equal(matchesSearch('Madina Herbal Syrup 200ml', 'syrup tablet'), false);
});

test('Bangla text is found by a part of a word', () => {
    assert.equal(matchesSearch('ঠাকুরগাঁও সদর', 'ঠাকুর'), true);
    assert.equal(matchesSearch('ঠাকুরগাঁও সদর', 'রংপুর'), false);
});

test('Bangla and English digits find each other', () => {
    assert.equal(matchesSearch('কোড ০১৭৩০', '0173'), true);
    assert.equal(matchesSearch('Code 01730', '০১৭৩'), true);
    assert.equal(normalizeSearch('  ৫০০  মিলি '), '500 মিলি');
});

test('a code typed without its name still finds the row', () => {
    assert.equal(matchesSearch('HO-PIRGANJ — Head office MD-0042', 'md-0042'), true);
});
