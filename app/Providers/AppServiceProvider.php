<?php

namespace App\Providers;

use App\Models\Domain;
use App\Models\ExternalService;
use App\Models\Incident;
use App\Models\Monitor;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use App\Net\DnsResolver;
use App\Net\HttpProbe;
use App\Net\PingProbe;
use App\Net\RdapClient;
use App\Net\Real\GuzzleHttpProbe;
use App\Net\Real\IanaRdapClient;
use App\Net\Real\NetDnsResolver;
use App\Net\Real\SocketTcpProbe;
use App\Net\Real\SocketWhoisClient;
use App\Net\Real\StreamTlsProbe;
use App\Net\Real\SystemPingProbe;
use App\Net\TcpProbe;
use App\Net\TlsProbe;
use App\Net\WhoisClient;
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
        // All network I/O goes through these wrappers so tests can fake it (SPEC §9.0).
        $this->app->singleton(DnsResolver::class, NetDnsResolver::class);
        $this->app->singleton(TcpProbe::class, SocketTcpProbe::class);
        $this->app->singleton(TlsProbe::class, StreamTlsProbe::class);
        $this->app->singleton(HttpProbe::class, GuzzleHttpProbe::class);
        $this->app->singleton(PingProbe::class, SystemPingProbe::class);
        $this->app->singleton(RdapClient::class, IanaRdapClient::class);
        $this->app->singleton(WhoisClient::class, SocketWhoisClient::class);
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
            'monitor' => Monitor::class,
            'incident' => Incident::class,
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
