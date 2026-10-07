<?php

namespace Tests\Unit;

use App\Support\Mobile;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Same examples as tests/js/mobiles.test.mjs: the browser preview and the server agree on every input. */
class MobileExtractTest extends TestCase
{
    public static function vectors(): array
    {
        $data = json_decode((string) file_get_contents(__DIR__.'/../fixtures/mobile-extract-vectors.json'), true);

        return array_map(fn ($v) => [$v['in'], $v['mobiles'], $v['invalid']], $data['vectors']);
    }

    #[DataProvider('vectors')]
    public function test_extracts_every_mobile_and_reports_leftovers(string $in, array $mobiles, array $invalid): void
    {
        $this->assertSame([$mobiles, $invalid], Mobile::extractAll($in));
    }
}
