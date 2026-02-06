<?php

declare(strict_types=1);

namespace App\GraphQL\Base;

use Illuminate\Auth\Access\AuthorizationException;

abstract class BaseMutation
{
    protected string $model;

    protected function authorize(string $ability): void
    {
        if (! auth()->user()->can($ability, $this->model)) {
            throw new AuthorizationException('You do not have permission to perform this action');
        }
    }
}
