<?php

declare(strict_types=1);

namespace Taiwanleaftea\TltVerifactu\Helpers;

use chillerlan\QRCode\QRCode as QRCodeRender;
use chillerlan\QRCode\QROptions;
use Illuminate\Support\Carbon;
use Taiwanleaftea\TltVerifactu\Constants\AEAT;

class QRCode
{
    /**
     * Render QR code as SVG string
     *
     * @param string $issuerNIF
     * @param Carbon $invoiceDate
     * @param string $number
     * @param float $totalAmount
     * @param bool $isProduction
     * @return mixed
     */
    public static function SVG(
        string $issuerNIF,
        Carbon $invoiceDate,
        string $number,
        float $totalAmount,
        bool $isProduction = false
    ): mixed
    {
        $url = self::buildUrl($issuerNIF, $invoiceDate, $number, $totalAmount, $isProduction);
        $render = self::buildQRRender();
        return $render->render($url);
    }

    /**
     * @param string $issuerNIF
     * @param Carbon $invoiceDate
     * @param string $number
     * @param float $totalAmount
     * @param bool $isProduction
     * @return string
     */
    public static function buildUrl(
        string $issuerNIF,
        Carbon $invoiceDate,
        string $number,
        float $totalAmount,
        bool $isProduction = false
    ): string
    {
        $url = $isProduction ? AEAT::QR_VERIFICATION_PRODUCTION : AEAT::QR_VERIFICATION_SANDBOX;
        $query = http_build_query([
            'nif' => $issuerNIF,
            'numserie' => $number,
            'fecha' => $invoiceDate->format('d-m-Y'),
            'importe' => number_format($totalAmount, 2, '.', ''),
        ]);

        return $url . $query;
    }

    /**
     * @return QRCodeRender
     */
    private static function buildQRRender(): QRCodeRender
    {
        $options = new QROptions();
        $options->version      = 7;
        $options->outputBase64 = false; // output raw image instead of base64 data URI

        return new QRCodeRender($options);
    }
}
