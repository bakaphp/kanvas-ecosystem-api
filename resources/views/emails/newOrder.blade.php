<tr>
    <td style="padding-right: 120px;">
        <p style="color: #9b9b9b; font-size: 14px;">
            Hi {{ $entity->user->firstname }} {{ $entity->user->lastname }},
        </p>

        <p style="color: #9b9b9b; font-size: 14px; margin: 0;">
            Thank you for your order (Order Number: <strong>{{ $entity->order_number }}</strong>)! Below are the details of your order.
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
        <p style="color: #9b9b9b; font-size: 14px; margin: 0;">
            Total: <strong>{{ number_format($entity->getTotalAmount(), 2) }} {{ $entity->currency }}</strong>
        </p>
    </td>
</tr>

<!-- <tr>
    <td style="padding-top: 20px;">
        <table style="margin: 17px 0 0px" cellspacing="0" cellpadding="0">
            <tr>
                <td>
                    <a href="{{ $app->url }}/orders/view/{{ $entity->uuid }}" target="_blank" style="display: inline-block;">
                        View Orders
                    </a>
                </td>
            </tr>
        </table>
    </td>
</tr> -->