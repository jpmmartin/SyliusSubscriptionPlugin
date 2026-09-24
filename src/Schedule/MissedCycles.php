<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\Schedule;

/** The choices of the "missed_cycles" option, for the plugin's own missed cycle policy. */
enum MissedCycles: string
{
    /** The late cycle is charged once and the next goes to the first date to come. */
    case Skip = 'skip';

    /** Each passed date is charged, one per run of the cycles command. */
    case Charge = 'charge';

    /** As Skip, and a scheduled cycle whose next date has also passed is cancelled instead of charged. */
    case SkipLate = 'skip_late';
}
