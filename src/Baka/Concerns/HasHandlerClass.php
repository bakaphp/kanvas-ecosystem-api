<?php

declare(strict_types=1);

namespace Baka\Concerns;

use Baka\Support\AllowedClass;
use Illuminate\Support\Str;

/**
 * For a model whose `handler` column names a class the runtime builds: the class must implement one
 * of handlerContracts(), checked when the row is saved and again by handlerClass() before it is built.
 */
trait HasHandlerClass
{
    /**
     * @return list<class-string>
     */
    abstract protected static function handlerContracts(): array;

    public static function bootHasHandlerClass(): void
    {
        static::saving(function (self $model): void {
            if ($model->isDirty('handler')) {
                AllowedClass::assert(
                    $model->handler,
                    Str::snake(class_basename(static::class), ' ') . ' handler',
                    ...static::handlerContracts()
                );
            }
        });
    }

    /**
     * The handler class to build, or null when the stored name does not implement the contract.
     */
    public function handlerClass(): ?string
    {
        return AllowedClass::resolve($this->handler, ...static::handlerContracts());
    }
}
