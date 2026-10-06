<?php

namespace Tests\Unit;

use App\Support\Digits;
use PHPUnit\Framework\TestCase;

/** Persian digits and mixed-direction text (invoice numbers with a Latin prefix inside RTL lines). */
class DigitsTest extends TestCase
{
    public function test_digits_round_trip_between_persian_arabic_and_latin(): void
    {
        $this->assertSame('۱۴۰۵-۰۰۴۲', Digits::toPersian('1405-0042'));
        $this->assertSame('1405-0042', Digits::toLatin('۱۴۰۵-۰۰۴۲'));
        $this->assertSame('0912', Digits::toLatin('٠٩١٢'));             // Arabic-Indic digits from Arabic keyboards
        $this->assertSame('', Digits::toLatin(null));
    }

    public function test_invoice_numbers_keep_their_order_inside_rtl_text(): void
    {
        // A Latin prefix gets a left-to-right mark after it, so «ZR-1405-12» does not flip around the dashes in RTL.
        $this->assertSame("ZR\u{200E}-۱۴۰۵-۱۲", Digits::invoiceNumber('ZR-1405-12'));
        $this->assertSame("INV\u{200E}/۷", Digits::invoiceNumber('INV/7'));
        // Purely numeric numbers need no mark.
        $this->assertSame('۱۴۰۵-۰۰۰۱', Digits::invoiceNumber('1405-0001'));
        $this->assertSame('', Digits::invoiceNumber(null));
        $this->assertSame(Digits::invoiceNumber('ZR-1405-12'), invno('ZR-1405-12'));
    }
}
