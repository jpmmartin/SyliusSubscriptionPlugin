<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * When the customer removed each subscription item. A removed item stays, as one that reached its
 * maximum of cycles does, so the cycles that carried it keep showing it.
 */
final class Version20260925140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adds when the customer removed each subscription item.';
    }

    public function up(Schema $schema): void
    {
        $schema->getTable('jpm_martin_sylius_subscription_item')->addColumn('removed_at', 'datetime_immutable', ['notnull' => false]);
    }

    /** Without the column, a removed item would renew again. */
    public function preDown(Schema $schema): void
    {
        $removed = $this->connection->fetchOne('SELECT COUNT(*) FROM jpm_martin_sylius_subscription_item WHERE removed_at IS NOT NULL');

        $this->abortIf(
            is_numeric($removed) && 0 < (int) $removed,
            'Some subscription items were removed by their customers, and would renew again without the removed_at column.',
        );
    }

    public function down(Schema $schema): void
    {
        $schema->getTable('jpm_martin_sylius_subscription_item')->dropColumn('removed_at');
    }
}
