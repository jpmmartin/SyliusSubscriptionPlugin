<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\StateMachine;

/**
 * The cycle graph. Cycle 1 is created paid, with the initial order; every other cycle starts scheduled
 * and is open until it is paid, fails or is cancelled. A failed cycle can still be retried.
 */
interface SubscriptionCycleTransitions
{
    public const GRAPH = 'jpm_martin_sylius_subscription_cycle';

    /** A gate asked the cycle to wait: no order is placed meanwhile. */
    public const TRANSITION_HOLD = 'hold';

    /** Every gate passed and the cycle's order was placed, to be charged. */
    public const TRANSITION_PLACE_ORDER = 'place_order';

    public const TRANSITION_PAY = 'pay';

    /** Its charge, its gates or its items could not be settled: the next cycle takes over. */
    public const TRANSITION_FAIL = 'fail';

    /** An administrator charges a failed cycle again, with a new order. */
    public const TRANSITION_RETRY = 'retry';

    /** Its subscription stopped, or an administrator cancelled its order. */
    public const TRANSITION_CANCEL = 'cancel';
}
