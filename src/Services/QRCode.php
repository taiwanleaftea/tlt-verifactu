<?php

declare(strict_types=1);

namespace Taiwanleaftea\TltVerifactu\Services;

use chillerlan\QRCode\Output\QRGdImagePNG;
use chillerlan\QRCode\Output\QRImagick;
use chillerlan\QRCode\Output\QRMarkupSVG;
use chillerlan\QRCode\QRCode as QRCodeRender;
use chillerlan\QRCode\QROptions;
use Illuminate\Support\Carbon;
use Taiwanleaftea\TltVerifactu\Constants\AEAT;
use Taiwanleaftea\TltVerifactu\Exceptions\QRGeneratorException;

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
     *
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
        $render = self::buildQRRender(QRMarkupSVG::class);
        return $render->render($url);
    }

    /**
     * Render QR code as PNG base64 image
     *
     * @param string $issuerNIF
     * @param Carbon $invoiceDate
     * @param string $number
     * @param float $totalAmount
     * @param bool $isProduction
     *
     * @return mixed
     *
     * @throws QRGeneratorException
     */
    public static function PNG(
        string $issuerNIF,
        Carbon $invoiceDate,
        string $number,
        float $totalAmount,
        bool $isProduction = false
    ): mixed
    {
        if (extension_loaded('gd') && function_exists('gd_info')) {
            $outputInterface = QRGdImagePNG::class;
        } elseif (extension_loaded('imagick')) {
            $outputInterface = QRImagick::class;
        } else {
            throw new QRGeneratorException('Image library not loaded.');
        }

        $url = self::buildUrl($issuerNIF, $invoiceDate, $number, $totalAmount, $isProduction);
        $render = self::buildQRRender($outputInterface, true);
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
     * @param string $outputInterface
     * @param bool $outputBase64
     *
     * @return QRCodeRender
     */
    private static function buildQRRender(string $outputInterface, bool $outputBase64 = false): QRCodeRender
    {
        $options = new QROptions();
        $options->version = 7;
        $options->outputInteface = $outputInterface;

        if ($outputInterface == QRImagick::class) {
            $options->imagickFormat = 'png';
        }

        $options->outputBase64 = $outputBase64; // output raw image or base64 data URI

        return new QRCodeRender($options);
    }
}
