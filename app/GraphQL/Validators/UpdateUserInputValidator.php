<?php declare(strict_types=1);

namespace App\GraphQL\Validators;

use Nuwave\Lighthouse\Validation\Validator;
use Kanvas\Users\Repositories\UsersRepository;

final class UpdateUserInputValidator extends Validator
{
    /**
     * Return the validation rules.
     *
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        $user = UsersRepository::getUserOfAppById((int)$this->arg('id'));
        $rules = $user->displayname == $this->arg('data.displayname') ? [] : ['displayname' => 'unique'];
        dd($rules);
        return [
            // TODO Add your validation rules
        ];
    }
}
