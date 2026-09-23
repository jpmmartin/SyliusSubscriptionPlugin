<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\Entity;

use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Resource\Model\ResourceInterface;

/**
 * A customer's acceptance, on one order, of the recurring-charge consent text: which version, the
 * text they were shown, and when. Kept per order rather than on the order itself so the store's
 * Order entity needs no second trait; each subscription created from the order copies it.
 */
interface SubscriptionConsentInterface extends ResourceInterface
{
    public function getOrder(): ?OrderInterface;

    public function setOrder(?OrderInterface $order): void;

    public function getTextVersion(): ?string;

    public function setTextVersion(?string $textVersion): void;

    public function getText(): ?string;

    public function setText(?string $text): void;

    public function getAcceptedAt(): ?\DateTimeImmutable;

    public function setAcceptedAt(?\DateTimeImmutable $acceptedAt): void;
}
