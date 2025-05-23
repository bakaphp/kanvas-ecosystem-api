<?php

declare(strict_types=1);

namespace Tests\Ecosystem\Integration\Users;

use Kanvas\Apps\Models\Apps;
use Kanvas\Users\Actions\SaveUserAppPreferencesAction;
use Tests\TestCase;

final class UserConfigTest extends TestCase
{
    /**
     * Test Create AppsPostData Dto.
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

        new SaveUserAppPreferencesAction(
            user: $user,
            app: $app,
            preferences: $preferences
        )->execute();

        $this->assertEquals(
            $preferences['preference_1'],
            $user->get('user_app_1_preferences')['preference_1']
        );
        $this->assertEquals(
            $preferences['preference_2'],
            $user->get('user_app_1_preferences')['preference_2']
        );
        $this->assertEquals(
            $preferences['preference_3'],
            $user->get('user_app_1_preferences')['preference_3']
        );
    }
}
