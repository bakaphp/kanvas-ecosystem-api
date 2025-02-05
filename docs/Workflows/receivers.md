# Receivers

## Introduction

## Create and Assign the Receiver Action

In the database, under the "actions" table, you need to add the receiver activity for the integration. In this case, it's for Shopify, so `Kanvas\Connectors\Shopify\Jobs\ProcessShopifyProductWebhookJob` is the activity to add to that table.

Next, we use the following Artisan command for the receiver wizard:

```bash
php artisan kanvas:create-receiver-workflow
```

**Follow the wizard while keeping the following in mind:**

- **App**: Select the app target of the receiver.

- **Receiver**: Select the receiver newly added: "ProcessShopifyProductWebhookJob"

- **User**: The responsable user for the receiver (mainly for log propouses).

- **Company**: The responsable user company for the receiver.

After that on the "receiver_webhooks" table, we need to add on the configuration column of the newly create receiver the following data:

 ```{"integration_company_id": {integration_company_id}```

Now we need to put in the integration entity, in this case shopify, the follow url of the receiver: `https://{url}/v1/receiver/{receiver-uuid}`

Where:

- $url: is the url of the app

- $receiver-uuid: is the uuid from the table receiver

For example: `https://graphapidev.kanvas.dev/v1/receiver/1234-456-678-9344-f3345`

Now you can make a test request to that url and check on the receiver_webhooks_logs and see if the request was succesfull (also check the response).