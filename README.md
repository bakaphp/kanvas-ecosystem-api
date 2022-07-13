# Laravel Kanvas API Skeleton

## Prerequisites

- PHP ^8.0

## Initial Setup

1. Use the ``docker compose up --build -d`` to bring up the containers.Make sure to have Docker Desktop active and have no other containers running that may cause conflict with this project's containers(There may be conflicts port wise if more than one container uses the same ports).

2. Check status of containers using the command ```docker-compose ps```. Make sure they are running and services are healthy.

3. Get inside the php container using ```docker exec -it php bash```.

4. Use the command ```php artisan migrate``` to migrate all kanvas migrations file.

5. Use the command ```php artisan db:seed --class=KanvasSeeder```  to seed the database with an app, role and default system modules.

6. To check if the API Skeleton is working just make a GET request to  ```http://localhost:80/v1/``` and see if the response returns ```"Woot Kanvas"```


## Running the project with Laravel Octane

After doing all the steps above, you could run the project with Laravel Octane by using the command ```php artisan octane:start --port 8080 --host=0.0.0.0```. 

Use `--watch` in development allowing you to refresh modified files , this works assuming to have `npm install chokidar` installed in the project.

Note: 
- To install Swoole you can use the command ```pecl install swoole``` 
- For production remove `--watch` from the command.
