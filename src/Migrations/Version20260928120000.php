<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * An announced price increase on each subscription item, and when its customer accepted it. None is
 * pending before, so the columns start empty.
 */
final class Version20260928120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adds the pending price increases of subscription items and their acceptance.';
    }

    public function up(Schema $schema): void
    {
        $item = $schema->getTable('jpm_martin_sylius_subscription_item');
        $item->addColumn('pending_unit_price', 'integer', ['notnull' => false]);
        $item->addColumn('pending_price_from', 'datetime_immutable', ['notnull' => false]);
        $schema->getTable('jpm_martin_sylius_subscription')->addColumn('price_increase_accepted_at', 'datetime_immutable', ['notnull' => false]);
    }

    /** The increases still pending are lost: wait until they apply, or tell the customers. */
    public function down(Schema $schema): void
    {
        $item = $schema->getTable('jpm_martin_sylius_subscription_item');
        $item->dropColumn('pending_unit_price');
        $item->dropColumn('pending_price_from');
        $schema->getTable('jpm_martin_sylius_subscription')->dropColumn('price_increase_accepted_at');
    }
}
