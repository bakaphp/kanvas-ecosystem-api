<?php

declare(strict_types=1);

namespace Kanvas\Apps\Actions;

use Baka\Support\Str;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Kanvas\Apps\DataTransferObject\AppKeyInput;
use Kanvas\Apps\Models\AppKey;
use Kanvas\Auth\Actions\RegisterUsersAppAction;
use Kanvas\Enums\StateEnums;

class CreateAppKeyAction
{
    public function __construct(
        protected AppKeyInput $data,
        protected bool $createUserInApp = true
    ) {
    }

    public function execute(): AppKey
    {
        $data = [
            'name' => $this->data->name,
            'apps_id' => $this->data->app->getId(),
        ];

        $validator = Validator::make($data, [
            'name' => [
                'required',
                Rule::unique('apps_keys')->where(function ($query) use ($data) {
                    return $query->where('apps_id', $data['apps_id'])
                        ->where('is_deleted', StateEnums::NO->getValue());
                }),
            ],
        ]);
        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        $app = new AppKey();
        $app->client_id = Str::uuid()->toString();
        $app->client_secret_id = Str::random(128);
        $app->name = $this->data->name;
        $app->apps_id = $this->data->app->getId();
        $app->users_id = $this->data->user->getId();
        $app->expires_at = $this->data->expiresAt;
        $app->saveOrFail();

        if ($this->createUserInApp) {
            $userRegisterInApp = new RegisterUsersAppAction($this->data->user);
            $userRegisterInApp->execute($this->data->user->password);
        }

        return $app;
    }
}
