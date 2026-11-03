/**
 * format.test.mjs - Test per format.js (ES modules per Node.js).
 * Eseguire con: node tests/js/format.test.mjs
 */

import { initFormat, formatShort, formatLong, parseAmount } from '../../public/js/format.js';

let passed = 0;
let failed = 0;

function assertEqual(actual, expected, message) {
    if (actual === expected) {
        console.log(`✓ ${message}`);
        passed++;
    } else {
        console.error(`✗ ${message}`);
        console.error(`  Expected: ${expected}`);
        console.error(`  Actual:   ${actual}`);
        failed++;
    }
}

function assertNull(actual, message) {
    if (actual === null) {
        console.log(`✓ ${message}`);
        passed++;
    } else {
        console.error(`✗ ${message}`);
        console.error(`  Expected: null`);
        console.error(`  Actual:   ${actual}`);
        failed++;
    }
}

function assertContains(actual, substring, message) {
    if (actual.includes(substring)) {
        console.log(`✓ ${message}`);
        passed++;
    } else {
        console.error(`✗ ${message}`);
        console.error(`  Expected to contain: ${substring}`);
        console.error(`  Actual:   ${actual}`);
        failed++;
    }
}

// Inizializza con config standard
initFormat({
    scale: ['k', 'Mln', 'Mld', 'Bln', 'Bld', 'Tln', 'Tld', 'Qln', 'Qld', 'Qnl', 'Qnd', 'Sln', 'Sld'],
    decimal_separator: ',',
    thousands_separator: '.',
    decimals: 2,
});

console.log('=== formatShort tests ===');

// Test valori semplici
assertEqual(formatShort(999), '999', 'formatShort(999) = "999"');
assertEqual(formatShort(0), '0', 'formatShort(0) = "0"');
assertEqual(formatShort(-0), '0', 'formatShort(-0) = "0"');

// Test migliaia
assertEqual(formatShort(1000), '1,00 k', 'formatShort(1000) = "1,00 k"');
assertEqual(formatShort(1230), '1,23 k', 'formatShort(1230) = "1,23 k"');
// 999999 rounds to 1000.00 k = 1.00 Mln
assertEqual(formatShort(999999), '1,00 Mln', 'formatShort(999999) = "1,00 Mln"');

// Test milioni (Mln = 10^6)
assertEqual(formatShort(1000000), '1,00 Mln', 'formatShort(1000000) = "1,00 Mln"');
assertEqual(formatShort(2500000), '2,50 Mln', 'formatShort(2500000) = "2,50 Mln"');

// Test miliardi (Mld = 10^9)
assertEqual(formatShort(1000000000), '1,00 Mld', 'formatShort(1000000000) = "1,00 Mld"');
assertEqual(formatShort(12450000000), '12,45 Mld', 'formatShort(12450000000) = "12,45 Mld"');

// Test biliardi (Bld = 10^15)
assertEqual(formatShort(1.5e15), '1,50 Bld', 'formatShort(1.5e15) = "1,50 Bld"');

// Test oltre la scala (notazione scientifica)
const sciResult = formatShort(1.23e66);
assertContains(sciResult, 'e', 'formatShort(1.23e66) contains "e"');
assertContains(sciResult, '1,23', 'formatShort(1.23e66) contains "1,23"');

// Test NaN/Infinity
assertEqual(formatShort(NaN), '0', 'formatShort(NaN) = "0"');
assertEqual(formatShort(Infinity), '0', 'formatShort(Infinity) = "0"');
assertEqual(formatShort(-Infinity), '0', 'formatShort(-Infinity) = "0"');

// Test negativi
assertEqual(formatShort(-12450000000), '-12,45 Mld', 'formatShort(-12450000000) = "-12,45 Mld"');

console.log('\n=== formatLong tests ===');
assertEqual(formatLong(12450000000), '12,45 Mld', 'formatLong delegates to formatShort');

console.log('\n=== parseAmount tests ===');

// Test suffissi
assertEqual(parseAmount('5k'), 5000, 'parseAmount("5k") = 5000');
assertEqual(parseAmount('2,5Mln'), 2500000, 'parseAmount("2,5Mln") = 2500000');
assertEqual(parseAmount('1,5e15'), 1.5e15, 'parseAmount("1,5e15") = 1.5e15');
assertEqual(parseAmount('1.5e15'), 1.5e15, 'parseAmount("1.5e15") = 1.5e15');

// Test con spazi
assertEqual(parseAmount(' 5k '), 5000, 'parseAmount(" 5k ") = 5000');
assertEqual(parseAmount(' 2,5Mln '), 2500000, 'parseAmount(" 2,5Mln ") = 2500000');

// Test invalidi
assertNull(parseAmount(''), 'parseAmount("") = null');
assertNull(parseAmount('-3'), 'parseAmount("-3") = null');
assertNull(parseAmount('abc'), 'parseAmount("abc") = null');
assertNull(parseAmount('not a number'), 'parseAmount("not a number") = null');

console.log('\n=== Summary ===');
console.log(`Passed: ${passed}`);
console.log(`Failed: ${failed}`);

if (failed > 0) {
    process.exit(1);
}