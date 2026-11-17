<?php

namespace App\Services\Probe;

use RuntimeException;

class ProbeException extends RuntimeException
{
    public function __construct(public readonly string $type, string $message)
    {
        parent::__construct($message);
    }
}
