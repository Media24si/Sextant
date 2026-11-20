<?php

namespace App\Http\Controllers;

use App\Data\UserRowData;
use App\Enums\UserRole;
use App\Models\User;
use App\Support\TableQuery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

/**
 * Users and their roles (SPEC §13.6), admins only. There is no public
 * registration: admins create accounts. Users are soft-deleted so the change
 * feed keeps their names, and the last admin can never be removed.
 */
class UserController extends Controller
{
    public function index(Request $request): Response
    {
        $query = QueryBuilder::for(User::class, $request)
            ->allowedFilters(AllowedFilter::exact('role'))
            ->allowedSorts('name', 'email', 'created_at')
            ->defaultSort('name');

        return Inertia::render('users/Index', [
            ...TableQuery::props($request, $query, UserRowData::class, ['role'], 'name'),
            'counts' => [
                'all' => User::query()->count(),
                'admin' => User::query()->where('role', UserRole::Admin)->count(),
                'reader' => User::query()->where('role', UserRole::Reader)->count(),
            ],
            'password_rules' => Password::defaults()->toPasswordRulesString(),
        ]);
    }

    /**
     * SPEC: GOAL says admins "invite/create" users, but no mail is set up, so
     * the admin sets a first password and tells the person, who changes it
     * under Settings.
     */
    public function store(Request $request): RedirectResponse
    {
        $request->merge(['email' => Str::lower(trim((string) $request->input('email')))]);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique(User::class)->withoutTrashed()],
            'role' => ['required', Rule::enum(UserRole::class)],
            'password' => ['required', 'string', Password::defaults()],
        ]);

        $user = new User(['name' => $validated['name'], 'email' => $validated['email'], 'password' => $validated['password']]);
        $user->role = UserRole::from($validated['role']);
        $user->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => "{$user->name} can now sign in as {$user->role->value}."]);

        return back();
    }

    public function updateRole(Request $request, User $user): RedirectResponse
    {
        $role = UserRole::from($request->validate(['role' => ['required', Rule::enum(UserRole::class)]])['role']);

        if ($role === UserRole::Reader && $this->isLastAdmin($user)) {
            Inertia::flash('toast', ['type' => 'error', 'message' => "{$user->name} is the last admin. Make someone else an admin first."]);

            return back();
        }

        $user->role = $role;
        $user->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => "{$user->name} is now {$role->value}."]);

        return back();
    }

    public function destroy(Request $request, User $user): RedirectResponse
    {
        if ($this->isLastAdmin($user)) {
            Inertia::flash('toast', ['type' => 'error', 'message' => "{$user->name} is the last admin and can't be deleted."]);

            return back();
        }

        $user->delete();

        if ($user->is($request->user())) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login');
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => "{$user->name} deleted. Their changes keep their name."]);

        return back();
    }

    private function isLastAdmin(User $user): bool
    {
        return $user->isAdmin() && User::query()->where('role', UserRole::Admin)->count() === 1;
    }
}
