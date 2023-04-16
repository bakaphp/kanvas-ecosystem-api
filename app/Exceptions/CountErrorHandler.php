<?php

namespace App\Exceptions;

use Baka\Exceptions\LightHouseCustomException;
use Baka\Support\Str;
use GraphQL\Error\Error;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\App;
use Nuwave\Lighthouse\Execution\ErrorHandler;

class CountErrorHandler implements ErrorHandler
{
    public function __invoke(?Error $error, \Closure $next): ?array
    {
        if ($error === null) {
            return $next(null);
        }

        if (! App::environment('production')) {
            return $next($error);
        }

        $underlyingException = $error->getPrevious();
        if ($underlyingException instanceof ModelNotFoundException
            || $underlyingException instanceof LightHouseCustomException) {
            $message = $error->getMessage();
            if ($module = Str::contains($error->getMessage(), '\\')) {
                $module = explode('\\', $error->getMessage());
                $module = str_replace('].', '', end($module));
                $message = 'No result found for model ' . $module;
            }

            return $next(new Error(
                $message,
                null,
                null,
                null,
                $error->getPath()
            ));
        }

        return $next($error);
    }
}
