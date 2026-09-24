<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusSubscriptionPlugin\Integration\Event;

use JpmMartin\SyliusSubscriptionPlugin\Event\RenewalUpcoming;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Tests\JpmMartin\SyliusSubscriptionPlugin\Integration\Lifecycle\LifecycleTestCase;
use Tests\JpmMartin\SyliusSubscriptionPlugin\Readme\TellTheCustomerAboutTheRenewal;

/**
 * The handler the README shows, copied into the test store as it is written there and registered by
 * autoconfiguration, as in a store: it receives the plugin's event and handles it without error.
 */
final class ReadmeExampleHandlerTest extends LifecycleTestCase
{
    public function testTheReadmesHandlerHandlesARenewalUpcoming(): void
    {
        $this->itIsNow('2027-02-01 09:00');
        $this->pay($this->placedCoffeeOrder());
        [$subscription] = $this->storedSubscriptions();
        [, $second] = $this->storedCycles($subscription);

        /** @var MessageBusInterface $eventBus */
        $eventBus = self::getContainer()->get('sylius.event_bus');
        $envelope = $eventBus->dispatch(new RenewalUpcoming((int) $subscription->getId(), (int) $second->getId(), 2, new \DateTimeImmutable('2027-03-01 09:00')));

        $handlers = array_map(static fn (HandledStamp $stamp): string => $stamp->getHandlerName(), $envelope->all(HandledStamp::class));
        self::assertContains(TellTheCustomerAboutTheRenewal::class . '::__invoke', $handlers);
    }

    public function testTheCopyIsTheReadmesExample(): void
    {
        $readme = (string) file_get_contents(__DIR__ . '/../../../README.md');
        $start = (int) strpos($readme, 'A handler, in a store with autoconfiguration:');
        $open = (int) strpos($readme, "```php\n", $start) + \strlen("```php\n");
        $example = substr($readme, $open, (int) strpos($readme, "```\n", $open) - $open);

        $copy = (string) file_get_contents(__DIR__ . '/../../TestApplication/src/Readme/TellTheCustomerAboutTheRenewal.php');

        self::assertSame(
            "<?php\n\ndeclare(strict_types=1);\n\n" . str_replace('namespace App\Subscription;', 'namespace Tests\JpmMartin\SyliusSubscriptionPlugin\Readme;', $example),
            $copy,
            'The test store runs the README\'s handler: copy the README\'s example again.',
        );
    }
}
