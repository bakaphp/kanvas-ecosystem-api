# Cart Discount Integration

## Overview
The `discountCodesUpdate` mutation in `CartManagementMutation` has been updated to use the new discount system instead of hardcoded discount codes.

## Key Changes

### 1. Dynamic Discount Lookup
- Discounts are now fetched from the database using the provided code
- Filtered by app and company to ensure multi-tenant security
- Only active discounts are considered

### 2. Comprehensive Validation
The integration now validates:
- Discount exists and is active
- Discount date range (start_date, end_date)
- Usage limits (total and per customer)
- Minimum order value requirements
- Maximum discount amount (for percentage discounts)

### 3. Cart Condition Application
Discounts are applied as cart conditions with:
- Proper discount value (percentage or fixed amount)
- Metadata including discount ID, name, and type
- Support for maximum discount caps on percentage discounts

### 4. Error Handling
Clear error messages for:
- Invalid or non-existent discount codes
- Expired discounts
- Usage limit exceeded
- Minimum order value not met
- Already used by customer (if one-per-customer)

## Usage Example

```graphql
mutation ApplyDiscountToCart {
  cartDiscountCodesUpdate(discountCodes: ["SUMMER20"]) {
    items {
      id
      name
      price
      quantity
    }
    subtotal
    total
    conditions {
      name
      type
      value
      attributes
    }
  }
}
```

## Migration Path

### For Existing Hardcoded Discounts
To migrate existing hardcoded discounts:

1. Create discount types:
```sql
INSERT INTO discount_types (name, description) VALUES 
('Percentage', 'Percentage off order total'),
('Fixed Amount', 'Fixed amount off order total');
```

2. Create discounts for each hardcoded code:
```graphql
mutation CreateDiscount {
  createDiscount(input: {
    name: "PDLC 10% Off"
    code: "pdlc10"
    discount_type_id: 1
    value: 10
    is_percentage: true
    is_active: true
  }) {
    id
  }
}
```

### Features Added

1. **Usage Tracking**: Each discount use is tracked when order is completed
2. **Customer Limits**: Enforce one-per-customer restrictions
3. **Date Ranges**: Discounts can have start and end dates
4. **Flexible Values**: Support both percentage and fixed amount discounts
5. **Conditions**: Future support for product/category specific discounts

## Integration Points

### Cart Service
The `CartService` automatically includes discount information when returning cart data.

### Order Creation
When an order is created from a cart with discounts:
1. Discount information is transferred to the order
2. Usage count is incremented
3. Customer usage is recorded

### Admin Management
Discounts can be managed through GraphQL mutations:
- `createDiscount`: Create new discount codes
- `updateDiscount`: Modify existing discounts
- `deleteDiscount`: Soft delete discounts
- `discounts`: Query all discounts with filtering
