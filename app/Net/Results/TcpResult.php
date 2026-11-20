<?php

namespace App\Net\Results;

final readonly class TcpResult
{
    public function __construct(
        public bool $ok,
        public int $ms,
        public ?string $error = null,
    ) {}
}
