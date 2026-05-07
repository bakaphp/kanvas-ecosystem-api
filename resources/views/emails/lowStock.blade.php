<tr>
    <td style="padding-right: 120px;">
        <p style="color: #333333; font-size: 14px; margin: 0;">
            Hello {{ $user->firstname ?? 'Administrator' }} {{ $user->lastname ?? '' }},
        </p>
        <p style="color: #333333; font-size: 14px; margin: 0;">
            We're notifying you that some products in your inventory are running <strong>low on stock</strong>. Below you'll find the details of the products that require attention.
        </p>
    </td>
</tr>

<tr>
    <td style="padding: 25px 0;">
        <table style="width: 100%; border-collapse: collapse;">
            <thead>
                <tr>
                    <th style="text-align: left; padding: 10px; font-size: 14px; color: #333333; border-bottom: 1px solid #eaeaea;">Product</th>
                    <th style="text-align: center; padding: 10px; font-size: 14px; color: #333333; border-bottom: 1px solid #eaeaea;">Current Stock</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($products as $product)
                    <tr style="border-bottom: 1px solid #f5f5f5;">
                        <td style="padding: 15px 10px; font-size: 14px; color: #333333;">
                            <strong>{{ $product->product_name }}</strong><br>
                            <small style="color: #666666;">{{ $product->product_slug }}</small>
                        </td>
                        <td style="text-align: center; padding: 15px 10px; font-size: 14px; color: {{ $product->total_stock_quantity <= 10 ? '#d32f2f' : '#f57c00' }};">
                            <strong>{{ $product->total_stock_quantity }}</strong>
                            @if($product->total_stock_quantity <= 10)
                                <br><small style="color: #d32f2f; font-weight: bold;">CRITICAL</small>
                            @elseif($product->total_stock_quantity <= 50)
                                <br><small style="color: #f57c00; font-weight: bold;">LOW</small>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </td>
</tr>

<tr>
    <td style="padding-top: 15px;">
        <div style="background-color: #f8f9fa; padding: 15px; border-radius: 5px; border-left: 4px solid #ffc107;">
            <p style="color: #333333; font-size: 14px; margin: 0; font-weight: bold;">
                📊 Inventory Summary
            </p>
            <p style="color: #333333; font-size: 14px; margin: 10px 0 0 0;">
                Total products with low stock: <strong>{{ $products->count() }}</strong><br>
                Configured threshold: <strong>{{ $lowStockThreshold ?? 200 }} units</strong>
            </p>
        </div>
    </td>
</tr>

<tr>
    <td style="padding-top: 20px;">
        <p style="color: #333333; font-size: 14px; margin: 0;">
            Report date: <strong>{{ now()->format('m/d/Y H:i:s') }}</strong>
        </p>
        <p style="color: #666666; font-size: 12px; margin: 5px 0 0 0;">
            This report is generated automatically when stock falls below the configured threshold.
        </p>
    </td>
</tr>