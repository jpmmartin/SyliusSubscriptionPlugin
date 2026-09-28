<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Second half: an introductory price lasts one cycle unless the store says otherwise, so every
 * existing plan and frequency gets one, unused while it has no introductory discount.
 */
final class Version20260928130100 extends AbstractMigration
{
    private const TABLES = ['jpm_martin_sylius_subscription_plan', 'jpm_martin_sylius_subscription_frequency'];

    public function getDescription(): string
    {
        return 'Gives subscription plans and frequencies one introductory cycle and requires it.';
    }

    public function up(Schema $schema): void
    {
        foreach (self::TABLES as $table) {
            $this->addSql(\sprintf('UPDATE %s SET introductory_cycles = 1', $table));

            // The default is set to none on purpose: on MariaDB taken for MySQL (a serverVersion without
            // "mariadb"), a nullable column's default is read back as the string 'NULL' and kept otherwise.
            $schema->getTable($table)->modifyColumn('introductory_cycles', ['notnull' => true, 'default' => null]);
        }
    }

    public function down(Schema $schema): void
    {
        foreach (self::TABLES as $table) {
            $schema->getTable($table)->modifyColumn('introductory_cycles', ['notnull' => false, 'default' => null]);
        }
    }
}
