<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="font-family: Arial, Helvetica, sans-serif; color: #222222; line-height: 1.4; margin: 20px 0 0; text-align: left;">
    <tr>
        <td colspan="{{ $photoUrl ? 3 : 1 }}" style="font-size: 14px; color: #444444; padding-bottom: 12px;">Best Regards,</td>
    </tr>
    <tr>
        @if($photoUrl)
        <td valign="top" style="padding-right: 18px;">
            <img src="{{ $photoUrl }}" alt="{{ $name }}" width="96" height="96" style="display: block; width: 96px; height: 96px; object-fit: cover; border-radius: 50%; border: 0;">
        </td>
        <td width="1" style="width: 1px; background-color: #dddddd; font-size: 1px; line-height: 1px;">&nbsp;</td>
        @endif
        <td valign="top" style="{{ $photoUrl ? 'padding-left: 18px;' : '' }} text-align: left;">
            <div style="font-size: 19px; font-weight: 700; color: #1f1f1f; line-height: 1.2; margin: 0;">{{ $name }}</div>
            @foreach($lines as $line)
            <div style="font-size: 13px; color: #666666; padding-top: 2px;">@if($line === '')&nbsp;@else{{ $line }}@endif</div>
            @endforeach
        </td>
    </tr>
</table>
