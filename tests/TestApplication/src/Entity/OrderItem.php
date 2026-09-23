<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusSubscriptionPlugin\Entity;

use Doctrine\ORM\Mapping as ORM;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionPlanAwareInterface;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionPlanAwareTrait;
use Sylius\Component\Core\Model\OrderItem as BaseOrderItem;

/** The installation step a store performs on its own OrderItem, applied to the test application. */
#[ORM\Entity]
#[ORM\Table(name: 'sylius_order_item')]
class OrderItem extends BaseOrderItem implements SubscriptionPlanAwareInterface
{
    use SubscriptionPlanAwareTrait;
}
