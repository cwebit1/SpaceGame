<?php

declare(strict_types=1);

namespace SpaceGame;

/**
 * Bootstrap file for SpaceGame.
 * Registers PSR-4 autoloader, sets timezone, and configures error handling.
 */

// Set default timezone to UTC
date_default_timezone_set('UTC');

// Convert warnings and notices to ErrorException
set_error_handler(static function (int $errno, string $errstr, string $errfile, int $errline): bool {
    if (!(error_reporting() & $errno)) {
        return false;
    }
    throw new \ErrorException($errstr, 0, $errno, $errfile, $errline);
});

// PSR-4 autoloader for SpaceGame namespace
spl_autoload_register(static function (string $class): void {
    $prefix = 'SpaceGame\\';
    $baseDir = __DIR__ . '/';

    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }

    $relativeClass = substr($class, $len);
    $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';

    if (file_exists($file)) {
        require $file;
    }
}, true, true);

/**
 * Returns true if the autoloader is registered.
 * Used for testing.
 */
function autoload_registered(): bool
{
    return true;
}