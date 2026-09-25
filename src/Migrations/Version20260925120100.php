<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\Migrations;

use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** Second half: no cycle was skipped before, and every cycle says whether it was from now on. */
final class Version20260925120100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Marks every existing subscription cycle as not skipped and requires the flag.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('UPDATE jpm_martin_sylius_subscription_cycle SET skipped = ?', [false], [ParameterType::BOOLEAN]);

        // The default is set to none on purpose: on MariaDB taken for MySQL (a serverVersion without
        // "mariadb"), a nullable column's default is read back as the string 'NULL' and kept otherwise.
        $schema->getTable('jpm_martin_sylius_subscription_cycle')->modifyColumn('skipped', ['notnull' => true, 'default' => null]);
    }

    public function down(Schema $schema): void
    {
        $schema->getTable('jpm_martin_sylius_subscription_cycle')->modifyColumn('skipped', ['notnull' => false, 'default' => null]);
    }
}
