# Sylius Subscription Plugin

Sell products by subscription in a standard Sylius 2 storefront. A variant offers one or more plans;
the customer buys it once or on a plan, in the same cart as anything else. The store can also offer
a few frequencies of its own, with which a customer repeats their whole cart. The lines of an order
that renew on the same interval make one subscription, so every renewal is one normal Sylius order
with all of them, charged without the customer present, with retries when a charge is declined.

## Features

- **Plans per variant**: interval (days, weeks, months or years), subscriber discount and an optional
  maximum of cycles, managed from the variant's Subscription tab in the admin.
- **Store frequencies and "Repeat this cart"**: the store defines its own frequencies (interval,
  discount, optional maximum of cycles and the channels that offer them) and marks the variants that
  can be repeated. On the cart page, and through the API, the customer repeats the cart with one of
  them: every one-time line of such a variant, including those added later, renews with it at the
  variant's price less its discount. Lines on a plan keep their plan; the rest are bought once. See
  [Store frequencies and repeated carts](#store-frequencies-and-repeated-carts).
- **Mixed carts**: the product page offers "one-time purchase" or a plan. A subscription line is never
  merged with a one-time line of the same variant, and it is priced at the variant's price less the
  plan's discount before promotions apply.
- **Checkout rules**, in the shop and in the API alike: an order with subscriptions needs a customer
  with an account, a payment method that can be charged without the customer, and the customer's
  consent to recurring charges. The consent text is versioned and stored as the customer saw it.
- **Subscriptions of several products**: an order starts one subscription per interval among its
  subscription lines, with an item per line (variant, quantity, frozen price, and plan or store
  frequency). Coffee and tea bought monthly in the same cart arrive together every month, in one
  order, whether each is on a plan or repeated with the store's monthly frequency.
- **Lifecycle**: a subscription starts pending when its order is placed and becomes active the day the
  order is paid. Cancelling that order before it is paid cancels it; once paid, only the customer or
  an administrator cancels it.
- **Renewals**: a console command processes the cycles that are due. Each cycle is checked by the
  store's gates, gets one completed renewal order with a line per item (frozen price on an immutable
  line; shipping, taxes and automatic promotions worked out as for any order), and is charged through
  a service the store can replace. An item whose variant cannot be sold that day (disabled, out of the
  channel or out of stock) is skipped in that cycle only, and the cycle says why. Declines are retried
  by a policy the store can configure or replace; an unknown outcome is reconciled instead of charged
  again. Running the command twice, or delivering its message twice, never charges a cycle twice.
- **Failures never cancel**: a cycle that cannot be charged fails, its unpaid order is cancelled and the
  next cycle is scheduled. Only a run of failed cycles suspends the subscription (three by default).
  An administrator can retry a failed cycle; cancelling a renewal order before it is paid skips that
  renewal.
