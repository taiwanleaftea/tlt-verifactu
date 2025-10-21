<?php

namespace Taiwanleaftea\TltVerifactu\Test;

use Illuminate\Support\Carbon;
use PHPUnit\Framework\TestCase;
use Taiwanleaftea\TltVerifactu\Classes\InvoiceCancellation;
use Taiwanleaftea\TltVerifactu\Classes\InvoiceSubmission;
use Taiwanleaftea\TltVerifactu\Enums\InvoiceType;

class HashGeneratorTest extends TestCase
{
    public function testSubmission()
    {
        $invoice = new InvoiceSubmission(
            '89890001K',
            'Issuer Name',
            '12345678/G33',
            Carbon::createFromFormat('d-m-Y', '01-01-2024'),
            'Description',
            InvoiceType::STANDARD,
            21,
            110,
            12.35,
            123.45,
            Carbon::now()
        );

        $hash = $invoice->hash('2024-01-01T19:20:30+01:00');

        $this->assertEquals('3C464DAF61ACB827C65FDA19F352A4E3BDC2C640E9E9FC4CC058073F38F12F60', $hash, 'Submission hash must be equal with AEAT example 1.');

        $invoice = new InvoiceSubmission(
            '89890001K',
            'Issuer Name',
            '12345679/G34',
            Carbon::createFromFormat('d-m-Y', '01-01-2024'),
            'Description',
            InvoiceType::STANDARD,
            21,
            110,
            12.35,
            123.45,
            Carbon::now()
        );

        $invoice->previousHash ='3C464DAF61ACB827C65FDA19F352A4E3BDC2C640E9E9FC4CC058073F38F12F60';

        $hash = $invoice->hash('2024-01-01T19:20:35+01:00');

        $this->assertEquals('F7B94CFD8924EDFF273501B01EE5153E4CE8F259766F88CF6ACB8935802A2B97', $hash, 'Submission hash must be equal with AEAT example 2.');
    }

    public function testCancellation()
    {
        $invoice = new InvoiceCancellation(
            '89890001K',
            'Issuer Name',
            '12345679/G34',
            Carbon::createFromFormat('d-m-Y', '01-01-2024'),
            'F7B94CFD8924EDFF273501B01EE5153E4CE8F259766F88CF6ACB8935802A2B97',
        );

        $hash = $invoice->hash('2024-01-01T19:20:40+01:00');

        $this->assertEquals('177547C0D57AC74748561D054A9CEC14B4C4EA23D1BEFD6F2E69E3A388F90C68', $hash, 'Cancellation hash must be equal with AEAT example 3.');
    }
}
