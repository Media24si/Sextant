<?php

namespace App\Models;

use App\Observers\GithubTokenObserver;
use Database\Factories\GithubTokenFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A read-only fine-grained GitHub token for one owner (an organisation or a
 * user), used to read lock files (SPEC §3.20, §11.9). Encrypted, hidden from
 * serialisation and never sent to the browser: only the last four characters
 * are shown.
 *
 * @property int $id
 * @property string $owner
 * @property string $token
 * @property string $token_last4
 * @property Carbon|null $expires_at
 * @property Carbon|null $checked_at
 * @property string|null $last_error
 * @property int $set_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $setBy
 */
#[ObservedBy(GithubTokenObserver::class)]
#[Fillable(['owner'])]
#[Hidden(['token'])]
class GithubToken extends Model
{
    /** @use HasFactory<GithubTokenFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'token' => 'encrypted',
            'expires_at' => 'datetime',
            'checked_at' => 'datetime',
        ];
    }

    /** The token for an owner; GitHub owners are case-insensitive, and so is the column. */
    public static function forOwner(string $owner): ?self
    {
        return self::query()->where('owner', $owner)->first();
    }

    /** Store a new token value and its last four characters. */
    public function setToken(string $token): void
    {
        $this->token = $token;
        $this->token_last4 = substr($token, -4);
        $this->expires_at = null;
        $this->checked_at = null;
        $this->last_error = null;
    }

    public function masked(): string
    {
        return 'github_pat_••••'.$this->token_last4;
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function setBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'set_by')->withTrashed();
    }
}
