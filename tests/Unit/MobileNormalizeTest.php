<?php

namespace Tests\Unit;

use App\Support\Mobile;
use PHPUnit\Framework\TestCase;

class MobileNormalizeTest extends TestCase
{
    public function test_accepted_forms_all_normalize_to_09(): void
    {
        foreach ([
            '09123456789', '9123456789', '+989123456789', '00989123456789', '989123456789',
            '۰۹۱۲۳۴۵۶۷۸۹', '٠٩١٢٣٤٥٦٧٨٩', '۰۹۱۲ ۳۴۵ ۶۷۸۹', '0912-345-6789', '(0912) 345.6789', '+98 912 345 6789',
        ] as $raw) {
            $this->assertSame('09123456789', Mobile::normalize($raw), $raw);
        }
    }

    public function test_non_iranian_mobiles_and_garbage_are_rejected(): void
    {
        foreach (['', null, '0912345678', '091234567890', '02112345678', '09803456789', '+447700900123', '0912345678a', '۰۹۱۲۳۴۵۶۷۸'] as $raw) {
            $this->assertNull(Mobile::normalize($raw), (string) $raw);
        }
    }

    public function test_mask_and_display_never_reveal_the_middle(): void
    {
        $this->assertSame('0912•••6789', Mobile::mask('09123456789'));
        $this->assertSame('', Mobile::mask('123'));
        $this->assertSame('۰۹۱۲ ۳۴۵ ۶۷۸۹', Mobile::display('09123456789'));
    }
}
