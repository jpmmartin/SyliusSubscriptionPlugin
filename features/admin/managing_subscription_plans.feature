@managing_subscription_plans
Feature: Managing the subscription plans of a variant
    In order to sell a variant by subscription as well as once
    As an Administrator
    I want to add, change and remove the plans the variant offers

    Background:
        Given the store operates on a single channel in "United States"
        And the store has a product "Coffee" priced at "$20.00"
        And I am logged in as an administrator

    @ui
    Scenario: Adding a plan to a variant
        When I want to add a subscription plan to the "Coffee" variant
        And I specify its code as "COFFEE_MONTHLY"
        And I name it "Monthly"
        And I set it to renew every 1 month
        And I set its subscriber discount to 10 percent
        And I add it
        Then I should be notified that it has been successfully created
        And the "Coffee" variant should offer the "COFFEE_MONTHLY" subscription plan renewing every 1 month with a 10% discount
        And the "COFFEE_MONTHLY" subscription plan of the "Coffee" variant should have no introductory price

    @ui
    Scenario: Adding a plan with an introductory price
        When I want to add a subscription plan to the "Coffee" variant
        And I specify its code as "COFFEE_MONTHLY"
        And I name it "Monthly"
        And I set it to renew every 1 month
        And I set its subscriber discount to 10 percent
        And I give it an introductory discount of 50 percent for the first 3 cycles
        And I add it
        Then I should be notified that it has been successfully created
        And the "COFFEE_MONTHLY" subscription plan of the "Coffee" variant should have an introductory price of "50% off the first 3 orders"

    @ui
    Scenario: Adding a plan whose introductory price is for the first order only
        When I want to add a subscription plan to the "Coffee" variant
        And I specify its code as "COFFEE_MONTHLY"
        And I name it "Monthly"
        And I set it to renew every 1 month
        And I give it an introductory discount of 30 percent for the first order only
        And I add it
        Then I should be notified that it has been successfully created
        And the "COFFEE_MONTHLY" subscription plan of the "Coffee" variant should have an introductory price of "30% off the first order"

    @ui
    Scenario: Adding a plan with a free trial
        When I want to add a subscription plan to the "Coffee" variant
        And I specify its code as "COFFEE_MONTHLY"
        And I name it "Monthly"
        And I set it to renew every 1 month
        And I set its subscriber discount to 10 percent
        And I give it a free trial of 14 days
        And I add it
        Then I should be notified that it has been successfully created
        And the "COFFEE_MONTHLY" subscription plan of the "Coffee" variant should have an introductory price of "14 days free"

    @ui
    Scenario: Adding a plan charged by blocks of deliveries
        When I want to add a subscription plan to the "Coffee" variant
        And I specify its code as "COFFEE_MONTHLY"
        And I name it "Monthly"
        And I set it to renew every 1 month
        And I set its subscriber discount to 10 percent
        And I make it charge 3 deliveries at a time
        And I add it
        Then I should be notified that it has been successfully created
        And the "COFFEE_MONTHLY" subscription plan of the "Coffee" variant should charge 3 deliveries at a time

    @ui
    Scenario: Trying to add an introductory price without its discount
        When I want to add a subscription plan to the "Coffee" variant
        And I specify its code as "COFFEE_MONTHLY"
        And I name it "Monthly"
        And I set it to renew every 1 month
        And I give it an introductory price for the first order only without a discount
        And I try to add it
        Then I should be notified that the introductory discount is required

    @ui
    Scenario: Trying to add a plan with a code another plan already uses
        Given the "Coffee" variant offers a "COFFEE_MONTHLY" subscription plan renewing every 1 month
        When I want to add a subscription plan to the "Coffee" variant
        And I specify its code as "COFFEE_MONTHLY"
        And I name it "Monthly again"
        And I set it to renew every 1 month
        And I try to add it
        Then I should be notified that a plan with this code already exists

    @ui
    Scenario: Trying to add a plan that never renews
        When I want to add a subscription plan to the "Coffee" variant
        And I specify its code as "COFFEE_NEVER"
        And I name it "Never"
        And I set it to renew every 0 month
        And I try to add it
        Then I should be notified that the interval must be at least 1

    @ui
    Scenario: Disabling a plan
        Given the "Coffee" variant offers a "COFFEE_MONTHLY" subscription plan renewing every 1 month
        When I want to edit the "COFFEE_MONTHLY" subscription plan of the "Coffee" variant
        And I disable it
        And I save the plan
        Then I should be notified that it has been successfully edited
        And the "COFFEE_MONTHLY" subscription plan of the "Coffee" variant should be disabled

    @ui
    Scenario: Deleting a plan that was never sold
        Given the "Coffee" variant offers a "COFFEE_MONTHLY" subscription plan renewing every 1 month
        When I delete the "COFFEE_MONTHLY" subscription plan of the "Coffee" variant
        Then I should be notified that it has been successfully deleted
        And the "Coffee" variant should offer no subscription plans
