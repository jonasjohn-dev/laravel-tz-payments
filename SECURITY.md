# Security Policy

This package handles payments, so security reports are taken seriously.

## Reporting a vulnerability

**Please do not open a public GitHub issue for security problems.**

Report it privately through GitHub instead:

1. Go to the [Security tab](https://github.com/jonasjohn-dev/laravel-tz-payments/security) of this repository.
2. Click **Report a vulnerability**.
3. Describe the problem, how to reproduce it, and which versions are affected.

You should get a first reply within 7 days. Once the problem is confirmed, a fix will be released as soon as possible and you will be credited, unless you prefer not to be.

## What counts as a security problem

For example:

- A webhook that could mark a payment as paid without real confirmation from the gateway.
- A payment that could be marked paid twice, or a final status that could be changed.
- API keys, tokens or secrets appearing in logs, exceptions or responses.
- Signature or checksum verification that can be bypassed.

## Supported versions

Until v1.0, only the latest release receives security fixes.
