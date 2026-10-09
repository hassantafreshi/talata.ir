<?php

namespace App\Domain\Invoices;

use App\Support\PersianShaper;
use Illuminate\Support\Facades\Storage;

/**
 * Link-preview image (og:image, 1200×630) for a shared invoice link (owner request 2026-10-07).
 * Shops with branding (capability invoice.shop_logo — Basic/Professional) get their own card: logo on the left,
 * shop name and phone under it. Other shops share the static Zarlio service card (public/og/zarlio-invoice.png).
 * Only public business details are drawn — never buyer data or amounts. Cards are cached per shop content.
 */
final class SharePreview
{
    public const W = 1200;

    public const H = 630;

    public const STATIC_CARD = '/og/zarlio-invoice.png';

    /** Public URL of the preview image for this shop snapshot (rendered once, then served from storage). */
    public function urlFor(string $tenantPublicId, array $shop, bool $branded): string
    {
        if (! $branded || ! function_exists('imagettftext')) {
            return rtrim((string) config('talata.public_url'), '/').self::STATIC_CARD;
        }
        $hash = substr(hash('sha256', json_encode([$shop['name'] ?? '', $shop['contact_primary'] ?? '', $shop['logo']['version'] ?? null, 'v1'])), 0, 20);
        $path = "og/{$tenantPublicId}/{$hash}.png";
        if (! Storage::disk('local')->exists($path)) {
            Storage::disk('local')->put($path, $this->render($shop, $tenantPublicId));
        }

        return route('public.og', [$tenantPublicId, $hash]);
    }

    public function render(array $shop, string $tenantPublicId): string
    {
        $im = imagecreatetruecolor(self::W, self::H);
        imagesavealpha($im, true);
        $bg = imagecolorallocate($im, 0x12, 0x14, 0x17);       // midnight (palette 1)
        $gold = imagecolorallocate($im, 0xE0, 0xB4, 0x4C);
        $card = imagecolorallocate($im, 0xFB, 0xF8, 0xF1);
        $ink = imagecolorallocate($im, 0x14, 0x16, 0x1A);
        $muted = imagecolorallocate($im, 0x5A, 0x5E, 0x66);
        $light = imagecolorallocate($im, 0xE8, 0xE3, 0xD6);
        imagefilledrectangle($im, 0, 0, self::W, self::H, $bg);
        $bold = resource_path('fonts/Vazirmatn-FD-Bold.ttf');
        $regular = resource_path('fonts/Vazirmatn-FD-Regular.ttf');

        // Physical left: the shop card — logo, then name and phone under it.
        $cx = 60;
        $cy = 60;
        $cw = 560;
        $ch = self::H - 120;
        $this->roundedRect($im, $cx, $cy, $cx + $cw, $cy + $ch, 28, $card);
        $logoBottom = $cy + 50;
        $logo = $this->logo($shop, $tenantPublicId);
        $boxW = 300;
        $boxH = 200;
        if ($logo) {
            [$lw, $lh] = [imagesx($logo), imagesy($logo)];
            $scale = min($boxW / $lw, $boxH / $lh, 1.0);
            $dw = (int) round($lw * $scale);
            $dh = (int) round($lh * $scale);
            imagecopyresampled($im, $logo, $cx + (int) (($cw - $dw) / 2), $cy + 50 + (int) (($boxH - $dh) / 2), 0, 0, $dw, $dh, $lw, $lh);
            imagedestroy($logo);
            $logoBottom = $cy + 50 + $boxH;
        } else {
            // No logo uploaded: a monogram with the shop's first letter.
            $r = 90;
            imagefilledellipse($im, $cx + (int) ($cw / 2), $cy + 50 + $r, $r * 2, $r * 2, $gold);
            $initial = mb_substr(trim((string) ($shop['name'] ?? 'ز')), 0, 1);
            $this->centered($im, PersianShaper::shape($initial), $bold, 84, $ink, $cx + (int) ($cw / 2), $cy + 50 + $r + 30);
            $logoBottom = $cy + 50 + $r * 2;
        }
        // Shop name: one line, or two balanced lines for long names, never smaller than readable.
        $lines = $this->wrap((string) ($shop['name'] ?? ''), $bold, 46, $cw - 60);
        $y = $logoBottom + 80;
        foreach ($lines[0] as $line) {
            $this->centered($im, $line, $bold, $lines[1], $ink, $cx + (int) ($cw / 2), $y);
            $y += (int) ($lines[1] * 1.45);
        }
        if (! empty($shop['contact_primary'])) {
            $this->centered($im, PersianShaper::shape('تلفن: '.$shop['contact_primary']), $regular, 30, $muted, $cx + (int) ($cw / 2), $y + 10);
        }

        // Physical right: what this link is.
        $rx = self::W - 70;
        $this->right($im, PersianShaper::shape('فاکتور فروش'), $bold, 64, $gold, $rx, 250);
        foreach (['برای دیدن جزئیات', 'و بررسی اصالت فاکتور،', 'لینک را باز کنید.'] as $k => $line) {
            $this->right($im, PersianShaper::shape($line), $regular, 32, $light, $rx, 330 + $k * 50);
        }
        $this->right($im, PersianShaper::shape('صادرشده با زرلیو · zarlio.ir'), $regular, 24, $muted, $rx, self::H - 80);

        ob_start();
        imagepng($im, null, 7);
        imagedestroy($im);

        return (string) ob_get_clean();
    }

