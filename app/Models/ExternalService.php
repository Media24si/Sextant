<?php

namespace App\Models;

use App\Enums\ExternalServiceKind;
use App\Enums\RangeSource;
use Database\Factories\ExternalServiceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * A service in front of our servers that we don't run, e.g. Cloudflare or
 * the Varnish server (SPEC §3.5). Sextant never checks it directly.
 *
 * @property int $id
 * @property string $name
 * @property ExternalServiceKind $kind
 * @property list<string> $ranges
 * @property RangeSource|null $ranges_source
 * @property list<string>|null $cname_suffixes
 * @property list<string>|null $response_headers
 * @property string|null $notes
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
#[Fillable(['name', 'kind', 'ranges', 'ranges_source', 'cname_suffixes', 'response_headers', 'notes'])]
class ExternalService extends Model
{
    /** @use HasFactory<ExternalServiceFactory> */
    use HasFactory, SoftDeletes;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => ExternalServiceKind::class,
            'ranges' => 'array',
            'ranges_source' => RangeSource::class,
            'cname_suffixes' => 'array',
            'response_headers' => 'array',
        ];
    }

    /**
     * Sites this service is in front of (SPEC §3.7).
     *
     * @return HasMany<Site, $this>
     */
    public function sites(): HasMany
    {
        return $this->hasMany(Site::class, 'front_service_id');
    }

    /**
     * A service used by live sites can't be deleted (SPEC §3.5).
     */
    public function isInUse(): bool
    {
        return $this->sites()->exists();
    }
}
