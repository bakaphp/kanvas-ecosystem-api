<br />
<p align="center">
    <img  src="https://kanvas.dev/img/logo.png" alt="Kanvas Logo"></a>
    <br />
    <br />
</p>

[![static analysis](https://github.com/bakaphp/kanvas-ecosystem-api/actions/workflows/static-analysis.yml/badge.svg)](https://github.com/bakaphp/kanvas-ecosystem-api/actions/workflows/static-analysis.yml)
[![CI](https://github.com/bakaphp/kanvas-ecosystem-api/actions/workflows/tests.yml/badge.svg)](https://github.com/bakaphp/kanvas-ecosystem-api/actions/workflows/tests.yml)

Kanvas Niche is a set of headless modules designed to optimize the development of headless apps. Our solution provides graph APIs for common problems encountered during development, allowing you to focus on building your product instead of worrying about the backend infrastructure.

Kanvas Niche is not a replacement for your existing backend-as-a-service or development framework. Instead, it complements them by providing specific modules for common problems that you would otherwise need to develop yourself. 

We call it Niche because we focus on specific solutions for common problems, including:

- Ecosystem: authentication, teams aka companies
- Inventory: products, variants, distribution channels
- Social: follows, comments, reactions, messaging
- CRM: leads, deals, pipelines
- Workflow: connecting your app with others

With Kanvas Niche, you can install our ecosystem in your workspace and optimize the development of headless apps with faster development times and more reliable performance without having to rewrite existing backend code.

To get started, check out our documentation and installation instructions. If you have any questions or feedback, don't hesitate to get in touch with our team.

Todo:
- [x] Ecosystem (in progress)
- [x] Inventory (in progress)
- [x] CRM (in progress)
- [x] Social (in progress)
- [ ] Workflow (in progress)

## Prerequisites

- PHP ^8.2
- Laravel ^10.0

## Initial Setup

1. Use the ``docker compose up --build -d`` to bring up the containers.Make sure to have Docker Desktop active and have no other containers running that may cause conflict with this project's containers(There may be conflicts port wise if more than one container uses the same ports).

2. Check status of containers using the command ```docker-compose ps```. Make sure they are running and services are healthy.

3. Get inside the php container using ```docker exec -it php bash```.

4. Create 3 databases `inventory`, `social`, `crm`, update your .env with the connection info

5. Check the .env and setup correctly the `KANVAS_APP_ID` and the `REDIS`parameters before running the setup-ecosystem

6. Use the command ```php artisan kanvas:setup-ecosystem``` to run the kanvas setup

7. Generate app keys `php artisan key:generate` 

8. To check if the API is working just make a GET request to  ```http://localhost:80/v1/``` and see if the response returns ```"Woot Kanvas"```

### Setup Inventory
1. php artisan migrate --path database/migrations/Inventory/ --database inventory
2. Set env var in .env
```
DB_INVENTORY_HOST=mysql
DB_INVENTORY_PORT=3306
DB_INVENTORY_DATABASE=inventory
DB_INVENTORY_USERNAME=root
DB_INVENTORY_PASSWORD=password
```

`php artisan inventory:setup` to create initialize the inventory module for a current company

### Setup Social
1. php artisan migrate --path database/migrations/Social/ --database social
2. Set env var in .env
```
DB_SOCIAL_HOST=mysql
DB_SOCIAL_PORT=3306
DB_SOCIAL_DATABASE=social
DB_SOCIAL_USERNAME=root
DB_SOCIAL_PASSWORD=password
```

`php artisan social:setup` to create initialize the social module for a current company

### Setup Guild
1. php artisan migrate --path database/migrations/Guild/ --database crm
2. Set env var in .env
```
DB_CRM_HOST=mysql
DB_CRM_PORT=3306
DB_CRM_DATABASE=cr
DB_CRM_USERNAME=root
DB_CRM_PASSWORD=password
```

`php artisan guild:setup` to create initialize the crm module for a current company

## Running the project with Laravel Octane

After doing all the steps above, you could run the project with Laravel Octane by using the command ```php artisan octane:start --port 8080 --host=0.0.0.0```. 

Use `--watch` in development allowing you to refresh modified files , this works assuming to have `npm install chokidar` installed in the project.
****
Note: 
- To install Swoole you can use the command ```pecl install swoole``` 
- For production remove `--watch` from the command.
- roles_kanvas_legacy will be deleted in the future.
