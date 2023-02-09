# Kanvas Niche App Ecosystem

Welcome to our niche app ecosystem for developers! Our platform is designed to help developers build powerful and innovative applications that solve real business challenges. With our comprehensive set of headless Graph API's, you can easily add key functionality such as user and team management, CRM, inventory management, social graph integration, and workflow automation to your app. Whether you're looking to streamline your sales pipeline, manage product inventory across multiple regions, or add social features to your app, our platform has you covered. Join us and revolutionize the way you build applications!


Todo:
- [x] Ecosystem (in progress)
- [x] Inventory (in progress)
- [ ] CRM (in progress)
- [ ] Social (in progress)
- [ ] Workflow (in progress)

## Prerequisites

- PHP ^8.1
- Laravel ^9.1

## Initial Setup

1. Use the ``docker compose up --build -d`` to bring up the containers.Make sure to have Docker Desktop active and have no other containers running that may cause conflict with this project's containers(There may be conflicts port wise if more than one container uses the same ports).

2. Check status of containers using the command ```docker-compose ps```. Make sure they are running and services are healthy.

3. Get inside the php container using ```docker exec -it php bash```.

4. Use the command ```php artisan migrate``` to migrate all kanvas migrations file.

5. Use the command ```php artisan db:seed --class=DatabaseSeeder```  to seed the database with an app, role and default system modules.

6. Generate app keys `php artisan key:generate` 

7. To check if the API is working just make a GET request to  ```http://localhost:80/v1/``` and see if the response returns ```"Woot Kanvas"```

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
