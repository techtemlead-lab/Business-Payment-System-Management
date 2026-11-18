import assert from 'node:assert/strict';
import test from 'node:test';
import { bangladeshSpelling, devanagariToBangla, joinSentences, prepareEnglish, sentences, shouldFill, tidyBangla } from '../../resources/js/shared/banglaFill/rules.ts';

test('the Bangla box is filled only when English was typed and the Bangla box is empty', () => {
    assert.equal(shouldFill('Abdur Rahim', ''), true);
    assert.equal(shouldFill('Abdur Rahim', '   '), true);
});

test('a Bangla value someone typed or corrected is never replaced', () => {
    assert.equal(shouldFill('Abdur Rahim', 'আব্দুর রহিম'), false);
    assert.equal(shouldFill('Abdur Rahim', 'x'), false);
});

test('nothing is written from an empty English box or one without English letters', () => {
    assert.equal(shouldFill('', ''), false);
    assert.equal(shouldFill('   ', ''), false);
    assert.equal(shouldFill('01711-223344', ''), false);
    assert.equal(shouldFill('রহিম', ''), false);
});

test('the model output in the shared Indian script becomes Bangla letters, keeping the full stop', () => {
    // रहिम । → রহিম ।
    assert.equal(devanagariToBangla('रहिम ।'), 'রহিম ।');
    assert.equal(devanagariToBangla('Neem 500'), 'Neem 500');
});

test('common names get the spelling used in Bangladesh, whole words only', () => {
    assert.equal(bangladeshSpelling('মহম্মদ রফিকুল ইসলাম'), 'মোহাম্মদ রফিকুল ইসলাম');
    assert.equal(bangladeshSpelling('শিরিন আকতার'), 'শিরিন আক্তার');
    assert.equal(bangladeshSpelling('মো. আবুল হুসেন চৌধুরি'), 'মো. আবুল হোসেন চৌধুরী');
    assert.equal(bangladeshSpelling('আকতারপুর'), 'আকতারপুর');
});

test('English is split into words and punctuation the way the model learnt it', () => {
    assert.equal(prepareEnglish('Take one “capsule” – twice daily.'), 'Take one " capsule " - twice daily .');
});

test('written Bangla has no space before punctuation and uses Bangla digits', () => {
    assert.equal(tidyBangla('দিনে 2 বার ( খাবারের পর ) ।'), 'দিনে ২ বার (খাবারের পর)।');
});

test('long text is translated sentence by sentence and keeps its line breaks', () => {
    const pieces = sentences('Helps digestion. Reduces gas!\n\nKeep dry');
    assert.deepEqual(pieces, ['Helps digestion.', 'Reduces gas!', '\n', '\n', 'Keep dry']);
    assert.equal(joinSentences(['হজমে সাহায্য করে।', 'গ্যাস কমায়!', '\n', '\n', 'শুকনো রাখুন']), 'হজমে সাহায্য করে। গ্যাস কমায়!\n\nশুকনো রাখুন');
});

test('a very long line without full stops is cut at commas', () => {
    const long = Array.from({ length: 40 }, (_, index) => `ingredient number ${index}`).join(', ');
    const pieces = sentences(long);
    assert.ok(pieces.length > 1);
    assert.ok(pieces.every((piece) => piece.length <= 400));
});
