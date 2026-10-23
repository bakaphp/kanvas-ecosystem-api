<?php

declare(strict_types=1);

namespace App\GraphQL\Directives;

use Illuminate\Contracts\Auth\Authenticatable;
use Kanvas\Apps\Models\AppKey;
use Kanvas\Apps\Models\Apps;
use Kanvas\Enums\AppSettingsEnums;
use Kanvas\Users\Models\Users;
use Nuwave\Lighthouse\Auth\AuthServiceProvider;
use Nuwave\Lighthouse\Auth\GuardDirective;
use Nuwave\Lighthouse\Exceptions\AuthorizationException;
use Nuwave\Lighthouse\Execution\ResolveInfo;
use Nuwave\Lighthouse\Schema\Values\FieldValue;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;
use Override;

class GuardByAppKeyDirective extends GuardDirective
{
    #[Override]
    public static function definition(): string
    {
        return /** @lang GraphQL */ <<<'GRAPHQL'
directive @guardByAppKey(
  """
  Specify which guards to use, e.g. ["web"].
  When not defined, the default from `lighthouse.php` is used.
  """
  with: [String!]
) repeatable on FIELD_DEFINITION | OBJECT
GRAPHQL;
    }

    #[Override]
    public function handleField(FieldValue $fieldValue): void
    {
        $fieldValue->wrapResolver(
            fn (callable $previousResolver) => function (
                $root,
                array $args,
                GraphQLContext $context,
                ResolveInfo $resolveInfo
            ) use ($previousResolver) {
                if (! app()->bound(AppKey::class)) {
                    $this->unauthenticated(['No App Key configure with this key']);
                }

                $with = (array) $this->directiveArgValue('with', current(AuthServiceProvider::guards()));
                $user = $this->authenticate($with);

                $this->assertKeyMatchesUser($user, $resolveInfo->fieldName);

                return $previousResolver($root, $args, $context, $resolveInfo);
            }
        );
    }

    /**
     * The key is the credential: a token for another user rides on it only as an owner of the key's app.
     * Until public apps stop forwarding the end-user token on their admin client, a mismatch is let
     * through unless the app (or the global default) opts into enforcement.
     */
    private function assertKeyMatchesUser(Authenticatable $user, string $field): void
    {
        $appKey = app(AppKey::class);
        $app = app(Apps::class);

        if ($appKey->isHeldBy((int) $user->getAuthIdentifier())) {
            return;
        }

        if ($user instanceof Users && $user->isOwnerOf($app)) {
            return;
        }

        if ($this->enforcesUserMatch($app)) {
            throw new AuthorizationException('This app key belongs to another user.');
        }
    }

    private function enforcesUserMatch(Apps $app): bool
    {
        $setting = $app->get(AppSettingsEnums::APP_KEY_ENFORCE_USER_MATCH->getValue());

        return $setting === null
            ? (bool) config('kanvas.app_key.enforce_user_match')
            : filter_var($setting, FILTER_VALIDATE_BOOL);
    }
}
