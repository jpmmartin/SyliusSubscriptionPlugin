<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Prepaid deliveries: how many each charge of a plan or a frequency pays for, the deliveries a
 * subscription has paid for and not received, whether its cancellation waits for them, and whether a
 * cycle charges. The next migration fills them and requires them.
 */
final class Version20260928160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adds the prepaid deliveries of subscription plans, frequencies, subscriptions and cycles.';
    }

    public function up(Schema $schema): void
    {
        foreach (['jpm_martin_sylius_subscription_plan', 'jpm_martin_sylius_subscription_frequency'] as $table) {
            $schema->getTable($table)->addColumn('deliveries_per_charge', 'integer', ['notnull' => false]);
        }
        $subscription = $schema->getTable('jpm_martin_sylius_subscription');
        $subscription->addColumn('prepaid_deliveries_left', 'integer', ['notnull' => false]);
        $subscription->addColumn('cancels_after_prepaid_deliveries', 'boolean', ['notnull' => false]);
        $schema->getTable('jpm_martin_sylius_subscription_cycle')->addColumn('charging', 'boolean', ['notnull' => false]);
    }

    public function down(Schema $schema): void
    {
        foreach (['jpm_martin_sylius_subscription_plan', 'jpm_martin_sylius_subscription_frequency'] as $table) {
            $schema->getTable($table)->dropColumn('deliveries_per_charge');
        }
        $subscription = $schema->getTable('jpm_martin_sylius_subscription');
        $subscription->dropColumn('prepaid_deliveries_left');
        $subscription->dropColumn('cancels_after_prepaid_deliveries');
        $schema->getTable('jpm_martin_sylius_subscription_cycle')->dropColumn('charging');
    }
}
