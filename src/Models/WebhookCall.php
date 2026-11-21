<?php

namespace JonasJohn\TzPayments\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use JonasJohn\TzPayments\Support\ModelResolver;

/**
 * A log of every webhook received, kept for debugging and auditing.
 * Old rows are removed by `php artisan model:prune --model="JonasJohn\TzPayments\Models\WebhookCall"`.
 *
 * @property int $id
 * @property string $gateway
 * @property int|null $payment_id
 * @property string $status One of the STATUS_* constants.
 * @property array<string, mixed>|null $payload
 * @property string|null $message
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class WebhookCall extends Model
{
    use MassPrunable;

    /** Received, not yet handled. */
    public const STATUS_RECEIVED = 'received';

    /** Verified and applied to a payment (even if the status did not change). */
    public const STATUS_PROCESSED = 'processed';

    /** Readable, but for a payment this app does not know. */
    public const STATUS_IGNORED = 'ignored';

    /** Unreadable or not authentic; answered with a 4xx. */
    public const STATUS_REJECTED = 'rejected';

    /** The gateway could not be reached to confirm it; answered with a 5xx so it retries. */
    public const STATUS_FAILED = 'failed';

    protected $fillable = ['gateway', 'payment_id', 'status', 'payload', 'message'];

    protected $casts = [
        'payload' => 'array',
    ];

    public function getTable(): string
    {
        return config('tz-payments.database.webhook_calls_table', 'tz_webhook_calls');
    }

    public function getConnectionName(): ?string
    {
        return config('tz-payments.database.connection') ?? parent::getConnectionName();
    }

    /**
     * @return BelongsTo<Payment, $this>
     */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(ModelResolver::paymentModel());
    }

    /**
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        $days = (int) config('tz-payments.webhooks.log_retention_days', 30);

        return static::query()->where('created_at', '<', now()->subDays($days));
    }
}
