<?php

namespace GamePlatform\Ops;

use GamePlatform\Platform;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Carbon;

/**
 * A bug report or suggestion from a player, sent on to GitHub issues.
 *
 * @property int $id
 * @property int $user_id
 * @property int|null $game_id
 * @property string $type
 * @property string $title
 * @property string $body
 * @property array<string, mixed> $meta
 * @property string|null $state
 * @property string $status
 * @property string|null $issue_url
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['user_id', 'game_id', 'type', 'title', 'body', 'meta', 'state', 'status', 'issue_url'])]
#[Hidden(['state'])]
class Feedback extends Model
{
    public const string BUG = 'bug';

    public const string SUGGESTION = 'suggestion';

    public const string PENDING = 'pending';

    public const string SENT = 'sent';

    public const string FAILED = 'failed';

    protected $table = 'feedback';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'meta' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Authenticatable, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(Platform::userModel(), 'user_id');
    }
}
