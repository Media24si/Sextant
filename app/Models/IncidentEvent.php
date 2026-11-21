<?php

namespace App\Models;

use App\Enums\IncidentEventType;
use App\Enums\Status;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One entry on an incident's timeline, and the notification outbox: events
 * with `notify` are sent by `notifications:flush` (SPEC §3.12, §12.1).
 *
 * @property int $id
 * @property int $incident_id
 * @property IncidentEventType $type
 * @property Status $severity
 * @property string $message
 * @property int|null $user_id
 * @property bool $notify
 * @property CarbonInterface|null $notified_at
 * @property string|null $suppressed_reason
 * @property CarbonInterface $created_at
 * @property-read Incident $incident
 * @property-read User|null $user
 */
class IncidentEvent extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => IncidentEventType::class,
            'severity' => Status::class,
            'notify' => 'boolean',
            'notified_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Incident, $this>
     */
    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }
}
