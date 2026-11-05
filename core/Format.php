<?php

declare(strict_types=1);

namespace SpaceGame;

/**
 * Format - Formattazione numeri in notazione scala lunga italiana.
 * Scala: k, Mln, Mld, Bln, Bld, Tln, Tld, Qln, Qld, Qnl, Qnd, Sln, Sld...
 * Oltre 10^63: notazione scientifica.
 */
final class Format
{
    /** @var array<string> */
    private static array $scale = [];

    /** @var array<string, int> */
    private static array $suffixMap = [];

    /** @var string */
    private static string $decimalSeparator = ',';

    /** @var string */
    private static string $thousandsSeparator = '.';

    /** @var int */
    private static int $decimals = 2;

    /**
     * Inizializza il formattatore dalla configurazione.
     * Deve essere chiamato prima di usare short() o long().
     *
     * @param array<string, mixed> $formatConfig Chiave 'format' da game_config.json
     */
    public static function init(array $formatConfig): void
    {
        self::$scale = $formatConfig['scale'] ?? ['k', 'Mln', 'Mld', 'Bln', 'Bld', 'Tln', 'Tld', 'Qln', 'Qld', 'Qnl', 'Qnd', 'Sln', 'Sld'];
        self::$decimalSeparator = $formatConfig['decimal_separator'] ?? ',';
        self::$thousandsSeparator = $formatConfig['thousands_separator'] ?? '.';
        self::$decimals = (int)($formatConfig['decimals'] ?? 2);

        // Costruisce la mappa suffissi-esponenti dalla scala del config
        self::$suffixMap = [];
        foreach (self::$scale as $index => $suffix) {
            $exp = ($index + 1) * 3; // k=10^3, Mln=10^6, Mld=10^9, ecc.
            self::$suffixMap[strtolower($suffix)] = $exp;
        }
    }

    /**
     * Formato breve: 12,45 Mld
     * Restituisce "0" per NaN o valori non validi.
     */
    public static function short(float $x): string
    {
        if (!is_finite($x) || $x === 0.0) {
            return '0';
        }

        $negative = $x < 0;
        $x = abs($x);

        if ($x < 1000) {
            $formatted = self::formatNumber($x, 0);
            return $negative ? '-' . $formatted : $formatted;
        }

        // Trova l'indice della scala appropriata
        // k = 10^3, Mln = 10^6, Mld = 10^9, Bln = 10^12, Bld = 10^15, ecc.
        // Ogni step è 10^3
        $exp = (int)floor(log10($x) / 3);
        $maxIndex = count(self::$scale) - 1;

        if ($exp > $maxIndex) {
            // Oltre la scala definita: notazione scientifica
            return $negative ? '-' . self::toScientific($x) : self::toScientific($x);
        }

        $divisor = pow(10, $exp * 3);
        $value = $x / $divisor;

        // Arrotonda per controllare se dobbiamo salire di scala (E11)
        // Se l'arrotondamento porta a >= 1000, passa alla scala successiva
        $roundedValue = round($value, self::$decimals);
        if ($roundedValue >= 1000 && $exp < $maxIndex) {
            $exp++;
            $divisor = pow(10, $exp * 3);
            $value = $x / $divisor;
        }

        $suffix = self::$scale[$exp - 1]; // k è indice 0 = 10^3

        $formatted = self::formatNumber($value, self::$decimals);
        $result = $formatted . ' ' . $suffix;

        return $negative ? '-' . $result : $result;
    }

    /**
     * Formato lungo: "12 miliardi 450 milioni" (da implementare se necessario)
     * Per ora delega a short().
     */
    public static function long(float $x): string
    {
        // Implementazione semplificata: usa short per ora
        // Una vera implementazione lunga scriverebbe i nomi per esteso
        return self::short($x);
    }

