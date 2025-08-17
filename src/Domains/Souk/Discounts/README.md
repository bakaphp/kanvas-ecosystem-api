# Discount System for Kanvas Commerce (Souk)

This is a comprehensive discount system that integrates with the Kanvas Commerce (Souk) domain to provide flexible discount management capabilities.

## Features

- **Multiple Discount Types**: Support for percentage, fixed amount, free shipping, and buy X get Y discounts
- **Flexible Conditions**: Apply discounts based on products, categories, variants, customers, or customer groups
- **Usage Limits**: Set total usage limits and one-per-customer restrictions
- **Time-based Discounts**: Configure start and end dates for promotional periods
- **Order Validation**: Minimum order value requirements and maximum discount amount caps
- **Discount Codes**: Unique coupon codes for customer redemption
- **Order Integration**: Seamless integration with the existing order system

## Database Schema

The discount system uses the following tables:

### discount_types
- Stores different types of discounts (percentage, fixed amount, etc.)

### discounts
- Main discount table with all discount configurations
- Includes value, percentage flag, usage limits, date ranges, and codes

### discount_conditions
- Defines conditions for discount applicability
- Types: product, category, variant, customer, customer_group
- Operators: in, not_in

### discount_condition_values
- Stores the actual values for each condition

### order_discounts
- Tracks discounts applied to orders

### order_item_discounts
- Tracks discounts applied to individual order items

## Usage Examples

### Creating a Discount

```graphql
mutation {
  createDiscount(input: {
    name: "Summer Sale 20% Off"
    description: "20% discount on all summer products"
    discount_type_id: 1
    value: 20
    is_percentage: true
    code: "SUMMER20"
    min_order_value: 50
    max_discount_amount: 100
    start_date: "2025-06-01 00:00:00"
    end_date: "2025-08-31 23:59:59"
    is_active: true
    usage_limit: 1000
    is_one_per_customer: true
    conditions: [
      {
        type: CATEGORY
        operator: IN
        values: ["summer-collection", "beachwear"]
      }
    ]
  }) {
    id
    uuid
    name
    code
  }
}
```

### Applying a Discount to an Order

```graphql
mutation {
  applyDiscountToOrder(
    discount_code: "SUMMER20"
    order_id: "123"
  ) {
    success
    message
    discount {
      id
      name
      code
    }
    amount
  }
}
```

### Querying Active Discounts

```graphql
query {
  activeDiscounts {
    id
    name
    code
    value
    is_percentage
    conditions {
      type
      operator
      values {
        value
      }
    }
  }
}
```

### Finding a Discount by Code

```graphql
query {
  discountByCode(code: "SUMMER20") {
    id
    name
    value
    is_percentage
    usage_count
    usage_limit
  }
}
```

## Discount Calculation Logic

1. **Percentage Discounts**: Calculated as `orderValue * (discountValue / 100)`
2. **Fixed Amount Discounts**: Direct deduction of the discount value
3. **Maximum Discount Cap**: If set, limits the maximum discount amount
4. **Minimum Order Value**: Discount only applies if order meets minimum threshold

## Condition Evaluation

The system supports multiple condition types:

- **Product**: Discount applies to specific products
- **Category**: Discount applies to products in specific categories
- **Variant**: Discount applies to specific product variants
- **Customer**: Discount applies to specific customers
- **Customer Group**: Discount applies to customers in specific groups

Conditions can use `in` or `not_in` operators for flexible targeting.

## Integration with Orders

When a discount is applied to an order:

1. The system validates the discount can be used (active, within date range, usage limits)
2. Checks if the order meets all discount conditions
3. Calculates the discount amount based on the discount type
4. Creates an `order_discount` record
5. Updates the order's discount fields
6. Increments the discount usage count

## Best Practices

1. **Use Unique Codes**: Ensure discount codes are unique and meaningful
2. **Set Appropriate Limits**: Use usage limits to control discount distribution
3. **Test Conditions**: Thoroughly test discount conditions before activation
4. **Monitor Usage**: Regularly check discount usage statistics
5. **Plan Date Ranges**: Set clear start and end dates for promotional periods

## Future Enhancements

- Stacking multiple discounts
- Discount priority system
- Advanced conditions (order history, customer lifetime value)
- Automatic discount application based on cart contents
- Discount analytics and reporting
- Integration with marketing campaigns
