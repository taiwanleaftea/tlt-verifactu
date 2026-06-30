<?php

declare(strict_types=1);

namespace Taiwanleaftea\TltVerifactu\Enums;

use InvalidArgumentException;

enum VerifactuMode: string
{
    use EnumValues;

    case ONLINE = 'online';
    case NO_VERIFACTU = 'no_verifactu';

    public static function fromConfig(mixed $value): self
    {
        if ($value instanceof self) {
            return $value;
        }

        if (! is_string($value)) {
            throw new InvalidArgumentException('VERIFACTU mode must be "online" or "no_verifactu".');
        }

        return self::tryFrom(strtolower(trim($value)))
            ?? throw new InvalidArgumentException('VERIFACTU mode must be "online" or "no_verifactu".');
    }

    public function sendsRecordsOnline(): bool
    {
        return $this === self::ONLINE;
    }

    public function storesRecordsOnly(): bool
    {
        return $this === self::NO_VERIFACTU;
    }

    public function signsStoredRecords(): bool
    {
        return $this === self::NO_VERIFACTU;
    }
}
