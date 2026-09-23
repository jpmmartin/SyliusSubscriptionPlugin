<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusSubscriptionPlugin\Payment;

/**
 * The test store's card gateway, behind the "scripted" gateway factory. It approves every request
 * until a test scripts its next answers, and counts the requests it was sent.
 */
final class ScriptedGateway
{
    public const APPROVE = 'approve';

    public const DECLINE = 'decline';

    /** The gateway never answers: the payment stays as it was. */
    public const NO_ANSWER = 'no_answer';

    /** @var list<array{string, ?string, ?string}> */
    private array $answers = [];

    /** @var list<string> the action of every request it was sent, in order */
    private array $requests = [];

    public function willAnswer(string $answer, ?string $reason = null, ?string $code = null): void
    {
        $this->answers[] = [$answer, $reason, $code];
    }

    /** @return array{string, ?string, ?string} the answer, the issuer's reason and its code */
    public function answer(string $action): array
    {
        $this->requests[] = $action;

        return array_shift($this->answers) ?? [self::APPROVE, null, null];
    }

    /** @return list<string> */
    public function requests(): array
    {
        return $this->requests;
    }
}
