<?php

namespace JonasJohn\TzPayments\Enums;

enum Capability: string
{
    /** The customer approves a USSD prompt on their phone. */
    case UssdPush = 'ussd_push';

    /** The customer is redirected to a payment page hosted by the gateway. */
    case HostedCheckout = 'hosted_checkout';

    /** The gateway can be asked for the current status of a payment. */
    case StatusLookup = 'status_lookup';
}
