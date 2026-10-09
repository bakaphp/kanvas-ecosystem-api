<?php

declare(strict_types=1);

namespace Kanvas\Insurance\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Insurance\DataTransferObject\Insurer as InsurerData;
use Kanvas\Insurance\Models\Insurer;

class CreateInsurerAction
{
    public function __construct(
        protected readonly InsurerData $data,
    ) {
    }

    public function execute(): Insurer
    {
        $slug = $this->data->slug ?? Str::slug($this->data->name);

        return DB::connection('commerce')->transaction(function () use ($slug): Insurer {
            $taken = Insurer::query()
                ->withoutGlobalScopes()
                ->where('apps_id', $this->data->app->getId())
                ->where('companies_id', $this->data->company->getId())
                ->where('slug', $slug)
                ->exists();

            if ($taken) {
                throw new ValidationException('An insurer with slug ' . $slug . ' already exists (soft-deleted insurers keep their slug)');
            }

            $insurer = new Insurer();
            $insurer->apps_id = $this->data->app->getId();
            $insurer->companies_id = $this->data->company->getId();
            $insurer->name = $this->data->name;
            $insurer->slug = $slug;
            $insurer->provider = $this->data->provider;
            $insurer->logo = $this->data->logo;
            $insurer->status = $this->data->status;
            $insurer->saveOrFail();

            return $insurer;
        });
    }
}
