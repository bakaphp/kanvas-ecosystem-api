<?php

declare(strict_types=1);

namespace Kanvas\Companies\Companies\DataTransferObject;

use Illuminate\Http\Request;
use Kanvas\Contracts\DataTransferObject\BaseDataTransferObject;

/**
 * CompaniesPostData class.
 */
class CompaniesPostData extends BaseDataTransferObject
{
    /**
     * Construct function.
     *
     * @param int $users_id
     * @param string $name
     */
    public function __construct(
        public int $users_id,
        public string $name,
        public ?array $files = null
    ) {
    }

    /**
     * Create new instance of DTO from request.
     *
     * @param Request $request Request Input data
     *
     * @return self
     */
    public static function fromRequest(Request $request) : self
    {
        return new self(
            users_id: (int)$request->get('users_id'),
            name: $request->get('name'),
            files: $request->get('files') ?? null
        );
    }

    /**
     * Create new instance of DTO from Console Command.
     *
     * @param array $data Input data
     *
     * @return self
     */
    public static function fromConsole(array $data) : self
    {
        return new self(
            users_id: (int)$data['users_id'],
            name: $data['name'],
        );
    }

    /**
     * Create new instance of DTO from array of data.
     *
     * @param array $data Input data
     *
     * @return self
     */
    public static function fromArray(array $data) : self
    {
        return new self(
            users_id: (int)$data['users_id'],
            name: $data['name'],
        );
    }
}
