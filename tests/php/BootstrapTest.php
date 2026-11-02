<?php

declare(strict_types=1);

namespace SpaceGame\Tests;

use PHPUnit\Framework\TestCase;
use function SpaceGame\autoload_registered;

/**
 * Test per verificare che il bootstrap funzioni correttamente.
 */
final class BootstrapTest extends TestCase
{
    public function test_autoload_registered(): void
    {
        $this->assertTrue(autoload_registered());
    }

    public function test_timezone_is_utc(): void
    {
        $this->assertSame('UTC', date_default_timezone_get());
    }
}