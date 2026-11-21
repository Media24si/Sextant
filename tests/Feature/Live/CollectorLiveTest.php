<?php

use App\Enums\Software;
use App\Models\Server;
use App\Versions\CollectorKit;
use App\Versions\VersionsFile;
use Symfony\Component\Process\Process;

/*
 * The collector and installer on real distributions (SPEC §11.3, §11.8, §17):
 * runs them in Ubuntu 24.04 and Debian 12 containers and validates the file
 * against schema 1. Needs Docker; `php artisan test --group=live`.
 */
function skipWithoutDocker(): void
{
    $docker = new Process(['docker', 'info']);
    $docker->run();

    if (! $docker->isSuccessful()) {
        test()->markTestSkipped('Docker is not running.');
    }
}

it('writes a valid versions file on', function (string $image) {
    skipWithoutDocker();

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

it('installs, then updates, the collector, cron and versions.php on', function (string $image) {
    skipWithoutDocker();

    $server = Server::factory()->make(['name' => 'web02']);
    $server->versions_token = 'sxt_'.str_repeat('b', 32);
    $installer = app(CollectorKit::class)->installScript($server);

    // Run twice: the second run is an update and must keep a customised cron.
    $process = new Process([
        'docker', 'run', '--rm', '-i', $image,
        'bash', '-c', 'cat > /tmp/install.sh && groupadd -f www-data && bash /tmp/install.sh && echo "SEXTANT_GROUP=root" > /etc/cron.d/sextant.keep && cat /etc/cron.d/sextant >> /etc/cron.d/sextant.keep && mv /etc/cron.d/sextant.keep /etc/cron.d/sextant && bash /tmp/install.sh && echo "---" && cat /etc/cron.d/sextant && stat -c "%a %G" /var/lib/sextant/versions.json && cat /var/lib/sextant/versions.json && cat /var/www/sextant/versions.php',
    ]);
    $process->setInput($installer)->setTimeout(300)->run();

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput().$process->getOutput());

    [$log, $files] = explode("---\n", $process->getOutput(), 2);
    $json = substr($files, strpos($files, '{'), strrpos($files, "}\n<?php") - strpos($files, '{') + 1);

    expect($log)->toContain('Installed collector '.app(CollectorKit::class)->version())
        ->and($log)->toContain('location = /.sextant/versions.php {')
        ->and($log)->toContain('fastcgi_param SCRIPT_FILENAME /var/www/sextant/versions.php;')
        ->and($files)->toStartWith("SEXTANT_GROUP=root\n17 * * * * root /usr/local/bin/sextant-collect\n640 root\n")
        ->and($files)->toContain(hash('sha256', 'sxt_'.str_repeat('b', 32)))
        ->and(VersionsFile::fromJson($json)->collectorVersion)->toBe(app(CollectorKit::class)->version());
})->with(['ubuntu:24.04', 'debian:12'])->group('live');
