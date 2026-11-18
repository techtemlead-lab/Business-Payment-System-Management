import assert from 'node:assert/strict';
import test from 'node:test';
import { banglaKindOf, bilingualBox, bilingualBoxFor } from '../../resources/js/shared/banglaFill/pair.ts';
import { banglaBoxes } from './bangla-boxes.mjs';

test('an English box gets an id and its Bangla twin points at it', () => {
    assert.deepEqual(bilingualBox('title', 'event-'), { id: 'event-title' });
    assert.deepEqual(bilingualBox('title_en', 'event-'), { id: 'event-title' });
    assert.deepEqual(bilingualBox('title_bn', 'event-'), { 'data-bangla-from': 'event-title', 'data-bangla-kind': 'text' });
});

test('rows keep their own pairs, and odd characters never break an id', () => {
    assert.equal(bilingualBox('question_bn', 'faq-3-')['data-bangla-from'], bilingualBox('question', 'faq-3-').id);
    assert.notEqual(bilingualBox('question', 'faq-3-').id, bilingualBox('question', 'faq-4-').id);
    assert.equal(bilingualBox('trust signer.name', 'x ')['id'], 'x-trust-signer-name');
});

test('names are spelt out, other text is translated, and a form may say which', () => {
    assert.equal(banglaKindOf('doctor_name'), 'name');
    assert.equal(banglaKindOf('md_message_name'), 'name');
    assert.equal(banglaKindOf('name'), 'name');
    assert.equal(banglaKindOf('md_message_body'), 'text');
    assert.equal(banglaKindOf('surname_hint'), 'text');
    assert.equal(bilingualBox('body_bn', 'a-', 'name')['data-bangla-kind'], 'name');
    assert.deepEqual(bilingualBoxFor('chamber', 'bn', 'lh-'), { 'data-bangla-from': 'lh-chamber', 'data-bangla-kind': 'text' });
    assert.deepEqual(bilingualBoxFor('chamber', 'en', 'lh-'), { id: 'lh-chamber' });
});

test('every Bangla box in the staff and owner screens fills itself from its English twin', () => {
    const boxes = banglaBoxes(process.cwd());
    const missing = boxes.filter((box) => !box.handled).map((box) => `${box.file}:${box.line}  ${box.tag}`);

    assert.ok(boxes.length >= 10, 'the scan should find the known Bangla boxes');
    assert.deepEqual(missing, [], 'Use bilingualBox()/data-bangla-from on these, or data-bangla-manual for a Bangla-only box.');
});
