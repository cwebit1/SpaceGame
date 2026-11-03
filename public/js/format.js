/**
 * format.js - Formattazione numeri per frontend (JS vanilla).
 * Replica la logica di core/Format.php per coerenza.
 */

let scale = ['k', 'Mln', 'Mld', 'Bln', 'Bld', 'Tln', 'Tld', 'Qln', 'Qld', 'Qnl', 'Qnd', 'Sln', 'Sld'];
let decimalSeparator = ',';
let thousandsSeparator = '.';
let decimals = 2;

/**
 * Inizializza il formattatore dalla configurazione del server.
 * @param {Object} config - Oggetto con scale, decimal_separator, thousands_separator, decimals
 */
export function initFormat(config) {
    if (config?.scale) scale = config.scale;
    if (config?.decimal_separator) decimalSeparator = config.decimal_separator;
    if (config?.thousands_separator) thousandsSeparator = config.thousands_separator;
    if (config?.decimals !== undefined) decimals = config.decimals;
}

/**
 * Formato breve: "12,45 Mld"
 * Restituisce "0" per NaN, Infinity o 0.
 */
export function formatShort(x) {
    if (!isFinite(x) || x === 0) {
        return '0';
    }

    const negative = x < 0;
    x = Math.abs(x);

    if (x < 1000) {
        const formatted = formatNumber(x, 0);
        return negative ? '-' + formatted : formatted;
    }

    // Trova l'indice della scala: k=10^3, Mln=10^6, Mld=10^9, ecc.
    let exp = Math.floor(Math.log10(x) / 3);
    const maxIndex = scale.length - 1;

    if (exp > maxIndex) {
        // Oltre la scala: notazione scientifica
        return negative ? '-' + toScientific(x) : toScientific(x);
    }

    // Calcola il valore e controlla se l'arrotondamento lo porta a >= 1000
    // In tal caso, passa alla scala successiva
    let divisor = Math.pow(10, exp * 3);
    let value = x / divisor;

    // Arrotonda per controllare se dobbiamo salire di scala
    const roundedValue = Number(value.toFixed(decimals));
    if (roundedValue >= 1000 && exp < maxIndex) {
        exp++;
        divisor = Math.pow(10, exp * 3);
        value = x / divisor;
    }

    const suffix = scale[exp - 1]; // k è indice 0 = 10^3

    const formatted = formatNumber(value, decimals);
    const result = formatted + ' ' + suffix;

    return negative ? '-' + result : result;
}

/**
 * Formato lungo (per ora delega a short).
 */
export function formatLong(x) {
    return formatShort(x);
}

/**
 * Parsa una stringa come "2,5Mln", "5k", "1.5e15" in number.
 * Restituisce null se non valida, vuota, negativa o non numerica.
 */
export function parseAmount(s) {
    if (typeof s !== 'string') return null;
    const trimmed = s.trim();
    if (trimmed === '') return null;

    // Negativi non ammessi
    if (trimmed.startsWith('-')) return null;

    // Suffissi scala (case-insensitive)
    const suffixes = {
        'k': 3,
        'mln': 6,
        'mld': 9,
        'bln': 12,
        'bld': 15,
        'tln': 18,
        'tld': 21,
        'qln': 24,
        'qld': 27,
        'qnl': 30,
        'qnd': 33,
        'sln': 36,
        'sld': 39,
    };

    const lower = trimmed.toLowerCase();
    for (const [suffix, exp] of Object.entries(suffixes)) {
        if (lower.endsWith(suffix)) {
            const numPart = trimmed.slice(0, -suffix.length);
            // Normalizza la parte numerica prima di parsare
            const normalizedNumPart = normalizeNumber(numPart);
            const num = parseNumber(normalizedNumPart);
            if (num !== null) {
                return num * Math.pow(10, exp);
            }
            return null;
        }
    }

    // Notazione scientifica (es. 1.5e15 o 1,5e15)
    // Prima prova notazione standard (con punto)
    if (/^[0-9]+(\.[0-9]+)?e[+-]?[0-9]+$/i.test(trimmed)) {
        const val = parseFloat(trimmed);
        return isFinite(val) ? val : null;
    }
    // Poi prova notazione italiana (con virgola)
    if (/^[0-9]+(,[0-9]+)?e[+-]?[0-9]+$/i.test(trimmed)) {
        const normalized = trimmed.replace(',', '.');
        const val = parseFloat(normalized);
        return isFinite(val) ? val : null;
    }

    // Numero semplice (gestisce separatore decimale italiano)
    const normalized = normalizeNumber(trimmed);
    return parseNumber(normalized);
}

/**
 * Normalizza un numero italiano (gestisce separatori migliaia e decimali).
 */
function normalizeNumber(s) {
    const trimmed = s.trim();
    if (trimmed === '') return '';

    // Se è notazione scientifica, non modificare
    if (/^[0-9]+([.,][0-9]+)?e[+-]?[0-9]+$/i.test(trimmed)) {
        return trimmed.replace(',', '.');
    }

    // Se contiene il separatore decimale italiano
    if (trimmed.includes(',')) {
        // Divide in parte intera e decimale
        const parts = trimmed.split(',', 2);
        const integerPart = parts[0];
        const decimalPart = parts[1] ?? '';

        // Rimuove separatori migliaia solo dalla parte intera
        const cleanInteger = integerPart.replace(/\./g, '');

        // Ricompone con punto decimale standard
        return cleanInteger + (decimalPart !== '' ? '.' + decimalPart : '');
    }

    // Nessun separatore decimale italiano: potrebbe avere solo separatori migliaia
    return trimmed.replace(/\./g, '');
}

/**
 * Formatta un numero con separatori.
 */
function formatNumber(x, decimals) {
    const rounded = Number(x.toFixed(decimals));
    const parts = rounded.toFixed(decimals).split('.');
    let integerPart = parts[0];

    // Aggiungi separatori migliaia
    let formattedInteger = '';
    const len = integerPart.length;
    for (let i = 0; i < len; i++) {
        if (i > 0 && (len - i) % 3 === 0) {
            formattedInteger += thousandsSeparator;
        }
        formattedInteger += integerPart[i];
    }

    if (decimals > 0 && parts[1]) {
        return formattedInteger + decimalSeparator + parts[1];
    }
    return formattedInteger;
}

/**
 * Parsa una stringa numerica già normalizzata (con . come separatore decimale, senza separatori migliaia).
 */
function parseNumber(s) {
    const trimmed = s.trim();
    if (trimmed === '') return null;

    // Deve essere un numero valido in formato standard (es. "123", "123.45", "1.5e15")
    if (!/^[0-9]+(\.[0-9]+)?$/.test(trimmed) && !/^[0-9]+(\.[0-9]+)?e[+-]?[0-9]+$/i.test(trimmed)) {
        return null;
    }

    const val = parseFloat(trimmed);
    return isFinite(val) ? val : null;
}

/**
 * Converte in notazione scientifica (es. 1,23e66).
 */
function toScientific(x) {
    // Usa toExponential per notazione scientifica con 2 decimali
    const sci = x.toExponential(2);
    // Sostituisce . con separatore decimale italiano
    return sci.replace('.', decimalSeparator);
}