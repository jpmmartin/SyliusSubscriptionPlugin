<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusSubscriptionPlugin\Integration\Frequency;

use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionFrequencyInterface;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionIntervalUnit;
use Sylius\Resource\Factory\FactoryInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Tests\JpmMartin\SyliusSubscriptionPlugin\Integration\Lifecycle\LifecycleTestCase;

/** The rules the admin form applies to a frequency, in the plugin's validation group. */
final class SubscriptionFrequencyValidationTest extends LifecycleTestCase
{
    public function testAFrequencyWithACodeANameAnIntervalADiscountAndAChannelIsValid(): void
    {
        self::assertSame([], $this->violationsOf($this->frequency('MONTHLY')));
    }

    public function testItsCodeIsUnique(): void
    {
        $this->entityManager()->persist($this->frequency('MONTHLY'));
        $this->entityManager()->flush();

        self::assertSame(['code' => 'jpm_martin_sylius_subscription.subscription_frequency.code.unique'], $this->violationsOf($this->frequency('MONTHLY')));
    }

    public function testItsDiscountIsFromZeroToAHundredAndItsCyclesAtLeastOne(): void
    {
        $frequency = $this->frequency('MONTHLY');
        $frequency->setDiscountPercentage(101);
        $frequency->setMaxCycles(0);

        self::assertSame([
            'discountPercentage' => 'jpm_martin_sylius_subscription.subscription_frequency.discount_percentage.range',
            'maxCycles' => 'jpm_martin_sylius_subscription.subscription_frequency.max_cycles.positive',
        ], $this->violationsOf($frequency));
    }

    public function testItIsOfferedInOneChannelAtLeast(): void
    {
        $frequency = $this->frequency('MONTHLY');
        $frequency->removeChannel($this->channel);

        self::assertSame(['channels' => 'jpm_martin_sylius_subscription.subscription_frequency.channels.min'], $this->violationsOf($frequency));
    }

    private function frequency(string $code): SubscriptionFrequencyInterface
    {
        /** @var FactoryInterface<SubscriptionFrequencyInterface> $factory */
        $factory = self::getContainer()->get('jpm_martin_sylius_subscription.factory.subscription_frequency');
        $frequency = $factory->createNew();
        $frequency->setCode($code);
        $frequency->setName('Every month');
        $frequency->setIntervalCount(1);
        $frequency->setIntervalUnit(SubscriptionIntervalUnit::Month);
        $frequency->setDiscountPercentage(5);
        $frequency->addChannel($this->channel);

        return $frequency;
    }

    /** @return array<string, string> the message template of each violation, by property */
    private function violationsOf(SubscriptionFrequencyInterface $frequency): array
    {
        /** @var ValidatorInterface $validator */
        $validator = self::getContainer()->get('validator');
        $violations = [];
        foreach ($validator->validate($frequency, null, ['jpm_martin_sylius_subscription']) as $violation) {
            $violations[$violation->getPropertyPath()] = $violation->getMessageTemplate();
        }
        ksort($violations);

        return $violations;
    }
}
