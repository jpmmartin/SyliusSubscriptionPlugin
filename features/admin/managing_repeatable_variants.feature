@managing_repeatable_variants
Feature: Choosing the variants customers can repeat
    In order to keep gift cards and anything that must not renew out of repeated carts
    As an Administrator
    I want to choose which variants customers can repeat with the store's frequencies

    Background:
        Given the store operates on a single channel in "United States"
        And the store has a product "Coffee" priced at "$20.00"
        And I am logged in as an administrator

    @ui
    Scenario: Letting customers repeat a variant
        Then the "Coffee" variant should not be repeatable
        When I let customers repeat the "Coffee" variant
        Then I should be notified that it has been successfully edited
        And the "Coffee" variant should be repeatable

    @ui
    Scenario: No longer letting customers repeat a variant
        Given the "Coffee" variant can be repeated
        When I stop letting customers repeat the "Coffee" variant
        Then I should be notified that it has been successfully edited
        And the "Coffee" variant should not be repeatable
