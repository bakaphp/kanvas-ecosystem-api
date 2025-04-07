<td style="padding-right: 120px;">
        <p style="color: #9b9b9b; font-size: 14px; ">
            Hi {{ $entity->firstname}} {{ $entity->lastname}},
        </h2>

        <p style="color: #9b9b9b; font-size: 14px; margin: 0;">
            You have been invited to {{ $app->name }} by {{ $fromUser->firstname }} {{ $fromUser->lastname }}. Your account is already active. Please click the button below to log in and access the app.
        </p>
    </td>
</tr>

<tr>
    <td>
        <table style="margin: 17px 0 0px" cellspacing="0" cellpadding="0">
            <tr>
                <td>
                    <a href="{{ config('kanvas.app.frontend_url') }}" target="_blank" style="display: inline-block;">
                        Login
                    </a>
                </td>
            </tr>
        </table>
    </td>
</tr>