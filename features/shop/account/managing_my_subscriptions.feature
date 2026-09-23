@managing_my_subscriptions
Feature: Managing my subscriptions
    In order to decide what I keep receiving and how often
    As a Customer
    I want to see my subscriptions in my account, cancel them and change how often they renew

    Background:
        Given the store operates on a single channel in "United States"
        And the store has a product "Coffee" priced at "$20.00"
        And the "Coffee" variant offers a "COFFEE_MONTHLY" subscription plan renewing every 1 month with a 10% discount
        And the "Coffee" variant offers a "COFFEE_QUARTERLY" subscription plan renewing every 3 months with a 15% discount
        And the store has a product "Tea" priced at "$10.00"
        And the "Tea" variant offers a "TEA_MONTHLY" subscription plan renewing every 1 month
        And the "Tea" variant offers a "TEA_QUARTERLY" subscription plan renewing every 3 months
        And the "Tea" variant offers a "TEA_WEEKLY" subscription plan renewing every 1 week
        And the store ships everywhere for Free
        And the store allows paying with "Card on file"
        And the "Card on file" payment method charges renewals through the test gateway
        And I am a logged in customer
        And it is "2027-01-01 09:00" now
        And I subscribed to "Coffee" on the "COFFEE_MONTHLY" plan and to "Tea" on the "TEA_MONTHLY" plan

    @ui
    Scenario: Seeing my subscriptions
        When I browse my subscriptions
        Then I should see my subscription to "Coffee" renewing "Every month"
        And my subscription to "Tea" should cost "$28.00" per renewal
        And my subscription to "Coffee" should be "Active"
        And my subscription to "Coffee" should next renew on "Feb 1, 2027"

    @ui
    Scenario: Seeing a subscription of several products
        When I view my subscription to "Coffee"
        Then this subscription should have 1 "Coffee" at "$18.00"
        And this subscription should have 1 "Tea" at "$10.00"
        And it should cost "$28.00" per renewal
        And this subscription should renew "Every month"
        And it should next renew on "Feb 1, 2027"

    @ui
    Scenario: Seeing the size of a product sold in sizes
        Given the store has a "T-Shirt" configurable product
        And this product has option "Size" with values "S", "M" and "L"
        And this product has "T-Shirt M" variant priced at "$20.00" configured with "M" option value
        And the "T-Shirt M" variant offers a "T_SHIRT_M_MONTHLY" subscription plan renewing every 1 month
        And I subscribed to "T-Shirt" on the "T_SHIRT_M_MONTHLY" plan
        When I browse my subscriptions
        Then my subscription to "T-Shirt" should list 1 "T-Shirt" in "Size: M" at "$20.00"
        When I view my subscription to "T-Shirt"
        Then this subscription should have 1 "T-Shirt" in "Size: M" at "$20.00"

    @ui
    Scenario: Seeing the renewals of a subscription
        When I view my subscription to "Coffee"
        Then this subscription should have 2 renewals
        And its renewal #1 should be "Paid" on "Jan 1, 2027"
        And its renewal #2 should be "Scheduled" on "Feb 1, 2027"

    @ui
    Scenario: Seeing a renewal that skipped a product out of stock
        Given the product "Tea" is out of stock
        And the renewals due on "2027-02-01 09:00" are processed
        When I view my subscription to "Coffee"
        Then its renewal #2 should be "Paid" on "Feb 1, 2027"
        And its renewal #2 should have skipped "Tea" because "Out of stock"
        And its renewal #2 should have been charged "$18.00"

    @ui
    Scenario: Cancelling a subscription before it renews
        Given it is "2027-01-22 09:00" now
        When I view my subscription to "Coffee"
        And I cancel this subscription
        Then I should be notified that the subscription has been cancelled
        And this subscription should be "Cancelled"
        And its renewal #2 should be "Cancelled" on "Feb 1, 2027"
        And I should not be able to cancel it again
        When the renewals due on "2027-02-01 09:00" are processed
        Then no renewal order should have been placed for it

    @ui
    Scenario: Changing from monthly to quarterly halfway through the month
        Given it is "2027-01-15 09:00" now
        When I view my subscription to "Coffee"
        And I change its frequency to "Every 3 months"
        Then I should be notified that the subscription frequency has been changed
        And this subscription should renew "Every 3 months"
        And it should cost "$27.00" per renewal
        And it should next renew on "Feb 1, 2027"
        When the renewals due on "2027-02-01 09:00" are processed
        Then its renewal #2 should have been charged "$27.00"
        When I view my subscription to "Coffee"
        Then it should next renew on "May 1, 2027"

    @ui
    Scenario: Changing a repeated cart to another of the store's frequencies
        Given the store has a product "Honey" priced at "$10.00"
        And the "Honey" variant can be repeated
        And the store offers a "MONTHLY" subscription frequency renewing every 1 month with a 5% discount
        And the store offers a "QUARTERLY" subscription frequency renewing every 3 months with a 10% discount
        And I repeated my cart of the "Honey" variant with the "MONTHLY" subscription frequency
        And it is "2027-01-15 09:00" now
        When I view my subscription to "Honey"
        Then it should cost "$9.50" per renewal
        When I change its frequency to "Every 3 months"
        Then I should be notified that the subscription frequency has been changed
        And this subscription should renew "Every 3 months"
        And it should cost "$9.00" per renewal
        And it should next renew on "Feb 1, 2027"

    @ui
    Scenario: Being offered only the frequencies every product of the subscription has a plan for
        When I view my subscription to "Coffee"
        And I start changing its frequency
        Then I should only be offered the "Every 3 months" frequency

    @ui
    Scenario: Not seeing another customer's subscription
        Given the customer "ann@example.com" subscribed to "Coffee" on the "COFFEE_MONTHLY" plan
        When I try to view that subscription
        Then I should be told that it does not exist
