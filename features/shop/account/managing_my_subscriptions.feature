@managing_my_subscriptions
Feature: Managing my subscriptions
    In order to decide what I keep receiving and how often
    As a Customer
    I want to see my subscriptions in my account, pause, resume or cancel them, skip a renewal, change where and how often they renew, and change what they bring

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
    Scenario: Pausing a subscription before it renews
        Given it is "2027-01-20 09:00" now
        When I view my subscription to "Coffee"
        And I pause this subscription
        Then I should be notified that the subscription has been paused
        And this subscription should be "Paused"
        And its renewal #2 should be "Cancelled" on "Feb 1, 2027"
        When the renewals due on "2027-02-01 09:00" are processed
        Then no renewal order should have been placed for it

    @ui
    Scenario: Resuming a paused subscription on the first date of its calendar
        Given my subscription has been paused
        And it is "2027-03-10 12:00" now
        When I view my subscription to "Coffee"
        And I resume this subscription
        Then I should be notified that the subscription has been resumed
        And this subscription should be "Active"
        And it should next renew on "Apr 1, 2027"

    @ui
    Scenario: Skipping the next renewal
        Given it is "2027-01-20 09:00" now
        When I view my subscription to "Coffee"
        And I skip its renewal of "Feb 1, 2027"
        Then I should be notified that the renewal has been skipped
        And its renewal #2 should be "Skipped" on "Feb 1, 2027"
        And it should next renew on "Mar 1, 2027"
        When the renewals due on "2027-02-01 09:00" are processed
        Then no renewal order should have been placed for it

    @ui
    Scenario: Not being offered to resume a subscription suspended after three failed renewals in a row
        Given the next 3 renewals of my subscription failed
        When I view my subscription to "Coffee"
        Then this subscription should be "Suspended"
        And I should not be able to pause or resume it, nor skip its next renewal

    @ui
    Scenario: Changing the address of a subscription to one of my address book
        Given I have an address "John Doe", "Elm Street 13", "43210", "Springwood", "United States" in my address book
        And it is "2027-01-20 09:00" now
        When I view my subscription to "Coffee"
        And I change its shipping address to the "Elm Street 13" address of my address book
        Then I should be notified that the subscription's addresses have been changed
        And this subscription should be shipped to "Elm Street 13"
        When the renewals due on "2027-02-01 09:00" are processed
        Then its renewal #2 should be shipped to "Elm Street 13" with "Free"

    @ui
    Scenario: Moving to another zone and choosing a shipping method that reaches it
        Given the store operates in "France"
        And the store has a zone "Europe" with code "EU"
        And it has the "France" country member
        And the store has "Europe Express" shipping method with "$15.00" fee within the "EU" zone
        And it is "2027-01-20 09:00" now
        When I view my subscription to "Coffee"
        And I change its shipping address to "Paris", "Rue de Rivoli 1", "75001", "France" for "John Doe"
        Then I should be asked to choose the "Europe Express" shipping method for "$15.00"
        When I choose the "Europe Express" shipping method
        Then I should be notified that the subscription's addresses have been changed
        And this subscription should be shipped to "Rue de Rivoli 1"
        When the renewals due on "2027-02-01 09:00" are processed
        Then its renewal #2 should be shipped to "Rue de Rivoli 1" with "Europe Express"

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

    @ui
    Scenario: Raising the quantity of a product and accepting the recurring charges again
        Given it is "2027-01-20 09:00" now
        When I view my subscription to "Coffee"
        And I start changing its items
        And I change the quantity of "Coffee" to 2
        And I save my item changes
        Then I should be asked to accept the recurring charges for "$46.00" per renewal
        When I accept the recurring charges again
        And I save my item changes
        Then I should be notified that the subscription's items have been changed
        And this subscription should have 2 "Coffee" at "$18.00"
        And it should cost "$46.00" per renewal

    @ui
    Scenario: Moving a product to another of its variants
        Given the product "Coffee" has a "Decaf" variant priced at "$25.00"
        And the "Decaf" variant offers a "DECAF_MONTHLY" subscription plan renewing every 1 month with a 10% discount
        When I view my subscription to "Coffee"
        And I start changing its items
        And I move "Coffee" to the "Decaf" variant
        And I save my item changes
        Then I should be asked to accept the recurring charges for "$32.50" per renewal
        When I accept the recurring charges again
        And I save my item changes
        Then I should be notified that the subscription's items have been changed
        And this subscription should have 1 "Coffee" in "Decaf" at "$22.50"

    @ui
    Scenario: Removing a product without being asked to accept the recurring charges again
        When I view my subscription to "Coffee"
        And I start changing its items
        And I remove "Tea"
        And I save my item changes
        Then I should be notified that the subscription's items have been changed
        And it should cost "$18.00" per renewal
        And "Tea" should be marked as removed from this subscription

    @ui
    Scenario: Adding a product
        Given the store has a product "Honey" priced at "$5.00"
        And the "Honey" variant offers a "HONEY_MONTHLY" subscription plan renewing every 1 month
        When I view my subscription to "Coffee"
        And I add 2 "Honey" to it accepting the recurring charges
        Then I should be notified that the product has been added to the subscription
        And this subscription should have 2 "Honey" at "$5.00"
        And it should cost "$38.00" per renewal

    @ui
    Scenario: Not adding a product without accepting the recurring charges
        Given the store has a product "Honey" priced at "$5.00"
        And the "Honey" variant offers a "HONEY_MONTHLY" subscription plan renewing every 1 month
        When I view my subscription to "Coffee"
        And I try to add 1 "Honey" to it without accepting the recurring charges
        Then I should be told to accept the recurring charges
        When I view my subscription to "Coffee"
        Then it should cost "$28.00" per renewal

    @ui
    Scenario: Paying a renewal whose charge was declined from my account
        Given the test gateway will decline the next charge with "Insufficient funds."
        And the renewals due on "2027-02-01 09:00" are processed
        When I view my subscription to "Coffee"
        And I pay its renewal #2 now
        And I view my subscription to "Coffee"
        Then its renewal #2 should be "Paid"
        And its renewal #2 should be marked as paid by me
        And I should not be able to pay its renewal #2 now

    @ui
    Scenario: Changing the card on the gateway's page
        When I view my subscription to "Coffee"
        Then I should be able to change its card on the gateway's page "https://gateway.example.com/cards/update?subscription="

    @ui
    Scenario: Recovering a subscription suspended after its renewals failed by paying the last one
        Given the next 3 renewals of my subscription failed
        And it is "2027-04-10 09:00" now
        When I view my subscription to "Coffee"
        And I pay and reactivate it
        And I view my subscription to "Coffee"
        Then this subscription should be "Active"
        And its renewal #4 should be "Paid"
        And it should next renew on "May 1, 2027"

    @ui
    Scenario: Being told why a subscription suspended after its renewals failed cannot be recovered
        Given the next 3 renewals of my subscription failed
        And the product "Coffee" has been disabled
        And the product "Tea" has been disabled
        And it is "2027-04-10 09:00" now
        When I view my subscription to "Coffee"
        Then I should not be able to pay and reactivate it, because "It cannot be recovered now: none of its products can be sold."