    /**
     * Normalizza una stringa numerica italiana in formato standard (decimal point = .)
     * Gestisce separatori migliaia (.) e decimali (,) correttamente.
     * Non modifica la notazione scientifica.
     * Regola deterministica per ambiguità (E15):
     * - Se entrambi i separatori presenti: l'ultimo è il decimale (convenzione italiana)
     * - Se solo virgola presente: è decimale
     * - Se solo punto presente:
     *   - Se appare esattamente una volta e non in posizione migliaia (non ogni 3 cifre da destra): decimale
     *   - Altrimenti: separatore migliaia
     */
    private static function normalizeNumber(string $s): string
    {
        $s = trim($s);

        // Se è notazione scientifica (con . o , come separatore decimale), non modificare
        // Accetta sia 1.5e15 che 1,5e15 (E13)
        if (preg_match('/^[0-9]+([.,][0-9]+)?e[+-]?[0-9]+$/i', $s)) {
            // Sostituisce eventuale virgola decimale con punto per il parsing standard
            return str_replace(',', '.', $s);
        }

        $hasDecimalSep = str_contains($s, self::$decimalSeparator);
        $hasThousandsSep = str_contains($s, self::$thousandsSeparator);

        // Se contiene il separatore decimale italiano
        if ($hasDecimalSep) {
            // Se ha anche separatori migliaia, l'ultimo separatore è il decimale (E15)
            if ($hasThousandsSep) {
                $lastDecimalPos = strrpos($s, self::$decimalSeparator);
                $lastThousandsPos = strrpos($s, self::$thousandsSeparator);

                if ($lastDecimalPos > $lastThousandsPos) {
                    // L'ultimo separatore è il decimale - convenzione standard
                    $parts = explode(self::$decimalSeparator, $s, 2);
                    $integerPart = $parts[0];
                    $decimalPart = $parts[1] ?? '';

                    // Rimuove separatori migliaia solo dalla parte intera
                    $integerPart = str_replace(self::$thousandsSeparator, '', $integerPart);

                    return $integerPart . ($decimalPart !== '' ? '.' . $decimalPart : '');
                } else {
                    // L'ultimo separatore è il migliaia - trattiamo la virgola come migliaia (raro)
                    // Ma per coerenza, se la virgola è l'unico separatore decimale-like, la usiamo come decimale
                    $parts = explode(self::$decimalSeparator, $s, 2);
                    $integerPart = $parts[0];
                    $decimalPart = $parts[1] ?? '';

                    $integerPart = str_replace(self::$thousandsSeparator, '', $integerPart);

                    return $integerPart . ($decimalPart !== '' ? '.' . $decimalPart : '');
                }
            }

            // Solo separatore decimale (virgola) presente
            $parts = explode(self::$decimalSeparator, $s, 2);
            $integerPart = $parts[0];
            $decimalPart = $parts[1] ?? '';

            // Rimuove separatori migliaia solo dalla parte intera (anche se non dovrebbero essercene)
            $integerPart = str_replace(self::$thousandsSeparator, '', $integerPart);

            return $integerPart . ($decimalPart !== '' ? '.' . $decimalPart : '');
        }

        // Nessun separatore decimale italiano: potrebbe avere solo separatori migliaia (punti)
        // OPPURE un punto come separatore decimale (es. "1.5" in notazione internazionale) - E15
        if ($hasThousandsSep) {
            // Conta i punti
            $dotCount = substr_count($s, self::$thousandsSeparator);

            if ($dotCount === 1) {
                // Un solo punto: controlla se è in posizione migliaia (ogni 3 cifre da destra)
                $dotPos = strpos($s, self::$thousandsSeparator);
                $afterDot = strlen($s) - $dotPos - 1;

                // Se dopo il punto ci sono 1-3 cifre ma non un multiplo di 3, o se la parte prima ha <= 3 cifre
                // è probabile che sia un separatore decimale
                $beforeDot = $dotPos;

                // Esempi: "1.5" (1 cifra prima, 1 dopo) -> decimale
                // "12.345" (2 prima, 3 dopo) -> migliaia
                // "1.234" (1 prima, 3 dopo) -> potrebbe essere decimale o migliaia
                // Regola: se la parte prima ha <= 3 cifre E la parte dopo ha <= 3 cifre, trattiamo come decimale
                // se la parte dopo non è esattamente 3 cifre
                if ($beforeDot <= 3 && ($afterDot < 1 || $afterDot > 3 || $afterDot % 3 !== 0)) {
                    // Probabile separatore decimale
                    $parts = explode(self::$thousandsSeparator, $s, 2);
                    $integerPart = $parts[0];
                    $decimalPart = $parts[1] ?? '';
                    return $integerPart . ($decimalPart !== '' ? '.' . $decimalPart : '');
                }
            } else {
                // Più punti: controlla se sono in posizione migliaia regolare (ogni 3 cifre da destra)
                $parts = explode(self::$thousandsSeparator, $s);
                $isRegularThousands = true;
                // L'ultima parte (destra) può avere 1-3 cifre
                // Le altre devono avere esattamente 3 cifre
                for ($i = 0; $i < count($parts) - 1; $i++) {
                    if (strlen($parts[$i]) !== 3) {
                        $isRegularThousands = false;
                        break;
                    }
                }
                // L'ultima parte deve avere 1-3 cifre
                if (strlen($parts[count($parts) - 1]) < 1 || strlen($parts[count($parts) - 1]) > 3) {
                    $isRegularThousands = false;
                }

                if ($isRegularThousands) {
                    // È separatori migliaia regolari - rimuovi tutti
                    return str_replace(self::$thousandsSeparator, '', $s);
                } else {
                    // Non regolare - potrebbe essere input malformato, trattiamo l'ultimo come decimale
                    $lastDotPos = strrpos($s, self::$thousandsSeparator);
                    $integerPart = substr($s, 0, $lastDotPos);
                    $decimalPart = substr($s, $lastDotPos + 1);
                    $integerPart = str_replace(self::$thousandsSeparator, '', $integerPart);
                    return $integerPart . ($decimalPart !== '' ? '.' . $decimalPart : '');
                }
            }
        }

        // Nessun separatore presente
        return $s;
    }

