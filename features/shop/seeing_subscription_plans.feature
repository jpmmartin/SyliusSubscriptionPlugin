@subscribing_to_products
Feature: Seeing the subscription plans of a product
    In order to decide whether to buy a product once or subscribe to it
    As a Visitor
    I want to see the plans its variant offers on the product page

    Background:
        Given the store operates on a single channel in "United States"
        And the store has a product "Coffee" priced at "$20.00"

    @ui
    Scenario: Seeing the plans a variant offers
        Given the "Coffee" variant offers a "COFFEE_MONTHLY" subscription plan renewing every 1 month with a 10% discount
        And the "Coffee" variant offers a "COFFEE_QUARTERLY" subscription plan renewing every 3 months with a 15% discount
        When I view product "Coffee" in the store
        Then I should be able to buy it once or subscribe on the "COFFEE_MONTHLY" and "COFFEE_QUARTERLY" plans
        And the choices should read "One-time purchase", "Subscribe: COFFEE_MONTHLY (save 10%)" and "Subscribe: COFFEE_QUARTERLY (save 15%)"

    @ui
    Scenario: Seeing the introductory price of a plan
        Given the "Coffee" variant offers a "COFFEE_MONTHLY" subscription plan renewing every 1 month with a 10% discount
        And the "COFFEE_MONTHLY" subscription plan has an introductory discount of 50% for the first 3 orders
        And the "Coffee" variant offers a "COFFEE_QUARTERLY" subscription plan renewing every 3 months with a 15% discount
        And the "COFFEE_QUARTERLY" subscription plan has an introductory discount of 25% for the first order
        When I view product "Coffee" in the store
        Then the "COFFEE_MONTHLY" plan should read "$10.00 for your first 3 orders, then $18.00"
        And the "COFFEE_QUARTERLY" plan should read "$15.00 for your first order, then $17.00"

    @ui
    Scenario: Seeing the minimum commitment of a plan
        Given the "Coffee" variant offers a "COFFEE_MONTHLY" subscription plan renewing every 1 month with a 10% discount
        And the "COFFEE_MONTHLY" subscription plan has a minimum commitment of 6 cycles
        When I view product "Coffee" in the store
        Then the "COFFEE_MONTHLY" plan should warn "Minimum commitment: you can cancel once you have paid 6 orders"

    @ui
    Scenario: Seeing the minimum commitment of a line in my cart
        Given the "Coffee" variant offers a "COFFEE_MONTHLY" subscription plan renewing every 1 month with a 10% discount
        And the "COFFEE_MONTHLY" subscription plan has a minimum commitment of 6 cycles
        And I have product "Coffee" in the cart on the "COFFEE_MONTHLY" plan
        Then the "Coffee" line of my cart should warn "Minimum commitment: you can cancel once you have paid 6 orders"

    @ui
    Scenario: Seeing the free trial of a plan
        Given the "Coffee" variant offers a "COFFEE_MONTHLY" subscription plan renewing every 1 month with a 10% discount
        And the "COFFEE_MONTHLY" subscription plan has a free trial of 14 days
        When I view product "Coffee" in the store
        Then the "COFFEE_MONTHLY" plan should read "14 days free, then $18.00"

    @ui
    Scenario: Seeing the free trial of a line in my cart
        Given the "Coffee" variant offers a "COFFEE_MONTHLY" subscription plan renewing every 1 month with a 10% discount
        And the "COFFEE_MONTHLY" subscription plan has a free trial of 14 days
        And I have product "Coffee" in the cart on the "COFFEE_MONTHLY" plan
        Then the "Coffee" line of my cart should read "14 days free, then $18.00"
        And I should see "Coffee" with unit price "$0.00" in my cart

    @ui
    Scenario: Seeing the introductory price of a line in my cart
        Given the "Coffee" variant offers a "COFFEE_MONTHLY" subscription plan renewing every 1 month with a 10% discount
        And the "COFFEE_MONTHLY" subscription plan has an introductory discount of 50% for the first order
        And I have product "Coffee" in the cart on the "COFFEE_MONTHLY" plan
        Then the "Coffee" line of my cart should read "$10.00 for your first order, then $18.00"
        And I should see "Coffee" with unit price "$10.00" in my cart

    @ui
    Scenario: Not being offered a disabled plan
        Given the "Coffee" variant offers a "COFFEE_MONTHLY" subscription plan renewing every 1 month
        And the "Coffee" variant offers a "COFFEE_QUARTERLY" subscription plan renewing every 3 months
        And the "COFFEE_QUARTERLY" subscription plan is disabled
        When I view product "Coffee" in the store
        Then I should be able to buy it once or subscribe on the "COFFEE_MONTHLY" plan

    @ui
    Scenario: Buying once a product whose variant offers no plans
        When I view product "Coffee" in the store
        Then I should only be able to buy it once

    @ui @javascript
    Scenario: Subscribing to a product from its page
        Given the "Coffee" variant offers a "COFFEE_MONTHLY" subscription plan renewing every 1 month with a 10% discount
        When I view product "Coffee" in the store
        And I choose to subscribe on the "COFFEE_MONTHLY" plan
        And I add it to my cart
        Then my cart should have "Coffee" on the "COFFEE_MONTHLY" plan
        And I should see "Coffee" with unit price "$18.00" in my cart
