<?php

namespace GamePlatform\Billing;

use GamePlatform\Platform;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Carbon;

/**
 * A one-off payment for play-time (see config/billing.php).
 *
 * @property int $id
 * @property int $user_id
 * @property string $product
 * @property int $amount
 * @property string $currency
 * @property string|null $stripe_session_id
 * @property string $status
 * @property string $terms_version
 * @property Carbon|null $paid_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['user_id', 'product', 'amount', 'currency', 'stripe_session_id', 'status', 'terms_version', 'paid_at'])]
class Purchase extends Model
{
    public const string PENDING = 'pending';

    public const string PAID = 'paid';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'paid_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Authenticatable, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(Platform::userModel(), 'user_id');
    }

    /** The product's name, as it was on sale. */
    public function productName(): string
    {
        return (string) config("billing.products.{$this->product}.name", $this->product);
    }
}
