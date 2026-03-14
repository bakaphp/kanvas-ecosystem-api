<?php

declare(strict_types=1);

namespace Tests\GraphQL\Ecosystem\Users;

use Illuminate\Support\Facades\RateLimiter;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Twilio\Enums\ConfigurationEnum;
use Tests\TestCase;

class TwoFactorAuthRateLimitTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $app = app(Apps::class);
        RateLimiter::clear('two-factor-send:' . $app->getId() . ':' . auth()->id());
        RateLimiter::clear('two-factor-verify:' . $app->getId() . ':' . auth()->id());

        // Reset any custom rate limit configs
        $app->del(ConfigurationEnum::TWILIO_2FA_SEND_RATE_LIMIT->value);
        $app->del(ConfigurationEnum::TWILIO_2FA_VERIFY_RATE_LIMIT->value);
    }

    public function testSendVerificationCodeRateLimitPerUser(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $rateLimitKey = 'two-factor-send:' . $app->getId() . ':' . $user->getId();

        // Default limit is 3
        for ($i = 0; $i < 3; $i++) {
            RateLimiter::hit($rateLimitKey, 600);
        }

        $this->graphQL('
            mutation {
                sendVerificationCode
            }
        ')
        ->assertJson([
            'errors' => [
                [
                    'message' => 'Too many verification attempts. Please try again later.',
                ],
            ],
        ]);
    }

    public function testVerifyCodeRateLimitPerUser(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $rateLimitKey = 'two-factor-verify:' . $app->getId() . ':' . $user->getId();

        // Default limit is 3
        for ($i = 0; $i < 3; $i++) {
            RateLimiter::hit($rateLimitKey, 600);
        }

        $this->graphQL('
            mutation {
                verifyCode(code: "123456")
            }
        ')
        ->assertJson([
            'errors' => [
                [
                    'message' => 'Too many verification attempts. Please try again later.',
                ],
            ],
        ]);
    }

    public function testRateLimitMessageDoesNotLeakTimingInfo(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $rateLimitKey = 'two-factor-verify:' . $app->getId() . ':' . $user->getId();

        for ($i = 0; $i < 3; $i++) {
            RateLimiter::hit($rateLimitKey, 600);
        }

        $response = $this->graphQL('
            mutation {
                verifyCode(code: "000000")
            }
        ');

        $errorMessage = $response->json('errors.0.message');

        $this->assertStringNotContainsString('seconds', $errorMessage);
        $this->assertStringNotContainsString('second', $errorMessage);
        $this->assertEquals('Too many verification attempts. Please try again later.', $errorMessage);
    }

    public function testVerifyCodeRateLimitIsConfigurableViaAppSetting(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $rateLimitKey = 'two-factor-verify:' . $app->getId() . ':' . $user->getId();

        // Set custom limit to 5
        $app->set(ConfigurationEnum::TWILIO_2FA_VERIFY_RATE_LIMIT->value, 5);

        // 3 hits should NOT trigger rate limit with custom limit of 5
        for ($i = 0; $i < 3; $i++) {
            RateLimiter::hit($rateLimitKey, 600);
        }

        $this->graphQL('
            mutation {
                verifyCode(code: "123456")
            }
        ')
        ->assertJsonMissing([
            'message' => 'Too many verification attempts. Please try again later.',
        ]);

        // Now hit it 2 more times to reach 5 (3 previous + the 1 from the mutation + 1 more)
        RateLimiter::hit($rateLimitKey, 600);

        $this->graphQL('
            mutation {
                verifyCode(code: "123456")
            }
        ')
        ->assertJson([
            'errors' => [
                [
                    'message' => 'Too many verification attempts. Please try again later.',
                ],
            ],
        ]);
    }

    public function testSendRateLimitIsConfigurableViaAppSetting(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $rateLimitKey = 'two-factor-send:' . $app->getId() . ':' . $user->getId();

        // Set custom limit to 1
        $app->set(ConfigurationEnum::TWILIO_2FA_SEND_RATE_LIMIT->value, 1);

        // 1 hit should trigger rate limit
        RateLimiter::hit($rateLimitKey, 600);

        $this->graphQL('
            mutation {
                sendVerificationCode
            }
        ')
        ->assertJson([
            'errors' => [
                [
                    'message' => 'Too many verification attempts. Please try again later.',
                ],
            ],
        ]);
    }
}
