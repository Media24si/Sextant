<?php

namespace App\Models;

use App\Data\ServingPathData;
use App\Enums\Status;
use App\Models\Concerns\HasMonitors;
use App\Models\Concerns\Monitorable;
use App\Observers\SiteObserver;
use App\Support\Hostname;
use Database\Factories\SiteFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * One hostname we serve, e.g. podjetje.svet24.si (SPEC §3.7).
 *
 * @property int $id
 * @property int $domain_id
 * @property int|null $application_id
 * @property string $hostname
 * @property string $scheme
 * @property int|null $port
 * @property int|null $front_service_id
 * @property int|null $server_id
 * @property string|null $description
 * @property string|null $github_url
 * @property string|null $notes
 * @property Status $status
 * @property Carbon|null $status_changed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read string $url
 * @property-read string $display_hostname
 * @property-read Domain $domain
 * @property-read Application|null $application
 * @property-read ExternalService|null $frontService
 * @property-read Server|null $server
 * @property-read Repository|null $repository
 */
#[ObservedBy(SiteObserver::class)]
#[Fillable(['domain_id', 'application_id', 'hostname', 'scheme', 'port', 'front_service_id', 'server_id', 'description', 'github_url', 'notes'])]
class Site extends Model implements Monitorable
{
    /** @use HasFactory<SiteFactory> */
    use HasFactory, HasMonitors, SoftDeletes;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'port' => 'integer',
            'status' => Status::class,
            'status_changed_at' => 'datetime',
        ];
    }

    /**
     * @return Attribute<string, string>
     */
    protected function hostname(): Attribute
    {
        return Attribute::set(fn (string $value): string => Hostname::normalize($value));
    }

    /**
     * The hostname in Unicode, for display.
     *
     * @return Attribute<string, never>
     */
    protected function displayHostname(): Attribute
    {
        return Attribute::get(fn (): string => Hostname::display($this->hostname));
    }

    /**
     * `scheme://hostname[:port]`. Paths belong to monitor arguments (SPEC §3.7).
     *
     * @return Attribute<string, never>
     */
    protected function url(): Attribute
    {
        return Attribute::get($this->buildUrl(...));
    }

    private function buildUrl(): string
    {
        return $this->scheme.'://'.$this->hostname.($this->port !== null ? ':'.$this->port : '');
    }

    /**
     * The optional external service in front, then the backend (SPEC §3.7).
     */
    public function servingPath(): ServingPathData
    {
        return new ServingPathData(
            front: $this->frontService?->name,
            backend: $this->server?->name,
        );
    }

    /**
     * @return BelongsTo<Domain, $this>
     */
    public function domain(): BelongsTo
    {
        return $this->belongsTo(Domain::class);
    }

    /**
     * The application this hostname belongs to, if any (SPEC §3.19).
     *
     * @return BelongsTo<Application, $this>
     */
    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    /**
     * The repository of this site's own GitHub link (SPEC §3.21).
     *
     * @return BelongsTo<Repository, $this>
     */
    public function repository(): BelongsTo
    {
        return $this->belongsTo(Repository::class, 'github_url', 'url');
    }

    /** Its own repository, or else its application's. */
    public function effectiveRepository(): ?Repository
    {
        return $this->github_url !== null ? $this->repository : $this->application?->repository;
    }

    /**
     * @return BelongsTo<ExternalService, $this>
     */
    public function frontService(): BelongsTo
    {
        return $this->belongsTo(ExternalService::class, 'front_service_id');
    }

    /**
     * The backend server.
     *
     * @return BelongsTo<Server, $this>
     */
    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    public function resourceName(): string
    {
        return $this->display_hostname;
    }
}