    /**
     * Parsa una stringa come "2,5Mln", "5k", "1,5e15" in float.
     * Restituisce null se non valida, vuota, negativa o non numerica.
     */
    public static function parse(string $s): ?float
    {
        $s = trim($s);
        if ($s === '') {
            return null;
        }

        // Rifiuta numeri negativi (per validazione input utente) - E14
        if (str_starts_with($s, '-')) {
            return null;
        }

        // Rifiuta prefisso '+' esplicito per coerenza - E14
        if (str_starts_with($s, '+')) {
            return null;
        }

        // Controlla suffissi scala dalla config (case-insensitive)
        if (!empty(self::$suffixMap)) {
            $lower = strtolower($s);
            // Ordina per lunghezza decrescente per matchare suffissi più lunghi prima (es. Mld prima di Mln)
            $suffixes = array_keys(self::$suffixMap);
            usort($suffixes, fn($a, $b) => strlen($b) - strlen($a));

            foreach ($suffixes as $suffix) {
                $exp = self::$suffixMap[$suffix];
                if (str_ends_with($lower, $suffix)) {
                    // Estrae la parte numerica usando la lunghezza del suffisso minuscolo
                    $numPart = substr($s, 0, -strlen($suffix));
                    // Normalizza correttamente
                    $normalizedNumPart = self::normalizeNumber($numPart);
                    $num = self::parseNumber($normalizedNumPart);
                    if ($num !== null) {
                        return $num * pow(10, $exp);
                    }
                    return null;
                }
            }
        }

        // Prova notazione scientifica (es. 1.5e15 o 1,5e15) - E13
        $normalized = self::normalizeNumber($s);
        if (preg_match('/^[0-9]+(\.[0-9]+)?e[+-]?[0-9]+$/i', $normalized)) {
            $val = (float)$normalized;
            return is_finite($val) ? $val : null;
        }

        // Prova numero semplice - gestisce ambiguità separatori (E15)
        $normalized = self::normalizeNumber($s);
        $num = self::parseNumber($normalized);
        return $num;
    }

    /**
     * Formatta un numero con separatori.
     */
    private static function formatNumber(float $x, int $decimals): string
    {
        $rounded = round($x, $decimals);
        $parts = explode('.', number_format($rounded, $decimals, '.', ''));
        $integerPart = $parts[0];

        // Aggiungi separatori migliaia
        $formattedInteger = '';
        $len = strlen($integerPart);
        for ($i = 0; $i < $len; $i++) {
            if ($i > 0 && ($len - $i) % 3 === 0) {
                $formattedInteger .= self::$thousandsSeparator;
            }
            $formattedInteger .= $integerPart[$i];
        }

        if ($decimals > 0 && isset($parts[1])) {
            return $formattedInteger . self::$decimalSeparator . $parts[1];
        }

        return $formattedInteger;
    }

    /**
     * Parsa una parte numerica (già normalizzata con . come separatore decimale).
     */
    private static function parseNumber(string $s): ?float
    {
        $s = trim($s);
        if ($s === '') {
            return null;
        }

        // Deve essere un numero valido
        if (!preg_match('/^[0-9]+(\.[0-9]+)?$/', $s)) {
            return null;
        }

        $val = (float)$s;
        return is_finite($val) ? $val : null;
    }

    /**
     * Converte in notazione scientifica (es. 1,23e66).
     */
    private static function toScientific(float $x): string
    {
        // Formatta con 2 decimali in notazione scientifica
        $sci = sprintf('%.2e', $x);
        // Sostituisce . con , per il separatore decimale italiano
        return str_replace('.', self::$decimalSeparator, $sci);
    }

    /**
     * Restituisce la configurazione per passarla al JS via API.
     */
    public static function getJsConfig(): array
    {
        return [
            'scale' => self::$scale,
            'decimal_separator' => self::$decimalSeparator,
            'thousands_separator' => self::$thousandsSeparator,
            'decimals' => self::$decimals,
        ];
    }
}