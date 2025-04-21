<?php

declare(strict_types=1);

namespace Tests\Ecosystem\Integration\Users;

use Tests\TestCase;
use Kanvas\Apps\Models\Apps;
use Kanvas\Users\Actions\SaveUserAppPreferencesAction;

final class UserConfigTest extends TestCase
{
    /**
     * Test Create AppsPostData Dto.
     *
     * @return void
     */
    public function testSaveUserAppPreferences(): void
    {
        $user = auth()->user();
        $app = app(Apps::class);
        $app->set('in_app_user_settings_keys', [
            'preference_1',
            'preference_2',
            'preference_3',
        ]);
        $preferences = [
            'preference_1' => 1,
            'preference_2' => 1,
            'preference_3' => 0,
        ];

        $saveUserAppPreferences = new SaveUserAppPreferencesAction(
            user: $user,
            app: $app,
            preferences: $preferences
        );

        $saveUserAppPreferences->execute();
        $this->assertEquals(
            $preferences['preference_1'],
            $user->get('preference_1')
        );
        $this->assertEquals(
            $preferences['preference_2'],
            $user->get('preference_2')
        );
        $this->assertEquals(
            $preferences['preference_3'],
            $user->get('preference_3')
        );
    }
}
