<?php

namespace App\Logging;

final class LogMessage
{
    /* ============================
     | CHECKOUT LIFECYCLE
     |============================ */

    public const CHECKOUT_CREATED = 'CHECKOUT_SESSION_CREATED';

    public const CHECKOUT_STOCK_CONFIRMED = 'CHECKOUT_STOCK_CONFIRMED';

    public const CHECKOUT_MOVED_TO_PENDING_PAYMENT = 'CHECKOUT_MOVED_TO_PENDING_PAYMENT';

    public const CHECKOUT_PAYMENT_CONFIRMED = 'CHECKOUT_PAYMENT_CONFIRMED';

    public const CHECKOUT_PAYMENT_FAILED = 'CHECKOUT_PAYMENT_FAILED';

    /* ============================
     | FAILURE / REJECTION
     |============================ */

    public const CHECKOUT_REJECTED_STOCK_FAILED = 'CHECKOUT_REJECTED_STOCK_FAILED';

    /* ============================
     | SAFETY / IDEMPOTENCY
     |============================ */

    public const CHECKOUT_STATE_STOCK_IGNORED = 'CHECKOUT_STATE_STOCK_IGNORED';

    public const CHECKOUT_STATE_TRANSITION_IGNORED = 'CHECKOUT_STATE_TRANSITION_IGNORED';

    public const CHECKOUT_REJECT_IGNORED_INVALID_STATE = 'CHECKOUT_REJECT_IGNORED_INVALID_STATE';
}
