@repeating_a_cart
Feature: Repeating my cart
    In order to receive the same products again without subscribing to each of them
    As a Visitor
    I want to repeat my cart with one of the store's frequencies

    Background:
        Given the store operates on a single channel in "United States"
        And the store has a product "Coffee" priced at "$20.00"
        And the store has a product "Tea" priced at "$10.00"
        And the store has a product "Gift card" priced at "$50.00"
        And the "Coffee" variant can be repeated
        And the "Tea" variant can be repeated
        And the store offers a "MONTHLY" subscription frequency renewing every 1 month with a 5% discount
        And the store offers a "BIWEEKLY" subscription frequency renewing every 2 weeks

    @ui
    Scenario: Being offered the store's frequencies
        Given I have product "Coffee" in the cart
        When I see the summary of my cart
        Then I should be offered to repeat my cart "Every month (save 5%)" or "Every 2 weeks"

    @ui
    Scenario: Repeating my cart every month
        Given I have product "Coffee" in the cart
        And I have product "Tea" in the cart
        When I choose to repeat my cart "Every month (save 5%)"
        Then I should be notified that my cart will be repeated
        And the "Coffee" item should be repeated "Every month (save 5%)"
        And the "Tea" item should be repeated "Every month (save 5%)"
        And my cart total should be "$28.50"

    @ui
    Scenario: Seeing which products are bought once in a repeated cart
        Given I have product "Coffee" in the cart
        And I have product "Gift card" in the cart
        When I choose to repeat my cart "Every month (save 5%)"
        Then the "Coffee" item should be repeated "Every month (save 5%)"
        And the "Gift card" item should be bought once
        And my cart total should be "$69.00"

    @ui
    Scenario: No longer repeating my cart
        Given I have product "Coffee" in the cart
        And I chose to repeat my cart "Every month (save 5%)"
        When I choose not to repeat my cart
        Then I should be notified that my cart will no longer be repeated
        And the "Coffee" item should not be repeated
        And my cart total should be "$20.00"

    @ui @javascript
    Scenario: Changing a quantity of a repeated cart without reloading the page
        Given I have product "Coffee" in the cart
        And I chose to repeat my cart "Every month (save 5%)"
        When I change product "Coffee" quantity to 3 in my cart
        Then the "Coffee" item should be repeated "Every month (save 5%)"
        And my cart total should be "$57.00"

    @ui
    Scenario: Not being offered to repeat a cart with nothing that can be repeated
        Given I have product "Gift card" in the cart
        When I see the summary of my cart
        Then I should not be offered to repeat my cart
