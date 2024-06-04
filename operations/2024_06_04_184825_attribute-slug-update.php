<?php

use Baka\Support\Str;
use Kanvas\Inventory\Attributes\Models\Attributes;
use TimoKoerber\LaravelOneTimeOperations\OneTimeOperation;

return new class extends OneTimeOperation
{
    /**
     * Determine if the operation is being processed asynchronously.
     */
    protected bool $async = true;

    /**
     * The queue that the job will be dispatched to.
     */
    protected string $queue = 'default';

    /**
     * A tag name, that this operation can be filtered by.
     */
    protected ?string $tag = 'inventory';

    /**
     * Process the operation.
     */
    public function process(): void
    {
        $attributes = Attributes::all();

        foreach ($attributes as $attribute) {
            $attribute->slug = Str::slug($attribute->name);
            $attribute->saveQuietly();
        }
    }
};
