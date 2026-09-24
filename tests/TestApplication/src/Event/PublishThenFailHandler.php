<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusSubscriptionPlugin\Event;

use JpmMartin\SyliusSubscriptionPlugin\Event\EventPublisher;

final class PublishThenFailHandler
{
    public function __construct(private readonly EventPublisher $publisher)
    {
    }

    public function __invoke(PublishThenFail $command): void
    {
        $this->publisher->publish($command->event);

        throw new \RuntimeException('The change could not be stored.');
    }
}
