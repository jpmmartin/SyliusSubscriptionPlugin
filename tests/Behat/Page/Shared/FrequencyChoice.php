<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusSubscriptionPlugin\Behat\Page\Shared;

use Behat\Mink\Element\DocumentElement;
use Behat\Mink\Element\NodeElement;

/** The frequencies of the change form, radio buttons read and chosen by their labels, "Every 3 months". */
final class FrequencyChoice
{
    private const FIELD = 'jpm_martin_sylius_subscription_frequency_change[frequency]';

    /** @return list<string> */
    public static function offered(DocumentElement $document): array
    {
        return array_keys(self::radiosByLabel($document));
    }

    public static function choose(DocumentElement $document, string $label): void
    {
        $radio = self::radiosByLabel($document)[$label] ?? null;
        if (null === $radio) {
            throw new \InvalidArgumentException(\sprintf('The frequency "%s" is not offered; these are: %s.', $label, implode(', ', self::offered($document))));
        }

        $document->selectFieldOption(self::FIELD, (string) $radio->getAttribute('value'));
    }

    /** @return array<string, NodeElement> */
    private static function radiosByLabel(DocumentElement $document): array
    {
        $radios = [];
        foreach ($document->findAll('css', \sprintf('input[name="%s"]', self::FIELD)) as $radio) {
            $label = $document->find('css', \sprintf('label[for="%s"]', $radio->getAttribute('id')));
            $radios[trim((string) $label?->getText())] = $radio;
        }

        return $radios;
    }
}
