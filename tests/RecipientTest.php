<?php

namespace Taiwanleaftea\TltVerifactu\Test;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Taiwanleaftea\TltVerifactu\Classes\Recipient;
use Taiwanleaftea\TltVerifactu\Enums\IdType;
use Taiwanleaftea\TltVerifactu\Exceptions\RecipientException;

#[CoversClass(Recipient::class)]
class RecipientTest extends TestCase
{
    public function testCreateEURecipientWithoutID()
    {
        $this->expectException(RecipientException::class);
        new Recipient(
            'Recipient Name',
            '',
            'DE',
            IdType::NIF
        );
    }

    public function testCreateESRecipient()
    {
        $recipient = new Recipient(
            'Recipient Name',
            '89890001K',
            'ES',
            IdType::NIF
        );

        $this->assertTrue($recipient->isDomestic(), 'Domestic must be true for ES recipient.');
    }

    public function testCreateEURecipient()
    {
        $recipient = new Recipient(
            'Recipient Name',
            '89890001K',
            'DE',
            IdType::NIF
        );

        $this->assertFalse($recipient->isDomestic(), 'Domestic must be false for non ES recipient.');
    }
}
