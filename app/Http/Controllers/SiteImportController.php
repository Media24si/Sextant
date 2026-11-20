<?php

namespace App\Http\Controllers;

use App\Actions\Sites\ImportSite;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The Import page (SPEC §10.6). The page sends the URLs one at a time, so
 * each request stays within one detection's time.
 */
class SiteImportController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('sites/Import');
    }

    public function store(Request $request, ImportSite $importSite): JsonResponse
    {
        $request->validate(['url' => ['required', 'string', 'max:2048']]);

        return response()->json($importSite->handle((string) $request->input('url')));
    }
}
