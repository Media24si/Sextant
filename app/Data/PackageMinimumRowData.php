<?php

namespace App\Data;

use App\Enums\Ecosystem;
use App\Models\PackageMinimum;
use Spatie\LaravelData\Data;

/** A package row of the Minimum versions page (SPEC §13.6, §11.9). */
class PackageMinimumRowData extends Data
{
    public function __construct(
        public int $id,
        public Ecosystem $ecosystem,
        public string $name,
        /** @var list<string> one per major, e.g. ["12.13", "13.39"] */
        public array $min_versions,
        public ?string $cve_url,
        public ?string $note,
        /** @var list<array{id: int, name: string, version: string}> */
        public array $repositories_below,
        public string $set_by,
        public string $updated_at,
    ) {}

    /**
     * @param  list<array{id: int, name: string, version: string}>  $repositoriesBelow
     */
    public static function fromModel(PackageMinimum $minimum, array $repositoriesBelow): self
    {
        return new self(
            id: $minimum->id,
            ecosystem: $minimum->ecosystem,
            name: $minimum->name,
            min_versions: $minimum->min_versions,
            cve_url: $minimum->cve_url,
            note: $minimum->note,
            repositories_below: $repositoriesBelow,
            set_by: $minimum->setBy->name,
            updated_at: ($minimum->updated_at ?? $minimum->created_at ?? now())->toIso8601String(),
        );
    }
}
