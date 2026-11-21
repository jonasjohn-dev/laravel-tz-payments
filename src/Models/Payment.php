<?php

namespace JonasJohn\TzPayments\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use JonasJohn\TzPayments\Data\PaymentResponse;
use JonasJohn\TzPayments\Enums\Operator;
use JonasJohn\TzPayments\Enums\PaymentStatus;

/**
 * @property int $id
 * @property string $gateway
 * @property string $reference
 * @property string $merchant_reference
 * @property string|null $gateway_reference
 * @property PaymentStatus $status
 * @property int $amount
 * @property string $currency
 * @property string|null $phone
 * @property Operator|null $operator
 * @property string|null $description
 * @property string|null $customer_name
 * @property string|null $customer_email
 * @property string|null $redirect_url
 * @property string|null $message
 * @property array<string, mixed>|null $meta
 * @property Carbon|null $paid_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class Payment extends Model
{
    protected $fillable = [
        'gateway',
        'reference',
        'merchant_reference',
        'gateway_reference',
        'status',
        'amount',
        'currency',
        'phone',
        'operator',
        'description',
        'customer_name',
        'customer_email',
        'redirect_url',
        'message',
        'meta',
        'paid_at',
    ];

    protected $attributes = [
        'status' => 'pending',
    ];

    protected $casts = [
        'status' => PaymentStatus::class,
        'operator' => Operator::class,
        'amount' => 'integer',
        'meta' => 'array',
        'paid_at' => 'datetime',
    ];

    public function getTable(): string
    {
        return config('tz-payments.database.table', 'tz_payments');
    }

    public function getConnectionName(): ?string
    {
        return config('tz-payments.database.connection') ?? parent::getConnectionName();
    }

    public function isFinal(): bool
    {
        return $this->status->isFinal();
    }

    /**
     * @param  array<string, mixed>  $raw
     */
    public function toResponse(?string $message = null, array $raw = []): PaymentResponse
    {
        return new PaymentResponse(
            gateway: $this->gateway,
            reference: $this->reference,
            merchantReference: $this->merchant_reference,
            status: $this->status,
            amount: $this->amount,
            currency: $this->currency,
            gatewayReference: $this->gateway_reference,
            redirectUrl: $this->redirect_url,
            message: $message ?? $this->message,
            raw: $raw,
        );
    }
}
