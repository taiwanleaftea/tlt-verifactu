<?php

namespace Taiwanleaftea\TltVerifactu\Traits;

trait CheckArray
{
    /**
     * Check array by keys
     *
     * @param array $keys
     * @param array $array
     * @return bool|string
     */
    private function checkArray(array $keys, array $array): bool|string
    {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $array)) {
                return $key;
            }
        }

        return true;
    }
}