    private function logo(array $shop, string $tenant): ?\GdImage
    {
        $v = $shop['logo']['version'] ?? null;
        $path = $v ? "logos/{$tenant}/v{$v}.png" : null;
        if (! $path || ! Storage::disk('local')->exists($path)) {
            return null;
        }
        $img = @imagecreatefromstring((string) Storage::disk('local')->get($path));

        return $img ?: null;
    }

    /**
     * Logical text → shaped lines at a size that fits: one line if possible, else two lines split at the most
     * balanced space, shrinking only as far as needed. @return array{0:list<string>,1:int}
     */
    private function wrap(string $text, string $font, int $size, int $maxW): array
    {
        $one = PersianShaper::shape($text);
        if ($this->width($one, $font, $size) <= $maxW) {
            return [[$one], $size];
        }
        $words = preg_split('/\s+/u', trim($text)) ?: [$text];
        $best = null;
        for ($i = 1; $i < count($words); $i++) {
            $a = PersianShaper::shape(implode(' ', array_slice($words, 0, $i)));
            $b = PersianShaper::shape(implode(' ', array_slice($words, $i)));
            $w = max($this->width($a, $font, $size), $this->width($b, $font, $size));
            if ($best === null || $w < $best[2]) {
                $best = [$a, $b, $w];
            }
        }
        $lines = $best ? [$best[0], $best[1]] : [$one];
        while ($size > 26 && max(array_map(fn ($l) => $this->width($l, $font, $size), $lines)) > $maxW) {
            $size -= 2;
        }

        return [$lines, $size];
    }

    private function width(string $text, string $font, int $size): int
    {
        $b = imagettfbbox($size, 0, $font, $text);

        return (int) abs($b[2] - $b[0]);
    }

    private function centered(\GdImage $im, string $text, string $font, int $size, int $color, int $x, int $y): void
    {
        imagettftext($im, $size, 0, $x - (int) ($this->width($text, $font, $size) / 2), $y, $color, $font, $text);
    }

    private function right(\GdImage $im, string $text, string $font, int $size, int $color, int $x, int $y): void
    {
        imagettftext($im, $size, 0, $x - $this->width($text, $font, $size), $y, $color, $font, $text);
    }

    private function roundedRect(\GdImage $im, int $x1, int $y1, int $x2, int $y2, int $r, int $color): void
    {
        imagefilledrectangle($im, $x1 + $r, $y1, $x2 - $r, $y2, $color);
        imagefilledrectangle($im, $x1, $y1 + $r, $x2, $y2 - $r, $color);
        foreach ([[$x1 + $r, $y1 + $r], [$x2 - $r, $y1 + $r], [$x1 + $r, $y2 - $r], [$x2 - $r, $y2 - $r]] as [$cx, $cy]) {
            imagefilledellipse($im, $cx, $cy, $r * 2, $r * 2, $color);
        }
    }
}
