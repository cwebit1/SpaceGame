<?php

declare(strict_types=1);

namespace SpaceGame;

/**
 * Eccezione per errori di configurazione.
 */
class ConfigError extends \RuntimeException
{
}

/**
 * Config - Carica e valida game_config.json.
 * Accesso tramite notazione dot-path (es. 'ships.light_fighter.attack').
 */
final class Config
{
    private array $data;
    private string $sourcePath;

    private function __construct(array $data, string $sourcePath)
    {
        $this->data = $data;
        $this->sourcePath = $sourcePath;
    }

    /**
     * Carica la configurazione da file JSON e valida lo schema.
     */
    public static function fromFile(string $path): self
    {
        $content = file_get_contents($path);
        if ($content === false) {
            throw new ConfigError("Impossibile leggere il file: {$path}");
        }

        $data = json_decode($content, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $error = json_last_error_msg();
            $pos = function_exists('json_last_error_pos') ? json_last_error_pos() : -1;
            throw new ConfigError("JSON non valido in {$path} (posizione {$pos}): {$error}");
        }

        if (!is_array($data)) {
            throw new ConfigError("Il file {$path} deve contenere un oggetto JSON");
        }

        // Valida lo schema (E27)
        ConfigSchema::validate($data);

        return new self($data, $path);
    }

    /**
     * Crea una configurazione da array (per test).
     * Valida lo schema (E27).
     */
    public static function fromArray(array $data): self
    {
        ConfigSchema::validate($data);
        return new self($data, 'array');
    }

    /**
     * Crea una configurazione da stringa JSON.
     * Valida lo schema (E27).
     */
    public static function fromJson(string $json): self
    {
        $data = json_decode($json, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new ConfigError("JSON non valido: " . json_last_error_msg());
        }
        if (!is_array($data)) {
            throw new ConfigError("Il JSON deve contenere un oggetto");
        }
        ConfigSchema::validate($data);
        return new self($data, 'json');
    }

    /**
     * Recupera un valore usando notazione dot-path.
     * Lancia ConfigError con il percorso se la chiave non esiste.
     */
    public function get(string $dotPath): mixed
    {
        $keys = explode('.', $dotPath);
        $current = $this->data;

        foreach ($keys as $key) {
            if (!is_array($current) || !array_key_exists($key, $current)) {
                throw new ConfigError("Chiave mancante: {$dotPath} (file: {$this->sourcePath})");
            }
            $current = $current[$key];
        }

        return $current;
    }

    /**
     * Restituisce la configurazione come stringa JSON.
     */
    public function toJson(): string
    {
        return json_encode($this->data, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
    }

    /**
     * Restituisce l'array interno (per validazione).
     */
    public function toArray(): array
    {
        return $this->data;
    }
}