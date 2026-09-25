@managing_subscriptions
Feature: Managing subscriptions
    In order to look after the customers' renewals
    As an Administrator
    I want to find subscriptions, see what they renew and every charge attempt, retry failed renewals, skip a renewal, and change their state or frequency

    Background:
        Given the store operates on a single channel in "United States"
        And the store has a product "Coffee" priced at "$20.00"
        And the "Coffee" variant offers a "COFFEE_MONTHLY" subscription plan renewing every 1 month with a 10% discount
        And the "Coffee" variant offers a "COFFEE_QUARTERLY" subscription plan renewing every 3 months with a 15% discount
        And the store has a product "Tea" priced at "$10.00"
        And the "Tea" variant offers a "TEA_WEEKLY" subscription plan renewing every 1 week
        And the "Tea" variant offers a "TEA_MONTHLY" subscription plan renewing every 1 month
        And the "Tea" variant offers a "TEA_QUARTERLY" subscription plan renewing every 3 months
        And the store ships everywhere for Free
        And the store allows paying with "Card on file"
        And the "Card on file" payment method charges renewals through the test gateway
        And it is "2027-01-01 09:00" now
        And the customer "ann@example.com" subscribed to "Coffee" on the "COFFEE_MONTHLY" plan and to "Tea" on the "TEA_MONTHLY" plan
        And the customer "bob@example.com" subscribed to "Tea" on the "TEA_WEEKLY" plan
        And I am logged in as an administrator

    @ui
    Scenario: Browsing the subscriptions
        When I browse subscriptions
        Then I should see 2 subscriptions in the list
        And I should see the subscription of "ann@example.com" to "Coffee" renewing next on "01-02-2027"
        And I should see the subscription of "bob@example.com" to "Tea" renewing next on "08-01-2027"

    @ui
    Scenario: Filtering the subscriptions by customer
        When I filter the subscriptions by customer "ann@"
        Then I should see 1 subscription in the list

    @ui
    Scenario: Filtering the subscriptions by a variant any of their products is of
        When I filter the subscriptions by variant "TEA"
        Then I should see 2 subscriptions in the list
        And I should see the subscription of "ann@example.com" to "Tea" renewing next on "01-02-2027"
        And I should see the subscription of "bob@example.com" to "Tea" renewing next on "08-01-2027"

    @ui
    Scenario: Filtering the subscriptions by state
        Given the subscription of "bob@example.com" has been suspended
        When I filter the subscriptions by state "Suspended"
        Then I should see 1 subscription in the list

    @ui
    Scenario: Filtering the subscriptions by the paused state
        Given the subscription of "bob@example.com" has been paused
        When I filter the subscriptions by state "Paused"
        Then I should see 1 subscription in the list

    @ui
    Scenario: Filtering the subscriptions by their next renewal
        When I filter the subscriptions renewing next between "2027-01-15" and "2027-02-15"
        Then I should see 1 subscription in the list
        And I should see the subscription of "ann@example.com" to "Coffee" renewing next on "01-02-2027"

    @ui
    Scenario: Seeing the products a subscription renews
        When I view the subscription of "ann@example.com"
        Then it should renew "Every month"
        And its "Coffee" item should be 1 at "$18.00" on the "COFFEE_MONTHLY" plan
        And its "Tea" item should be 1 at "$10.00" on the "TEA_MONTHLY" plan
        And it should cost "$28.00" per renewal
        And it should have 0 failed renewals in a row

    @ui
    Scenario: Seeing a renewal whose charge was declined and then approved on a retry
        Given the subscription of "bob@example.com" has been cancelled
        And the test gateway will decline the next charge with "Insufficient funds."
        And the renewals due on "2027-02-01 09:00" are processed
        And the renewals due on "2027-02-02 09:00" are processed
        When I view the subscription of "ann@example.com"
        Then I should see that the recurring charges were accepted in version "1"
        And its renewal #2 should be "Paid"
        And its renewal #2 should show a "Declined" charge on "01-02-2027 09:00" because "Insufficient funds."
        And its renewal #2 should show an "Approved" charge on "02-02-2027 09:00"

    @ui
    Scenario: Seeing the gateway's code of a declined charge
        Given the subscription of "bob@example.com" has been cancelled
        And the test gateway will decline the next charge with "Insufficient funds." and the code "insufficient_funds"
        And the renewals due on "2027-02-01 09:00" are processed
        When I view the subscription of "ann@example.com"
        Then its renewal #2 should show a "Declined" charge on "01-02-2027 09:00" because "Insufficient funds." with the code "insufficient_funds"

    @ui
    Scenario: Seeing a renewal that skipped a product out of stock
        Given the subscription of "bob@example.com" has been cancelled
        And the product "Tea" is out of stock
        And the renewals due on "2027-02-01 09:00" are processed
        When I view the subscription of "ann@example.com"
        Then its renewal #2 should be "Paid"
        And its renewal #2 should show "Tea" skipped because "Out of stock"

    @ui
    Scenario: Retrying a renewal that failed
        Given the subscription of "bob@example.com" has been cancelled
        And the test gateway will decline the next 4 charges with "Insufficient funds."
        And the renewals due on "2027-02-01 09:00" are processed
        And the renewals due on "2027-02-02 09:00" are processed
        And the renewals due on "2027-02-04 09:00" are processed
        And the renewals due on "2027-02-08 09:00" are processed
        And it is "2027-02-10 11:00" now
        When I view the subscription of "ann@example.com"
        Then its renewal #2 should be "Failed"
        And it should be "Active"
        And it should have 1 failed renewal in a row
        And it should next renew on "01-03-2027"
        When I retry its renewal #2
        Then I should be notified that the renewal has been charged
        And its renewal #2 should be "Paid"
        And its renewal #2 should show an "Approved" charge on "10-02-2027 11:00"
        And I should not be able to retry its renewal #2
        And it should have 0 failed renewals in a row
        And it should next renew on "01-03-2027"

    @ui
    Scenario: Suspending an active subscription
        When I view the subscription of "ann@example.com"
        And I suspend it
        Then I should be notified that it has been suspended
        And it should be "Suspended"
        And its renewal #2 should be "Cancelled"

    @ui
    Scenario: Reactivating a suspended subscription on the first date of its calendar
        Given the subscription of "ann@example.com" has been suspended
        And it is "2027-03-10 12:00" now
        When I view the subscription of "ann@example.com"
        And I reactivate it
        Then I should be notified that it has been reactivated
        And it should be "Active"
        And it should next renew on "01-04-2027"

    @ui
    Scenario: Pausing a subscription for its customer and resuming it
        Given it is "2027-01-20 09:00" now
        When I view the subscription of "ann@example.com"
        And I pause it
        Then I should be notified that it has been paused
        And it should be "Paused"
        And its renewal #2 should be "Cancelled"
        When it is "2027-03-10 12:00" now
        And I view the subscription of "ann@example.com"
        And I resume it
        Then I should be notified that it has been resumed
        And it should be "Active"
        And it should next renew on "01-04-2027"

    @ui
    Scenario: Skipping the next renewal of a subscription for its customer
        Given it is "2027-01-20 09:00" now
        When I view the subscription of "ann@example.com"
        And I skip its next renewal
        Then I should be notified that the renewal has been skipped
        And its renewal #2 should be "Skipped"
        And it should next renew on "01-03-2027"

    @ui
    Scenario: Not being offered to reactivate a cancelled subscription
        Given the subscription of "ann@example.com" has been cancelled
        When I view the subscription of "ann@example.com"
        Then it should be "Cancelled"
        And I should not be able to suspend it, reactivate it or cancel it

    @ui
    Scenario: Cancelling a subscription
        When I view the subscription of "ann@example.com"
        And I cancel it
        Then I should be notified that it has been cancelled
        And it should be "Cancelled"
        And its renewal #2 should be "Cancelled"

    @ui
    Scenario: Changing the address of a subscription for its customer
        When I view the subscription of "ann@example.com"
        And I change its shipping address to "Springwood", "Elm Street 13", "43210", "United States" for "Ann Other"
        Then I should be notified that the subscription's addresses have been changed
        And it should be shipped to "Elm Street 13"

    @ui
    Scenario: Changing the frequency of a subscription
        Given it is "2027-01-15 09:00" now
        When I view the subscription of "ann@example.com"
        And I change its frequency to "Every 3 months"
        Then I should be notified that its frequency has been changed
        And it should renew "Every 3 months"
        And its "Coffee" item should be 1 at "$17.00" on the "COFFEE_QUARTERLY" plan
        And its "Tea" item should be 1 at "$10.00" on the "TEA_QUARTERLY" plan
        And it should cost "$27.00" per renewal
        And it should next renew on "01-02-2027"
