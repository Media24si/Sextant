<?php

namespace App\Data;

use App\Models\Application;
use Spatie\LaravelData\Data;

/** The values of the application form (SPEC §3.19). */
class ApplicationFormData extends Data
{
    public function __construct(
        public ?int $id,
        public string $name,
        public ?string $description,
        public ?string $github_url,
        public ?string $notes,
    ) {}

    public static function blank(): self
    {
        return new self(null, '', null, null, null);
    }

    public static function fromModel(Application $application): self
    {
        return new self(
            id: $application->id,
            name: $application->name,
            description: $application->description,
            github_url: $application->github_url,
            notes: $application->notes,
        );
    }
}
