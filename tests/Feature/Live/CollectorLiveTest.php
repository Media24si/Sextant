<?php

use App\Enums\Software;
use App\Versions\VersionsFile;
use Symfony\Component\Process\Process;

/*
 * The collector on real distributions (SPEC §11.3, §17): runs it in Ubuntu
 * 24.04 and Debian 12 containers and validates the file against schema 1.
 * Needs Docker; `php artisan test --group=live`.
 */
it('writes a valid versions file on', function (string $image) {
    $docker = new Process(['docker', 'info']);
    $docker->run();

    if (! $docker->isSuccessful()) {
        $this->markTestSkipped('Docker is not running.');
    }

    $process = new Process([
        'docker', 'run', '--rm',
        '-v', resource_path('collector/sextant-collect.sh').':/usr/local/bin/sextant-collect:ro',
        '-e', 'SEXTANT_DIR=/tmp/sextant',
        '-e', 'SEXTANT_GROUP=root',
        $image,
        'bash', '-c', 'apt-get update -qq >/dev/null && apt-get install -y -qq openssl >/dev/null && bash /usr/local/bin/sextant-collect && cat /tmp/sextant/versions.json',
    ]);
    $process->setTimeout(300)->run();

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());

    $file = VersionsFile::fromJson($process->getOutput());
    $software = array_map(fn ($reading) => $reading->software, $file->readings);

    expect($software)->toContain(Software::Os, Software::Kernel, Software::Openssl)
        ->and($file->generatedAt->diffInMinutes(now(), true))->toBeLessThan(10);
})->with(['ubuntu:24.04', 'debian:12'])->group('live');
