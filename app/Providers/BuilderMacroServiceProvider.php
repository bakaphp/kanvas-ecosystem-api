<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\ServiceProvider;

class BuilderMacroServiceProvider extends ServiceProvider
{
    /**
    * @psalm-suppress UndefinedMethod
    */
    public function boot()
    {
        Builder::macro('wheresContain', function (string $column, string $operator, mixed $value) {
            if (! method_exists($this, 'getQuery')) {
                return false;
            }

            $wheres = $this->getQuery()->wheres;

            foreach ($wheres as $where) {
                if ($where['type'] === 'Basic'
                    && $where['column'] === $column
                    && $where['operator'] === $operator
                    && $where['value'] == $value
                ) {
                    return true;
                }
            }

            return false;
        });
    }

    public function register()
    {
    }
}
