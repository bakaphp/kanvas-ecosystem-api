<br />
<p align="center">
    <img  src="https://kanvas.dev/images/kanvasL.svg" alt="Kanvas Logo" width="200" height="24"></a>
    <br />
    <br />
</p>

[![static analysis](https://github.com/bakaphp/kanvas-ecosystem-api/actions/workflows/static-analysis.yml/badge.svg)](https://github.com/bakaphp/kanvas-ecosystem-api/actions/workflows/static-analysis.yml)
[![CI](https://github.com/bakaphp/kanvas-ecosystem-api/actions/workflows/tests.yml/badge.svg)](https://github.com/bakaphp/kanvas-ecosystem-api/actions/workflows/tests.yml)

Kanvas was born out of years spent building complex backend systems for modern commerce. As an agency, we kept running into the same problems — slow integrations, disconnected systems, repetitive workflows, and custom logic that had to be rebuilt for every project.

We realized there was a better way.

## **Enter Kanvas Niche.**
Kanvas is your operational engine — a modular backend designed to unify your systems, automate your workflows, and power the next generation of commerce applications.

Kanvas gives you APIs, workflows, and agent-ready infrastructure — so you can launch faster, integrate better, and scale smarter.

## **What Kanvas Niche Offers:**
- **Ecosystem**: Dive into authentication and teams (or multi-tenant) management.
- **Inventory**: Manage products, their variants, and distribution channels efficiently.
- **Social**: Engage with features like follows, comments, reactions, and messaging.
- **CRM**: Navigate through leads, deals, and pipelines with ease.
- **Workflow**: Seamlessly connect your app with other systems.

## **Built to Extend, Not Replace**
Kanvas isn’t trying to be your monolithic platform. It connects the stack you already use — NetSuite, Shopify, Salesforce, custom apps — and becomes your operational layer in the middle.

Unlike typical low-code automation tools, Kanvas is designed to be part of your core product architecture. Built with Laravel + GraphQL, it supports scalable APIs and deep system integrations.

## **Use Kanvas to Launch**
🛍️ Marketplaces – With built-in vendor, product, and order logic

🚘 Dealer platforms – CRM, inventory, and lead routing included

🧩 Product bundlers – Dynamic SKUs + inventory syncing

🏪 B2B commerce portals – Multi-user pricing, approvals, and logic

📱 B2C apps – Headless APIs to power custom frontends

## Prerequisites

- PHP ^8.3
- Laravel ^11.0

## Initial Setup

1. Use the ``docker compose up --build -d`` to bring up the containers. Make sure to have Docker Desktop active and have no other containers running that may cause conflict with this project's containers(There may be conflicts port wise if more than one container uses the same ports).

2. Check the status of containers using the command ```docker-compose ps```. Make sure they are running and services are healthy.

3. Get inside the database container using ```docker exec -it mysqlLaravel /bin/bash```. Then, create 7 databases: `inventory`, `social`, `crm`, `workflow`, `commerce`, `action_engine`, `event`.

4. Set up your .env: You can start by copying the `.env.example setup`. Next, update it with the database and Redis connection info, making sure that the host values match your container's name.

5. Get inside the php container using ```docker exec -it phpLaravel bash```.

6. Generate app keys with `php artisan key:generate`.
**Note:** Confirm that your app key is correctly registered in the `apps` table within the `kanvas_laravel` database.

7. Update the app variables in your .env `APP_JWT_TOKEN`, `APP_KEY`, `KANVAS_APP_ID` before running the setup-ecosystem.
**Note:** You can use the default values provided in `tests.yml`.

8. Use the command ```php artisan kanvas:setup-ecosystem``` to run the kanvas setup.

9. If you're presenting some errors after running the command from before, drop all the tables from the schema `kanvas_laravel` and run it again.

10. To check if the API is working just make a GET request to  ```http://localhost:80/v1/``` and see if the response returns ```"Woot Kanvas"```.

### Setup Inventory
1. composer migrate-inventory
2. Set env var in .env
```
DB_INVENTORY_HOST=mysqlLaravel
DB_INVENTORY_PORT=3306
DB_INVENTORY_DATABASE=inventory
DB_INVENTORY_USERNAME=root
DB_INVENTORY_PASSWORD=password
```

`php artisan inventory:setup` to create and initialize the inventory module for a current company

### Setup Social
1. composer migrate-social
2. Set env var in .env
```
DB_SOCIAL_HOST=mysqlLaravel
DB_SOCIAL_PORT=3306
DB_SOCIAL_DATABASE=social
DB_SOCIAL_USERNAME=root
DB_SOCIAL_PASSWORD=password
```

`php artisan social:setup` to create and initialize the social module for a current company

### Setup Guild
1. composer migrate-crm
2. Set env var in .env
```
DB_CRM_HOST=mysqlLaravel
DB_CRM_PORT=3306
DB_CRM_DATABASE=cr
DB_CRM_USERNAME=root
DB_CRM_PASSWORD=password
```


`php artisan guild:setup` to create and initialize the crm module for a current company

## Running the project with Laravel Octane

After doing all the steps above, you could run the project with Laravel Octane by using the command ```php artisan octane:start --server=swoole --host=0.0.0.0 --port=8000```. 

Use `--watch` in development allowing you to refresh modified files, this works assuming to have `npm install chokidar` installed in the project.
****

## Working with kanvas
- [Coding guideline](https://github.com/bakaphp/kanvas-ecosystem-api/wiki/Coding-Guidelines)
- [Wiki](https://github.com/alexeymezenin/laravel-best-practices#follow-laravel-naming-conventions)
- [TypeScript SDK](https://github.com/bakaphp/kanvas-core-js)
- [Documentation](https://github.com/bakaphp/kanvas-doc)

Note: 
- To install Swoole you can use the command ```pecl install swoole``` 
- For production remove `--watch` from the command.
- roles_kanvas_legacy will be deleted in the future.
