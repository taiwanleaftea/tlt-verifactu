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
use Taiwanleaftea\TltVerifactu\Enums\QRCodeFormat;
use Taiwanleaftea\TltVerifactu\Exceptions\QRGeneratorException;

class QRCode
{
    /**
     * Render QR code as SVG string
     */
    public static function SVG(
        string $issuerNIF,
        Carbon $invoiceDate,
        string $number,
        float $totalAmount,
        bool $isProduction = false,
        bool $isVerifactu = true
    ): mixed {
        $url = self::buildUrl($issuerNIF, $invoiceDate, $number, $totalAmount, $isProduction, $isVerifactu);
        $render = self::buildQRRender(QRMarkupSVG::class);

        return $render->render($url);
    }

    /**
     * Render QR code as PNG base64 image
     *
     * @throws QRGeneratorException
     */
    public static function PNG(
        string $issuerNIF,
        Carbon $invoiceDate,
        string $number,
        float $totalAmount,
        bool $isProduction = false,
        bool $isVerifactu = true
    ): mixed {
        if (extension_loaded('gd') && function_exists('gd_info')) {
            $outputInterface = QRGdImagePNG::class;
        } elseif (extension_loaded('imagick')) {
            $outputInterface = QRImagick::class;
        } else {
            throw new QRGeneratorException('Image library not loaded.');
        }

        $url = self::buildUrl($issuerNIF, $invoiceDate, $number, $totalAmount, $isProduction, $isVerifactu);
        $render = self::buildQRRender($outputInterface, true);

        return $render->render($url);
    }

    public static function buildUrl(
        string $issuerNIF,
        Carbon $invoiceDate,
        string $number,
        float $totalAmount,
        bool $isProduction = false,
        bool $isVerifactu = true
    ): string {
        $baseUrl = match (true) {
            $isProduction && $isVerifactu => AEAT::QR_VERIFICATION_PRODUCTION,
            ! $isProduction && $isVerifactu => AEAT::QR_VERIFICATION_SANDBOX,
            $isProduction && ! $isVerifactu => AEAT::QR_NO_VERIFACTU_PRODUCTION,
            default => AEAT::QR_NO_VERIFACTU_SANDBOX,
        };

        $query = http_build_query([
            'nif' => $issuerNIF,
            'numserie' => $number,
            'fecha' => $invoiceDate->format('d-m-Y'),
            'importe' => number_format($totalAmount, 2, '.', ''),
        ], '', '&', PHP_QUERY_RFC3986);

        return $baseUrl.$query;
    }

    /**
     * Generate QR code from URI
     *
     *
     * @throws QRGeneratorException
     */
    public static function fromURI(
        string $uri,
        QRCodeFormat $format
    ): string {
        if ($format === QRCodeFormat::SVG) {
            $render = self::buildQRRender(QRMarkupSVG::class);
        } elseif ($format === QRCodeFormat::PNG) {
            if (extension_loaded('gd') && function_exists('gd_info')) {
                $outputInterface = QRGdImagePNG::class;
            } elseif (extension_loaded('imagick')) {
                $outputInterface = QRImagick::class;
            } else {
                throw new QRGeneratorException('Image library not loaded.');
            }

            $render = self::buildQRRender($outputInterface, true);
        } else {
            throw new QRGeneratorException('Unsupported renderer format.');
        }

        return $render->render($uri);
    }

    private static function buildQRRender(string $outputInterface, bool $outputBase64 = false): QRCodeRender
    {
        $options = new QROptions;
        $options->version = 7;
        $options->outputInterface = $outputInterface;

        if ($outputInterface == QRImagick::class) {
            $options->imagickFormat = 'png';
        }

        $options->outputBase64 = $outputBase64; // output raw image or base64 data URI

        return new QRCodeRender($options);
    }
}
