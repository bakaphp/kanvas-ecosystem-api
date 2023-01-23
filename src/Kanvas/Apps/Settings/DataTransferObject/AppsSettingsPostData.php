<?php

declare(strict_types=1);

namespace Kanvas\Apps\Settings\DataTransferObject;

use Illuminate\Http\Request;
use Kanvas\AppsGroup\Settings\Models\Settings;
use Spatie\DataTransferObject\DataTransferObject;

/**
 * AppsData class.
 */
class AppsSettingsPostData extends DataTransferObject
{
    /**
     * Construct function.
     *
     * @param int $apps_id
     * @param string $name
     * @param mixed $value
     */
    public function __construct(
        public int $apps_id,
        public string $name,
        public mixed $value,
    ) {
    }

    /**
     * Create new instance of DTO from request.
     *
     * @param Request $request Request Input data
     *
     * @return self
     */
    public static function viaRequest(Request $request): self
    {
        return new self(
            apps_id: (int)$request->get('apps_id'),
            name: $request->get('name'),
            value: $request->get('value')
        );
    }

    /**
     * Create new instance of DTO from array of data.
     *
     * @param array $data Settings data array
     *
     * @return self
     */
    public static function fromArray(array $data): self
    {
        return new self(
            apps_id: (int)$data['apps_id'],
            name: $data['name'],
            value: $data['value']
        );
    }
}
