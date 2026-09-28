<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** The minimum commitment of plans, frequencies and each subscription item. None had one before. */
final class Version20260928150000 extends AbstractMigration
{
    private const TABLES = ['jpm_martin_sylius_subscription_plan', 'jpm_martin_sylius_subscription_frequency', 'jpm_martin_sylius_subscription_item'];

    public function getDescription(): string
    {
        return 'Adds the minimum commitment of subscription plans, frequencies and items.';
    }

    public function up(Schema $schema): void
    {
        foreach (self::TABLES as $table) {
            $schema->getTable($table)->addColumn('commitment_cycles', 'integer', ['notnull' => false]);
        }
    }

    /** The subscriptions still committed are freed of it: their customers may cancel them at once. */
    public function down(Schema $schema): void
    {
        foreach (self::TABLES as $table) {
            $schema->getTable($table)->dropColumn('commitment_cycles');
        }
    }
}
