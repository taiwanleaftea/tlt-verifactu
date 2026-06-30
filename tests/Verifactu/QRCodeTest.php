<?php

namespace Taiwanleaftea\TltVerifactu\Test\Verifactu;

use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Taiwanleaftea\TltVerifactu\Constants\AEAT;
use Taiwanleaftea\TltVerifactu\Services\QRCode;

#[CoversClass(QRCode::class)]
class QRCodeTest extends TestCase
{
    public function test_build_url_uses_verifactu_sandbox_endpoint_by_default(): void
    {
        $url = QRCode::buildUrl(
            issuerNIF: 'A12345678',
            invoiceDate: Carbon::createFromFormat('Y-m-d', '2026-01-02'),
            number: 'A/1',
            totalAmount: 121,
        );

        $this->assertSame(
            AEAT::QR_VERIFICATION_SANDBOX.'nif=A12345678&numserie=A%2F1&fecha=02-01-2026&importe=121.00',
            $url
        );
    }

    public function test_build_url_uses_verifactu_production_endpoint(): void
    {
        $url = QRCode::buildUrl(
            issuerNIF: 'A12345678',
            invoiceDate: Carbon::createFromFormat('Y-m-d', '2026-01-02'),
            number: 'A/1',
            totalAmount: 121,
            isProduction: true,
        );

        $this->assertStringStartsWith(AEAT::QR_VERIFICATION_PRODUCTION, $url);
    }

    public function test_build_url_uses_no_verifactu_sandbox_endpoint(): void
    {
        $url = QRCode::buildUrl(
            issuerNIF: 'A12345678',
            invoiceDate: Carbon::createFromFormat('Y-m-d', '2026-01-02'),
            number: 'A/1',
            totalAmount: 121,
            isVerifactu: false,
        );

        $this->assertStringStartsWith(AEAT::QR_NO_VERIFACTU_SANDBOX, $url);
    }

    public function test_build_url_uses_no_verifactu_production_endpoint(): void
    {
        $url = QRCode::buildUrl(
            issuerNIF: 'A12345678',
            invoiceDate: Carbon::createFromFormat('Y-m-d', '2026-01-02'),
            number: 'A/1',
            totalAmount: 121,
            isProduction: true,
            isVerifactu: false,
        );

        $this->assertStringStartsWith(AEAT::QR_NO_VERIFACTU_PRODUCTION, $url);
    }
}