- **Customer account**: the customer's subscriptions with their products, their renewals and what each
  skipped and where they are shipped; pausing and resuming them, skipping the next renewal,
  cancelling, changing how often and where they renew, changing their quantities, swapping a variant,
  removing a product or adding one, and paying a renewal whose charge was declined or changing their
  card through the gateway. See [Pausing and skipping](#pausing-and-skipping),
  [Changing the address](#changing-the-address), [Changing the items](#changing-the-items) and
  [Paying a declined renewal](#paying-a-declined-renewal).
- **Admin**: a list filterable by state, customer, variant (of any of the products) and next renewal,
  and a page with the products, the consent, the failed renewals in a row, every renewal with what it
  took in or skipped and each charge attempt (date, outcome and reason), where it is shipped, and the
  actions the state allows: pause, resume, suspend, reactivate, cancel, skip the next renewal, change
  the frequency, change the address and retry a failed renewal.
- **Committed cycles**: a read-only query of what the active subscriptions of a variant will renew
  within a horizon, for planning stock.
- **Events, no emails**: the plugin tells no customer anything. It publishes an event of its own at every
  moment of a subscription's life, a renewal coming up and a declined charge that will be retried
  included, for the store to tell its customers as it sees fit. See [Events](#events).

## Requirements

- Sylius `^2.2`, on PHP 8.2 to 8.5 and Symfony 6.4 or 7.4.
- One of the databases Sylius tests its plugins against: MySQL 8.0 or 8.4, MariaDB 10.11 or 11.4, or
  PostgreSQL 15, 16 or 17. Every one of them is checked on each change; see
  [Continuous integration](#continuous-integration). On MySQL and MariaDB, codes are compared without
  regard to case, as Sylius's own are: `MONTHLY` and `monthly` are the same code there. On MariaDB,
  name it in `serverVersion`, as Doctrine asks (`?serverVersion=mariadb-11.4.2`): given a bare number,
  Doctrine takes MariaDB for MySQL and misreads column defaults when comparing schemas.
- On MySQL and MariaDB, the plugin's tables are created in `utf8mb4` with `utf8mb4_unicode_ci`, as
  Sylius's are, so their text takes any character, emojis included. The connection has to carry them
  too: set `charset: utf8mb4` in your Doctrine connection if you want emojis anywhere, in Sylius's
  tables as in the plugin's.
- The Symfony Workflow state machine adapter for Sylius's order graphs, which is Sylius 2's default:
  the plugin reacts to the workflow events of `sylius_order_checkout`, `sylius_order_payment` and
  `sylius_order`. Its own graphs are always run by Symfony Workflow.
- A payment gateway that can charge a stored payment method without the customer. See
  [Charging renewals](#charging-renewals).
- **Your own customer notices.** The plugin sends no email, not even before charging a renewal. Listen to
  its [events](#events) to tell your customers that a renewal is coming, that a charge was declined or
  that their subscription changed; charging without telling them is what brings chargebacks.

## Installation

1. Require the plugin:

    ```bash
    composer require jpmmartin/sylius-subscription-plugin
    ```

2. Register the bundle in `config/bundles.php`, if Symfony Flex did not:

    ```php
    JpmMartin\SyliusSubscriptionPlugin\JpmMartinSyliusSubscriptionPlugin::class => ['all' => true],
    ```

3. Import its configuration and name the payment methods that may charge renewals, in
   `config/packages/jpm_martin_sylius_subscription.yaml`:

    ```yaml
    imports:
        - { resource: "@JpmMartinSyliusSubscriptionPlugin/config/config.yaml" }

    jpm_martin_sylius_subscription:
        payment_methods: ['card_on_file'] # payment method codes; nobody can subscribe until you name one
    ```

4. Import its routes in `config/routes/jpm_martin_sylius_subscription.yaml`. The admin routes go under
   the admin path, which puts them behind the admin firewall; the shop routes go under the locale
   prefix, exactly as Sylius's shop routes are imported, so the account's access control covers them:

    ```yaml
    jpm_martin_sylius_subscription_admin:
        resource: "@JpmMartinSyliusSubscriptionPlugin/config/routes/admin.yaml"
        prefix: /%sylius_admin.path_name%

    jpm_martin_sylius_subscription_shop:
        resource: "@JpmMartinSyliusSubscriptionPlugin/config/routes/shop.yaml"
        prefix: /{_locale}
        requirements:
            _locale: ^[A-Za-z]{2,4}(_([A-Za-z]{4}|[0-9]{3}))?(_([A-Za-z]{2}|[0-9]{3}))?$
    ```

5. Let your order item carry the chosen plan, or the frequency its cart is repeated with: the class
   your store configures as `sylius_order.resources.order_item.classes.model`, for example:

    ```php
    use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionPlanAwareInterface;
    use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionPlanAwareTrait;
    use Sylius\Component\Core\Model\OrderItem as BaseOrderItem;

    #[ORM\Entity]
    #[ORM\Table(name: 'sylius_order_item')]
    class OrderItem extends BaseOrderItem implements SubscriptionPlanAwareInterface
    {
        use SubscriptionPlanAwareTrait;
    }
    ```

    The application refuses to boot, with a message saying so, until the configured order item does this.

6. Run the migrations. The plugin registers its own migrations namespace; its migrations, written with
   Doctrine's schema API rather than one platform's SQL, create its tables and the
   `subscription_plan_id` and `subscription_frequency_id` columns of `sylius_order_item`:

    ```bash
    bin/console doctrine:migrations:migrate -n
    ```

7. Process the due cycles on a schedule, with cron or Symfony Scheduler, as often as you want renewals
   to be charged:

    ```bash
    bin/console jpm-martin:subscription:process-cycles
    ```

    The command dispatches one `ProcessSubscriptionCycle` message per due cycle on `sylius.command_bus`,
    so you may route that message to an asynchronous transport.

## Configuration reference

```yaml
jpm_martin_sylius_subscription:
    # Codes of the payment methods the default charging service may charge without the customer.
    payment_methods: []
    # Days after the first attempt at which a declined charge is retried. Strictly increasing; [] never
    # retries.
    retry_delays: [1, 3, 7]
    # Gateway codes of declines that are never retried: the cycle fails at once. See "Retrying failed
    # charges".
    final_decline_codes: []
    # Cycles failed in a row after which a subscription is suspended: a positive integer, or null to
    # never suspend. A paid cycle and a reactivation start the count afresh.
    suspend_after_failed_cycles: 3
    # Days before a renewal at which RenewalUpcoming is published, once per cycle; null never publishes
    # it. See "Events".
    renewal_notice_days: 3
    # Renewals in a row a customer may skip; null sets no limit. See "Pausing and skipping".
    max_consecutive_skips: ~
    # Minutes a retry waits for a payment the customer is making of the same renewal, counted from its
    # last change. See "Paying a declined renewal".
    customer_payment_wait_minutes: 60
    # What becomes of the dates of a calendar that came before a cycle could be scheduled on them:
    # skip, charge or skip_late. See "Missed dates".
    missed_cycles: skip
    # Version of the consent text. Raise it whenever you change the text.
    consent_version: '1'
```

## Charging renewals

A renewal is charged through `JpmMartin\SyliusSubscriptionPlugin\Payment\RenewalChargerInterface`:
`supports()` says whether a payment method can be charged without the customer (checkout refuses
subscriptions with any other), `charge()` charges a payment, and `status()` asks what became of a
charge whose outcome was unknown, without charging again. Each answer is approved, declined (with the
issuer's reason), not attempted (with a reason) or unknown. A decline, or a refused charge, may also
carry the gateway's code for it, such as `insufficient_funds`: `ChargeOutcome::declined($reason,
$code)`. Each attempt keeps it, and the admin shows it next to the reason.

### The default service

The default service supports the methods listed in `payment_methods`, and charges through Sylius's
payment requests: it creates a `capture` payment request for the renewal payment (a `status` one to
reconcile) and announces it, so the payment method's gateway handles it like any other payment
request. The gateway's handler must be able to capture that payment without the customer, typically
with a stored card or token; the outcome is read from the payment's state: completed is approved,
failed or cancelled is declined. The reason is read from the payment request's response data (the
first of `reason`, `message`, `error` or `responsetext`), and so is the code (the first of `code`,
`decline_code` or `error_code`).

None of the official gateway plugins checked reports a code yet: the Stripe plugin
(`flux-se/sylius-stripe-plugin`) writes only a `reason` when a payment fails, and the Adyen, Mollie and
PayPal plugins charge through Payum rather than payment requests. To retry by code, have your gateway's
handler put the code in the response data, or charge renewals with your own service.

- **Synchronous or asynchronous payment requests.** Sylius's own default transport for payment
  requests is `sync://`, and the outcome is then known at once. If your store routes payment requests
  to a worker, the charge is only queued: the plugin records an unknown outcome and, on the next runs,
  asks for the payment's status until the worker has settled it. It never charges the payment again.
- **Encryption.** Sylius 2 encrypts payment requests. Generate the key once with
  `bin/console sylius:payment:generate-key`.
- **A card the customer pays with must be kept.** A customer may pay a declined renewal on the store's
  order payment page with another card (see [Paying a declined renewal](#paying-a-declined-renewal)),
  and the next renewals are charged without them. The gateway's handler must keep that card for charges
  without the customer, as the checkout already requires of it; how depends on the gateway.

### Your own service

Implement `RenewalChargerInterface` and point the interface's alias at your service, or redefine the
`jpm_martin_sylius_subscription.payment.renewal_charger` service. Everything in the plugin that
charges or checks a payment method goes through that alias.

```yaml
services:
    App\Payment\MyGatewayRenewalCharger: ~
    JpmMartin\SyliusSubscriptionPlugin\Payment\RenewalChargerInterface: '@App\Payment\MyGatewayRenewalCharger'
```

## Failed cycles and manual retries

A cycle fails when its retries run out, a gate rejects it, its hold expires or none of its items can be
sold that day. A failed cycle cancels its order if nothing was charged on it, which gives the reserved
stock back, and the next cycle is scheduled on the subscription's calendar: a failure never cancels a
subscription. After `suspend_after_failed_cycles` failed cycles in a row the subscription is suspended
instead, and generates no cycles until an administrator reactivates it.

An administrator can retry a failed cycle of an active or suspended subscription from its page. The
retry places a new order with the items that can be sold now and charges it once, with no automatic
retries; an unknown outcome is reconciled like any other. If it is paid, the cycle is paid without
moving the calendar; if it is declined, the cycle stays failed and its new order is cancelled. The
subscription's state does not change, except when the retry pays the last cycle its items had left: an
active subscription then completes, and a suspended one completes when it is reactivated.

If an administrator cancels a renewal order before it is paid, its cycle is cancelled and the next one
scheduled, without counting as a failure: that renewal is skipped.

Suspending a subscription cancels its open cycle but leaves alone a retry still awaiting the gateway's
answer, which the scheduler keeps reconciling. Cancelling the subscription cancels that retry too.

A suspended or a paused subscription is taken up again on the first date of its calendar after that
day, even when that date is the one of the cycle the suspension or the pause cancelled.

## Pausing and skipping

A customer pauses an active subscription from their account, and resumes it when they want: a pause
has no end date. An administrator can do both on the customer's behalf. A pause is not a suspension:

- pausing is the customer's, and so is resuming; a suspension, by an administrator or after failed
  cycles in a row, is lifted only by an administrator reactivating the subscription;
- pausing cancels the open cycle, and its order if nothing was charged on it, like a suspension, and
  leaves alone an administrator's retry still awaiting the gateway's answer;
- a paused subscription generates no cycles and no renewal notices;
- resuming schedules the next cycle on the first date of its calendar after that day, and keeps the
  failed cycles in a row: pausing changes nothing about the payment method, so it is no way around
  the suspension.

A customer, or an administrator on their behalf, can also skip the next renewal of an active
subscription while its order has not been placed: its cycle is cancelled, marked as skipped, and the
next cycle keeps its date on the calendar. A skipped renewal is neither a failure nor a charge, so it
does not use up a plan's maximum. Once the renewal's order is placed, for instance while its charge
awaits a retry, it can no longer be skipped. `max_consecutive_skips` limits how many renewals in a
row can be skipped: those skipped right before the open cycle, with no paid, failed or otherwise
cancelled cycle between them. With none set, there is no limit. A skip cannot be undone.

The skip is a service, `SubscriptionRenewalSkipperInterface`: replace it to let customers skip
another way.

## Changing the address

A renewal goes to the addresses of the subscription's last order until they are changed. A customer
changes them from their account, and an administrator from the subscription's page, for a subscription
that is neither cancelled nor completed:

- the shipping address is one of the customer's address book or a new one; billing goes there too
  unless another billing address is given;
- the subscription keeps copies, so editing the address book afterwards changes nothing, and a new
  address is not added to the book;
- they apply from the next renewal whose order is not placed yet: an order already placed, for
  instance one awaiting a retry, keeps its addresses.

When the subscription's shipping method does not reach the new shipping address, the form lists the
methods that do, the same ones the checkout would offer for its items there, each with what it would
cost the next renewal at today's prices, and the subscription moves to the one chosen. When none
reaches it, the change is refused and nothing changes. A subscription that ships nothing only changes
its addresses.

Changing where a subscription ships is the first thing a stolen account does: listen to
`SubscriptionAddressChanged` and tell the customer. The change is a service,
`SubscriptionAddressChangerInterface`: replace it to change addresses another way.

## Changing the items

A customer changes what an active subscription brings from their account, while its open cycle has no
order yet: once the renewal's order is placed, for instance while its charge awaits a retry, that
order keeps its items and nothing can be changed until the next cycle. The changes apply from the open
cycle. On the "Change items" page, each item still renewing can:

- change its quantity, from 1 to the most Sylius allows on a cart line
  (`sylius.order_item_quantity_modifier.limit`, 9999 by default), keeping its frozen price;
- move to another variant of the same product, which renews at that variant's current price less the
  discount of its plan, or of the store's frequency, of the subscription's interval, and keeps the
  cycles the item has been paid;
- be removed, while another item still renews.

A variant is offered when the channel sells it (enabled, its product in the channel, and at least one
in stock when its stock is tracked), when it has terms of the subscription's interval of the item's
kind (an enabled plan of its own for an item on a plan, the store's frequency, if the variant can be
repeated, for an item repeated with it), when those terms' maximum of cycles is above what the item
has been paid, and when no other renewing item has it.

The "Add a product" page lists, by product, every variant the channel sells with an enabled plan of the
subscription's interval, or else that can be repeated with the store's frequency of that interval, and
that no renewing item has: an item already renewing changes its quantity instead. The plan wins when a
variant has both. The new item is frozen at the variant's current price less that discount, with no
cycle paid.

When the changes raise what each renewal costs, the page shows the new total and asks the customer to
accept the recurring charges again, and saves nothing until they do; see
[Consent to recurring charges](#consent-to-recurring-charges). The "Add a product" page asks in
the same step. Lowering a quantity, removing an item, or adding one that is free asks for nothing.

A removed item is not deleted: it stays on the subscription marked as removed, like one that reached its
maximum of cycles, so the renewals that carried it keep showing it. It no longer renews, the frequency
change leaves it alone, and the committed cycles do not count it. Its variant can be added again, as a
new item. Nothing reserves stock: a renewal still skips what it cannot sell that day.

Listen to `SubscriptionItemsChanged`, which carries the totals before and after, to keep your own
record of each change. The changes are a service, `SubscriptionItemEditorInterface`: replace it to
offer other changes.

## Retrying failed charges

What becomes of a charge that was declined or not attempted is decided by
`JpmMartin\SyliusSubscriptionPlugin\Cycle\RetryPolicyInterface`: charge the same order again at a
date, or fail the cycle. It is never asked about an administrator's retry, which is charged once.

The default policy retries after each of `retry_delays`, counted in days from the cycle's first
attempt, and fails the cycle once they run out. A decline whose code is in `final_decline_codes` fails
the cycle at once: retrying a stolen card only earns more declines. Codes are compared exactly and are
the gateway's own, so the list is empty by default. With Stripe, for instance, whose
[decline codes](https://docs.stripe.com/declines/codes) include these:

```yaml
jpm_martin_sylius_subscription:
    final_decline_codes: ['stolen_card', 'lost_card', 'pickup_card', 'expired_card']
```

For anything else, such as retrying by payment method, within hours, or by the gateway's advice,
implement `RetryPolicyInterface` and point the interface's alias at your service. `nextAttemptAt()` is
called once the failed attempt is among the cycle's attempts, with the charge's outcome and its code;
return the date to charge again, or `null` to fail the cycle.

```yaml
services:
    App\Subscription\MyRetryPolicy: ~
    JpmMartin\SyliusSubscriptionPlugin\Cycle\RetryPolicyInterface: '@App\Subscription\MyRetryPolicy'
```

### Unpaid orders expiring

Sylius's `sylius:cancel-unpaid-orders` cancels the orders left unpaid for longer than
`sylius_order.expiration.order`, five days by default, which is shorter than the plugin's default
retries. So that it never cuts a retry short, the plugin decorates `sylius.updater.unpaid_orders_state`
with a version that leaves out the renewal orders whose cycle still awaits payment: the retry policy
decides when to give up on them, and failing the cycle cancels them. Every other order, initial orders
included, expires as before. If your store decorates or replaces that service too, keep renewal orders
awaiting payment out of it.

## Paying a declined renewal

When a renewal's charge is declined, or could not be attempted, and a retry is to come, its customer
can pay the renewal order on Sylius's own order payment page, `sylius_shop_order_show`
(`/order/{tokenValue}`), with another card or another method:

- their account offers "Pay now" on that renewal, and
  `JpmMartin\SyliusSubscriptionPlugin\Payment\RenewalPaymentLinkGeneratorInterface` gives the page's
  absolute address for the store's own notice of `RenewalChargeDeclined`; see [Events](#events);
- on a renewal order, the page offers only the methods the renewal charger `supports()`: the plugin
  decorates `sylius.resolver.payment_methods`, and leaves every other order as it was;
- once paid, the cycle is paid as if the plugin had charged it, its retry is not made, and its history
  shows "Paid by the customer". Paid with another method the plugin can charge, the subscription renews
  with it from then on, and `SubscriptionPaymentMethodChanged` is published. A payment an administrator
  marks complete in the admin pays the cycle too, but is not shown as the customer's.

A retry does not charge while the customer is paying the same order, so the renewal is not charged
twice: a payment request of the pending payment still new or processing holds the retry back to the
next run of the command. A customer who gives up on the gateway's page leaves that request behind, so it
counts only until it has gone `customer_payment_wait_minutes` without changing, an hour by default; the
retry then goes ahead. Only gateways that use Sylius's payment requests leave a request to find.

### Changing the card

Without a renewal to pay, where the customer changes their card is the gateway integration's, since the
card is the gateway's. An integration implements
`JpmMartin\SyliusSubscriptionPlugin\Payment\CardUpdateProviderInterface`: `supports()` a subscription,
usually by its payment method, and `getUrl()` of its own page. Tag it with
`jpm_martin_sylius_subscription.card_update_provider`, or let autoconfiguration do it; the first that
supports a subscription, by the tag's priority, gives the account's "Change card" link, and
`JpmMartin\SyliusSubscriptionPlugin\Payment\CardUpdateProviderRegistry` gives it to your own templates
or emails. The plugin registers none, so nothing is offered until one is.

```php
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionInterface;
use JpmMartin\SyliusSubscriptionPlugin\Payment\CardUpdateProviderInterface;

final class StripeCardUpdateProvider implements CardUpdateProviderInterface
{
    public function supports(SubscriptionInterface $subscription): bool
    {
        return 'STRIPE' === $subscription->getPaymentMethod()?->getCode();
    }

    public function getUrl(SubscriptionInterface $subscription): string
    {
        return '/account/stripe/cards'; // a page of your integration
    }
}
```

## Missed dates

A cycle's date comes from its subscription's calendar, so a late charge never moves the cycles after
it. When a cycle is settled, paid, failed or cancelled, the next goes on the calendar's next date. That
date may already have come: after `jpm-martin:subscription:process-cycles` stopped running for a
while, or when a cycle of a short interval spent longer than its interval being retried. What becomes
of such dates is decided by `JpmMartin\SyliusSubscriptionPlugin\Schedule\MissedCyclePolicyInterface`,
and the default policy does what `missed_cycles` says:

- `skip`, the default: the late cycle is charged once, and the next goes on the first date to come. The
  calendar keeps its day and time, and skipped dates create no cycle and use up no plan or frequency.
  After the command stopped from 1 March to 10 June, a monthly subscription due on 1 March is charged
  once on 10 June and renews next on 1 July. A weekly cycle that runs out of retries on day 7, after its
  time, skips the date that came meanwhile.
- `charge`: each date that came is charged, one per run of the command, each with its own order.
- `skip_late`: as `skip`, and a scheduled cycle whose next date has come too is cancelled instead of
  charged, without an order and without counting as a failed cycle. A cycle held by a gate is never
  skipped: its wait is deliberate.

The command says how many of the due cycles are more than one interval late, and each skip is logged
as a warning with the subscription and its next date.

To decide it another way, implement `MissedCyclePolicyInterface` and point the interface's alias at your
service. `datesToSkip()` is asked, before a cycle is scheduled, how many of the dates that have come to
skip, from 0 up to all of them; `isStillDue()` is asked whether a scheduled cycle that is due is still
processed.

```yaml
services:
    App\Subscription\MyMissedCyclePolicy: ~
    JpmMartin\SyliusSubscriptionPlugin\Schedule\MissedCyclePolicyInterface: '@App\Subscription\MyMissedCyclePolicy'
```

## Gates

Before a cycle places its order, every gate is asked whether it passes, waits until a date (with a
reason) or is rejected (with a reason). A waiting cycle is held and asked again on every run until
the earliest deadline it was given; if that deadline arrives, or a gate rejects it, the cycle fails.
Without gates, every cycle passes.

Implement `JpmMartin\SyliusSubscriptionPlugin\Gate\CycleGateInterface`; with autoconfiguration the
service is tagged `jpm_martin_sylius_subscription.cycle_gate` for you.

```php
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionCycleInterface;
use JpmMartin\SyliusSubscriptionPlugin\Gate\CycleGateInterface;
use JpmMartin\SyliusSubscriptionPlugin\Gate\GateDecision;
use Psr\Clock\ClockInterface;

final class PrescriptionGate implements CycleGateInterface
{
    public function __construct(private readonly ClockInterface $clock)
    {
    }

    public function check(SubscriptionCycleInterface $cycle): GateDecision
    {
        if ($this->isPrescriptionValidFor($cycle)) {
            return GateDecision::pass();
        }

        return GateDecision::wait($this->clock->now()->add(new \DateInterval('P5D')), 'Waiting for the prescriber.');
    }

    // ...
}
```

## Consent to recurring charges

The text the customer accepts is the translation `jpm_martin_sylius_subscription.consent.text`
(domain `messages`). Override it in your translations and raise `consent_version` when you change
it: customers are then asked again, and each subscription keeps the version, text and date that were
accepted for it.

The same text is asked again when a customer's changes to a subscription's items raise what each
renewal costs, so write it to fit both moments. The subscription then keeps the version, text and date
of that acceptance instead; the consent recorded on the initial order stays as the proof of the first.

## Store frequencies and repeated carts

- **Frequencies** are managed in the admin under Configuration > Subscription frequencies. Each has a
  code, a name, an interval, a discount, an optional maximum of cycles and the channels that offer it.
  Disable one to stop offering it; the subscriptions already on it keep renewing. One that a cart, an
  order line or a subscription item uses cannot be deleted.
- **Variants that can be repeated** are marked with the "Can be repeated with the store's frequencies"
  switch on the variant's Subscription tab, off by default, so a gift card or anything that must not
  renew stays out of repeated carts. The mark is kept in a table of the plugin; the store's variant
  class is left alone.
- **The cart page** offers "Repeat this cart" when the channel has a frequency and the cart a one-time
  line of a variant that can be repeated. It is a form of its own, posting to the route
  `jpm_martin_sylius_subscription_shop_cart_repeat`, rendered after Sylius's live cart form by the
  hookable `jpm_martin_sylius_subscription_repeat_cart` of the hook `sylius_shop.cart.index.content`
  (priority 50): override or move it there. Each line says what it renews on, or that it is bought
  once in a repeated cart.
- **The choice belongs to the cart.** An order processor (priority 47, before the subscriber price at
  45) gives the cart's frequency to each one-time line of a variant that can be repeated, and takes
  it from every other line, whenever Sylius processes the cart. A frequency the cart can no longer
  have, disabled or taken off its channel, stops the cart being repeated.
- **Services**: `JpmMartin\SyliusSubscriptionPlugin\Frequency\CartRepeaterInterface` chooses or removes a
  cart's frequency and tells which are offered; `JpmMartin\SyliusSubscriptionPlugin\Frequency\RepeatableVariantsInterface`
  marks variants and tells which can be repeated.

## Shop API

- `POST /api/v2/shop/orders/{tokenValue}/subscription-items` adds a line on a plan, next to Sylius's
  own `/items`, with the variant's and the plan's codes:
  `{"productVariant": "COFFEE", "subscriptionPlan": "COFFEE_MONTHLY", "quantity": 1}`.
- `PATCH /api/v2/shop/orders/{tokenValue}/subscription-consent` records the customer's consent before
  the order is completed.
- `GET /api/v2/shop/subscription-frequencies` lists the frequencies the request's channel offers, with
  their code, name, `intervalCount`, `intervalUnit` and `discountPercentage`.
- `PATCH /api/v2/shop/orders/{tokenValue}/subscription-frequency` repeats the cart with one of them,
  `{"subscriptionFrequency": "MONTHLY"}`, or stops repeating it with `{"subscriptionFrequency": null}`
  (`Content-Type: application/merge-patch+json`). A frequency the channel does not offer is refused
  with a validation error.
- Every line of a shop cart, in the cart and on its own (`/shop/orders/{tokenValue}/items/{id}`),
  tells the code of its plan and of its frequency, or null:
  `"subscriptionPlan": "COFFEE_MONTHLY", "subscriptionFrequency": null`.

## Committed cycles

`JpmMartin\SyliusSubscriptionPlugin\Query\CommittedCyclesQueryInterface::forProductVariant($variant, new \DateInterval('P3M'))`
returns, by date, the cycles the items of a variant in active subscriptions will renew within the
horizon, each with its subscription, item, date and quantity, never past the cycles the item's plan
or frequency still allows. It follows the missed cycle policy: a date the policy will skip is not
returned, nor a cycle it will cancel.

## Events

The plugin sends no email, SMS or any other notice to customers: what to say, in which words and through
which channel is the store's. It publishes instead an event of its own at every moment of a
subscription's life, as a Symfony Messenger message on `sylius.event_bus`, the bus Sylius publishes its
own events on. Each event is a class of `JpmMartin\SyliusSubscriptionPlugin\Event` with public,
read-only properties: identifiers and simple data, never entities, so it can go through an asynchronous
transport. Every one implements `SubscriptionEventInterface`, whose `getSubscriptionId()` gives the
subscription; listen to that interface to receive them all.

| Event | Published when | Data besides `subscriptionId` |
|---|---|---|
| `SubscriptionActivated` | the subscription is activated: its initial order was paid | — |
| `SubscriptionPaused` | it is paused, by its customer or an administrator on the customer's behalf | — |
| `SubscriptionResumed` | the paused subscription is resumed | — |
| `SubscriptionSuspended` | it is suspended, by an administrator or after failed cycles in a row | — |
| `SubscriptionReactivated` | it is reactivated | — |
| `SubscriptionCancelled` | it is cancelled, by its customer or an administrator | — |
| `SubscriptionCompleted` | it ends: no item has a cycle left to renew | — |
| `SubscriptionFrequencyChanged` | its frequency changes | `intervalCount`, `intervalUnit` (`day`, `week`, `month` or `year`) |
| `SubscriptionAddressChanged` | its addresses change, by its customer or an administrator | `shippingMethodChanged` |
| `SubscriptionItemsChanged` | its customer changes, removes or adds items | `previousRenewalTotal`, `renewalTotal`, in minor units of the subscription's currency |
| `SubscriptionPaymentMethodChanged` | its customer paid a declined renewal with another method the plugin can charge, which it renews with from then on | `paymentMethodCode` |
| `RenewalUpcoming` | a renewal is `renewal_notice_days` away, once per cycle | `cycleId`, `cycleNumber`, `scheduledAt` |
| `RenewalHeld` | a gate holds the cycle | `cycleId`, `cycleNumber`, `holdUntil`, `reason` |
| `RenewalOrderPlaced` | the cycle places its renewal order | `cycleId`, `cycleNumber`, `orderId` |
| `RenewalChargeDeclined` | a charge is declined or not attempted, and will be retried | `cycleId`, `cycleNumber`, `orderId`, `nextAttemptAt`, `reason`, `code` |
| `RenewalPaid` | the renewal order is paid | `cycleId`, `cycleNumber`, `orderId` |
| `RenewalFailed` | the cycle fails: retries run out, a gate rejects it, its hold expires or nothing could be renewed | `cycleId`, `cycleNumber`, `orderId` (null without an order), `reason` |
| `RenewalRetried` | an administrator retries a failed cycle; `RenewalPaid` or `RenewalFailed` follows with its new order | `cycleId`, `cycleNumber` |
| `RenewalSkipped` | the next renewal is skipped, by the customer or an administrator; published instead of `RenewalCancelled` | `cycleId`, `cycleNumber`, `scheduledAt`, `nextScheduledAt` |
| `RenewalCancelled` | the cycle is cancelled: its order was cancelled before being paid, the subscription was paused, suspended or cancelled, or the cycle was skipped as late | `cycleId`, `cycleNumber`, `orderId` and `reason`, each null when there is none |

The renewal events come from renewals only: the first cycle is the initial order, paid when the
subscription is activated. The last retry that is declined publishes `RenewalFailed`, not
`RenewalChargeDeclined`, and an administrator's retry, which is charged once, never publishes
`RenewalChargeDeclined`. `RenewalUpcoming` is published by `jpm-martin:subscription:process-cycles` on
its first run within `renewal_notice_days` of a scheduled cycle of an active subscription, and not for
a cycle that is already due. So it reaches your customers only if the command runs at least once a day
or so. A subscription that renews more often than that, every day for instance, has its next cycle
within the notice as soon as it is scheduled: that cycle is announced in the same run that charged the
one before, so less than `renewal_notice_days` ahead.

A notice of `RenewalChargeDeclined` can tell the customer where to pay the renewal themselves:
`JpmMartin\SyliusSubscriptionPlugin\Payment\RenewalPaymentLinkGeneratorInterface::generate($cycle)`
gives the absolute address of the order payment page, or `null` once the renewal can no longer be paid
there. It is on the channel's hostname when the channel has one. Otherwise the host is the request's,
and a worker handling events has none: set `framework.router.default_uri`, or the link points to
`localhost`.

A handler, in a store with autoconfiguration:

```php
namespace App\Subscription;

use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionInterface;
use JpmMartin\SyliusSubscriptionPlugin\Event\RenewalUpcoming;
use JpmMartin\SyliusSubscriptionPlugin\Repository\SubscriptionRepositoryInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'sylius.event_bus')]
final class TellTheCustomerAboutTheRenewal
{
    /** @param SubscriptionRepositoryInterface<SubscriptionInterface> $subscriptions */
    public function __construct(private readonly SubscriptionRepositoryInterface $subscriptions)
    {
    }

    public function __invoke(RenewalUpcoming $event): void
    {
        $subscription = $this->subscriptions->find($event->subscriptionId);
        if (!$subscription instanceof SubscriptionInterface) {
            return;
        }

        // Email $subscription->getCustomer() that it renews on $event->scheduledAt, with your own sender.
    }
}
```

When they are delivered:

- An event published while the command processes a cycle is delivered once that cycle's change is
  stored and committed, as Sylius's own events are, and not at all if it fails.
- An event of anything done outside the command, such as an administrator suspending a subscription, the
  customer cancelling it or changing its frequency or its address, or the payment of its initial order
  activating it, may be handled while that request runs, before its changes are stored. It waits for
  them only when the action is itself a message of one of Sylius's command buses, which commit before
  delivering: skipping a renewal is one, so `RenewalSkipped` is delivered once the skip is stored, and
  not at all if the cycles command changed the cycle meanwhile.
- A handler run synchronously that throws:
  - in the command, makes it report the cycle as failed although the cycle was stored. Running the
    command again does not charge it twice, since the cycle changed, but the report misleads;
  - anywhere else, stops the action, and its change is not stored: while your mail service is down, a
    customer could not cancel their subscription, nor an administrator suspend one.

So route the events you send notices from to an asynchronous transport, where a notice that fails is
retried by the worker instead of stopping what happens to a subscription:

```yaml
framework:
    messenger:
        routing:
            'JpmMartin\SyliusSubscriptionPlugin\Event\SubscriptionEventInterface': async
```

The plugin itself never stops a transition to publish its event: one of a subscription, cycle or
renewal order that is not stored yet, which no flow of the plugin makes, publishes nothing and is
logged. Only a handler of yours that throws, run synchronously, stops it, as above.

## Upgrading

From a version without customer payments of declined renewals, nothing needs migrating: a charge
attempt gains the `customer` type. Sylius's order payment page now offers, on a renewal order, only the
methods the plugin can charge; every other order is as before. `customer_payment_wait_minutes` is new,
with a default.

From a version without item changes, `doctrine:migrations:migrate` adds when each item was removed, with
no item removed before. Going back down is refused while any item is removed, since that version would
renew it again.

From a version without the subscription's own addresses, `doctrine:migrations:migrate` adds them,
empty: every subscription keeps renewing to its last order's addresses until they are changed. Going
back down deletes the addresses the subscriptions had of their own.

From a version without pausing, `doctrine:migrations:migrate` adds whether each cycle was skipped, with
no cycle skipped before. Subscriptions gain the `paused` state: a store that shows the states in its
own templates or translations has one more to add. Before going back to a version without it, resume
or cancel the paused subscriptions, since that version's graph does not know the state.

From a version that charged each missed date, one per run of the command, the default is now to skip
them; see "Missed dates". Set `missed_cycles: charge` to keep charging them. Nothing needs migrating.

From a version without store frequencies, `doctrine:migrations:migrate` adds their tables and columns;
nothing else changes, and every existing subscription keeps its plans.

From a version with one subscription per order line:

- `doctrine:migrations:migrate` turns every existing subscription into a subscription of one item, with
  the cycles it had paid counted as the item's. Going back down works while no subscription has more
  than one item.
- `on_failure` is gone, because a failed cycle no longer suspends or cancels a subscription by itself;
  the configuration refuses it with a message. Use `suspend_after_failed_cycles` instead.

## Known limitations

- Renewal orders are placed from code, and Sylius sends its order confirmation email only from the
  shop's checkout and the API's, so no confirmation is sent for them. Send your own from `RenewalPaid`;
  see [Events](#events).
- An order that skips the payment step cannot start a subscription: the plugin needs the payment
  method it will charge the renewals with.
- With `missed_cycles: charge`, the dates a subscription missed while the command did not run are
  processed one per run, each with its own order and its own charge.
- Changing the frequency is not offered while the open cycle's order is awaiting payment, because that
  order keeps the old prices. It is only offered for intervals every item can move to: an item on a
  plan to an enabled plan of its variant, an item repeated with a store frequency to another enabled
  frequency of the channel, never from one kind to the other. Every item moves: one that had used up
  its plan or frequency renews again if the new one allows more cycles.
- "Repeat this cart" is outside Sylius's live cart form, so it shows what the cart had when the page
  was loaded: after lines are changed in place, it catches up on the next page load. The lines
  themselves always show what they renew on.
- The shop API lists frequencies without a page of their own: the `@id` of each one in the list is not
  exposed, and answers 404.
- A frequency's name is the admin's; the shop shows the interval and the discount instead ("Every
  month (save 5%)"), which are translated.
- An item that cannot be sold is skipped in every cycle until it can again, or until its customer
  changes or removes it.
- Products are added to a subscription from its page in the customer's account, not from the product
  page: the product page's form is Sylius's live cart form.
- With a gateway that charges through Payum rather than payment requests, a retry cannot see that the
  customer is paying the same renewal, so both could charge it. Prefer gateways with payment requests,
  Sylius 2's own.
- A change of items saved while the command places the open cycle's order may miss that order, which
  keeps the items it was placed with; the next renewal carries the change.

## Development

The plugin is developed against Sylius's test application.

```bash
(cd vendor/sylius/test-application && yarn install && yarn build)
vendor/bin/console assets:install
vendor/bin/console doctrine:database:create
vendor/bin/console doctrine:migrations:migrate -n
```

Configure the database in `tests/TestApplication/.env.local` and `tests/TestApplication/.env.test.local`.

```bash
composer check   # ECS, PHPStan, PHPUnit and Behat without JavaScript
```

### Scenarios with JavaScript

The `@javascript` scenarios drive a headless Chrome listening on `127.0.0.1:9222` against the test
application served on the URL in `BEHAT_BASE_URL` (`http://127.0.0.1:8080/` unless
`tests/TestApplication/.env.test.local` says otherwise). Any Chrome started with remote debugging
works; in Docker:

```bash
docker run -d --rm --name chrome --network host --shm-size=1g chromedp/headless-shell:latest --window-size=2880,1800
APP_ENV=test symfony server:start --port=8080 --dir=vendor/sylius/test-application/public --daemon --no-tls
composer behat-js
```

If the product page answers 500 with an empty body, PHP-FPM has run out of memory: the test
environment needs more than the 128M of a default `php.ini`. Raise `memory_limit` for the PHP the
server uses, for example with an extra ini file:
`PHP_INI_SCAN_DIR=":/path/to/dir-with-a-memory-ini" symfony server:start ...`.

### Another database

The tests use whatever `DATABASE_URL` says, and an environment variable wins over the `.env` files.
To run them on MariaDB 11.4, for example:

```bash
docker run -d --rm --name mariadb -e MYSQL_ROOT_PASSWORD=root -e MYSQL_USER=sylius -e MYSQL_PASSWORD=sylius \
    -e MYSQL_DATABASE=sylius -p 127.0.0.1:3307:3306 mariadb:11.4
# once it accepts connections:
export APP_ENV=test DATABASE_URL="mysql://sylius:sylius@127.0.0.1:3307/sylius?serverVersion=mariadb-11.4.0"
vendor/bin/console doctrine:migrations:migrate -n
composer migrations-roundtrip   # takes the plugin's migrations down and up again
composer check
```

Run one suite at a time per checkout: the test clock is a file of the test application
(`vendor/sylius/test-application/var/date.txt`), so two runs from the same directory change each
other's date.

### Continuous integration

`.github/workflows/build.yaml` runs on every push and pull request:

- once, `composer validate --strict`, ECS and PHPStan;
- for each database above with PHP 8.3 and Symfony 7.4, and on PostgreSQL 17 with PHP 8.2 and
  Symfony 6.4, and with PHP 8.4 and 8.5 and Symfony 7.4 (ten runs, every supported database, PHP and
  Symfony at least once): the container lint, the migrations' round trip, PHPUnit, and Behat without
  and with JavaScript.

The test application is built by Sylius's own action, as in Sylius's PluginSkeleton, which migrates
MariaDB as if it were MySQL; the tests then run with MariaDB named in `DATABASE_URL`, as a store
configures it. A failing combination does not stop the others, and the Behat logs and screenshots of
a failed run are kept as an artifact. A browser scenario that fails is run once more: if it then
passes, the run stays green but carries a warning for each scenario that failed, with its title and
at its line in the feature file; if it fails again, the job fails. A scenario broken by a hook, which
Behat lists without its title, adds a warning pointing to the step's log. GitHub shows ten warnings a
step, so past that the last warning names the rest, each with its file and line.

The tests charge renewals through a scripted gateway in the test application
(`tests/TestApplication/src/Payment`), with payment requests handled synchronously and encrypted with
a key kept for the tests only.

## License

MIT. See [LICENSE](LICENSE).
