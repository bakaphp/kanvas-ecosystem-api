<br />
<p align="center">
    <img  src="https://kanvas.dev/images/kanvasL.svg" alt="Kanvas Logo" width="200" height="24"></a>
    <br />
    <br />
</p>

[![static analysis](https://github.com/bakaphp/kanvas-ecosystem-api/actions/workflows/static-analysis.yml/badge.svg)](https://github.com/bakaphp/kanvas-ecosystem-api/actions/workflows/static-analysis.yml)
[![CI](https://github.com/bakaphp/kanvas-ecosystem-api/actions/workflows/tests.yml/badge.svg)](https://github.com/bakaphp/kanvas-ecosystem-api/actions/workflows/tests.yml)

Originating from our agency background, we've catered to clients with unique requirements. Over time, we realized we were repeatedly solving the same challenges: whether it was crafting a full-fledged solution, integrating with existing systems, enhancing inventory connectivity, offering agent portals for CRM expansion, introducing social interactions to headless sites, or establishing workflows for seamless system integration.

## **Enter Kanvas Niche.**
Born from the need to streamline these repetitive tasks, Kanvas Niche offers a suite of headless modules tailored to accelerate the development of headless applications. We've encapsulated our years of experience into these modules, addressing the common hurdles we face daily in app development.

## **What Kanvas Niche Offers:**
- **Ecosystem**: Dive into authentication and teams (or multi-tenant) management.
- **Inventory**: Manage products, their variants, and distribution channels efficiently.
- **Social**: Engage with features like follows, comments, reactions, and messaging.
- **CRM**: Navigate through leads, deals, and pipelines with ease.
- **Workflow**: Seamlessly connect your app with other systems.

## **Why Kanvas Niche?**
Kanvas Niche isn't here to replace your existing backend-as-a-service or development framework. Think of us as your development partner, complementing your tools by offering specialized modules for challenges you'd otherwise tackle from scratch.

Our name, "Niche", reflects our mission: providing specialized solutions for prevalent challenges. By integrating Kanvas Niche into your workspace, you can expedite the development of headless apps, ensuring quicker delivery and dependable performance without overhauling your existing backend.

Todo:
- [x] Ecosystem
- [x] Inventory (in progress)
- [x] CRM (in progress)
- [x] Social (in progress)
- [x] Workflow
- [x] Action Engine
- [x] GraphQL Documentation (in progress)

## Prerequisites

- PHP ^8.2
- Laravel ^10.0

## Initial Setup

1. Use the ``docker compose up --build -d`` to bring up the containers.Make sure to have Docker Desktop active and have no other containers running that may cause conflict with this project's containers(There may be conflicts port wise if more than one container uses the same ports).

2. Check the status of containers using the command ```docker-compose ps```. Make sure they are running and services are healthy.

3. Get inside the php container using ```docker exec -it php bash```.

4. Create 4 databases `inventory`, `social`, `crm`, `workflow` update your .env with the connection info

5. Check the .env and setup correctly the `REDIS` parameters and your database connections before running the setup-ecosystem

6. Use the command ```php artisan kanvas:setup-ecosystem``` to run the kanvas setup

7. If you're presenting some errors after running the command from before, drop all the tables from the schema `kanvas_laravel` and run it again

8. Generate app keys `php artisan key:generate` 

9. To check if the API is working just make a GET request to  ```http://localhost:80/v1/``` and see if the response returns ```"Woot Kanvas"```

### Setup Inventory
1. composer migrate-inventory
2. Set env var in .env
```
DB_INVENTORY_HOST=mysql
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
DB_SOCIAL_HOST=mysql
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
DB_CRM_HOST=mysql
DB_CRM_PORT=3306
DB_CRM_DATABASE=cr
DB_CRM_USERNAME=root
DB_CRM_PASSWORD=password
```


`php artisan guild:setup` to create and initialize the crm module for a current company

## Running the project with Laravel Octane

After doing all the steps above, you could run the project with Laravel Octane by using the command ```php artisan octane:start --port 8080 --host=0.0.0.0```. 

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


## Feature Labs

### Run the project using FrankenPHP

``` sh
docker compose -f docker-compose.franken.yml up -d --build
```