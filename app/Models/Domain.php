<?php

namespace App\Models;

use App\Enums\Status;
use App\Support\Hostname;
use Database\Factories\DomainFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * A registrable domain we renew, e.g. svet24.si (SPEC §3.6).
 *
 * @property int $id
 * @property string $name
 * @property Carbon|null $expires_at
 * @property string|null $registrar
 * @property string|null $notes
 * @property Status $status
 * @property Carbon|null $status_changed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read string $display_name
 */
#[Fillable(['name', 'notes'])]
class Domain extends Model
{
    /** @use HasFactory<DomainFactory> */
    use HasFactory, SoftDeletes;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expires_at' => 'date',
            'status' => Status::class,
            'status_changed_at' => 'datetime',
        ];
    }

    /**
     * @return Attribute<string, string>
     */
    protected function name(): Attribute
    {
        return Attribute::set(fn (string $value): string => Hostname::normalize($value));
    }

    /**
     * The name in Unicode, for display.
     *
     * @return Attribute<string, never>
     */
    protected function displayName(): Attribute
    {
        return Attribute::get(fn (): string => Hostname::display($this->name));
    }

    /**
     * @return HasMany<Site, $this>
     */
    public function sites(): HasMany
    {
        return $this->hasMany(Site::class);
    }

    /**
     * A domain with live sites can't be deleted (SPEC §3.7).
     */
    public function hasLiveSites(): bool
    {
        return $this->sites()->exists();
    }
}
