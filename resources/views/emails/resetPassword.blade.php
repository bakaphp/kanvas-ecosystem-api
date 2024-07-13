<tr>
<td style="padding-right: 120px;">
    <p style="color: #9b9b9b; font-size: 14px; ">
        Hi {{ $user->firstname}} {{ $user->lastname}},
    </h2>
    <p style="color: #9b9b9b; font-size: 14px; margin: 0;">
        You recently requested to reset your password for your SalesAssist account. Click the button below to reset it.
    </p>
</td>
</tr>

<tr>
<td>
    <table style="margin: 17px 0 30px" cellspacing="0" cellpadding="0">
        <tr>
            <td>
                <a href="{{ $resetUrl }}" target="_blank" style="display: inline-block;">
                    <img style="border-radius: 4px;" src="https://cdn.salesassist.io/emails/reset-password.png" alt="Join Now">
                </a>
            </td>
        </tr>
    </table>
</td>
</tr>