<?php

declare(strict_types=1);

namespace Kanvas\Apps\Actions;

use Baka\Support\Str;
use Bouncer;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Kanvas\AccessControlList\Enums\RolesEnums;
use Kanvas\Apps\DataTransferObject\AppKeyInput;
use Kanvas\Apps\Models\AppKey;
use Kanvas\Auth\Actions\RegisterUsersAppAction;
use Kanvas\Users\Models\Users;

class CreateAppKeyAction
{
    /**
     * Construct function.
     */
    public function __construct(
        protected AppKeyInput $data,
        protected ?Users $user = null
    ) {
    }

    /**
     * Invoke function.
     */
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
                    return $query->where('apps_id', $data['apps_id']);
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

        //@todo change to use scope
        Bouncer::scope()->to(RolesEnums::getScope($this->data->app));
        $this->data->user->assign(RolesEnums::OWNER->value);

        if ($this->user) {
            return $app;
        }
        $userRegisterInApp = new RegisterUsersAppAction($this->data->user);
        $userRegisterInApp->execute($this->data->user->password);

        return $app;
    }
}
