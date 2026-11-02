<?php

declare(strict_types=1);

namespace Kanvas\Workflow\Rules\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Kanvas\Apps\Models\Apps;
use Kanvas\Exceptions\ValidationException;
use ReflectionMethod;
use ReflectionNamedType;

/**
 * A model as a stored condition sees it: attributes, relations and the read-only getters in
 * SafeExpression::GETTERS. Eloquent resolves an unknown property by calling the method of that name,
 * so `entity.delete` on a bare model runs delete(); through this it reads nothing.
 */
final class ExpressionModel
{
    public function __construct(
        private readonly Model $model
    ) {
    }

    public function __get(string $name): mixed
    {
        if (array_key_exists($name, $this->model->getAttributes())
            || $this->model->hasGetMutator($name)
            || $this->model->hasAttributeGetMutator($name)
        ) {
            return ExpressionData::wrapValue($this->model->getAttributeValue($name));
        }

        if ($this->model->relationLoaded($name) || $this->isDeclaredRelation($name)) {
            return ExpressionData::wrapValue($this->model->getRelationValue($name));
        }

        return null;
    }

    public function __isset(string $name): bool
    {
        return $this->__get($name) !== null;
    }

    public function __call(string $method, array $arguments): mixed
    {
        if (! in_array($method, SafeExpression::GETTERS, true)
            || $this->model instanceof Apps
            || ! method_exists($this->model, $method)
        ) {
            throw new ValidationException("Expressions cannot call {$method}().");
        }

        return ExpressionData::wrapValue($this->model->{$method}(...$arguments));
    }

    /**
     * Read from the signature, never by calling the method: only a method declared to return a
     * Relation is one.
     */
    private function isDeclaredRelation(string $name): bool
    {
        if (! method_exists($this->model, $name)) {
            return false;
        }

        $method = new ReflectionMethod($this->model, $name);
        $type = $method->getReturnType();

        return $method->isPublic()
            && $method->getNumberOfRequiredParameters() === 0
            && $type instanceof ReflectionNamedType
            && is_a($type->getName(), Relation::class, true);
    }
}
