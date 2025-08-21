<?php

declare(strict_types=1);

namespace Database\Seeders\Souk;

use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Souk\Cart\Models\Cart;
use Kanvas\Users\Models\Users;

class CartSeeder extends Seeder
{
    public function run(): void
    {
        $app = Apps::first();
        $company = Companies::first();
        $user = Users::first();

        Cart::create([
            'uuid' => Str::uuid(),
            'apps_id' => $app->id,
            'companies_id' => $company->id,
            'users_id' => $user->id,
            'cart_session_id' => 'session_user_' . $user->id,
            'email' => $user->email,
            'amount' => 149.99,
            'currency' => 'usd',
            'status' => 'pending',
            'metadata' => [
                'source' => 'web',
                'utm_campaign' => 'summer_sale',
                'items_count' => 3
            ]
        ]);

        Cart::create([
            'uuid' => Str::uuid(),
            'apps_id' => $app->id,
            'companies_id' => $company->id,
            'users_id' => null,
            'cart_session_id' => 'guest_session_123',
            'email' => 'guest@example.com',
            'amount' => 89.99,
            'currency' => 'usd',
            'status' => 'abandoned',
            'metadata' => [
                'source' => 'mobile',
                'abandoned_at' => now()->subHours(2)->toISOString()
            ]
        ]);

        Cart::create([
            'uuid' => Str::uuid(),
            'apps_id' => $app->id,
            'companies_id' => $company->id,
            'users_id' => $user->id,
            'cart_session_id' => 'session_payment_' . $user->id,
            'email' => $user->email,
            'amount' => 299.99,
            'currency' => 'usd',
            'status' => 'pending',
            'metadata' => [
                'source' => 'checkout',
                'items_count' => 5
            ]
        ]);

        if (app()->environment(['local', 'development', 'testing'])) {
            Cart::factory(20)->create([
                'apps_id' => $app->id,
                'companies_id' => $company->id,
            ]);
        }
    }
}
