<?php

use App\Enums\Ecosystem;
use App\Models\PackageMinimum;
use App\Packages\PackageVersion;

/*
 * Package versions (SPEC §11.9): a package's branch is its major version, and
 * a version is compared with the listed minimum of the nearest major at or
 * below its own.
 */
it('normalises locked versions', function (string $version, ?string $normalised, ?string $major, ?string $line) {
    expect(PackageVersion::normalise($version))->toBe($normalised)
        ->and(PackageVersion::branch($version))->toBe($major)
        ->and(PackageVersion::line($version))->toBe($line);
})->with([
    'composer tag' => ['v12.9.1', '12.9.1', '12', '12.9'],
    'plain' => ['13.39', '13.39', '13', '13'],
    'pre-release' => ['4.0.0-beta.1', '4.0.0', '4', '4.0'],
    'one part' => ['3', '3', '3', '3'],
    'branch' => ['dev-main', null, null, null],
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

it('picks the minimum of the nearest line at or below on its major', function (string $version, string $floor, bool $below) {
    $minimum = new PackageMinimum(['ecosystem' => Ecosystem::Npm, 'name' => 'vite', 'min_versions' => ['8.3.7', '8.2.5', '8.1.7', '7.3.8', '6.4.6']]);

    expect($minimum->floorFor($version))->toBe($floor)
        ->and(PackageVersion::isBelow($version, $floor))->toBe($below);
})->with([
    'above on its line' => ['8.3.9', '8.3.7', false],
    'below on its line' => ['8.2.3', '8.2.5', true],
    'newer line than any listed of its major' => ['8.4.0', '8.3.7', false],
    'older than every listed line of its major' => ['8.0.5', '8.1.7', true],
    'below on a major with one line' => ['7.3.1', '7.3.8', true],
    'newer line on a major with one line' => ['7.4.0', '7.3.8', false],
    'newer major than any listed' => ['9.0.0', '8.3.7', false],
    'older than every listed major' => ['5.9.0', '6.4.6', true],
]);
