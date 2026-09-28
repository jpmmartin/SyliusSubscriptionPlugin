<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\Migrations;

use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Second half: every existing plan and frequency charges one delivery at a time, no subscription has
 * deliveries paid for ahead, and every cycle charges, as they all did.
 */
final class Version20260928160100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Makes every existing plan, frequency, subscription and cycle charge one delivery at a time, and requires the columns.';
    }

    public function up(Schema $schema): void
    {
        foreach (['jpm_martin_sylius_subscription_plan', 'jpm_martin_sylius_subscription_frequency'] as $table) {
            $this->addSql(\sprintf('UPDATE %s SET deliveries_per_charge = 1', $table));
        }
        $this->addSql('UPDATE jpm_martin_sylius_subscription SET prepaid_deliveries_left = 0, cancels_after_prepaid_deliveries = ?', [false], [ParameterType::BOOLEAN]);
        $this->addSql('UPDATE jpm_martin_sylius_subscription_cycle SET charging = ?', [true], [ParameterType::BOOLEAN]);

        // The default is set to none on purpose: on MariaDB taken for MySQL (a serverVersion without
        // "mariadb"), a nullable column's default is read back as the string 'NULL' and kept otherwise.
        foreach (self::COLUMNS as $table => $columns) {
            foreach ($columns as $column) {
                $schema->getTable($table)->modifyColumn($column, ['notnull' => true, 'default' => null]);
            }
        }
    }

    /** Going down leaves prepaid subscriptions charging every delivery: only while none is prepaid. */
    public function preDown(Schema $schema): void
    {
        $deliveries = $this->connection->fetchOne('SELECT COUNT(*) FROM jpm_martin_sylius_subscription_cycle WHERE charging = ?', [false], [ParameterType::BOOLEAN]);
        $this->abortIf(
            is_numeric($deliveries) && 0 < (int) $deliveries,
            'Some subscriptions have prepaid deliveries, which the previous version would charge one by one.',
        );
    }

    public function down(Schema $schema): void
    {
        foreach (self::COLUMNS as $table => $columns) {
            foreach ($columns as $column) {
                $schema->getTable($table)->modifyColumn($column, ['notnull' => false, 'default' => null]);
            }
        }
    }

    private const COLUMNS = [
        'jpm_martin_sylius_subscription_plan' => ['deliveries_per_charge'],
        'jpm_martin_sylius_subscription_frequency' => ['deliveries_per_charge'],
        'jpm_martin_sylius_subscription' => ['prepaid_deliveries_left', 'cancels_after_prepaid_deliveries'],
        'jpm_martin_sylius_subscription_cycle' => ['charging'],
    ];
}
