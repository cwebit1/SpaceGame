<?php

declare(strict_types=1);

namespace SpaceGame;

/**
 * Requirements - Verifica prerequisiti per edifici e ricerche.
 */
final class Requirements
{
    /**
     * Verifica se i prerequisiti sono soddisfatti.
     *
     * @param array<string, int> $requires Array associativo 'tipo.codice' => livello_richiesto
     * @param array $bodyBuildings Edifici del corpo [codice => ['level' => int]]
     * @param array $research Ricerche dell'impero [codice => livello]
     * @param array|null $moonOfBody Dati del pianeta madre (per edifici su luna che richiedono edifici sul pianeta)
     * @return bool True se tutti i prerequisiti sono soddisfatti
     */
    public static function met(
        array $requires,
        array $bodyBuildings,
        array $research,
        ?array $moonOfBody
    ): bool {
        foreach ($requires as $key => $requiredLevel) {
            if ($requiredLevel <= 0) {
                continue;
            }

            if (str_starts_with($key, 'building.')) {
                $buildingCode = substr($key, 9); // rimuove 'building.'
                $currentLevel = $bodyBuildings[$buildingCode]['level'] ?? 0;

                // Se siamo su una luna e l'edificio non c'è, controlla sul pianeta madre
                if ($currentLevel < $requiredLevel && $moonOfBody !== null) {
                    $currentLevel = $moonOfBody['buildings'][$buildingCode]['level'] ?? 0;
                }

                if ($currentLevel < $requiredLevel) {
                    return false;
                }
            } elseif (str_starts_with($key, 'research.')) {
                $researchCode = substr($key, 9); // rimuove 'research.'
                $currentLevel = $research[$researchCode] ?? 0;

                if ($currentLevel < $requiredLevel) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Restituisce l'elenco dei prerequisiti mancanti.
     *
     * @param array<string, int> $requires
     * @param array $bodyBuildings
     * @param array $research
     * @param array|null $moonOfBody
     * @return array<string, int> Array associativo 'tipo.codice' => livello_richiesto (solo mancanti)
     */
    public static function missing(
        array $requires,
        array $bodyBuildings,
        array $research,
        ?array $moonOfBody
    ): array {
        $missing = [];

        foreach ($requires as $key => $requiredLevel) {
            if ($requiredLevel <= 0) {
                continue;
            }

            if (str_starts_with($key, 'building.')) {
                $buildingCode = substr($key, 9);
                $currentLevel = $bodyBuildings[$buildingCode]['level'] ?? 0;

                if ($currentLevel < $requiredLevel && $moonOfBody !== null) {
                    $currentLevel = $moonOfBody['buildings'][$buildingCode]['level'] ?? 0;
                }

                if ($currentLevel < $requiredLevel) {
                    $missing[$key] = $requiredLevel;
                }
            } elseif (str_starts_with($key, 'research.')) {
                $researchCode = substr($key, 9);
                $currentLevel = $research[$researchCode] ?? 0;

                if ($currentLevel < $requiredLevel) {
                    $missing[$key] = $requiredLevel;
                }
            }
        }

        return $missing;
    }
}