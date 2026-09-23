<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\StateMachine;

/** The subscription graph, applied through Sylius's state machine abstraction like the core graphs. */
interface SubscriptionTransitions
{
    public const GRAPH = 'jpm_martin_sylius_subscription';

    /** Pending to active, once the initial order is paid. */
    public const TRANSITION_ACTIVATE = 'activate';

    public const TRANSITION_SUSPEND = 'suspend';

    /** Suspended to active. */
    public const TRANSITION_REACTIVATE = 'reactivate';

    /** From any state that is not final. */
    public const TRANSITION_CANCEL = 'cancel';

    /** Active to completed, once the plan's last cycle is paid. */
    public const TRANSITION_COMPLETE = 'complete';
}
