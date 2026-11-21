<?php

namespace App\Models;

use App\Enums\Software;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One reading of a server's software (SPEC §3.14). Written only by
 * `VersionsRecorder`.
 *
 * @property int $id
 * @property int $server_id
 * @property Software $software
 * @property string $branch
 * @property string $version
 * @property string|null $package_version
 * @property array<string, mixed>|null $details
 * @property CarbonInterface $read_at
 * @property CarbonInterface $fetched_at
 * @property-read Server $server
 */
class ServerSoftware extends Model
{
    protected $table = 'server_software';

    public $timestamps = false;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'software' => Software::class,
            'details' => 'array',
            'read_at' => 'datetime',
            'fetched_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Server, $this>
     */
    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    public function label(): string
    {
        return $this->software->labelWithBranch($this->branch);
    }
}
