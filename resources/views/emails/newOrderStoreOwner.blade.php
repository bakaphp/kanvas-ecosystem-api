<tr>
    <td style="padding-right: 120px;">
        <p style="color: #9b9b9b; font-size: 14px;">
            Hi {{ $admin->firstname }} {{ $admin->lastname }},
        </p>

        <p style="color: #9b9b9b; font-size: 14px; margin: 0;">
            A new order (Order Number: <strong>{{ $order->order_number }}</strong>) has been placed in your store.
        </p>

        <p style="color: #9b9b9b; font-size: 14px; margin: 0;">
            Here are the details of the order:
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
                @foreach ($order->items as $item)
                    <tr>
                        <td style="padding: 10px;">{{ $item->product_name }}</td>
                        <td style="text-align: right; padding: 10px;">{{ $item->quantity }}</td>
                        <td style="text-align: right; padding: 10px;">{{ number_format($item->unit_price_gross_amount, 2) }} {{ $order->currency }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </td>
</tr>

<tr>
    <td style="padding-top: 20px;">
        <p style="color: #9b9b9b; font-size: 14px; margin: 0;">
            Subtotal: <strong>{{ number_format($order->getSubTotalAmount(), 2) }} {{ $order->currency }}</strong>
        </p>
        <p style="color: #9b9b9b; font-size: 14px; margin: 0;">
            Shipping: <strong>{{ number_format($order->shipping_price_gross_amount, 2) }} {{ $order->currency }}</strong>
        </p>
        <p style="color: #9b9b9b; font-size: 14px; margin: 0;">
            Tax: <strong>{{ number_format($order->getTotalTaxAmount(), 2) }} {{ $order->currency }}</strong>
        </p>
        <p style="color: #9b9b9b; font-size: 14px; margin: 0;">
            Total: <strong>{{ number_format($order->getTotalAmount(), 2) }} {{ $order->currency }}</strong>
        </p>
    </td>
</tr>

<tr>
    <td style="padding-top: 20px;">
        <p style="color: #9b9b9b; font-size: 14px; margin: 0;">
            Customer Name: <strong>{{ $order->user->firstname }} {{ $order->user->lastname }}</strong>
        </p>
        <p style="color: #9b9b9b; font-size: 14px; margin: 0;">
            Email: <strong>{{ $order->getEmail() }}</strong>
        </p>
        <p style="color: #9b9b9b; font-size: 14px; margin: 0;">
            Phone: <strong>{{ $order->getPhone() }}</strong>
        </p>
    </td>
</tr>
