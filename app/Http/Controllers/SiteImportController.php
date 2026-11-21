<?php

namespace App\Http\Controllers;

use App\Actions\Sites\ImportSite;
use App\Data\OptionData;
use App\Models\Application;
use App\Models\Server;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The Import page (SPEC §10.6). The page sends the URLs one at a time, so
 * each request stays within one detection's time. An application named here
 * is created by the first request and found by the rest.
 */
class SiteImportController extends Controller
{
    public function create(Request $request): Response
    {
        $application = Application::query()->find($request->integer('application'));

        return Inertia::render('sites/Import', [
            'applications' => ApplicationController::options(),
            'servers' => array_values(Server::query()->orderBy('name')->get()
                ->map(fn (Server $server): OptionData => new OptionData($server->id, $server->name))->all()),
            'application_id' => $application?->id,
        ]);
    }

    public function store(Request $request, ImportSite $importSite): JsonResponse
    {
        $request->validate([
            'url' => ['required', 'string', 'max:2048'],
            'application_id' => ['nullable', 'integer', Rule::exists('applications', 'id')->whereNull('deleted_at')],
            'application_name' => ['nullable', 'string', 'max:100'],
            'server_id' => ['nullable', 'integer', Rule::exists('servers', 'id')->whereNull('deleted_at')],
        ]);

        return response()->json($importSite->handle(
            (string) $request->input('url'),
            $this->application($request),
            $request->filled('server_id') ? Server::query()->find($request->integer('server_id')) : null,
        ));
    }

    private function application(Request $request): ?Application
    {
        if ($request->filled('application_id')) {
            return Application::query()->find($request->integer('application_id'));
        }

        $name = trim((string) $request->input('application_name'));

        return $name === '' ? null : Application::query()->firstOrCreate(['name' => $name]);
    }
}
