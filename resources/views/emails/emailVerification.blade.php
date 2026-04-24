<tr>
<td style="padding-right: 120px;">
    <p style="color: #9b9b9b; font-size: 14px; ">
        Hi {{ $user->firstname }} {{ $user->lastname }},
    </p>
    <p style="color: #9b9b9b; font-size: 14px; margin: 0;">
        Please verify your email address to activate your account on {{ $app->name }}.
    </p>
</td>
</tr>

<tr>
<td>
    <table style="margin: 17px 0 30px" cellspacing="0" cellpadding="0">
        <tr>
            <td>
                <a href="{{ $verifyUrl }}" target="_blank" style="display: inline-block; background-color: #4A90E2; color: #ffffff; padding: 12px 24px; border-radius: 4px; text-decoration: none; font-size: 14px;">
                    Verify Email
                </a>
            </td>
        </tr>
    </table>
    <p style="color: #9b9b9b; font-size: 12px;">
        If the button doesn't work, copy and paste this link into your browser:<br>
        <a href="{{ $verifyUrl }}" style="color: #4A90E2; word-break: break-all;">{{ $verifyUrl }}</a>
    </p>
</td>
</tr>
