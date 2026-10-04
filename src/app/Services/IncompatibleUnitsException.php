<?php

namespace App\Services;

use App\Models\Unit;
use RuntimeException;

class IncompatibleUnitsException extends RuntimeException
{
    public function __construct(public readonly Unit $from, public readonly Unit $to)
    {
        parent::__construct("Impossible de convertir « {$from->label} » en « {$to->label} ».");
    }
}
