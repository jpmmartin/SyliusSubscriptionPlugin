<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusSubscriptionPlugin\Unit\Consent;

use Doctrine\Persistence\ObjectManager;
use JpmMartin\SyliusSubscriptionPlugin\Consent\SubscriptionConsentRecorder;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionConsent;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionConsentInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Sylius\Component\Core\Model\Order;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Resource\Doctrine\Persistence\RepositoryInterface;
use Sylius\Resource\Factory\FactoryInterface;
use Symfony\Component\Clock\MockClock;
use Symfony\Contracts\Translation\TranslatorInterface;

final class SubscriptionConsentRecorderTest extends TestCase
{
    /** @var FactoryInterface<SubscriptionConsentInterface>&MockObject */
    private FactoryInterface&MockObject $factory;

    /** @var RepositoryInterface<SubscriptionConsentInterface>&MockObject */
    private RepositoryInterface&MockObject $repository;

    private ObjectManager&MockObject $manager;

    private TranslatorInterface&MockObject $translator;

    private MockClock $clock;

    protected function setUp(): void
    {
        $this->factory = $this->createMock(FactoryInterface::class);
        $this->factory->method('createNew')->willReturnCallback(static fn (): SubscriptionConsent => new SubscriptionConsent());
        $this->repository = $this->createMock(RepositoryInterface::class);
        $this->manager = $this->createMock(ObjectManager::class);
        $this->translator = $this->createMock(TranslatorInterface::class);
        $this->translator->method('trans')->willReturnCallback(
            static fn (string $key, array $parameters, string $domain, string $locale): string => \sprintf('%s in %s', $key, $locale),
        );
        $this->clock = new MockClock('2026-09-22 10:00:00');
    }

    public function testAnAcceptanceIsRecordedWithTheCurrentVersionTheTextInTheOrdersLanguageAndTheDate(): void
    {
        $order = $this->unsavedOrder('es_ES');

        $this->manager->expects(self::once())->method('persist')->with(self::isInstanceOf(SubscriptionConsentInterface::class));
        $consent = $this->recorder('2')->record($order);

        self::assertSame($order, $consent->getOrder());
        self::assertSame('2', $consent->getTextVersion());
        self::assertSame('jpm_martin_sylius_subscription.consent.text in es_ES', $consent->getText());
        self::assertEquals(new \DateTimeImmutable('2026-09-22 10:00:00'), $consent->getAcceptedAt());
    }

    public function testAnAcceptanceCountsBeforeItIsFlushed(): void
    {
        $recorder = $this->recorder('1');
        $order = $this->unsavedOrder();

        self::assertFalse($recorder->isGivenFor($order));

        $recorder->record($order);

        self::assertTrue($recorder->isGivenFor($order));
    }

    public function testAcceptingAgainUpdatesTheSameConsent(): void
    {
        $recorder = $this->recorder('1');
        $order = $this->unsavedOrder();

        $this->factory->expects(self::once())->method('createNew');
        $first = $recorder->record($order);
        $this->clock->modify('+5 minutes');
        $second = $recorder->record($order);

        self::assertSame($first, $second);
        self::assertEquals(new \DateTimeImmutable('2026-09-22 10:05:00'), $second->getAcceptedAt());
    }

    public function testAnAcceptanceOnOneOrderDoesNotCountForAnother(): void
    {
        $recorder = $this->recorder('1');

        $recorder->record($this->unsavedOrder());

        self::assertFalse($recorder->isGivenFor($this->unsavedOrder()));
    }

    public function testAnUnflushedAcceptanceIsForgottenBetweenRequests(): void
    {
        $recorder = $this->recorder('1');
        $order = $this->unsavedOrder();
        $recorder->record($order);

        $recorder->reset();

        self::assertFalse($recorder->isGivenFor($order));
    }

    public function testAStoredAcceptanceCountsOnlyForTheCurrentVersion(): void
    {
        $order = $this->createMock(OrderInterface::class);
        $order->method('getId')->willReturn(7);
        $stored = new SubscriptionConsent();
        $stored->setTextVersion('1');
        $this->repository->method('findOneBy')->with(['order' => $order])->willReturn($stored);

        self::assertTrue($this->recorder('1')->isGivenFor($order));
        self::assertFalse($this->recorder('2')->isGivenFor($order));
    }

    private function recorder(string $currentVersion): SubscriptionConsentRecorder
    {
        return new SubscriptionConsentRecorder($this->factory, $this->repository, $this->manager, $this->translator, $this->clock, $currentVersion);
    }

    private function unsavedOrder(string $localeCode = 'en_US'): Order
    {
        $order = new Order();
        $order->setLocaleCode($localeCode);

        return $order;
    }
}
