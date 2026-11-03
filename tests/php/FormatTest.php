<?php

declare(strict_types=1);

namespace SpaceGame\Tests;

use PHPUnit\Framework\TestCase;
use SpaceGame\Format;

/**
 * Test per la classe Format.
 */
final class FormatTest extends TestCase
{
    protected function setUp(): void
    {
        // Inizializza con la configurazione standard dal game_config.json
        Format::init([
            'scale' => ['k', 'Mln', 'Mld', 'Bln', 'Bld', 'Tln', 'Tld', 'Qln', 'Qld', 'Qnl', 'Qnd', 'Sln', 'Sld'],
            'decimal_separator' => ',',
            'thousands_separator' => '.',
            'decimals' => 2,
        ]);
    }

    public function test_short_simple(): void
    {
        $this->assertSame('999', Format::short(999));
        // 1000 = 1k, con 2 decimali: "1,00 k"
        $this->assertSame('1,00 k', Format::short(1000));
        $this->assertSame('1,23 k', Format::short(1230));
    }

    public function test_short_millions(): void
    {
        // 12.450.000.000 = 12,45 Mld (miliardi)
        $this->assertSame('12,45 Mld', Format::short(12450000000));
    }

    public function test_short_billions(): void
    {
        // 1,5e15 = 1,50 Bld (biliardi)
        $this->assertSame('1,50 Bld', Format::short(1.5e15));
    }

    public function test_short_beyond_scale(): void
    {
        // 1.23e66 oltre la scala definita -> notazione scientifica
        $result = Format::short(1.23e66);
        $this->assertStringContainsString('e', $result);
        $this->assertStringContainsString('1,23', $result);
    }

    public function test_short_zero_and_nan(): void
    {
        $this->assertSame('0', Format::short(0));
        $this->assertSame('0', Format::short(NAN));
        $this->assertSame('0', Format::short(INF));
        $this->assertSame('0', Format::short(-INF));
    }

    public function test_short_negative(): void
    {
        $this->assertSame('-12,45 Mld', Format::short(-12450000000));
    }

    public function test_parse_simple(): void
    {
        $this->assertSame(5000.0, Format::parse('5k'));
        $this->assertSame(2500000.0, Format::parse('2,5Mln'));
        $this->assertSame(1500000000000000.0, Format::parse('1.5e15'));
    }

    public function test_parse_invalid(): void
    {
        $this->assertNull(Format::parse(''));
        $this->assertNull(Format::parse('-3'));
        $this->assertNull(Format::parse('abc'));
        $this->assertNull(Format::parse('not a number'));
    }

    public function test_parse_with_spaces(): void
    {
        $this->assertSame(5000.0, Format::parse(' 5k '));
        $this->assertSame(2500000.0, Format::parse(' 2,5Mln '));
    }

    public function test_long_delegates_to_short(): void
    {
        $this->assertSame('12,45 Mld', Format::long(12450000000));
    }
}