<?php

declare(strict_types=1);

namespace Taiwanleaftea\TltVerifactu\Services;

use Illuminate\Support\Str;

class Vat
{
    /**
     * @var array
     * @link https://ec.europa.eu/taxation_customs/vies/faq.html?locale=lt#item_11
     */
    private static array $patterns = [
        'AT' => 'U[A-Z\d]{8}',
        'BE' => '(0|1)\d{9}',
        'BG' => '\d{9,10}',
        'CY' => '\d{8}[A-Z]',
        'CZ' => '\d{8,10}',
        'DE' => '\d{9}',
        'DK' => '(\d{2} ?){3}\d{2}',
        'EE' => '\d{9}',
        'EL' => '\d{9}',
        'ES' => '([A-Z]\d{7}[A-Z]|\d{8}[A-Z]|[A-Z]\d{8})',
        'EU' => '\d{9}',
        'FI' => '\d{8}',
        'FR' => '[A-Z\d]{2}\d{9}',
        'GB' => '(\d{9}|\d{12}|(GD|HA)\d{3})',
        'HR' => '\d{11}',
        'HU' => '\d{8}',
        'IE' => '((\d{7}[A-Z]{1,2})|(\d[A-Z]\d{5}[A-Z]))',
        'IT' => '\d{11}',
        'LT' => '(\d{9}|\d{12})',
        'LU' => '\d{8}',
        'LV' => '\d{11}',
        'MT' => '\d{8}',
        'NL' => '\d{9}B\d{2}',
        'PL' => '\d{10}',
        'PT' => '\d{9}',
        'RO' => '\d{2,10}',
        'SE' => '\d{12}',
        'SI' => '\d{8}',
        'SK' => '\d{10}',
        'SM' => '\d{5}',
    ];

    /**
     * Validate a VAT number format
     *
     * @param string $country
     * @param string $vatNumber
     * @return boolean
     */
    public static function validateFormat(string $country, string $vatNumber): bool
    {
        if ($vatNumber === '') {
            return false;
        }

        $vatNumber = Str::upper($vatNumber);
        $country = Str::upper($country);

        if (!isset(self::$patterns[$country])) {
            return false;
        }

        return preg_match('/^' . self::$patterns[$country] . '$/', $vatNumber) > 0;
    }

}
