<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\Consent;

use Doctrine\Persistence\ObjectManager;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionConsentInterface;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionInterface;
use Psr\Clock\ClockInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Resource\Doctrine\Persistence\RepositoryInterface;
use Sylius\Resource\Factory\FactoryInterface;
use Symfony\Contracts\Service\ResetInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Webmozart\Assert\Assert;

/**
 * The text is stored as the customer was shown it, in the order's language, so the record stays
 * true when the translation is later reworded. An acceptance made during the current request is
 * remembered here too, because the checkout form records it before the order is validated and
 * nothing has been flushed by then. That memory is forgotten between requests, so a long-running
 * worker does not keep every order it has seen.
 */
final class SubscriptionConsentRecorder implements SubscriptionConsentRecorderInterface, ResetInterface
{
    public const TEXT_KEY = 'jpm_martin_sylius_subscription.consent.text';

    /** @var \WeakMap<OrderInterface, SubscriptionConsentInterface> */
    private \WeakMap $recordedNow;

    /**
     * @param FactoryInterface<SubscriptionConsentInterface> $consentFactory
     * @param RepositoryInterface<SubscriptionConsentInterface> $consentRepository
     */
    public function __construct(
        private readonly FactoryInterface $consentFactory,
        private readonly RepositoryInterface $consentRepository,
        private readonly ObjectManager $consentManager,
        private readonly TranslatorInterface $translator,
        private readonly ClockInterface $clock,
        private readonly string $currentVersion,
    ) {
        $this->recordedNow = new \WeakMap();
    }

    public function record(OrderInterface $order): SubscriptionConsentInterface
    {
        $consent = $this->findFor($order);
        if (null === $consent) {
            $consent = $this->consentFactory->createNew();
            Assert::isInstanceOf($consent, SubscriptionConsentInterface::class);
            $consent->setOrder($order);
        }

        $consent->setTextVersion($this->currentVersion);
        $consent->setText($this->translator->trans(self::TEXT_KEY, [], 'messages', $order->getLocaleCode()));
        $consent->setAcceptedAt($this->clock->now());

        $this->consentManager->persist($consent);
        $this->recordedNow[$order] = $consent;

        return $consent;
    }

    public function isGivenFor(OrderInterface $order): bool
    {
        return $this->currentVersion === $this->findFor($order)?->getTextVersion();
    }

    public function findFor(OrderInterface $order): ?SubscriptionConsentInterface
    {
        if (isset($this->recordedNow[$order])) {
            return $this->recordedNow[$order];
        }

        if (null === $order->getId()) {
            return null;
        }

        $consent = $this->consentRepository->findOneBy(['order' => $order]);

        return $consent instanceof SubscriptionConsentInterface ? $consent : null;
    }

    public function recordOnSubscription(SubscriptionInterface $subscription, string $localeCode): void
    {
        $subscription->setConsentVersion($this->currentVersion);
        $subscription->setConsentText($this->translator->trans(self::TEXT_KEY, [], 'messages', $localeCode));
        $subscription->setConsentAcceptedAt($this->clock->now());
    }

    public function reset(): void
    {
        $this->recordedNow = new \WeakMap();
    }
}
