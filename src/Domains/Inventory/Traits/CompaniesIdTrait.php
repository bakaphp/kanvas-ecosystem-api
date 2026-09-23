<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Traits;

use Illuminate\Database\Eloquent\Model;
use Kanvas\Exceptions\InternalServerErrorException;

/**
 * @todo remove this trait
 */
trait CompaniesIdTrait
{
    public static function bootCompaniesIdTrait()
    {
        static::creating(function (Model $model) {
            if ($model->companies_id !== null) {
                return;
            }

            $user = auth()->user();

            // Queue jobs, commands and agent tools run without auth, so there is nothing to fall
            // back to — name the model, or the failure surfaces far from the write that caused it.
            if ($user === null) {
                throw new InternalServerErrorException(
                    'Cannot resolve companies_id for ' . $model::class
                    . ': no company on the model and no authenticated user to fall back on. '
                    . 'Set companies_id explicitly — queue jobs, commands and agent tools run without auth.'
                );
            }

            $model->companies_id = $user->getCurrentCompany()->getId();
        });
    }
}
