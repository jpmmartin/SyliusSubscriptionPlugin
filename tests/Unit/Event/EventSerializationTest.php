<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusSubscriptionPlugin\Unit\Event;

use JpmMartin\SyliusSubscriptionPlugin\Event\RenewalCancelled;
use JpmMartin\SyliusSubscriptionPlugin\Event\RenewalChargeDeclined;
use JpmMartin\SyliusSubscriptionPlugin\Event\RenewalFailed;
use JpmMartin\SyliusSubscriptionPlugin\Event\RenewalHeld;
use JpmMartin\SyliusSubscriptionPlugin\Event\RenewalOrderPlaced;
use JpmMartin\SyliusSubscriptionPlugin\Event\RenewalPaid;
use JpmMartin\SyliusSubscriptionPlugin\Event\RenewalRetried;
use JpmMartin\SyliusSubscriptionPlugin\Event\RenewalSkipped;
use JpmMartin\SyliusSubscriptionPlugin\Event\RenewalUpcoming;
use JpmMartin\SyliusSubscriptionPlugin\Event\SubscriptionActivated;
use JpmMartin\SyliusSubscriptionPlugin\Event\SubscriptionAddressChanged;
use JpmMartin\SyliusSubscriptionPlugin\Event\SubscriptionCancelled;
use JpmMartin\SyliusSubscriptionPlugin\Event\SubscriptionCompleted;
use JpmMartin\SyliusSubscriptionPlugin\Event\SubscriptionEventInterface;
use JpmMartin\SyliusSubscriptionPlugin\Event\SubscriptionFrequencyChanged;
use JpmMartin\SyliusSubscriptionPlugin\Event\SubscriptionPaused;
use JpmMartin\SyliusSubscriptionPlugin\Event\SubscriptionReactivated;
use JpmMartin\SyliusSubscriptionPlugin\Event\SubscriptionResumed;
use JpmMartin\SyliusSubscriptionPlugin\Event\SubscriptionSuspended;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;
use Symfony\Component\Messenger\Transport\Serialization\Serializer;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;

/** Every event goes through an asynchronous transport and comes back the same, whichever serializer it uses. */
final class EventSerializationTest extends TestCase
{
    /** @return iterable<string, array{SubscriptionEventInterface}> */
    public static function events(): iterable
    {
        $at = new \DateTimeImmutable('2027-03-01 09:00:00');

        yield 'activated' => [new SubscriptionActivated(7)];
        yield 'paused' => [new SubscriptionPaused(7)];
        yield 'resumed' => [new SubscriptionResumed(7)];
        yield 'suspended' => [new SubscriptionSuspended(7)];
        yield 'reactivated' => [new SubscriptionReactivated(7)];
        yield 'cancelled' => [new SubscriptionCancelled(7)];
        yield 'completed' => [new SubscriptionCompleted(7)];
        yield 'frequency changed' => [new SubscriptionFrequencyChanged(7, 2, 'week')];
        yield 'address changed' => [new SubscriptionAddressChanged(7, true)];
        yield 'upcoming' => [new RenewalUpcoming(7, 11, 2, $at)];
        yield 'held' => [new RenewalHeld(7, 11, 2, $at, 'Waiting for the prescriber.')];
        yield 'held without a date' => [new RenewalHeld(7, 11, 2, null, null)];
        yield 'order placed' => [new RenewalOrderPlaced(7, 11, 2, 13)];
        yield 'charge declined' => [new RenewalChargeDeclined(7, 11, 2, 13, $at, 'Insufficient funds.', 'insufficient_funds')];
        yield 'paid' => [new RenewalPaid(7, 11, 2, 13)];
        yield 'failed' => [new RenewalFailed(7, 11, 2, 13, 'Insufficient funds.')];
        yield 'failed without an order' => [new RenewalFailed(7, 11, 2, null, 'The prescription has expired.')];
        yield 'retried' => [new RenewalRetried(7, 11, 2)];
        yield 'cancelled cycle' => [new RenewalCancelled(7, 11, 2, null, null)];
        yield 'skipped' => [new RenewalSkipped(7, 11, 2, $at, new \DateTimeImmutable('2027-04-01 09:00:00'))];
    }

    #[DataProvider('events')]
    public function testMessengersDefaultSerializerKeepsEveryDatum(SubscriptionEventInterface $event): void
    {
        $this->assertRoundTrip(new PhpSerializer(), $event);
    }

    #[DataProvider('events')]
    public function testSymfonysSerializerKeepsEveryDatum(SubscriptionEventInterface $event): void
    {
        $this->assertRoundTrip(Serializer::create(), $event);
    }

    private function assertRoundTrip(SerializerInterface $serializer, SubscriptionEventInterface $event): void
    {
        $decoded = $serializer->decode($serializer->encode(new Envelope($event)))->getMessage();

        self::assertInstanceOf($event::class, $decoded);
        self::assertEquals($event, $decoded);
        self::assertSame(7, $decoded->getSubscriptionId());
    }
}
