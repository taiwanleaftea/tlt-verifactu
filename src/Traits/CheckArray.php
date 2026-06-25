<?php

namespace Taiwanleaftea\TltVerifactu\Traits;

trait CheckArray
{
    /**
     * Check array by keys
     */
    private function checkArray(array $keys, array $array): bool|string
    {
        foreach ($keys as $key) {
            if (! array_key_exists($key, $array)) {
                return $key;
            }
        }

        return true;
    }
}
