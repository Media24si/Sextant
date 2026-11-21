<?php

namespace App\Http\Controllers;

use App\Checks\CheckCouldNotRun;
use App\Data\VersionsTestReadingData;
use App\Data\VersionsTestResultData;
use App\Data\VersionsTokenData;
use App\Models\Monitor;
use App\Models\Server;
use App\Monitoring\MonitorClaimer;
use App\Net\VersionsClient;
use App\Versions\SoftwareReading;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Inertia\Inertia;

/**
 * Setting up a server's versions endpoint (SPEC §11.2, §11.4): the token,
 * the two files to install, Test connection and Refresh. Admins only.
 */
class ServerVersionsController extends Controller
{
    /**
     * Generate or rotate the token. The full token is returned once and
     * never again; only its last four characters are shown afterwards.
     */
    public function token(Server $server): JsonResponse
    {
        $token = config('sextant.versions.token_prefix').bin2hex(random_bytes(16));

        $server->forceFill(['versions_token' => $token, 'versions_token_last4' => substr($token, -4)])->save();

        return response()->json(new VersionsTokenData($token, (string) $server->versions_token_last4));
    }

    /** The collector script, the same for every server (§11.3). */
    public function collector(Server $server): Response
    {
        return response((string) file_get_contents(resource_path('collector/sextant-collect.sh')), 200, [
            'Content-Type' => 'text/x-shellscript; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="sextant-collect.sh"',
        ]);
    }

    /**
     * `versions.php` for this server, built from the stored token at request
     * time. Holds the token's SHA-256 hash, never the token (§11.2).
     */
    public function endpoint(Server $server): Response|RedirectResponse
    {
        if ($server->versions_token === null) {
            Inertia::flash('toast', ['type' => 'error', 'message' => 'Generate a token first.']);

            return back();
        }

        $php = strtr((string) file_get_contents(resource_path('collector/versions.php.stub')), [
            '{{server}}' => str_replace(["\n", '*/'], ' ', $server->name),
            '{{token_hash}}' => hash('sha256', $server->versions_token),
            '{{file_path}}' => addslashes((string) config('sextant.versions.file_path')),
        ]);

        return response($php, 200, [
            'Content-Type' => 'text/x-php; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="versions.php"',
            'Cache-Control' => 'no-store',
        ]);
    }

    /**
     * Fetch the versions file now and show what it holds, storing nothing (§11.4).
     */
    public function test(Server $server, VersionsClient $client): JsonResponse
    {
        if ($server->versions_url === null || $server->versions_token === null) {
            return response()->json(VersionsTestResultData::failed('Set the endpoint URL and generate a token first.'));
        }

        try {
            $file = $client->fetch($server->versions_url, $server->versions_token);
        } catch (CheckCouldNotRun $exception) {
            return response()->json(VersionsTestResultData::failed($exception->getMessage()));
        }

        return response()->json(new VersionsTestResultData(
            ok: true,
            generated_at: $file->generatedAt->toIso8601String(),
            hostname: $file->hostname,
            readings: array_map(fn (SoftwareReading $reading): VersionsTestReadingData => new VersionsTestReadingData(
                software: $reading->software->labelWithBranch($reading->branch),
                version: $reading->version,
                package: $reading->package,
            ), $file->readings),
        ));
    }

    /** Refresh: claim the versions monitor for an immediate run (§5.1). */
    public function refresh(Server $server, MonitorClaimer $claimer): RedirectResponse
    {
        $monitor = $server->monitors()->where('checker', 'server.versions')->where('enabled', true)->first();

        Inertia::flash('toast', $monitor instanceof Monitor && $claimer->claimNow($monitor)
            ? ['type' => 'success', 'message' => 'Reading versions now. They show here in a few seconds.']
            : ['type' => 'error', 'message' => 'No versions monitor yet: set the endpoint URL and a token first.']);

        return back();
    }

    /** "sxt_••••a91f" */
    public static function maskedToken(Server $server): ?string
    {
        return $server->versions_token_last4 === null
            ? null
            : config('sextant.versions.token_prefix').Str::repeat('•', 4).$server->versions_token_last4;
    }
}
