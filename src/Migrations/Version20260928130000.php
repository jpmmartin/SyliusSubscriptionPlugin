<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The introductory price of plans and frequencies, and the one each subscription item agreed on. None
 * had one before. The next migration gives the terms their one cycle by default and requires it.
 */
final class Version20260928130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adds the introductory prices of subscription plans, frequencies and items.';
    }

    public function up(Schema $schema): void
    {
        foreach (['jpm_martin_sylius_subscription_plan', 'jpm_martin_sylius_subscription_frequency', 'jpm_martin_sylius_subscription_item'] as $table) {
            $schema->getTable($table)->addColumn('introductory_cycles', 'integer', ['notnull' => false]);
        }
        $schema->getTable('jpm_martin_sylius_subscription_plan')->addColumn('introductory_discount_percentage', 'integer', ['notnull' => false]);
        $schema->getTable('jpm_martin_sylius_subscription_frequency')->addColumn('introductory_discount_percentage', 'integer', ['notnull' => false]);
        $schema->getTable('jpm_martin_sylius_subscription_item')->addColumn('introductory_unit_price', 'integer', ['notnull' => false]);
    }

    /** The items still on their introductory price lose it, and renew at their normal price. */
    public function down(Schema $schema): void
    {
        foreach (['jpm_martin_sylius_subscription_plan', 'jpm_martin_sylius_subscription_frequency', 'jpm_martin_sylius_subscription_item'] as $table) {
            $schema->getTable($table)->dropColumn('introductory_cycles');
        }
        $schema->getTable('jpm_martin_sylius_subscription_plan')->dropColumn('introductory_discount_percentage');
        $schema->getTable('jpm_martin_sylius_subscription_frequency')->dropColumn('introductory_discount_percentage');
        $schema->getTable('jpm_martin_sylius_subscription_item')->dropColumn('introductory_unit_price');
    }
}
