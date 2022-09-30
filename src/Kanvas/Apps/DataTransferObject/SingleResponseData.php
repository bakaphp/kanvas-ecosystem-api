<?php

declare(strict_types=1);

namespace Kanvas\Apps\DataTransferObject;

use Spatie\DataTransferObject\DataTransferObject;
use Illuminate\Database\Eloquent\Collection;
use Kanvas\Apps\Models\Apps;

/**
 * ResponseData class
 */
class SingleResponseData extends DataTransferObject
{
    /**
     * Construct function
     *
     * @param int $id
     * @param string $name
     * @param string $url
     * @param string $description
     * @param string $domain
     * @param int $is_actived
     * @param int $ecosystem_auth
     * @param int $default_apps_plan_id
     * @param int $payments_active
     * @param int $is_public
     * @param int $domain_based
     * @param string $created_at
     * @param string $updated_at
     * @param int $is_deleted
     * @param Collection $settings
     * @param Collection $roles
     */
    public function __construct(
        public int $id,
        public string $key,
        public string $name,
        public string $url,
        public string $description,
        public string $domain,
        public int $is_actived,
        public int $ecosystem_auth,
        public ?int $default_apps_plan_id,
        public int $payments_active,
        public int $is_public,
        public int $domain_based,
        public string $created_at,
        public string $updated_at,
        public int $is_deleted,
        public ?Collection $settings,
        public ?Collection $roles
    ) {
    }

    /**
     * Create new instance of DTO from request
     *
     * @param App $app
     *
     * @return self
     */
    public static function fromModel(Apps $app): self
    {
        //Here we could filter the data we need

        return new self(
            id: $app->id,
            key: $app->key,
            name: $app->name,
            url: $app->url,
            description: $app->description,
            domain: $app->domain,
            is_actived: $app->is_actived,
            ecosystem_auth: $app->ecosystem_auth,
            default_apps_plan_id: $app->default_apps_plan_id,
            payments_active: $app->payments_active,
            is_public: $app->is_public,
            domain_based: $app->domain_based,
            created_at: $app->created_at->format('Y-m-d H:i:s'),
            updated_at: $app->updated_at->format('Y-m-d H:i:s'),
            is_deleted: $app->is_deleted,
            settings: $app->settings->where('is_deleted', 0),
            roles: $app->roles->where('is_deleted', 0)
        );
    }

    /**
     * Create new instance of DTO from array of data
     *
     * @param array $data Input data
     *
     * @return self
     */
    public static function fromArray(array $data): self
    {
        return new self(
            name: $data['name'],
            url: $data['url'],
            description: $data['description'],
            domain: $data['domain'],
            is_actived: (int)$data['is_actived'],
            ecosystem_auth: (int)$data['ecosystem_auth'],
            payments_active: (int)$data['payments_active'],
            is_public: (int)$data['is_public'],
            domain_based: (int)$data['domain_based']
        );
    }
}
