<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** The gateway's code for a failed charge, kept with the attempt; earlier attempts have none. */
final class Version20260923120200 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adds the gateway code to the subscription charge attempts.';
    }

    public function up(Schema $schema): void
    {
        $schema->getTable('jpm_martin_sylius_subscription_charge_attempt')->addColumn('code', 'string', ['length' => 64, 'notnull' => false]);
    }

    public function down(Schema $schema): void
    {
        $schema->getTable('jpm_martin_sylius_subscription_charge_attempt')->dropColumn('code');
    }
}
