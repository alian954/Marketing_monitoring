<?php

namespace App\Services\Probe;

class BoundedResponseBodyBuffer
{
    private string $body = '';

    private bool $exceededLimit = false;

    public function __construct(private readonly int $maxBytes) {}

    public function append(string $chunk): bool
    {
        if ($this->exceededLimit) {
            return false;
        }

        $remainingBytes = max(0, $this->maxBytes - strlen($this->body));
        $chunkLength = strlen($chunk);

        if ($chunkLength > $remainingBytes) {
            $this->body .= substr($chunk, 0, $remainingBytes);
            $this->exceededLimit = true;

            return false;
        }

        $this->body .= $chunk;

        return true;
    }

    public function body(): string
    {
        return $this->body;
    }

    public function exceededLimit(): bool
    {
        return $this->exceededLimit;
    }
}
