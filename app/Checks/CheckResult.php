<?php

namespace App\Checks;

use App\Enums\Status;

/** What one run of a checker found (SPEC §4.2). */
final readonly class CheckResult
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(
        public Status $status,
        public string $message,
        public array $data = [],
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function ok(string $message, array $data = []): self
    {
        return new self(Status::Ok, $message, $data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function warning(string $message, array $data = []): self
    {
        return new self(Status::Warning, $message, $data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function error(string $message, array $data = []): self
    {
        return new self(Status::Error, $message, $data);
    }

    /** Only the runner makes these, from `CheckCouldNotRun` and crashes (§5.3). */
    public static function unknown(string $message): self
    {
        return new self(Status::Unknown, $message);
    }

    public function isFailure(): bool
    {
        return $this->status->isFailure();
    }

    /** OK, warning or error: a result about the resource, not about Sextant. */
    public function isDefinitive(): bool
    {
        return $this->status !== Status::Unknown;
    }

    /** The same result with its severity capped (`max_severity`, §4.3). */
    public function cappedAt(Status $max): self
    {
        return $this->status->rank() > $max->rank() && $this->status->isFailure()
            ? new self($max, $this->message, $this->data)
            : $this;
    }
}
