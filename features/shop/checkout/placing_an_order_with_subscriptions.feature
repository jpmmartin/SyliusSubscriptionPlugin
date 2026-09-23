@subscription_checkout
Feature: Placing an order with subscriptions
    In order to be charged for my subscriptions later without being present
    As a Customer
    I want to place an order with subscriptions only with an account, a suitable payment method and my consent

    Background:
        Given the store operates on a single channel in "United States"
        And the store has a product "Coffee" priced at "$20.00"
        And the "Coffee" variant offers a "COFFEE_MONTHLY" subscription plan renewing every 1 month with a 10% discount
        And the store ships everywhere for Free
        And the store allows paying with "Card on file"
        And the store also allows paying Offline

    @api @ui
    Scenario: Placing an order with a subscription
        Given I am a logged in customer
        And I have product "Coffee" in the cart on the "COFFEE_MONTHLY" plan
        And I am at the checkout addressing step
        When I specify the billing address as "Ankh Morpork", "Frost Alley", "90210", "United States" for "Jon Snow"
        And I complete the addressing step
        And I proceed with "Free" shipping method and "Card on file" payment
        And I accept the recurring charges of my subscriptions
        And I confirm my order
        Then I should see the thank you page
        And the recurring charges I accepted on my order should be recorded as version "1"

    @api @ui
    Scenario: Not placing an order with a subscription as a guest
        Given I have product "Coffee" in the cart on the "COFFEE_MONTHLY" plan
        When I complete addressing step with email "john@example.com" and "United States" based billing address
        And I select "Free" shipping method
        And I complete the shipping step
        And I choose "Card on file" payment method
        And I accept the recurring charges of my subscriptions
        And I confirm my order
        Then I should be told that subscriptions need an account

    @api @ui
    Scenario: Not placing an order with a repeated cart as a guest
        Given the "Coffee" variant can be repeated
        And the store offers a "MONTHLY" subscription frequency renewing every 1 month with a 5% discount
        And I have product "Coffee" in the cart
        And I chose to repeat my cart with the "MONTHLY" subscription frequency
        When I complete addressing step with email "john@example.com" and "United States" based billing address
        And I select "Free" shipping method
        And I complete the shipping step
        And I choose "Card on file" payment method
        And I accept the recurring charges of my subscriptions
        And I confirm my order
        Then I should be told that subscriptions need an account

    @api @ui
    Scenario: Not placing an order with a subscription with a payment method that cannot charge renewals
        Given I am a logged in customer
        And I have product "Coffee" in the cart on the "COFFEE_MONTHLY" plan
        And I am at the checkout addressing step
        When I specify the billing address as "Ankh Morpork", "Frost Alley", "90210", "United States" for "Jon Snow"
        And I complete the addressing step
        And I proceed with "Free" shipping method and "Offline" payment
        And I accept the recurring charges of my subscriptions
        And I confirm my order
        Then I should be told that the "Offline" payment method cannot be used for subscriptions

    @api @ui
    Scenario: Not placing an order with a subscription without accepting its recurring charges
        Given I am a logged in customer
        And I have product "Coffee" in the cart on the "COFFEE_MONTHLY" plan
        And I am at the checkout addressing step
        When I specify the billing address as "Ankh Morpork", "Frost Alley", "90210", "United States" for "Jon Snow"
        And I complete the addressing step
        And I proceed with "Free" shipping method and "Card on file" payment
        And I confirm my order
        Then I should be told to accept the recurring charges of my subscriptions

    @api @ui
    Scenario: Placing an order without subscriptions as a guest, as before
        Given I added product "Coffee" to the cart
        When I complete addressing step with email "john@example.com" and "United States" based billing address
        And I select "Free" shipping method
        And I complete the shipping step
        And I choose "Offline" payment method
        And I confirm my order
        Then I should see the thank you page
