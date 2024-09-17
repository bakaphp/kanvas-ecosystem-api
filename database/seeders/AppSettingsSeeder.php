<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class AppSettingsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        DB::table('apps_settings')->insert([
            [
                'apps_id' => 1,
                'name' => 'language',
                'value' => 'EN',
                'created_at' => date('Y-m-d H:m:s'),
            ],
            [
                'apps_id' => 1,
                'name' => 'timezone',
                'value' => 'America/New_York',
                'created_at' => date('Y-m-d H:m:s'),
            ],
            [
                'apps_id' => 1,
                'name' => 'currency',
                'value' => 'USD',
                'created_at' => date('Y-m-d H:m:s'),
            ],
            [
                'apps_id' => 1,
                'name' => 'filesystem',
                'value' => 'local',
                'created_at' => date('Y-m-d H:m:s'),
            ],
            [
                'apps_id' => 1,
                'name' => 'allow_user_registration',
                'value' => '1',
                'created_at' => date('Y-m-d H:m:s'),
            ],
            [
                'apps_id' => 1,
                'name' => 'background_image',
                'value' => 'https://mc-canvas.s3.amazonaws.com/default-background-auth.jpg',
                'created_at' => date('Y-m-d H:m:s'),
            ],
            [
                'apps_id' => 1,
                'name' => 'logo',
                'value' => 'https://mc-canvas.s3.amazonaws.com/gewaer-logo-dark.png',
                'created_at' => date('Y-m-d H:m:s'),
            ],
            [
                'apps_id' => 1,
                'name' => 'registered',
                'value' => 1,
                'created_at' => date('Y-m-d H:m:s'),
            ],
            [
                'apps_id' => 1,
                'name' => 'favicon',
                'value' => 'https://mc-canvas.s3.amazonaws.com/gewaer-logo-dark.png',
                'created_at' => date('Y-m-d H:m:s'),
            ],
            [
                'apps_id' => 1,
                'name' => 'titles',
                'value' => 'Example Title',
                'created_at' => date('Y-m-d H:m:s'),
            ],
            [
                'apps_id' => 1,
                'name' => 'base_color',
                'value' => '#61c2cc',
                'created_at' => date('Y-m-d H:m:s'),
            ],
            [
                'apps_id' => 1,
                'name' => 'secondary_color',
                'value' => '#9ee5b5',
                'created_at' => date('Y-m-d H:m:s'),
            ],
            [
                'apps_id' => 1,
                'name' => 'allow_social_auth',
                'value' => '1',
                'created_at' => date('Y-m-d H:m:s'),
            ],
            [
                'apps_id' => 1,
                'name' => 'allowed_social_auths',
                'value' => '{"google": 1,"facebook": 1,"github": 1,"apple": 1}',
                'created_at' => date('Y-m-d H:m:s'),
            ],
            [
                'apps_id' => 1,
                'name' => 'default_sidebar_state ',
                'value' => 'closed',
                'created_at' => date('Y-m-d H:m:s'),
            ],
            [
                'apps_id' => 1,
                'name' => 'show_notifications',
                'value' => '1',
                'created_at' => date('Y-m-d H:m:s'),
            ],
            [
                'apps_id' => 1,
                'name' => 'delete_images_on_empty_files_field',
                'value' => '1',
                'created_at' => date('Y-m-d H:m:s'),
            ],
            [
                'apps_id' => 1,
                'name' => 'public_images',
                'value' => '0',
                'created_at' => date('Y-m-d H:m:s'),
            ],
            [
                'apps_id' => 1,
                'name' => 'default_admin_role',
                'value' => 'Admins',
                'created_at' => date('Y-m-d H:m:s'),
            ],
            [
                'apps_id' => 1,
                'name' => 'default_feeds_comments',
                'value' => '3',
                'created_at' => date('Y-m-d H:m:s'),
            ],
            [
                'apps_id' => 1,
                'name' => 'stripe_key',
                'value' => 'pk_test_51Pt9tU14jpNveAtLkAPO6G4zisfYhajJZ4yb2htK433GiLA2e3eWzYTzhDymiyfOd5SU6FmWNyT8vyRoSRHP4QcE001QBEbqe0',
                'created_at' => date('Y-m-d H:m:s'),
            ],
            [
                'apps_id' => 1,
                'name' => 'stripe_secret',
                'value' => 'sk_test_51Pt9tU14jpNveAtL5mIWHOXZ8tL3hhwsvpnZriiBWNae9nanYplBEoO6qdkbAIlRaWzsWycJwY2zTjFENu1mhmkT00kl5aBwBK',
                'created_at' => date('Y-m-d H:m:s'),
            ]

        ]);
    }
}
