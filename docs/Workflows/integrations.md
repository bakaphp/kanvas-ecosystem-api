# Integration

## Create and Assign the Workflow Action

In the database, under the "actions" table, you need to add the workflow activity for the integration. In this case, it's for Shopify, so `Kanvas\Connectors\Shopify\Workflows\Activities\ExportProductToShopifyActivity` is the activity to add to that table.

Next, we use the following Artisan command for the workflow wizard:

```bash
php artisan kanvas:create-workflow {app_id}
```

**Follow the wizard while keeping the following in mind:**

- **System module**: The entity that will be integrated/synchronized with the integration must already exist in the "system_module" table in the ecosystem.

- **Attribute**: This is the conditional parameter for the execution. For example, in our case, it would be:
  
  - `app_id` (attribute name)
  - `>` (operator)
  - `0` (value)

- **Assigned actions**: This is the name of the action we added to the database at the beginning.

With that, the wizard completes the workflow setup.

## 1. Create the Integration

To create an integration, use the following Artisan command:

```bash
php artisan kanvas:create-integration netsuite --config='{"endpoint": {"type": "text", "required": true},"host": {"type": "text", "required": true},"account":{"type": "text", "required": true},"consumerKey": {"type": "text", "required": true},"consumerSecret": {"type": "text", "required": true},"token": {"type": "text", "required": true},"tokenSecret": {"type": "text", "required": true}}' --handler='Kanvas\Connectors\NetSuite\Handlers\NetSuiteHandler'
```

```bash
php artisan kanvas:create-integration shopify --config='{"client_id":{"type":"text","required":true},"client_secret":{"type":"text","required":true},"shop_url":{"type":"text","required":true}}' --handler='Kanvas\Connectors\Shopify\Handlers\ShopifyHandler'
```

### Explanation of the parameters:

- **`--config`**: This is a JSON that contains the required fields for the integration. In the above example, the following fields are defined:
  
  ```json
  {
      "client_id": {"type": "text", "required": true},
      "client_secret": {"type": "text", "required": true},
      "shop_url": {"type": "text", "required": true}
  }
  ```
  
  - **`required`**: Validates that the field is mandatory when creating the integration.
  - **`type`**: Specifies the data type for each field.

- **`--handler`**: This is the namespace of the handler that manages the integration. In this case, it is `ShopifyHandler`.

---

## 2. Create Integration Statuses

If the statuses for the integration do not exist, you can create the set of statuses using the following command:

```bash
kanvas:create-workflow-status {--app_id=}
```

- **Note**: If you don't need a set of statuses for a specific application, you can omit the `app_id` parameter, and the statuses will be generated globally.

---

## 3. Associate a Company with the Integration

To associate a company with an integration, use the following GraphQL mutation:

### Mutation Body:

```graphql
mutation($input: IntegrationsCompaniesInput!) {
    integrationCompany(input: $input){
        id,
        company {
            id
            name
        }
        integration {
            id
            name
        }
        region {
            id
            name
        }
        status {
            name
            id
        }
        updated_at
    }
}
```

### Example Input:

```json
{
    "input": {
        "integration": {
            "id": 1
        },
        "company_id": 1,
        "region": {
            "id": 1
        },
        "config": "{ \"key\": \"value\", \"key\": \"value\", \"key\": \"value\" }"
    }
}
```

The fields inside the `config` must correspond to the required fields that were set when the integration was created.

```

```

- [ ] 