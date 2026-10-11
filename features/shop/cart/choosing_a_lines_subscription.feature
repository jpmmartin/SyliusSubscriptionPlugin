@choosing_a_lines_subscription
Feature: Choosing a single line's subscription
    In order to subscribe to, or stop subscribing to, one product without touching the rest of my cart
    As a Visitor
    I want to choose a frequency or a plan on a single line of my cart

    Background:
        Given the store operates on a single channel in "United States"
        And the store has a product "Coffee" priced at "$20.00"
        And the store has a product "Tea" priced at "$10.00"
        And the store has a product "Gift card" priced at "$50.00"
        And the "Coffee" variant can be repeated
        And the "Tea" variant offers a "TEA_MONTHLY" subscription plan renewing every 1 month with a 10% discount
        And the "Tea" variant offers a "TEA_WEEKLY" subscription plan renewing every 1 week with a 0% discount
        And the store offers a "MONTHLY" subscription frequency renewing every 1 month with a 5% discount
        And the store offers a "BIWEEKLY" subscription frequency renewing every 2 weeks with a 10% discount

    @ui
    Scenario: Choosing a frequency on a single line
        Given I have product "Coffee" in the cart
        When I choose "Every 2 weeks (save 10%)" for the "Coffee" line
        Then the "Coffee" line should be repeated "Every 2 weeks (save 10%)"

    @ui
    Scenario: Changing a line's plan to another plan
        Given I have product "Tea" in the cart on the "TEA_MONTHLY" plan
        When I choose "Subscribe: TEA_WEEKLY" for the "Tea" line
        Then the "Tea" line should have the plan "TEA_WEEKLY"

    @ui
    Scenario: Changing a line's plan to one-time
        Given I have product "Tea" in the cart on the "TEA_MONTHLY" plan
        When I choose "One-time purchase" for the "Tea" line
        Then the "Tea" line should have no plan and should not be repeated

    @ui
    Scenario: A line with only one option has no dropdown
        Given I have product "Gift card" in the cart
        Then the "Gift card" line should not offer to change its subscription

    @ui @javascript
    Scenario: Choosing a frequency without the live cart form erroring, and still changing the quantity after
        Given I have product "Coffee" in the cart
        When I choose "Every 2 weeks (save 10%)" for the "Coffee" line
        Then the "Coffee" line should be repeated "Every 2 weeks (save 10%)"
        When I change product "Coffee" quantity to 3 in my cart
        Then my cart total should be "$54.00"
