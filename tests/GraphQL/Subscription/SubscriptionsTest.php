<?php

declare(strict_types=1);

namespace Tests\GraphQL\Subscription;

use Tests\TestCase;
use Stripe\StripeClient;

class SubscriptionsTest extends TestCase
{
    protected StripeClient $stripe;

    /**
     * Test para crear una suscripción con un plan en Stripe.
     */
    public function testCreateSubscriptionWithStripePlan(): void
    {   

        // Datos del plan y de la suscripción
        $planId = 'price_1PvfPb14jpNveAtLGB4g3pfK'; // El plan de Stripe que proporcionaste
        $companyId = 1;
        $name = 'Enterprise Plan Subscription';

        // Llamar a la mutación de GraphQL
        $response = $this->graphQL(/** GraphQL */ '
            mutation CreateSubscription($input: SubscriptionInput!) {
                createSubscription(input: $input) {
                    id
                    stripe_id
                    name
                    stripe_plan
                    is_active
                    charge_date
                }
            }
        ', [
            'input' => [
                'companies_id' => $companyId,
                'apps_id' => 1, // Asumiendo que tienes una app con ID 1 en tu sistema
                'stripe_plan' => $planId,
                'name' => $name,
                'payment_method_id' => 'pm_card_visa', // Simulación de un método de pago
            ],
        ]);

        // Verificar que la respuesta no tenga errores y tenga los datos esperados
        $response->assertJson([
            'data' => [
                'createSubscription' => [
                    'name' => $name,
                    'stripe_plan' => $planId,
                    'is_active' => true,
                ],
            ],
        ]);

        // Verificar que la suscripción se haya creado en la base de datos
        $this->assertDatabaseHas('subscriptions', [
            'companies_id' => $companyId,
            'stripe_plan' => $planId,
            'name' => $name,
            'is_active' => true,
        ]);

        
    }
}
