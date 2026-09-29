<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusSubscriptionPlugin\Functional\Admin;

use Sylius\Behat\Context\Hook\DoctrineORMContext;
use Sylius\Behat\Context\Setup\AdminUserContext;
use Sylius\Behat\Context\Setup\ChannelContext;
use Sylius\Behat\Service\SharedStorageInterface;
use Sylius\Component\Core\Model\AdminUserInterface;
use Sylius\Component\Core\Model\OrderItem;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Tests\JpmMartin\SyliusSubscriptionPlugin\Installation\WithholdsSubscriptions;

/** The admin says which installation step is left, while it is, and nothing once it is made. */
final class InstallationNoticeTest extends WebTestCase
{
    use WithholdsSubscriptions;

    private const NOTICE = '[data-test-subscription-installation-notice]';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $container = self::getContainer();

        /** @var DoctrineORMContext $database */
        $database = $container->get('sylius.behat.context.hook.doctrine_orm');
        $database->purgeDatabase();
        /** @var ChannelContext $channels */
        $channels = $container->get('sylius.behat.context.setup.channel');
        $channels->storeOperatesOnASingleChannelInUnitedStates();

        /** @var AdminUserContext $admins */
        $admins = $container->get('sylius.behat.context.setup.admin_user');
        $admins->thereIsAnAdministratorIdentifiedBy('admin@example.com');
        /** @var SharedStorageInterface $sharedStorage */
        $sharedStorage = $container->get('sylius.behat.shared_storage');
        $admin = $sharedStorage->get('administrator');
        self::assertInstanceOf(AdminUserInterface::class, $admin);
        $this->client->loginUser($admin, 'admin');
    }

    protected function tearDown(): void
    {
        $this->restoreSubscriptions();

        parent::tearDown();
    }

    public function testTheDashboardAndTheSubscriptionsSayTheOrderItemIsLeftToAdapt(): void
    {
        $this->withholdSubscriptions();

        foreach (['/admin/', '/admin/subscriptions/'] as $page) {
            $this->client->request('GET', $page);

            self::assertResponseIsSuccessful($page);
            self::assertSelectorExists(self::NOTICE, $page);
            self::assertSelectorTextContains(self::NOTICE, OrderItem::class, $page);
            self::assertSelectorTextContains(self::NOTICE, 'SubscriptionPlanAwareTrait', $page);
        }
    }

    public function testNothingIsSaidOnceTheOrderItemCarriesThePlan(): void
    {
        foreach (['/admin/', '/admin/subscriptions/'] as $page) {
            $this->client->request('GET', $page);

            self::assertResponseIsSuccessful($page);
            self::assertSelectorNotExists(self::NOTICE, $page);
        }
    }
}
