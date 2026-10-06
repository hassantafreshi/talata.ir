<?php

namespace App\Domain\Invoices;

use chillerlan\QRCode\Common\EccLevel;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;

/** Server-rendered QR as inline SVG (no external service, CSP-safe). */
final class Qr
{
    public static function svg(string $url): string
    {
        $options = new QROptions([
            'outputBase64' => false, 'svgAddXmlHeader' => false, 'eccLevel' => EccLevel::M,
            'addQuietzone' => true, 'quietzoneSize' => 2, 'drawLightModules' => false,
            'svgUseFillAttributes' => true,
        ]);
        $svg = (new QRCode($options))->render($url);

        return str_replace('<svg ', '<svg role="img" aria-label="QR بررسی اصالت فاکتور" ', $svg);
    }
}
