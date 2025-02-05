# Subscriptions with Stripe

## Setup

To manage subscriptions using Stripe, follow these steps to ensure that your backend is correctly set up and integrated with Stripe's API.

## 1. Set the Stripe Secret Key

Create your Stripe keys using the Stripe portal to configure your Stripe secret project key: `'STRIPE_SECRET_KEY=sk_test_XXXXXXXXXXXXXXXXXX'`. This key authenticates your application to perform operations like subscriptions, plans, managing customers, and handling payments.

## 2. Create plans/products and their prices:

To offer subscriptions to customers, you must first define the plans/products they will subscribe to. Each plan/product can have different pricing tiers (prices) based on amount, currency, or billing intervals (e.g., monthly, yearly). 

Before creating subscriptions, make sure to set up these plans/products in your system, linking them to Stripe with corresponding price IDs. These prices will later be used when creating or updating subscriptions.

## Managing Subscriptions

## 1. Create Subscription

To create a subscription use the following GraphQL mutation:

### Mutation Body:
```graphql
mutation($input: CreateSubscriptionInput!) {
    createSubscription(input: $input) {
        id
        stripe_id
        stripe_status
        items{
                id
                stripe_id
                stripe_product
                stripe_product_name
                stripe_price
             }
    }
}
```
### Example Input:
```graphql
mutation {
    createSubscription(input: {
        apps_plans_prices_id: 10, #Basic
        name: "TestCreate Subscription",
        payment_method_id: "pm_XXXXXXXXXXXXXXXXXXXXXXXX",
    }) {
        id
        stripe_id
        stripe_status
        items{
                id
                stripe_id
                stripe_product
                stripe_product_name
                stripe_price
             }
    }
}
```
- **Notes**: 

1. When you create a subscription, its `type` is set to `default` value to distinguish between subscription types. If your application offers only one subscription by customer, 'default' is recommended and assigned automatically.
2. If a Stripe customer doesn’t already exist when a subscription is created, one will be automatically generated, and the selected payment method will be set as default.

## 2. Update Subscription

To update a subscription use the following GraphQL mutation:

### Mutation Body:
```graphql
mutation($input: UpdateSubscriptionInput!) {
    updateSubscription(input: $input) {
        id
        stripe_id
        stripe_status
        items{
                id
                stripe_id
                stripe_product
                stripe_product_name
                stripe_price
             }
    }
}
```
### Example Input:
```graphql
mutation {
    updateSubscription(input: {
        apps_plans_prices_id: 2,
    }) {
        id
        stripe_id
        stripe_status
        items{
                id
                stripe_id
                stripe_product
                stripe_product_name
                stripe_price
             }
    }
}
```

## 3. Cancel Subscription

To cancel a subscription use the following GraphQL mutation:

### Mutation Body:
```graphql
mutation($id: ID!) {
    cancelSubscription(id: $id) {
        success
    }
}
```
### Example Input:
```graphql
mutation {
    cancelSubscription(id: 1) 
}
```

## 4. Reactive Subscription

To reactivate a subscription use the following GraphQL mutation:

### Mutation Body:
```graphql
mutation($id: ID!) {
    reactiveSubscription(id: $id) {
        success
    }
}
```
### Example Input:
```graphql
mutation {
    reactiveSubscription(id: 1) 
}
```

## 5. Query Plans/Products

To retrieve available plans/products and their pricing details, use the following query:

### Query Body:
```graphql
query {
    subscriptionPlans {
        data{
            id
            name
            description
            stripe_id
            prices {
                id
                stripe_id
                amount
            }
        }
    }
}
```
## 6. Query Company Subscriptions

To retrieve the company’s active subscriptions, use the following query:

### Query Body:
```graphql
query {
    companySubscriptions {
        data{
            id
            stripe_id
            stripe_status
            items {
                id
                stripe_id
                stripe_product
                stripe_price
            }
        }
    }
}
```

- [ ]