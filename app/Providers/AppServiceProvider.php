<?php

namespace App\Providers;

use App\Models\Domain;
use App\Models\ExternalService;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureMorphMap();
        $this->configureAuthorization();
    }

    /**
     * Polymorphic types are stored as these keys, never class names (SPEC §3).
     * monitor, incident and minimum_version join when their models exist.
     */
    protected function configureMorphMap(): void
    {
        Relation::enforceMorphMap([
            'server' => Server::class,
            'external_service' => ExternalService::class,
            'domain' => Domain::class,
            'site' => Site::class,
            'user' => User::class,
        ]);
    }

    /**
     * Authorization scaffold (SPEC §13.2). `manage` covers every change to data;
     * EnsureAdmin, the `can.manage` prop and the model policies all use it.
     */
    protected function configureAuthorization(): void
    {
        Gate::define('manage', fn (User $user): bool => $user->isAdmin());
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
