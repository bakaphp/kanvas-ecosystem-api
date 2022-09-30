<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\BaseController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Kanvas\Apps\Apps\Models\Apps;
use Kanvas\Auth\Traits\AuthTrait;
use Kanvas\Auth\Traits\TokenTrait;
use Kanvas\UsersGroup\Users\Actions\RegisterUsersAction;
use Kanvas\UsersGroup\Users\DataTransferObject\RegisterPostData;

class AuthController extends BaseController
{
    use AuthTrait;
    use TokenTrait;

    /**
     * Fetch all apps.
     *
     * @return JsonResponse
     *
     * @todo Need to move this pagination somewhere else.
     */
    public function login(Request $request) : JsonResponse
    {
        $validator = Validator::make(
            $request->all(),
            [
                'email' => [
                    'required'
                ],
                'password' => [
                    'required',
                ],
            ]
        )->validate();

        //Should we use a dto here?
        $email = $request['email'];
        $password = $request['password'];

        $this->user = $this->loginUsers($request, $email, $password);

        return response()->json($this->generateToken($request));
    }

    /**
     * Fetch all apps.
     *
     * @return JsonResponse
     */
    public function register(Request $request) : JsonResponse
    {
        $validator = Validator::make(
            $request->all(),
            [
                'password' => [
                    'required',
                    'confirmed',
                    Password::min(8)
                ],
            ]
        )->validate();

        $data = RegisterPostData::fromRequest($request);
        $user = new RegisterUsersAction($data);

        $registeredUser = $user->execute();

        $this->user = $registeredUser;

        $tokenResponse = $this->generateToken($request);

        return response()->json(
            [
                'user' => $this->user,
                'session' => $tokenResponse
            ]
        );
    }
}
