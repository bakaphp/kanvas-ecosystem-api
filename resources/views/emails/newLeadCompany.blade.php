<tr>
    <td style="padding-right: 120px;">
        <p style="color: #9b9b9b; font-size: 14px;">
            Hi {{ $entity->owner->firstname }} {{ $entity->owner->lastname }},
        </p>

        <p style="color: #9b9b9b; font-size: 14px; margin: 0;">
            A new lead has been created successfully via receiver <strong>{{ $entity->leads_receivers_id }}</strong>. Below are the details of the lead:
        </p>
    </td>
</tr>

<tr>
    <td style="padding: 20px 0;">
        <table style="width: 100%; border-collapse: collapse;">
            <thead>
                <tr>
                    <th style="text-align: left; border-bottom: 1px solid #ddd; padding: 10px;">Field</th>
                    <th style="text-align: left; border-bottom: 1px solid #ddd; padding: 10px;">Details</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td style="padding: 10px;">Lead Name</td>
                    <td style="padding: 10px;">{{ $entity->firstname }} {{ $entity->lastname }}</td>
                </tr>
                <tr>
                    <td style="padding: 10px;">Email</td>
                    <td style="padding: 10px;">{{ $entity->email }}</td>
                </tr>
                <tr>
                    <td style="padding: 10px;">Phone</td>
                    <td style="padding: 10px;">{{ $entity->phone }}</td>
                </tr>
                <tr>
                    <td style="padding: 10px;">Title</td>
                    <td style="padding: 10px;">{{ $entity->title }}</td>
                </tr>
                <tr>
                    <td style="padding: 10px;">Company</td>
                    <td style="padding: 10px;">{{ $entity->company->name }}</td>
                </tr>
            </tbody>
        </table>
    </td>
</tr>

<tr>
    <td style="padding-top: 20px;">
        <p style="color: #9b9b9b; font-size: 14px; margin: 0;">
            Created At: <strong>{{ $entity->created_at }}</strong>
        </p>
        <p style="color: #9b9b9b; font-size: 14px; margin: 0;">
            Lead ID: <strong>{{ $entity->id }}</strong>
        </p>
    </td>
</tr>

<!-- <tr>
    <td style="padding-top: 20px;">
        <table style="margin: 17px 0 0px" cellspacing="0" cellpadding="0">
            <tr>
                <td>
                    <a href="{{ $entity->app->url }}/leads/view/{{ $entity->uuid }}" target="_blank" style="display: inline-block;">
                        View Lead
                    </a>
                </td>
            </tr>
        </table>
    </td>
</tr> -->