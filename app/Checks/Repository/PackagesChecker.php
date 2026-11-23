<?php

namespace App\Checks\Repository;

use App\Checks\Arguments;
use App\Checks\CheckCouldNotRun;
use App\Checks\CheckResult;
use App\Checks\RepositoryChecker;
use App\Models\GithubToken;
use App\Models\Repository;
use App\Net\GithubClient;
use App\Packages\LockFiles\InvalidLockFile;
use App\Packages\LockFiles\LockFiles;
use App\Packages\PackageMinimums;
use App\Packages\PackagesRecorder;
use App\Support\Duration;

/**
 * `repository.packages`: reads the lock files in the root of the default
 * branch with the owner's read-only token, stores the locked versions and
 * compares them with the package minimums (SPEC §9.10, §11.9). Anything that
 * keeps it from reading them is Unknown, and the last stored packages stay.
 * A token about to expire is a warning: an expired one would leave every
 * repository of its owner Unknown, which never notifies.
 */
class PackagesChecker extends RepositoryChecker
{
    public function __construct(
        private GithubClient $client,
        private PackagesRecorder $recorder,
        private PackageMinimums $minimums,
    ) {}

    public static function name(): string
    {
        return 'Packages';
    }

    protected function check(Repository $repository, Arguments $arguments): CheckResult
    {
        $token = GithubToken::forOwner($repository->owner)
            ?? throw new CheckCouldNotRun("No GitHub token for {$repository->owner}: add one under Settings → GitHub access");

        $github = $this->client->repository($repository->owner, $repository->name, $token->token);

        if ($github->tokenExpiresAt !== null && ($token->expires_at === null || ! $github->tokenExpiresAt->equalTo($token->expires_at))) {
            $token->forceFill(['expires_at' => $github->tokenExpiresAt])->saveQuietly();
        }

        $root = $this->client->rootFiles($repository->owner, $repository->name, $token->token);
        $lockFiles = LockFiles::among(array_map(strval(...), array_keys($root)));
        $readings = [];

        foreach ($lockFiles as $file) {
            $format = LockFiles::for($file);

            if ($format === null) {
                continue;
            }

            $manifestName = $format->manifest();
            $manifest = $manifestName !== null && isset($root[$manifestName])
                ? $this->client->file($repository->owner, $repository->name, $manifestName, $token->token)
                : null;

            try {
                array_push($readings, ...$format->parse($this->client->file($repository->owner, $repository->name, $file, $token->token), $manifest));
            } catch (InvalidLockFile $exception) {
                throw new CheckCouldNotRun($exception->getMessage());
            }
        }

        $this->recorder->record($repository, $readings, $lockFiles, $github->defaultBranch, now());

        $packages = $repository->packages()->get();
        $below = $this->minimums->below($packages);
        $warnings = [
            ...array_map(fn (array $item): string => "{$item['software']} {$item['version']} < {$item['min_version']}", $below),
            ...$this->expiryWarning($token),
        ];
        $data = [
            'read_at' => now()->toIso8601String(),
            'lock_files' => $lockFiles,
            'below' => $below,
            'counts' => ['packages' => $packages->count(), 'below' => count($below)],
        ];

        $message = $lockFiles === []
            ? "No lock file in the root of {$github->defaultBranch}"
            : trans_choice('{1} 1 package from :files, at or above minimum|[2,*] :count packages from :files, all at or above minimum', $packages->count(), ['files' => implode(', ', $lockFiles)]);

        return $warnings === []
            ? CheckResult::ok($message, $data)
            : CheckResult::warning(implode('; ', $warnings), $data);
    }

    /**
     * @return list<string>
     */
    private function expiryWarning(GithubToken $token): array
    {
        $days = (int) config('sextant.github.expiry_warning_days');

        if ($token->expires_at === null || $token->expires_at->gt(now()->addDays($days))) {
            return [];
        }

        return $token->expires_at->isPast()
            ? ["GitHub token for {$token->owner} expired"]
            : ["GitHub token for {$token->owner} expires in ".Duration::between(now(), $token->expires_at)];
    }
}
