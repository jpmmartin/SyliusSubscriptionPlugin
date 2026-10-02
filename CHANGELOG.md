# Changelog

All notable changes to this project are documented in this file.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project
follows [Semantic Versioning](https://semver.org/spec/v2.0.0.html). What a version number promises
here, and what counts as a breaking change, are written down in [RELEASING.md](RELEASING.md).

## [Unreleased]

### Changed

- The Flex recipe is served by `symfony/recipes-contrib` since 2026-10-02: on a store with Flex, Sylius
  Standard's included, `composer require` applies it with nothing to configure. The README's
  installation starts there.

## [1.1.0] - 2026-09-29

### Added

- A Symfony Flex recipe, in `recipe/`: on a store with Flex, `composer require` registers the bundle,
  writes the plugin's configuration and routes, and prints the steps no recipe can take.

### Changed

- Until the store's order item carries the plan, the plugin no longer stops the application from
  booting. It offers no product by subscription, refuses a plan or a frequency asked for through the
  API as not offered, the admin's dashboard and subscriptions list say what is left, and the cycles
  command warns. No line chosen as a subscription can become a one-off purchase meanwhile.
- The configuration the README shows names no payment method: `payment_methods: []`, as the recipe
  writes it.

## [1.0.0] - 2026-09-28

The first release.

For Sylius 2.2, on PHP 8.2 to 8.5 and Symfony 6.4 or 7.4, on MySQL 8.0 or 8.4, MariaDB 10.11 or 11.4,
or PostgreSQL 15 to 17; and for Sylius 2.3, on MySQL or PostgreSQL.

### Added

- Plans per variant: an interval of days, weeks, months or years, a subscriber discount and an
  optional maximum of cycles, managed from the variant's *Subscription* tab in the admin.
- The store's own frequencies, with the channels that offer them and the variants that can be
  repeated, and *Repeat this cart*: every one-time line of such a variant renews with the frequency
  the customer chooses, at the variant's price less its discount.
- Mixed carts: the product page offers a one-time purchase or a plan, and a subscription line is
  never merged with a one-time line of the same variant.
- A checkout, in the shop and the API alike, that asks for a customer with an account, a payment
  method that can be charged without the customer, and the customer's consent to recurring charges,
  versioned and stored as they saw it.
- One subscription per interval among an order's lines, with an item per line: products bought on
  the same interval renew together, in one order.
- Introductory prices: a discount of their own on the first order, or the first cycles, of each new
  subscription.
- Free trials: some days free for each new subscriber, once per customer and variant, with a payment
  method whose gateway keeps the card without charging it.
- Minimum commitments: a number of paid cycles before the customer can cancel or pause.
- Prepaid deliveries: a block of deliveries charged at once, the deliveries between charges placed as
  orders of nothing.
- Renewals by a console command. Each cycle is checked by the store's gates, gets one renewal order
  with a line per item at its frozen price, and is charged through a service the store can replace.
  An item that cannot be sold that day is skipped in that cycle only, and the cycle says why. Running
  the command twice never charges a cycle twice.
- Declined charges retried by a policy the store configures or replaces. An unknown outcome is checked
  with the gateway instead of charged again, and dates missed while the command did not run are
  skipped or charged, as the store configures.
- Failures that never cancel: a cycle that cannot be charged fails and the next one is scheduled. Only
  a run of failed cycles suspends the subscription, three by default.
- The customer's account: pausing, resuming and skipping the next renewal, cancelling, changing the
  frequency, the address and the items, adding a product, paying a declined renewal, changing the card
  through the gateway, and recovering a subscription suspended for unpaid renewals.
- The admin: a filterable list, and a page with every renewal and charge attempt, the actions the
  state allows and the retry of a failed renewal.
- Price updates: an administrator reprices the subscriptions of a plan, a store frequency or a variant
  from today's catalogue. A decrease applies at once; an increase after a configurable notice and, if
  the store wants, the customer's acceptance.
- The shop API: the cart's subscription operations, and the signed-in customer's subscriptions with
  every action of their account.
- Events at every moment of a subscription's life, on `sylius.event_bus`, for the store to tell its
  customers: the plugin sends no email.
- A read-only query of what the active subscriptions of a variant will renew within a horizon, for
  planning stock.
- English and Spanish translations.

### Known limitations

Stated here as well as in the README, because they decide whether this release fits a store:

- **No confirmation email for renewal orders.** Sylius sends it only from its own checkouts; send
  yours from `RenewalPaid`.
- **An order of 0 without a free trial cannot start a subscription**: the plugin needs the payment
  method it will charge the renewals with.
- **With a Payum gateway, a retry and the customer's own payment could both charge a renewal.** Prefer
  gateways with payment requests, Sylius 2's own.
- **The shop API does not take free trials yet.** Completing a cart with a free trial through it is
  refused with a message.
- **An introductory price is offered to every new subscription**, a returning customer's included. For
  first-time customers only, use a Sylius promotion with the "Nth order" rule.
- **Changing the frequency is limited**: not while the open cycle's order awaits payment, and only to
  intervals every item can move to.
- **MariaDB with Sylius 2.3 is not supported.** Sylius 2.3's own migrations do not create its tables on
  a MariaDB that DBAL 4 recognises, and DBAL 4 misreads one it does not.

[Unreleased]: https://github.com/jpmmartin/SyliusSubscriptionPlugin/compare/v1.1.0...main
[1.1.0]: https://github.com/jpmmartin/SyliusSubscriptionPlugin/releases/tag/v1.1.0
[1.0.0]: https://github.com/jpmmartin/SyliusSubscriptionPlugin/releases/tag/v1.0.0
