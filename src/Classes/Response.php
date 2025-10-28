<?php

namespace Taiwanleaftea\TltVerifactu\Classes;

use stdClass;

class Response extends stdClass
{
    public bool $success;
    public array $errors = [];
}
