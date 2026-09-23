<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusSubscriptionPlugin\Behat\Context\Domain;

use Behat\Behat\Context\Context;
use Behat\Step\Then;
use Doctrine\ORM\EntityManagerInterface;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionConsentInterface;
use Sylius\Resource\Doctrine\Persistence\RepositoryInterface;
use Webmozart\Assert\Assert;

/** What was stored when the consent was accepted, the same whichever way the order was placed. */
final class SubscriptionConsentContext implements Context
{
    /** @param RepositoryInterface<SubscriptionConsentInterface> $consentRepository */
    public function __construct(
        private readonly RepositoryInterface $consentRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Then('/^the recurring charges I accepted on my order should be recorded as version "([^"]+)"$/')]
    public function theRecurringChargesIAcceptedShouldBeRecordedAsVersion(string $version): void
    {
        $this->entityManager->clear();
        $consents = $this->consentRepository->findAll();
        Assert::count($consents, 1);

        $consent = $consents[0];
        Assert::isInstanceOf($consent, SubscriptionConsentInterface::class);
        Assert::same($consent->getTextVersion(), $version);
        Assert::startsWith((string) $consent->getText(), 'I authorise the store to charge my payment method');
        Assert::notNull($consent->getAcceptedAt());
        Assert::notNull($consent->getOrder()?->getNumber(), 'The order the consent belongs to was not placed.');
    }
}
