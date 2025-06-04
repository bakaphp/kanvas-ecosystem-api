<tr>
    <td style="padding-right: 120px;">
        <p style="color: #333333; font-size: 14px; margin: 0;">
            Hello {{ $entity->user->firstname }} {{ $entity->user->lastname }},
        </p>
        <p style="color: #333333; font-size: 14px; margin: 0;">
            Thank you for your eSIM purchase (Order Number: <strong>{{ $entity->order_number }}</strong>)! Below you'll find your eSIM details and activation instructions.
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

{{-- eSIM Details Section --}}
<div style="clear: both; width: 100%; display: inline-block; padding: 25px 0;">
    <h3 style="font-size: 16px; font-weight: 600; margin: 15px 0 10px 0; border-bottom: solid 1px #eaeaea; color: #333333;">
        eSIM Details
    </h3>

    @php
        $counter = 1;
        $esimData = $entity->metadata['data'] ?? [];
        $esimMetadata = $entity->get('order_esim_metadata') ?? [];
    @endphp

    @foreach ($entity->items as $key => $item)
        @php
            $variant = $item->variant;
            $isUnlimited = $variant->getAttributeBySlug('variant-type')?->value === 'unlimited';
            $planData = $variant->getAttributeBySlug('data')?->value ?? 'N/A';
            $planDuration = $variant->getAttributeBySlug('esim_days')?->value ?? $variant->getAttributeBySlug('esim-days')?->value ?? 'N/A';
            $planName = $variant->name ?? $item->product_name;

            // Get eSIM specific data from metadata
            $iccid = $esimData['iccid'] ?? $esimMetadata['data']['iccid'] ?? 'N/A';
            $lpaCode = $esimData['lpa_code'] ?? $esimMetadata['data']['lpa_code'] ?? 'N/A';
            $activationCode = $esimData['matching_id'] ?? $esimMetadata['data']['matching_id'] ?? $esimData['activation_code'] ?? 'N/A';
            $smdpAddress = $esimData['smdp_address'] ?? $esimMetadata['data']['smdp_address'] ?? 'N/A';
            
            // Handle different provider data structures
            if (isset($esimMetadata['data']['downloadUrl'])) {
                $lpaCode = $esimMetadata['data']['downloadUrl'];
            }
            if (isset($esimMetadata['data']['activationCode'])) {
                $activationCode = $esimMetadata['data']['activationCode'];
            }

            $itemCount = $entity->items->count();
            $itemWidth = $itemCount > 1 ? '48%' : '100%';
            $itemFloat = $counter % 2 === 0 ? 'right' : 'left';
        @endphp
        
        <div style="border-bottom: solid 1px #eaeaea; max-width: {{ $itemWidth }}; width: 100%; padding: 15px 0; float: {{ $itemFloat }}; margin-bottom: 20px;">
            @if (!$isUnlimited)
                <p style="margin: 0; padding: 0; color: #333333; font-size: 14px;">
                    <span style="font-weight: 600;">Plan:</span> {{ $planName }}
                    @if (!empty($planData) && !empty($planDuration))
                        - ({{ $planData }} - {{ $planDuration }} Days)
                    @else
                        - ({{ $variant->sku }})
                    @endif
                </p>
            @endif
            
            @if ($isUnlimited)
                <p style="margin: 0; padding: 0; color: #333333; font-size: 14px;">
                    <span style="font-weight: 600;">Plan:</span> {{ $planName }}
                    - (Unlimited {{ $planDuration }} Days)
                </p>
                @if (isset($esimData['date_from']))
                    <p style="margin: 0; padding: 0; color: #333333; font-size: 14px;">
                        <span style="font-weight: 600;">Date from:</span> {{ $esimData['date_from'] }}
                    </p>
                @endif
                @if (isset($esimData['date_to']))
                    <p style="margin: 0; padding: 0; color: #333333; font-size: 14px;">
                        <span style="font-weight: 600;">Date to:</span> {{ $esimData['date_to'] }}
                    </p>
                @endif
                @if (!empty($planDuration))
                    <p style="margin: 0; padding: 0; color: #333333; font-size: 14px;">
                        <span style="font-weight: 600;">Total Days:</span> {{ $planDuration }}
                    </p>
                @endif
            @endif

            <p style="margin: 0; padding: 0; color: #333333; font-size: 14px;">
                <span style="font-weight: 600;">ICCID:</span> {{ $iccid }}
            </p>
            
            <p style="margin: 0; padding: 0; color: #333333; font-size: 14px;">
                <span style="font-weight: 600;">Activation Type:</span>
                @if (isset($entity->metadata['parent_order_id']) && !empty($entity->metadata['parent_order_id']))
                    TopUp
                @else
                    New
                @endif
            </p>

            <p style="margin: 0; padding: 0; color: #333333; font-size: 14px;">
                <span style="font-weight: 600;">LPA Code:</span> {{ $lpaCode }}
            </p>

            <p style="margin: 0; padding: 0; color: #333333; font-size: 14px;">
                <span style="font-weight: 600;">Activation Code:</span> {{ $activationCode }}
            </p>

            <p style="margin: 0; padding: 0; color: #333333; font-size: 14px;">
                <span style="font-weight: 600;">SM-DP+ Address:</span> {{ $smdpAddress }}
            </p>
        </div>

        @php $counter++; @endphp
    @endforeach
</div>

{{-- Clear floats --}}
<tr>
    <td style="clear: both; padding-top: 20px;">
        <p style="color: #333333; font-size: 14px; margin: 0; font-weight: bold;">
            Activation Instructions:
        </p>
        <p style="color: #333333; font-size: 14px; margin: 4px 0 0 0;">
            1. Scan the QR code or manually enter the LPA code in your device's eSIM settings<br>
            2. Follow your device's prompts to download and install the eSIM<br>
            3. Your eSIM will be activated and ready to use once installation is complete
        </p>
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