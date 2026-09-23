<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusSubscriptionPlugin\Gate;

use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionCycleInterface;
use JpmMartin\SyliusSubscriptionPlugin\Gate\CycleGateInterface;
use JpmMartin\SyliusSubscriptionPlugin\Gate\GateDecision;

/** The test store's only gate: it lets every cycle through until a test tells it otherwise. */
final class ScriptedCycleGate implements CycleGateInterface
{
    private GateDecision $decision;

    public function __construct()
    {
        $this->decision = GateDecision::pass();
    }

    public function decide(GateDecision $decision): void
    {
        $this->decision = $decision;
    }

    public function check(SubscriptionCycleInterface $cycle): GateDecision
    {
        return $this->decision;
    }
}
