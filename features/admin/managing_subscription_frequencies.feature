@managing_subscription_frequencies
Feature: Managing subscription frequencies
    In order to let customers repeat their carts
    As an Administrator
    I want to define the store's subscription frequencies

    Background:
        Given the store operates on a single channel in "United States"
        And I am logged in as an administrator

    @ui
    Scenario: Adding a frequency
        When I want to create a new subscription frequency
        And I specify its code as "MONTHLY"
        And I name it "Every month"
        And I set it to renew every 1 month
        And I set its subscriber discount to 5 percent
        And I make it available in channel "United States"
        And I add it
        Then I should be notified that it has been successfully created
        And the subscription frequency "MONTHLY" should appear in the list renewing "Every month" with a 5% discount

    @ui
    Scenario: Disabling a frequency
        Given the store offers a "MONTHLY" subscription frequency renewing every 1 month with a 5% discount
        When I want to modify the "MONTHLY" subscription frequency
        And I disable it
        And I save my changes
        Then I should be notified that it has been successfully edited
        And the "MONTHLY" subscription frequency should be disabled

    @ui
    Scenario: Not being able to delete a frequency in use
        Given the store offers a "MONTHLY" subscription frequency renewing every 1 month with a 5% discount
        And a cart is repeated with the "MONTHLY" subscription frequency
        When I delete the "MONTHLY" subscription frequency
        Then I should be notified that it is in use
        And the subscription frequency "MONTHLY" should still appear in the list
