{{-- Template row: name = customer-update-email. Only $body is required; the rest fall back. --}}
<!DOCTYPE html>
<html lang="en"><head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="x-apple-disable-message-reformatting">
<meta name="color-scheme" content="light">
<title>{{ $subject ?? 'Product update' }}</title>
</head>
<body style="margin:0;padding:0;width:100%;background:#ffffff;">

<div style="display:none;font-size:1px;color:#ffffff;line-height:1px;max-height:0;max-width:0;opacity:0;overflow:hidden;">{{ $preheader ?? 'What shipped in Kanvas this month, and what it changes for you.' }}</div>

<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#ffffff;">
<tr><td align="center" style="padding:36px 20px 52px 20px;">

  <table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" style="width:600px;max-width:600px;">

    <tr><td align="left" style="padding:0 0 26px 0;">
      <img src="{{ $logo ?? 'https://kanvas.dev/logos/lockup-black-trim.png' }}" alt="{{ $brandName ?? 'Kanvas' }}" height="26" style="height:26px;width:auto;border:0;display:block;">
    </td></tr>

    {{-- The agent's copy, already converted and inline-styled. --}}
    <tr><td align="left">{!! $body !!}</td></tr>

    <tr><td align="left" style="padding:32px 0 0 0;">
      <div style="height:1px;background:#e6e6e6;line-height:1px;font-size:0;">&nbsp;</div>
    </td></tr>

    <tr><td align="left" style="padding:24px 0 0 0;font:400 14px/1.7 -apple-system,BlinkMacSystemFont,'Segoe UI',Helvetica,Arial,sans-serif;color:#767676;">
      You are receiving this email because {{ $organizationName ?? 'your team' }} uses {{ $brandName ?? 'Kanvas' }}.<br>
      @isset($unsubscribeUrl)If you prefer not to hear from us again, <a href="{{ $unsubscribeUrl }}" style="color:#767676;text-decoration:underline;">unsubscribe</a>.@endisset
    </td></tr>

    @isset($websiteUrl)
    <tr><td align="left" style="padding:20px 0 0 0;font:400 14px/1.7 -apple-system,BlinkMacSystemFont,'Segoe UI',Helvetica,Arial,sans-serif;color:#767676;">
      <a href="{{ $websiteUrl }}" style="color:#767676;text-decoration:none;">{{ $brandName ?? 'Kanvas' }}</a>@isset($address)<span style="color:#c4c4c4;"> &middot; </span>{{ $address }}@endisset
    </td></tr>
    @endisset

  </table>

</td></tr>
</table>
</body></html>
