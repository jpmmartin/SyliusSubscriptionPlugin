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
