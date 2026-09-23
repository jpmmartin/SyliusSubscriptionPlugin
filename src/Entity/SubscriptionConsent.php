<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\Entity;

use Sylius\Component\Core\Model\OrderInterface;

class SubscriptionConsent implements SubscriptionConsentInterface
{
    protected ?int $id = null;

    protected ?OrderInterface $order = null;

    protected ?string $textVersion = null;

    protected ?string $text = null;

    protected ?\DateTimeImmutable $acceptedAt = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getOrder(): ?OrderInterface
    {
        return $this->order;
    }

    public function setOrder(?OrderInterface $order): void
    {
        $this->order = $order;
    }

    public function getTextVersion(): ?string
    {
        return $this->textVersion;
    }

    public function setTextVersion(?string $textVersion): void
    {
        $this->textVersion = $textVersion;
    }

    public function getText(): ?string
    {
        return $this->text;
    }

    public function setText(?string $text): void
    {
        $this->text = $text;
    }

    public function getAcceptedAt(): ?\DateTimeImmutable
    {
        return $this->acceptedAt;
    }

    public function setAcceptedAt(?\DateTimeImmutable $acceptedAt): void
    {
        $this->acceptedAt = $acceptedAt;
    }
}
