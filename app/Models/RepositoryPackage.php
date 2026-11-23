<?php

namespace App\Models;

use App\Enums\Ecosystem;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One locked package version of a repository (SPEC §3.22). Written only by
 * `PackagesRecorder`.
 *
 * @property int $id
 * @property int $repository_id
 * @property Ecosystem $ecosystem
 * @property string $name
 * @property string $version
 * @property bool $dev
 * @property bool $direct
 * @property-read Repository $repository
 */
class RepositoryPackage extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'ecosystem' => Ecosystem::class,
            'dev' => 'boolean',
            'direct' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Repository, $this>
     */
    public function repository(): BelongsTo
    {
        return $this->belongsTo(Repository::class);
    }
}
