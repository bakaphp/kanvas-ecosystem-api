<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Importer\Models;

use Kanvas\Inventory\Models\BaseModel;

/**
 *  class Importer
 *  @property int $id
 *  @property int $apps_id
 *  @property int $companies_id
 *  @property int $users_id
 *  @property string $status
 *  @property string $job_uuid
 *  @property string $request
 *  @property int $products_count
 */
class ImporterRequests extends BaseModel
{
    protected $table = 'importer_requests';

    protected $guarded = [];
}
