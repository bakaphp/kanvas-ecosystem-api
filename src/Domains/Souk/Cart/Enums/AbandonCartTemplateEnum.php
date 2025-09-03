<?php

declare(strict_types=1);

namespace Kanvas\Souk\Cart\Enums;

enum AbandonCartTemplateEnum: string
{
    // Template variables for email notifications

    // First Email
    case EMAIL_FIRST_TITLE_EN = 'Your Cart is Waiting';
    case EMAIL_FIRST_TITLE_ES = 'Tu Carrito te está Esperando';
    case EMAIL_FIRST_MESSAGE_EN = 'Hi %s! You left some great items in your cart. Don\'t let them get away!';
    case EMAIL_FIRST_MESSAGE_ES = 'Hola %s! Dejaste algunos artículos geniales en tu carrito. ¡No dejes que se escapen!';

    // Second Email
    case EMAIL_SECOND_TITLE_EN = 'Don\'t Miss Out on Your Items';
    case EMAIL_SECOND_TITLE_ES = 'No te Pierdas tus Artículos';
    case EMAIL_SECOND_MESSAGE_EN = 'Hi %s! Your cart is still waiting. Complete your purchase now with code %s to save!';
    case EMAIL_SECOND_MESSAGE_ES = 'Hola %s! Tu carrito aún te espera. ¡Completa tu compra ahora con el código %s para ahorrar!';
    case EMAIL_SECOND_MESSAGE_NO_DISCOUNT_EN = 'Hi %s! Your cart is still waiting. Complete your purchase now!';
    case EMAIL_SECOND_MESSAGE_NO_DISCOUNT_ES = 'Hola %s! Tu carrito aún te espera. ¡Completa tu compra ahora!';

    // Third Email
    case EMAIL_THIRD_TITLE_EN = 'Last Chance - Your Cart Expires Soon';
    case EMAIL_THIRD_TITLE_ES = 'Última Oportunidad - Tu Carrito Expira Pronto';
    case EMAIL_THIRD_MESSAGE_EN = 'Hi %s! This is your final reminder. Complete your purchase now with code %s before items are gone!';
    case EMAIL_THIRD_MESSAGE_ES = 'Hola %s! Este es tu recordatorio final. ¡Completa tu compra ahora con el código %s antes de que se agoten!';
    case EMAIL_THIRD_MESSAGE_NO_DISCOUNT_EN = 'Hi %s! This is your final reminder. Complete your purchase now before items are gone!';
    case EMAIL_THIRD_MESSAGE_NO_DISCOUNT_ES = 'Hola %s! Este es tu recordatorio final. ¡Completa tu compra ahora antes de que se agoten!';

    // Template variables for push notifications

    // First Push
    case PUSH_FIRST_TITLE_EN = 'Cart Waiting';
    case PUSH_FIRST_TITLE_ES = 'Carrito Esperando';
    case PUSH_FIRST_MESSAGE_EN = 'You have items in your cart. Complete your purchase now!';
    case PUSH_FIRST_MESSAGE_ES = 'Tienes artículos en tu carrito. ¡Completa tu compra ahora!';

    // Second Push
    case PUSH_SECOND_TITLE_EN = 'Don\'t Miss Out';
    case PUSH_SECOND_TITLE_ES = 'No te lo Pierdas';
    case PUSH_SECOND_MESSAGE_EN = 'Your cart is waiting. Use code %s to save!';
    case PUSH_SECOND_MESSAGE_ES = 'Tu carrito te espera. ¡Usa el código %s para ahorrar!';
    case PUSH_SECOND_MESSAGE_NO_DISCOUNT_EN = 'Your cart is waiting. Complete your purchase now!';
    case PUSH_SECOND_MESSAGE_NO_DISCOUNT_ES = 'Tu carrito te espera. ¡Completa tu compra ahora!';

    // Third Push
    case PUSH_THIRD_TITLE_EN = 'Last Chance';
    case PUSH_THIRD_TITLE_ES = 'Última Oportunidad';
    case PUSH_THIRD_MESSAGE_EN = 'Final notice: Complete your purchase now! Code: %s';
    case PUSH_THIRD_MESSAGE_ES = 'Aviso final: ¡Completa tu compra ahora! Código: %s';
    case PUSH_THIRD_MESSAGE_NO_DISCOUNT_EN = 'Final notice: Complete your purchase now before items are gone!';
    case PUSH_THIRD_MESSAGE_NO_DISCOUNT_ES = 'Aviso final: ¡Completa tu compra ahora antes de que se agoten!';

    // Common text
    case COMPLETE_PURCHASE_EN = 'Complete Your Purchase';
    case COMPLETE_PURCHASE_ES = 'Completa tu Compra';
    case CONTINUE_SHOPPING_EN = 'Continue Shopping';
    case CONTINUE_SHOPPING_ES = 'Continuar Comprando';
    case CART_SUMMARY_EN = 'Cart Summary';
    case CART_SUMMARY_ES = 'Resumen del Carrito';
    case ITEM_EN = 'Item';
    case ITEM_ES = 'Artículo';
    case QUANTITY_EN = 'Quantity';
    case QUANTITY_ES = 'Cantidad';
    case PRICE_EN = 'Price';
    case PRICE_ES = 'Precio';
    case TOTAL = 'Total';
    case DISMISS_EN = 'Dismiss';
    case DISMISS_ES = 'Cerrar';

    /**
     * Get template text by key and language.
     */
    public static function get(string $key, string $lang = 'en'): string
    {
        $enumKey = strtoupper($key) . '_' . strtoupper($lang);

        foreach (self::cases() as $case) {
            if ($case->name === $enumKey) {
                return $case->value;
            }
        }

        // Try without language suffix for universal cases like TOTAL
        $universalKey = strtoupper($key);
        foreach (self::cases() as $case) {
            if ($case->name === $universalKey) {
                return $case->value;
            }
        }

        // Fallback to English if not found
        $fallbackKey = strtoupper($key) . '_EN';
        foreach (self::cases() as $case) {
            if ($case->name === $fallbackKey) {
                return $case->value;
            }
        }

        return $key; // Return key as fallback
    }
}
