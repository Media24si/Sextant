<?php

use App\Packages\LockFiles\ComposerLock;
use App\Packages\LockFiles\InvalidLockFile;
use App\Packages\LockFiles\NpmLock;
use App\Packages\LockFiles\PnpmLock;
use App\Packages\PackageReading;

/*
 * Lock file parsing (SPEC §11.9): every locked version once, with whether
 * it's only for development and whether the project requires it directly.
 */
function lockFixture(string $name): string
{
    return (string) file_get_contents(base_path("tests/Fixtures/lockfiles/{$name}"));
}

/**
 * @param  list<PackageReading>  $readings
 * @return array<string, string> "name@version" => flags
 */
function summarise(array $readings): array
{
    $summary = [];

    foreach ($readings as $reading) {
        $summary["{$reading->name}@{$reading->version}"] = ($reading->direct ? 'direct' : 'indirect').($reading->dev ? ' dev' : '');
    }

    ksort($summary);

    return $summary;
}

it('reads composer.lock with composer.json for the direct packages', function () {
    expect(summarise((new ComposerLock)->parse(lockFixture('composer.lock'), lockFixture('composer.json'))))->toBe([
        'guzzlehttp/guzzle@7.9.2' => 'indirect',
        'laravel/framework@v12.9.1' => 'direct',
        'media24/internal@dev-main' => 'direct',
        'pestphp/pest@v3.7.4' => 'direct dev',
        'phpunit/phpunit@11.5.3' => 'indirect dev',
    ]);
});

it('reads composer.lock without composer.json, knowing nothing is direct', function () {
    expect(collect((new ComposerLock)->parse(lockFixture('composer.lock')))->where('direct', true))->toBeEmpty();
});

it('reads package-lock.json v3 paths, aliases and nested copies', function () {
    expect(summarise((new NpmLock)->parse(lockFixture('package-lock-v3.json'))))->toBe([
        'axios@1.7.9' => 'direct',
        'esbuild@0.21.5' => 'indirect dev',
        'esbuild@0.24.2' => 'indirect dev',
        'follow-redirects@1.15.9' => 'indirect',
        'lodash@4.17.21' => 'direct',
        'vite@6.0.7' => 'direct dev',
        'vue@3.5.13' => 'direct',
    ]);
});

it('reads package-lock.json v1 with package.json for the direct packages', function () {
    expect(summarise((new NpmLock)->parse(lockFixture('package-lock-v1.json'), lockFixture('package.json'))))->toBe([
        'jquery@3.4.1' => 'direct',
        'laravel-mix@5.0.9' => 'direct dev',
        'lodash@4.17.15' => 'direct',
        'webpack@4.46.0' => 'indirect dev',
        'webpack@5.97.1' => 'indirect dev',
    ]);
});

it('reads pnpm-lock.yaml', function (string $fixture, array $expected) {
    expect(summarise((new PnpmLock)->parse(lockFixture($fixture))))->toBe($expected);
})->with([
    'v5 slash keys and _peer suffixes' => ['pnpm-lock-v5.yaml', [
        '@vue/compiler-sfc@3.2.45' => 'direct',
        '@vue/shared@3.2.45' => 'indirect',
        'vite@4.0.0' => 'direct dev',
        'vue@3.2.45' => 'direct',
    ]],
    'v6 at-sign keys, (peer) suffixes, git entries skipped' => ['pnpm-lock-v6.yaml', [
        '@babel/parser@7.23.5' => 'indirect',
        'axios@1.6.2' => 'direct',
        'follow-redirects@1.15.3' => 'indirect',
        'vite@4.4.9' => 'direct dev',
        'vue@3.3.4' => 'direct',
    ]],
    'v9 importers, workspace links skipped' => ['pnpm-lock-v9.yaml', [
        '@inertiajs/vue3@2.0.3' => 'direct',
        'axios@1.7.9' => 'indirect',
        'typescript@5.7.3' => 'direct dev',
        'vite@6.0.7' => 'direct dev',
        'vue@3.5.13' => 'direct',
    ]],
]);

it('rejects files that are not lock files', function (Closure $parse, string $message) {
    expect($parse)->toThrow(InvalidLockFile::class, $message);
})->with([
    'broken JSON' => [fn () => (new ComposerLock)->parse('{"packages": ['), 'composer.lock is not valid JSON'],
    'composer.json instead of the lock' => [fn () => (new ComposerLock)->parse(lockFixture('composer.json')), 'composer.lock has no packages list'],
    'package.json instead of the lock' => [fn () => (new NpmLock)->parse('{"name": "x", "lockfileVersion": 3}'), 'package-lock.json has neither packages nor dependencies'],
    'not a pnpm lock' => [fn () => (new PnpmLock)->parse("packages:\n  /vue@3.3.4:\n"), 'pnpm-lock.yaml has no lockfileVersion'],
]);
