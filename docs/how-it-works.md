# How it works

The package does a few things carefully so that money is handled safely, whichever gateway you use.

- [The life of a payment](#the-life-of-a-payment)
- [No double charges](#no-double-charges)
- [No fake payments](#no-fake-payments)
- [Paid stays paid](#paid-stays-paid)
- [Unclear outcomes stay pending](#unclear-outcomes-stay-pending)
- [Secrets stay secret](#secrets-stay-secret)

## The life of a payment

### USSD push (AzamPay, ClickPesa)

```
Your app                 Package                     Gateway              Customer
   │ TzPay::charge()        │                           │                     │
   ├───────────────────────►│ save as Pending           │                     │
   │                        ├──────── charge ──────────►│                     │
   │◄────── Pending ────────┤                           ├──── USSD prompt ───►│
   │                        │                           │◄──── enters PIN ────┤
   │                        │◄──────── webhook ─────────┤                     │
   │                        │ verify, apply once        │                     │
   │◄── PaymentSucceeded ───┤                           │                     │
```

### Hosted page (Pesapal, ClickPesa checkout and card)

```
Your app                 Package                     Gateway              Customer
   │ TzPay::charge()        │                           │                     │
   ├───────────────────────►│ save as Pending           │                     │
   │                        ├──────── order ───────────►│                     │
   │◄── Pending + URL ──────┤                           │                     │
   ├────────────── redirect to the gateway's page ─────────────────────────►│
   │                        │                           │◄──── pays ──────────┤
   │                        │◄──────── webhook ─────────┤                     │
   │                        │ verify, apply once        │                     │
   │◄── PaymentSucceeded ───┤                           │                     │
```

The payment is saved **before** the gateway is called, so even a very fast webhook always finds it.

## No double charges

- A `reference` that is already **pending** or **paid** cannot be charged again. `DuplicatePaymentException` is thrown, and the gateway is not called.
- Two simultaneous requests for the same reference (a double-click, or two servers) are separated by an atomic cache lock. The second one gets `DuplicatePaymentException`.
- A charge is never retried automatically.

The lock uses your default cache store. In production with more than one server, use a shared store that supports locks: Redis, database or Memcached. If the store cannot lock, the database check still applies, but simultaneous requests are not separated.

## No fake payments

Nothing changes a payment's status unless the package can prove it is true:

| Gateway | How a webhook is proven |
|---|---|
| AzamPay | RSA signature (SHA-256) checked with AzamPay's public key, plus an optional secret token in the URL |
| ClickPesa | HMAC-SHA256 checksum, when a checksum key is configured; otherwise a status lookup |
| Pesapal | Status lookup (Pesapal notifications carry no signature) |

A status lookup is accepted only when its reference, amount and currency match the stored payment. The package always looks up the gateway reference it stored, not one taken from the webhook. Without these checks, a forged webhook could point your order at someone else's paid order.

A webhook that can't be verified is rejected with `403`. Unlike some packages, there is no "skip verification" mode: a missing secret means rejection, never acceptance.

## Paid stays paid

Status changes happen in one place, inside a locked database transaction:

- A final status never changes, except `Succeeded` becoming `Reversed`.
- A duplicate webhook finds the status already applied and changes nothing.
- Events fire only when the status really changed, so `PaymentSucceeded` fires exactly once per payment.

## Unclear outcomes stay pending

When the package can't know whether the gateway received a charge, it leaves the payment **Pending** rather than marking it failed:

| What happened | Status | `GatewayException::$outcomeUnknown` |
|---|---|---|
| The gateway rejected the request (4xx, or an error in the body) | Failed | `false` |
| No token could be obtained, so nothing was sent | Failed | `false` |
| The request timed out after it was sent | **Pending** | `true` |
| The gateway answered with a server error (5xx) | **Pending** | `true` |

A pending payment is settled later by the webhook or `TzPay::status()`. Marking it failed would invite the customer to pay again while the first payment may still go through.

## Secrets stay secret

- Credentials are read only from config and `.env`.
- Error messages and exception data never include keys, tokens or secrets. There are tests for this.
- Secrets in webhook payloads are hidden in the log.
- Signatures, checksums and tokens are compared in constant time (`hash_equals`).
- Access tokens are cached under a key derived from a hash of the account id, never the secret itself.
