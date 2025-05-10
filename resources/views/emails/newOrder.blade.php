<tr>
    <td style="padding-right: 120px;">
        <p style="color: #333333; font-size: 14px; margin: 0;">
            Hello {{ $entity->user->firstname }} {{ $entity->user->lastname }},
        </p>
        <p style="color: #333333; font-size: 14px; margin: 0;">
            Thank you for your purchase (Order Number: <strong>{{ $entity->order_number }}</strong>)! Below you’ll find the details of your order.
        </p>
    </td>
</tr>

<tr>
    <td style="padding: 25px 0;">
        <table style="width: 100%; border-collapse: collapse;">
            <thead>
                <tr>
                    <th style="text-align: left; padding: 10px; font-size: 14px; color: #333333; border-bottom: 1px solid #eaeaea;">Product</th>
                    <th style="text-align: center; padding: 10px; font-size: 14px; color: #333333; border-bottom: 1px solid #eaeaea;">Quantity</th>
                    <th style="text-align: right; padding: 10px; font-size: 14px; color: #333333; border-bottom: 1px solid #eaeaea;">Price</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($entity->items as $item)
                    <tr>
                        <td style="padding: 10px; font-size: 14px; color: #333333;">{{ $item->product_name }}</td>
                        <td style="text-align: center; padding: 10px; font-size: 14px; color: #333333;">{{ $item->quantity }}</td>
                        <td style="text-align: right; padding: 10px; font-size: 14px; color: #333333;">{{ number_format($item->unit_price_gross_amount, 2) }} {{ $entity->currency }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </td>
</tr>

<tr>
    <td style="padding-top: 15px;">
        <table style="width: 100%; max-width: 300px; float: right; font-size: 14px; color: #333333;">
            <tr>
                <td style="padding: 5px 0;">Subtotal:</td>
                <td style="text-align: right;"><strong>{{ number_format($entity->getSubTotalAmount(), 2) }} {{ $entity->currency }}</strong></td>
            </tr>
            <tr>
                <td style="padding: 5px 0;">Shipping:</td>
                <td style="text-align: right;"><strong>{{ number_format($entity->shipping_price_gross_amount, 2) }} {{ $entity->currency }}</strong></td>
            </tr>
            <tr>
                <td style="padding: 5px 0;">Taxes:</td>
                <td style="text-align: right;"><strong>{{ number_format($entity->getTotalTaxAmount(), 2) }} {{ $entity->currency }}</strong></td>
            </tr>
            <tr>
                <td style="padding: 10px 0; font-weight: bold;">Total:</td>
                <td style="text-align: right; font-weight: bold;">{{ number_format($entity->getTotalAmount(), 2) }} {{ $entity->currency }}</td>
            </tr>
        </table>
    </td>
</tr>

@php
    $address = $entity->people->address()->first();
@endphp

@if ($address)
<tr>
    <td style="padding-top: 40px;">
        <p style="color: #333333; font-size: 14px; margin: 0; font-weight: bold;">Shipping Address:</p>
        <p style="color: #333333; font-size: 14px; margin: 4px 0 0 0;">
            {{ $address->address }}{{ $address->address_2 ? ', ' . $address->address_2 : '' }}<br>
            {{ $address->city }}, {{ $address->state }} {{ $address->zip }}<br>
            {{ $address->country?->name }}
        </p>
    </td>
</tr>
@endif

<tr>
    <td style="padding-top: 20px;">
        <p style="color: #333333; font-size: 14px; margin: 0;">
            Estimated Delivery Date: <strong>{{ $entity->created_at->addDays(7)->format('d/m/Y') }}</strong>
        </p>
    </td>
</tr>

{{-- 
<tr>
    <td style="padding-top: 20px;">
        <table style="margin: 17px 0 0px" cellspacing="0" cellpadding="0">
            <tr>
                <td>
                    <a href="{{ $app->url }}/orders/view/{{ $entity->uuid }}" target="_blank" style="display: inline-block;">
                        View Order
                    </a>
                </td>
            </tr>
        </table>
    </td>
</tr>
--}}