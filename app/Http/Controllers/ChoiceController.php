<?php

namespace App\Http\Controllers;

use App\Data\ChoiceRowData;
use App\Enums\ChoiceList;
use App\Models\Choice;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The OS and backup location lists (SPEC §3.4, §13.6): add, rename, reorder,
 * and delete entries no server uses.
 */
class ChoiceController extends Controller
{
    public function index(): Response
    {
        $lists = [];

        foreach (ChoiceList::cases() as $list) {
            $lists[$list->value] = Choice::ofList($list)
                ->withCount([
                    'osServers' => fn ($query) => $query->withTrashed(),
                    'backupServers' => fn ($query) => $query->withTrashed(),
                ])
                ->get()
                ->map(ChoiceRowData::fromModel(...));
        }

        return Inertia::render('lists/Index', ['lists' => $lists]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'list' => ['required', Rule::enum(ChoiceList::class)],
            'label' => ['required', 'string', 'max:100', Rule::unique('choices')->where('list', $request->input('list'))],
        ]);

        Choice::query()->create([
            'list' => $validated['list'],
            'label' => $validated['label'],
            'position' => (int) Choice::query()->where('list', $validated['list'])->max('position') + 1,
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => "{$validated['label']} added."]);

        return back();
    }

    /**
     * Renaming is allowed even while the entry is in use (SPEC §3.4).
     */
    public function update(Request $request, Choice $choice): RedirectResponse
    {
        $validated = $request->validate([
            'label' => ['required', 'string', 'max:100', Rule::unique('choices')->where('list', $choice->list->value)->ignore($choice)],
        ]);

        $choice->update(['label' => $validated['label']]);

        Inertia::flash('toast', ['type' => 'success', 'message' => "Renamed to {$choice->label}."]);

        return back();
    }

    /**
     * Swap places with the neighbour above or below.
     */
    public function move(Request $request, Choice $choice): RedirectResponse
    {
        $direction = $request->validate(['direction' => ['required', Rule::in(['up', 'down'])]])['direction'];

        $neighbour = Choice::query()
            ->where('list', $choice->list)
            ->where('position', $direction === 'up' ? '<' : '>', $choice->position)
            ->orderBy('position', $direction === 'up' ? 'desc' : 'asc')
            ->first();

        if ($neighbour !== null) {
            DB::transaction(function () use ($choice, $neighbour): void {
                [$choice->position, $neighbour->position] = [$neighbour->position, $choice->position];
                $choice->save();
                $neighbour->save();
            });
        }

        return back();
    }

    /**
     * A choice in use can't be deleted, only renamed (SPEC §3.4).
     */
    public function destroy(Choice $choice): RedirectResponse
    {
        if ($choice->isInUse()) {
            Inertia::flash('toast', ['type' => 'error', 'message' => "{$choice->label} is in use, so it can only be renamed."]);

            return back();
        }

        $choice->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => "{$choice->label} deleted."]);

        return back();
    }
}
