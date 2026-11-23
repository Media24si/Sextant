<?php

use App\Enums\Ecosystem;
use App\Models\PackageMinimum;
use App\Packages\PackageVersion;

/*
 * Package versions (SPEC §11.9): a package's branch is its major version, and
 * a version is compared with the listed minimum of the nearest major at or
 * below its own.
 */
it('normalises locked versions', function (string $version, ?string $normalised, ?string $major) {
    expect(PackageVersion::normalise($version))->toBe($normalised)
        ->and(PackageVersion::branch($version))->toBe($major);
})->with([
    'composer tag' => ['v12.9.1', '12.9.1', '12'],
    'plain' => ['13.39.0', '13.39.0', '13'],
    'pre-release' => ['4.0.0-beta.1', '4.0.0', '4'],
    'branch' => ['dev-main', null, null],
]);

it('picks the minimum of the nearest major at or below', function (string $version, ?string $floor, bool $below) {
    $minimum = new PackageMinimum(['ecosystem' => Ecosystem::Composer, 'name' => 'laravel/framework', 'min_versions' => ['13.39', '12.13']]);

    expect($minimum->floorFor($version))->toBe($floor)
        ->and($floor !== null && PackageVersion::isBelow($version, $floor))->toBe($below);
})->with([
    'older than every listed major' => ['v11.44.2', '12.13', true],
    'below on its major' => ['v12.9.1', '12.13', true],
    'exactly the minimum' => ['v12.13.0', '12.13', false],
    'just below on the next major' => ['v13.38.9', '13.39', true],
    'above' => ['v13.40.0', '13.39', false],
    'newer major than any listed' => ['v14.0.0', '13.39', false],
    'a branch is never below' => ['dev-main', '12.13', false],
]);
