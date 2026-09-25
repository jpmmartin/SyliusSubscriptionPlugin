<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * First half: whether each cycle was skipped, nullable until the next migration fills it in. Doctrine
 * runs the statements added with addSql() before the SQL of the schema changes, so the column has to
 * exist before a migration can fill it.
 */
final class Version20260925120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adds whether each subscription cycle was skipped by its customer.';
    }

    public function up(Schema $schema): void
    {
        $schema->getTable('jpm_martin_sylius_subscription_cycle')->addColumn('skipped', 'boolean', ['notnull' => false]);
    }

    public function down(Schema $schema): void
    {
        $schema->getTable('jpm_martin_sylius_subscription_cycle')->dropColumn('skipped');
    }
}
