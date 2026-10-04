<?php

namespace App\Services;

use InvalidArgumentException;

class InvalidQuantityException extends InvalidArgumentException
{
    public function __construct(public readonly string $input)
    {
        parent::__construct("Quantité invalide : « {$input} »");
    }
}
