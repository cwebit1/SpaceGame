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
     */
    private static function normalizeNumber(string $s): string
    {
        $s = trim($s);

        // Se è notazione scientifica, non modificare
        if (preg_match('/^[0-9]+(\.[0-9]+)?e[+-]?[0-9]+$/i', $s)) {
            return $s;
        }

        // Se contiene il separatore decimale italiano
        if (str_contains($s, self::$decimalSeparator)) {
            // Divide in parte intera e decimale
            $parts = explode(self::$decimalSeparator, $s, 2);
            $integerPart = $parts[0];
            $decimalPart = $parts[1] ?? '';

            // Rimuove separatori migliaia solo dalla parte intera
            $integerPart = str_replace(self::$thousandsSeparator, '', $integerPart);

            // Ricompone con punto decimale standard
            return $integerPart . ($decimalPart !== '' ? '.' . $decimalPart : '');
        }

        // Nessun separatore decimale italiano: potrebbe avere solo separatori migliaia
        // Rimuove tutti i separatori migliaia
        return str_replace(self::$thousandsSeparator, '', $s);
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

        // Controlla se è negativo
        if (str_starts_with($s, '-')) {
            return null;
        }

        // Controlla suffissi scala (case-insensitive)
        $suffixes = [
            'k' => 3,
            'mln' => 6,
            'mld' => 9,
            'bln' => 12,
            'bld' => 15,
            'tln' => 18,
            'tld' => 21,
            'qln' => 24,
            'qld' => 27,
            'qnl' => 30,
            'qnd' => 33,
            'sln' => 36,
            'sld' => 39,
        ];

        $lower = strtolower($s);
        foreach ($suffixes as $suffix => $exp) {
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

        // Prova notazione scientifica (es. 1.5e15)
        $normalized = self::normalizeNumber($s);
        if (preg_match('/^[0-9]+(\.[0-9]+)?e[+-]?[0-9]+$/i', $normalized)) {
            $val = (float)$normalized;
            return is_finite($val) ? $val : null;
        }

        // Prova numero semplice
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