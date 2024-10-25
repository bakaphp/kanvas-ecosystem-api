<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Variants\Actions;

use Kanvas\Inventory\Attributes\Models\Attributes;
use Kanvas\Inventory\Variants\Models\Variants;

class AddAttributeAction
{
    public function __construct(
        public Variants $variants,
        public Attributes $attributes,
        public mixed $value,
    ) {
    }

    /**
     * execute.
     */
    public function execute(): Variants
    {
        if ($this->value === null || $this->value === '') {
            return $this->variants;
        }

        if ($this->variants->attributes()->find($this->attributes->getId())) {
            $this->variants->attributes()->syncWithoutDetaching(
                [$this->attributes->getId() => ['value' => is_array($this->value) ? json_encode($this->value) : $this->value]]
            );
        } else {
            $this->variants->attributes()->attach(
                $this->attributes->getId(),
                ['value' => is_array($this->value) ? json_encode($this->value) : $this->value]
            );
        }

        return $this->variants;
    }
}
