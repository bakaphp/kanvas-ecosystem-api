<?php
declare(strict_types=1);
namespace Kanvas\Inventory\Variants\Actions;

use Kanvas\Inventory\Variants\Models\Variants;
use Kanvas\Inventory\Attributes\Models\Attributes;

class AddAttributeAction
{
    public function __construct(
        public Variants $variants,
        public Attributes $attributes,
        public string $value,
    ) {
    }

    /**
     * execute
     *
     * @return Variants
     */
    public function execute(): Variants
    {
        if ($this->variants->attributes()->find($this->attributes->id)) {
            $this->variants->attributes()->syncWithoutDetaching([$this->attributes->id => ['value' => $this->value]]);
        } else {
            $this->variants->attributes()->attach($this->attributes->id, ['value' => $this->value]);
        }
        return $this->variants;
    }
}
