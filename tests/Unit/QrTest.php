<?php

namespace Tests\Unit;

use App\Domain\Invoices\Qr;
use PHPUnit\Framework\TestCase;

class QrTest extends TestCase
{
    public function test_printed_qr_keeps_a_four_module_quiet_zone_on_every_side(): void
    {
        $svg = Qr::svg('https://zarlio.ir/v/'.str_repeat('A', 43));
        $this->assertMatchesRegularExpression('/viewBox="0 0 (\d+) \1"/', $svg);
        preg_match('/viewBox="0 0 (\d+) /', $svg, $m);
        $size = (int) $m[1];
        preg_match_all('/M(\d+) (\d+)/', $svg, $points, PREG_SET_ORDER);
        $this->assertNotEmpty($points);
        $xs = array_map(fn ($p) => (int) $p[1], $points);
        $ys = array_map(fn ($p) => (int) $p[2], $points);
        $this->assertGreaterThanOrEqual(4, min($xs));
        $this->assertGreaterThanOrEqual(4, min($ys));
        $this->assertLessThanOrEqual($size - 4, max($xs) + 1);
        $this->assertLessThanOrEqual($size - 4, max($ys) + 1);
        $this->assertStringContainsString('aria-label="QR بررسی اصالت فاکتور"', $svg);
    }
}
