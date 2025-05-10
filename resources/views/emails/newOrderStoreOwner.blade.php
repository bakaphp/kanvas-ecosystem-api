<tr>
    <td style="padding-right: 120px;">
        <p style="color: #9b9b9b; font-size: 14px;">
            Hi {{ $user->firstname }} {{ $user->lastname }},
        </p>

        <p style="color: #9b9b9b; font-size: 14px; margin: 0;">
            A new order has just been placed in your store 🎉
        </p>

        <p style="color: #9b9b9b; font-size: 14px; margin: 0;">
            Order Number: <strong>{{ $entity->order_number }}</strong>
        </p>

        <p style="color: #9b9b9b; font-size: 14px; margin: 0;">
            Here's a summary of the order details:
        </p>
    </td>
</tr>

<tr>
    <td style="padding: 20px 0;">
        <table style="width: 100%; border-collapse: collapse;">
            <thead>
                <tr>
                    <th style="text-align: left; border-bottom: 1px solid #ddd; padding: 10px;">Item</th>
                    <th style="text-align: right; border-bottom: 1px solid #ddd; padding: 10px;">Quantity</th>
                    <th style="text-align: right; border-bottom: 1px solid #ddd; padding: 10px;">Price</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($entity->items as $item)
                    <tr>
                        <td style="padding: 10px;">{{ $item->product_name }}</td>
                        <td style="text-align: right; padding: 10px;">{{ $item->quantity }}</td>
                        <td style="text-align: right; padding: 10px;">{{ number_format($item->unit_price_gross_amount, 2) }} {{ $entity->currency }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </td>
</tr>

<tr>
    <td style="padding-top: 20px;">
        <p style="color: #9b9b9b; font-size: 14px; margin: 0;">
            Subtotal: <strong>{{ number_format($entity->getSubTotalAmount(), 2) }} {{ $entity->currency }}</strong>
        </p>
        <p style="color: #9b9b9b; font-size: 14px; margin: 0;">
            Shipping: <strong>{{ number_format($entity->shipping_price_gross_amount, 2) }} {{ $entity->currency }}</strong>
        </p>
        <p style="color: #9b9b9b; font-size: 14px; margin: 0;">
            Tax: <strong>{{ number_format($entity->getTotalTaxAmount(), 2) }} {{ $entity->currency }}</strong>
        </p>
        <p style="color: #000; font-size: 14px; font-weight: bold; margin: 0;">
            Total: {{ number_format($entity->getTotalAmount(), 2) }} {{ $entity->currency }}
        </p>
    </td>
</tr>

<tr>
    <td style="padding-top: 30px;">
        <p style="color: #9b9b9b; font-size: 14px; font-weight: bold; margin: 0;">
            Customer Information:
        </p>
        <p style="color: #9b9b9b; font-size: 14px; margin: 0;">
            Name: <strong>{{ $entity->user->firstname }} {{ $entity->user->lastname }}</strong><br>
            Email: <strong>{{ $entity->getEmail() }}</strong><br>
            Phone: <strong>{{ $entity->getPhone() }}</strong>
        </p>
    </td>
</tr>

@php
    $address = $entity->people->address()->first();
@endphp

@if ($address)
<tr>
    <td style="padding-top: 20px;">
        <p style="color: #9b9b9b; font-size: 14px; font-weight: bold; margin: 0;">Shipping Address:</p>
        <p style="color: #9b9b9b; font-size: 14px; margin: 0;">
            {{ $address->address }}{{ $address->address_2 ? ', ' . $address->address_2 : '' }}<br>
            {{ $address->city }}, {{ $address->state }} {{ $address->zip }}<br>
            {{ $address->country?->name }}
        </p>
    </td>
</tr>
@endif

<tr>
    <td style="padding-top: 20px;">
        <p style="color: #9b9b9b; font-size: 14px; margin: 0;">
            Estimated delivery date: <strong>{{ $entity->created_at->addDays(7)->format('d/m/Y') }}</strong>
        </p>
    </td>
</tr>