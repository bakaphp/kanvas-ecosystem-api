<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Models;

use Baka\Traits\KanvasModelTrait;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Lean base for NervousSystem tables whose rows must never change after
 * insert: ledger events and daily metric snapshots. No SoftDeletes (no
 * is_deleted column on these tables), no CustomFields, no Filesystem.
 * KanvasModelTrait still provides app/company scoping and relations; use
 * ::query()->fromApp()/->fromCompany() for lookups since the trait's
 * getById* helpers assume an is_deleted column.
 */
class ImmutableBaseModel extends Model
{
    use HasFactory;
    use KanvasModelTrait;

    protected $connection = 'intelligence';

    public $timestamps = false;
}
