<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusSubscriptionPlugin\Integration\Plan;

use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionPlanInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Tests\JpmMartin\SyliusSubscriptionPlugin\Integration\Lifecycle\LifecycleTestCase;

/** The rules the admin form applies to the introductory price of a plan, in the plugin's validation group. */
final class SubscriptionPlanValidationTest extends LifecycleTestCase
{
    public function testAPlanWithoutAnIntroductoryPriceIsValid(): void
    {
        $plan = $this->coffeeMonthly;

        self::assertNull($plan->getIntroductoryDiscountPercentage());
        self::assertSame(1, $plan->getIntroductoryCycles());
        self::assertSame([], $this->violationsOf($plan));
    }

    public function testItsIntroductoryDiscountIsFromZeroToAHundredAndLastsOneCycleAtLeast(): void
    {
        $plan = $this->coffeeMonthly;
        $plan->setIntroductoryDiscountPercentage(-1);
        $plan->setIntroductoryCycles(0);

        self::assertSame([
            'introductoryCycles' => 'jpm_martin_sylius_subscription.subscription_plan.introductory_cycles.positive',
            'introductoryDiscountPercentage' => 'jpm_martin_sylius_subscription.subscription_plan.introductory_discount_percentage.range',
        ], $this->violationsOf($plan));

        $plan->setIntroductoryDiscountPercentage(0);
        $plan->setIntroductoryCycles(3);
        self::assertSame([], $this->violationsOf($plan));
    }

    public function testItsFreeTrialLastsOneDayAtLeastAndIsNeverCombinedWithAnIntroductoryPrice(): void
    {
        $plan = $this->coffeeMonthly;
        $plan->setTrialDays(0);
        self::assertSame(['trialDays' => 'jpm_martin_sylius_subscription.subscription_plan.trial_days.positive'], $this->violationsOf($plan));

        $plan->setTrialDays(14);
        self::assertSame([], $this->violationsOf($plan));

        $plan->setIntroductoryDiscountPercentage(50);
        self::assertSame(['trialDays' => 'jpm_martin_sylius_subscription.subscription_plan.trial_days.not_with_introductory_price'], $this->violationsOf($plan));
    }

    public function testItsMinimumCommitmentIsOneCycleAtLeastAndNoLongerThanItsMaximum(): void
    {
        $plan = $this->coffeeMonthly;
        $plan->setCommitmentCycles(0);
        self::assertSame(['commitmentCycles' => 'jpm_martin_sylius_subscription.subscription_plan.commitment_cycles.positive'], $this->violationsOf($plan));

        $plan->setCommitmentCycles(6);
        $plan->setMaxCycles(4);
        self::assertSame(['commitmentCycles' => 'jpm_martin_sylius_subscription.subscription_plan.commitment_cycles.above_max_cycles'], $this->violationsOf($plan));

        $plan->setMaxCycles(6);
        self::assertSame([], $this->violationsOf($plan));
    }

    public function testItsDeliveriesPerChargeAreOneAtLeastAndNeverCombinedWithAnIntroductoryOffer(): void
    {
        $plan = $this->coffeeMonthly;
        self::assertSame(1, $plan->getDeliveriesPerCharge());
        $plan->setDeliveriesPerCharge(0);
        self::assertSame(['deliveriesPerCharge' => 'jpm_martin_sylius_subscription.subscription_plan.deliveries_per_charge.positive'], $this->violationsOf($plan));

        $plan->setDeliveriesPerCharge(3);
        self::assertSame([], $this->violationsOf($plan));

        $plan->setTrialDays(14);
        self::assertSame(['deliveriesPerCharge' => 'jpm_martin_sylius_subscription.subscription_plan.deliveries_per_charge.not_with_introductory_offer'], $this->violationsOf($plan));

        $plan->setTrialDays(null);
        $plan->setIntroductoryDiscountPercentage(50);
        self::assertSame(['deliveriesPerCharge' => 'jpm_martin_sylius_subscription.subscription_plan.deliveries_per_charge.not_with_introductory_offer'], $this->violationsOf($plan));
    }

    public function testItsMaximumOfCyclesIsAWholeNumberOfBlocks(): void
    {
        $plan = $this->coffeeMonthly;
        $plan->setDeliveriesPerCharge(3);
        $plan->setMaxCycles(4);
        self::assertSame(['maxCycles' => 'jpm_martin_sylius_subscription.subscription_plan.max_cycles.whole_blocks'], $this->violationsOf($plan));

        $plan->setMaxCycles(6);
        self::assertSame([], $this->violationsOf($plan));
    }

    /** @return array<string, string> the message template of each violation, by property */
    private function violationsOf(SubscriptionPlanInterface $plan): array
    {
        /** @var ValidatorInterface $validator */
        $validator = self::getContainer()->get('validator');
        $violations = [];
        foreach ($validator->validate($plan, null, ['jpm_martin_sylius_subscription']) as $violation) {
            $violations[$violation->getPropertyPath()] = $violation->getMessageTemplate();
        }
        ksort($violations);

        return $violations;
    }
}
