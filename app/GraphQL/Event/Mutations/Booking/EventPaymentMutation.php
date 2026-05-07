<?php

declare(strict_types=1);

namespace App\GraphQL\Event\Mutations\Booking;

use Baka\Support\Str;
use Exception;
use GraphQL\Type\Definition\ResolveInfo;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Stripe\Services\StripePaymentService;
use Kanvas\Event\Events\Enums\EventStatusEnum;
use Kanvas\Event\Events\Models\EventStatus;
use Kanvas\Event\Events\Models\EventVersion;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Souk\Payments\Actions\CreatePaymentAction;
use Kanvas\Souk\Payments\Enums\PaymentStatusEnum;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

class EventPaymentMutation
{
    public function confirmBooking(mixed $root, array $args, GraphQLContext $context, ResolveInfo $info): array
    {
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $app = app(Apps::class);

        $input = $args['input'];
        $eventVersionId = $input['event_version_id'];
        $paymentIntentId = $input['payment_intent_id'];

        $eventVersion = EventVersion::where('id', $eventVersionId)
            ->fromCompany($company)
            ->fromApp($app)
            ->firstOrFail();

        $event = $eventVersion->event;

        $order = $event->orders()->first();

        if (! $order) {
            throw new ValidationException('No order found for this event');
        }

        if ($order->isPaid()) {
            throw new ValidationException('Order is already paid');
        }

        try {
            $paymentData = [
                'payment_intent_id' => $paymentIntentId,
                'amount' => $input['amount'] ?? $order->getTotalAmount(),
                'status' => PaymentStatusEnum::PAID->value,
            ];

            // link existing stripe logic
            $stripe = new StripePaymentService($app);
            $stripe->processPaymentIntent($paymentIntentId);

            // Create payment record to keep the data separated from the order
            $payment = new CreatePaymentAction($order, $user)->execute($paymentData);

            if (! $payment) {
                throw new ValidationException('Payment could not be processed');
            }

            $activeStatus = EventStatus::firstOrCreate([
                'companies_id' => $company->getId(),
                'apps_id' => $app->getId(),
                'name' => EventStatusEnum::ACTIVE->value,
            ], [
                'users_id' => $user->getId(),
                'slug' => strtolower(Str::slug(EventStatusEnum::ACTIVE->value)),
            ]);

            $event->update([
                'event_status_id' => $activeStatus->getId(),
            ]);

            return [
                'success' => true,
                'message' => 'Payment confirmed and booking activated successfully',
                'event_version' => $eventVersion->fresh(),
                'payment' => $payment,
                'order' => $order->fresh(),
            ];
        } catch (Exception $e) {
            throw new ValidationException('Failed to confirm booking: ' . $e->getMessage());
        }
    }
}
