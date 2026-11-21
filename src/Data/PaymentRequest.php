<?php

namespace JonasJohn\TzPayments\Data;

use JonasJohn\TzPayments\Enums\Operator;
use JonasJohn\TzPayments\Exceptions\InvalidRequestException;
use JonasJohn\TzPayments\Support\Phone;
use JonasJohn\TzPayments\Support\Reference;

/**
 * A validated request to start one payment attempt.
 */
final class PaymentRequest
{
    /**
     * @param  int  $amount  Whole amount in the currency's main unit (TZS has no decimals in practice).
     * @param  string  $reference  The developer's own reference, such as an order number.
     * @param  string  $merchantReference  The unique alphanumeric reference sent to the gateway.
     * @param  array<string, mixed>  $meta
     */
    public function __construct(
        public readonly int $amount,
        public readonly string $currency,
        public readonly string $reference,
        public readonly string $merchantReference,
        public readonly ?Phone $phone = null,
        public readonly ?Operator $operator = null,
        public readonly ?string $description = null,
        public readonly ?string $customerName = null,
        public readonly ?string $customerEmail = null,
        public readonly ?string $callbackUrl = null,
        public readonly array $meta = [],
    ) {
        if ($amount <= 0) {
            throw InvalidRequestException::invalidAmount();
        }

        if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            throw InvalidRequestException::invalidCurrency($currency);
        }

        if ($reference === '' || strlen($reference) > 100) {
            throw InvalidRequestException::invalidReference();
        }

        if ($customerEmail !== null && filter_var($customerEmail, FILTER_VALIDATE_EMAIL) === false) {
            throw InvalidRequestException::invalidEmail($customerEmail);
        }
    }

    /**
     * Build a request from the array given to TzPay::charge().
     *
     * @param  array<string, mixed>  $data
     *
     * @throws InvalidRequestException
     */
    public static function fromArray(array $data, string $defaultCurrency = 'TZS'): self
    {
        $phone = isset($data['phone']) ? Phone::parse((string) $data['phone']) : null;
        $merchantReference = Reference::generate();

        return new self(
            amount: self::parseAmount($data['amount'] ?? null),
            currency: strtoupper((string) ($data['currency'] ?? $defaultCurrency)),
            reference: isset($data['reference']) ? trim((string) $data['reference']) : $merchantReference,
            merchantReference: $merchantReference,
            phone: $phone,
            operator: self::parseOperator($data['operator'] ?? null) ?? $phone?->operator(),
            description: $data['description'] ?? null,
            customerName: $data['customer_name'] ?? null,
            customerEmail: $data['customer_email'] ?? null,
            callbackUrl: $data['callback_url'] ?? null,
            meta: (array) ($data['meta'] ?? []),
        );
    }

    private static function parseAmount(mixed $amount): int
    {
        if (is_int($amount)) {
            return $amount;
        }

        if (is_string($amount) && ctype_digit($amount)) {
            return (int) $amount;
        }

        throw InvalidRequestException::invalidAmount();
    }

    private static function parseOperator(mixed $operator): ?Operator
    {
        if ($operator === null || $operator instanceof Operator) {
            return $operator;
        }

        return Operator::tryFrom(strtolower((string) $operator))
            ?? throw InvalidRequestException::invalidOperator((string) $operator);
    }
}
