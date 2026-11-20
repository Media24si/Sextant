<?php

namespace App\Models;

use App\Observers\ServerAddressObserver;
use Database\Factories\ServerAddressFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One of a server's IP addresses (SPEC §3.3). Stored in canonical text form;
 * the version follows from the address.
 *
 * @property int $id
 * @property int $server_id
 * @property string $ip
 * @property int $version
 * @property bool $is_primary
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Server $server
 */
#[ObservedBy(ServerAddressObserver::class)]
#[Fillable(['ip', 'is_primary'])]
class ServerAddress extends Model
{
    /** @use HasFactory<ServerAddressFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'is_primary' => 'boolean',
        ];
    }

    /**
     * @return Attribute<string, string>
     */
    protected function ip(): Attribute
    {
        return Attribute::set(function (string $value): array {
            $packed = inet_pton(trim($value));
            $canonical = $packed === false ? trim($value) : (string) inet_ntop($packed);

            return [
                'ip' => $canonical,
                'version' => $packed !== false && strlen($packed) === 16 ? 6 : 4,
            ];
        });
    }

    /**
     * @return BelongsTo<Server, $this>
     */
    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }
}
