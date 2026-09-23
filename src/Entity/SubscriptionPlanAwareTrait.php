<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * The implementation of SubscriptionPlanAwareInterface for the store's OrderItem.
 *
 * Mapped with attributes, the way a Sylius 2 application maps its own entities, so using the trait
 * is the whole installation step. Deleting a plan or a frequency that an order line points at is
 * refused by the database: disable it instead.
 */
trait SubscriptionPlanAwareTrait
{
    #[ORM\ManyToOne(targetEntity: SubscriptionPlanInterface::class)]
    #[ORM\JoinColumn(name: 'subscription_plan_id', referencedColumnName: 'id', nullable: true)]
    protected ?SubscriptionPlanInterface $subscriptionPlan = null;

    #[ORM\ManyToOne(targetEntity: SubscriptionFrequencyInterface::class)]
    #[ORM\JoinColumn(name: 'subscription_frequency_id', referencedColumnName: 'id', nullable: true)]
    protected ?SubscriptionFrequencyInterface $subscriptionFrequency = null;

    public function getSubscriptionPlan(): ?SubscriptionPlanInterface
    {
        return $this->subscriptionPlan;
    }

    public function setSubscriptionPlan(?SubscriptionPlanInterface $subscriptionPlan): void
    {
        $this->subscriptionPlan = $subscriptionPlan;
    }

    public function getSubscriptionFrequency(): ?SubscriptionFrequencyInterface
    {
        return $this->subscriptionFrequency;
    }

    public function setSubscriptionFrequency(?SubscriptionFrequencyInterface $subscriptionFrequency): void
    {
        $this->subscriptionFrequency = $subscriptionFrequency;
    }

    public function getSubscriptionTerms(): ?SubscriptionTermsInterface
    {
        return $this->subscriptionPlan ?? $this->subscriptionFrequency;
    }
}
