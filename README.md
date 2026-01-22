![Kanvas Logo](https://cdn.prod.website-files.com/66c9f056ff6b7f7ba51cdf21/66ccb2a881e7036ab59136f2_Logo_Kanvas_3.png)

[![static analysis](https://github.com/bakaphp/kanvas-ecosystem-api/actions/workflows/static-analysis.yml/badge.svg)](https://github.com/bakaphp/kanvas-ecosystem-api/actions/workflows/static-analysis.yml)
[![CI](https://github.com/bakaphp/kanvas-ecosystem-api/actions/workflows/tests.yml/badge.svg)](https://github.com/bakaphp/kanvas-ecosystem-api/actions/workflows/tests.yml)

# Kanvas

**Kanvas is an operational engine for commerce.**  
It sits between your products and your systems — unifying data, automating workflows, and enabling AI agents to run real operations.

Think of Kanvas as **the backend where operations live.**

Not a store.  
Not a CRM.  
Not an automation toy.

Kanvas is the **core execution layer** that connects and runs them all.

## Why Kanvas Exists

Modern commerce stacks are fragmented:

- Shopify for products  
- NetSuite for operations  
- HubSpot for CRM  
- Custom services everywhere  
- Automation glued together with brittle tools  

Every project ends up rebuilding the same logic: authentication, inventory sync, lead routing, workflows, permissions, integrations.

Kanvas was built to stop that.

It provides a **modular operational backend** where execution is first-class: APIs, workflows, events, and agent-ready infrastructure.

## What Kanvas Is

Kanvas is a **Laravel + GraphQL operational backend** that provides:

- Unified operational APIs  
- Cross-system workflows  
- Multi-tenant infrastructure  
- Event-driven execution  
- Agent-ready primitives  

So you can build systems where:

- Products sync automatically  
- Leads route themselves  
- Inventory propagates across channels  
- Agents can act, not just chat  
- Business logic lives in one place  

## Core Domains

Kanvas is composed of operational building blocks:

- **Ecosystem** – auth, apps, teams, multi-tenancy  
- **Inventory** – products, variants, distribution channels  
- **CRM** – people, leads, pipelines  
- **Social** – messaging, follows, reactions  
- **Workflow** – automations, actions, integrations  
- **Commerce** – orders, customers, operational logic  

You don’t install “features.”  
You assemble an **operating system for your product.**


## What People Use Kanvas For

- 🚘 Dealer platforms (inventory + CRM + lead routing)  
- 🛍 Marketplaces (products, vendors, workflows)  
- 🏪 B2B commerce systems (approvals, pricing, operations)  
- 🧩 Product bundlers (dynamic SKUs, fulfillment logic)  
- 📱 Headless apps (custom frontends, unified backend)  
- 🤖 Agent-driven operations (AI that executes)  

## The Mental Model

Kanvas is not your app.

Kanvas is the **engine your app runs on.**

Your frontends, dashboards, AI agents, and services connect to Kanvas — and Kanvas connects to the rest of your stack.

```text
UI / Mobile / Agents / Admin
            ↓
        Kanvas API
            ↓
Shopify • NetSuite • CRMs • Internal systems
```

## Prerequisites

- PHP ^8.4
- Laravel ^12.0

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
- roles_kanvas_legacy will be deleted in the future
