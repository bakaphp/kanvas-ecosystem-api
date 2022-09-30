<?php

declare(strict_types=1);

namespace Kanvas\Contracts\DataTransferObject;

use Illuminate\Http\Request;
use Spatie\DataTransferObject\DataTransferObject;
use Kanvas\CompanyGroup\Companies\Models\Companies;

/**
 * AppsData class.
 */
abstract class BaseDataTransferObject extends DataTransferObject
{
    /**
     * Create new instance of DTO from request.
     *
     * @param Request $request Request Input data
     *
     * @return self
     */
    abstract public static function fromRequest(Request $request) : self;

    /**
     * Create new instance of DTO from array of data.
     *
     * @param array $data Input data
     *
     * @return self
     */
    abstract public static function fromArray(array $data) : self;

    /**
     * Spit all filled fields as an array
     *
     * @return array
     */
    public function spitFilledAsArray() : array
    {
        foreach ($this as $key => $value) {
            if (empty($value)) {
                $this->exceptKeys[] = $key;
            }
        }

        return $this->toArray();
    }
}
