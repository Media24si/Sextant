<?php

use App\Enums\Software;
use App\Models\MinimumVersion;
use App\Versions\InvalidVersionsFile;
use App\Versions\VersionComparator;
use App\Versions\VersionsFile;

/* Version comparison (SPEC §11.6) and the versions file, schema 1 (§11.3). */
function versionsFixture(array $changes = []): array
{
    $file = json_decode((string) file_get_contents(base_path('tests/Fixtures/versions/web02.json')), true);

    foreach ($changes as $key => $value) {
        if ($value === '__unset') {
            unset($file[$key]);
        } else {
            $file[$key] = $value;
        }
    }

    return $file;
}

it('normalises versions before comparing', function (Software $software, string $version, ?string $normalised) {
    expect(VersionComparator::normalise($software, $version))->toBe($normalised);
})->with([
    'kernel with flavour' => [Software::Kernel, '6.8.0-49-generic', '6.8.0.49'],
    'cloud kernel' => [Software::Kernel, '6.1.0-27-cloud-amd64', '6.1.0.27'],
    'plain kernel' => [Software::Kernel, '6.8.0', '6.8.0'],
    'mysql with suffix' => [Software::Mysql, '8.0.40-0ubuntu0.24.04.1', '8.0.40'],
    'openssl letter release' => [Software::Openssl, '1.1.1w', '1.1.1'],
    'nginx' => [Software::Nginx, '1.26.2', '1.26.2'],
    'garbage' => [Software::Nginx, 'unknown', null],
]);

it('knows when a version is below the minimum', function (Software $software, string $version, string $minimum, bool $below) {
    expect(VersionComparator::isBelow($software, $version, $minimum))->toBe($below);
})->with([
    [Software::Php, '8.2.20', '8.2.26', true],
    [Software::Php, '8.2.26', '8.2.26', false],
    [Software::Nginx, '1.24.0', '1.26.2', true],
    [Software::Kernel, '6.8.0-49-generic', '6.8.0-51', true],
    [Software::Kernel, '6.8.0-51-generic', '6.8.0-49', false],
    [Software::Openssl, '3.0.13', '3.0.2', false],
    'unparseable is never below' => [Software::Mysql, 'unknown', '8.0.40', false],
]);

it('compares a reading with the nearest listed branch at or below its own', function (Software $software, array $minimums, string $version, string $floor, bool $below) {
    $minimum = new MinimumVersion(['software' => $software, 'min_versions' => $minimums]);

    expect($minimum->floorFor($version))->toBe($floor)
        ->and(VersionComparator::isBelow($software, $version, $floor))->toBe($below);
})->with([
    'older than every listed branch' => [Software::Php, ['8.2.36', '8.3.37', '8.4.28', '8.5.13'], '8.1.30', '8.2.36', true],
    'below on its branch' => [Software::Php, ['8.2.36', '8.3.37', '8.4.28', '8.5.13'], '8.2.35', '8.2.36', true],
    'at its branch' => [Software::Php, ['8.2.36', '8.3.37', '8.4.28', '8.5.13'], '8.3.37', '8.3.37', false],
    'newer than every listed branch' => [Software::Php, ['8.2.36', '8.3.37', '8.4.28', '8.5.13'], '8.6.0', '8.5.13', false],
    'between listed branches' => [Software::Php, ['8.2.36', '8.4.28'], '8.3.1', '8.2.36', false],
    'nginx on a later branch' => [Software::Nginx, ['1.31.9', '1.30.7'], '1.31.2', '1.31.9', true],
    'single version' => [Software::Nginx, ['1.26.2'], '1.27.0', '1.26.2', false],
    'kernel' => [Software::Kernel, ['6.8.0-51'], '6.8.0-49-generic', '6.8.0-51', true],
]);

it('reads a schema 1 file, ignoring fields it does not know', function () {
    $file = VersionsFile::fromArray(versionsFixture());

    expect($file->generatedAt->toIso8601String())->toBe('2026-11-20T06:00:02+00:00')
        ->and($file->hostname)->toBe('web02')
        ->and($file->osPrettyName)->toBe('Ubuntu 24.04.1 LTS')
        ->and(array_map(fn ($reading) => $reading->key(), $file->readings))
        ->toBe(['os:', 'kernel:', 'nginx:', 'openssl:', 'php:8.3', 'php:8.2'])
        ->and($file->readings[4]->details)->toBe(['cli' => true, 'fpm' => true])
        ->and($file->readings[2]->package)->toBe('1.26.2-1~noble');
});

it('treats null software as not installed', function () {
    $file = VersionsFile::fromArray(versionsFixture(['nginx' => null, 'php' => [], 'os' => null]));

    expect(array_map(fn ($reading) => $reading->software, $file->readings))->toBe([Software::Kernel, Software::Openssl]);
});

it('refuses files that are not schema 1', function (array $changes, string $message) {
    VersionsFile::fromArray(versionsFixture($changes));
})->with([
    'schema 2' => [['schema' => 2], 'unsupported schema 2'],
    'missing nginx' => [['nginx' => '__unset'], 'missing nginx'],
    'bad date' => [['generated_at' => 'yesterday-ish'], 'generated_at is not a date'],
    'php not a list' => [['php' => ['branch' => '8.3']], 'php must be a list'],
    'php without version' => [['php' => [['branch' => '8.3']]], 'php.0.version must be a short string'],
    'kernel as a string' => [['kernel' => '6.8.0'], 'kernel must be an object or null'],
])->throws(InvalidVersionsFile::class);

it('refuses text that is not JSON', function () {
    VersionsFile::fromJson('<html>Not here</html>');
})->throws(InvalidVersionsFile::class, 'not JSON');
