<td style="padding-right: 120px;">
        <p style="color: #9b9b9b; font-size: 14px; ">
            Hi {{ $entity->firstname}} {{ $entity->lastname}},
        </h2>

        <p style="color: #9b9b9b; font-size: 14px; margin: 0;">
            You have been invited to {{ $app->name }} by {{ $fromUser->firstname}} {{ $fromUser->lastname}}. Please click the button below to create your account.
        </p>
    </td>
</tr>

<tr>
    <td>
        <table style="margin: 17px 0 0px" cellspacing="0" cellpadding="0">
            <tr>
                <td>
                    <a href="{{ config('kanvas.app.frontend_url') }}/invite/{{ $entity->invite_hash }}" target="_blank" style="display: inline-block;">
                        <img style="border-radius: 4px;" src="https://cdn.salesassist.io/emails/create-account.png" alt="Join Now">
                    </a>
                </td>
            </tr>
        </table>
    </td>
</tr>