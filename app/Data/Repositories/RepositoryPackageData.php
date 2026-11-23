<?php

namespace App\Data\Repositories;

use App\Enums\Ecosystem;
use App\Models\RepositoryPackage;
use App\Packages\PackageMinimums;
use Spatie\LaravelData\Data;

/** A locked package version of a repository, with its minimum when one is set (SPEC §11.9). */
class RepositoryPackageData extends Data
{
    public function __construct(
        public Ecosystem $ecosystem,
        public string $name,
        public string $version,
        public bool $dev,
        public bool $direct,
        /** The listed version it's compared with, null without a minimum. */
        public ?string $min_version,
        public bool $below_minimum,
        public ?string $cve_url,
    ) {}

    public static function fromModel(RepositoryPackage $package, PackageMinimums $minimums): self
    {
        return new self(
            ecosystem: $package->ecosystem,
            name: $package->name,
            version: $package->version,
            dev: $package->dev,
            direct: $package->direct,
            min_version: $minimums->floorFor($package),
            below_minimum: $minimums->isBelow($package),
            cve_url: $minimums->for($package)?->cve_url,
        );
    }
}
