<?php

declare(strict_types=1);

namespace Kanvas\Subscription\Subscriptions\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model as EloquentModel;


class Subscription extends EloquentModel
{

    protected $fillable = [
        'stripe_id',
        'stripe_plan',
        'stripe_status',
        'quantity',
        'trial_ends_at',
        'grace_period_ends',
        'next_due_payment',
        'ends_at',
        'payment_frequency_id',
        'trial_ends_days',
        'is_freetrial',
        'is_active',
        'is_cancelled',
        'paid',
        'charge_date',
        'created_at',
        'updated_at',
        'is_deleted',
    ];

    protected $connection = 'subscriptions';

    public const DELETED_AT = 'is_deleted';

    /**
     * Determine if the model instance has been soft-deleted.
     *
     * @return bool
     */
    public function trashed()
    {
        return $this->{$this->getDeletedAtColumn()};
    }
}