@php
    $message = $entity->getMessage()['data']['form'];
    $leads = $entity->entity();
    $branchLogo = $leads?->branch?->getFileByName('logo');

    if (strtolower($message['financial']['employment_status'] ?? '') === 'retired') {
        $message['financial']['previous_employment_title'] = $message['financial']['current_employment_title'] ?? '';
        $message['financial']['previous_employer_phone'] = $message['financial']['current_employer_phone'] ?? '';
        $message['financial']['previous_employer'] = $message['financial']['current_employer'] ?? '';
        $message['financial']['years_at_previous_employment'] = $message['financial']['years_at_current_employment'] ?? '';
        $message['financial']['previous_employer_address_line1'] = $message['financial']['current_employer_address_line1'] ?? '';
        $message['financial']['previous_employer_address_line2'] = $message['financial']['current_employer_address_line2'] ?? '';
        $message['financial']['previous_city']['name'] = $message['financial']['city'] ?? '';
        $message['financial']['previous_state']['code'] = $message['financial']['state']['code'] ?? '';
        $message['financial']['previous_zip_code'] = $message['financial']['zip_code'] ?? '';

        $message['financial']['current_employment_title'] = '';
        $message['financial']['current_employer_phone'] = '';
        $message['financial']['current_employer'] = '';
        $message['financial']['years_at_current_employment'] = '';
        $message['financial']['current_employer_address_line1'] = '';
        $message['financial']['current_employer_address_line2'] = '';
        $message['financial']['city'] = '';
        $message['financial']['state']['code'] = '';
        $message['financial']['zip_code'] = '';
    }
@endphp
<html lang="en">

<head>
    <meta content="text/html; charset=utf-8" http-equiv="Content-Type" />
    <style>
        body {
            font-family: Arial, Helvetica, sans-serif;
            padding: 0 25pt 20pt;
            margin: 0;
        }

        h1 {
            font-size: 16pt;
            margin: 0 0 10pt;
            color: #000;
        }

        h3 {
            font-size: 8pt;
            font-weight: normal;
            text-decoration: underline;
            text-transform: uppercase;
            margin: 0 0 8pt;
            color: #000;
        }

        td {
            vertical-align: top;
        }

        hr {
            border-top: solid 1px #707070;
            border-bottom: none;
            margin: 4pt 0;
        }

        body>table:not(:first-child)>tbody>tr>td:not(:last-child) {
            border-right: solid 1pt #707070;
            padding-right: 8pt;
            margin-right: 4pt;
        }

        body>table:not(:first-child)>tbody>tr>td:not(:first-child) {
            padding-left: 4pt;
        }

        .contanct-data {
            margin: 0;
            width: 415pt;
            color: #000;
            font-size: 8pt;
            font-weight: bold;
        }

        .company-logo {
            width: 128pt;
            height: 43pt;
            position: relative;
        }

        .image-wrapper {
            border-radius: 4pt;
            position: absolute;
            top: 0;
            left: 0;
            bottom: 0;
            right: 0;
            background-size: contain;
            background-repeat: no-repeat;
            background-position: 50% 50%;
        }

        .label {
            font-size: 8pt;
            color: #000;
            display: inline-block;
            white-space: nowrap;
            margin-right: 4pt;
            margin-top: 1pt;
            text-transform: uppercase;
        }

        .field-answer {
            background: #f4f4f4;
            font-size: 10pt;
            color: #000;
            padding: 4pt 4pt 4pt 8pt;
            box-sizing: border-box;
        }

        .underline {
            text-decoration: underline;
        }
    </style>
</head>

<body>
    <table style="margin-bottom: 4pt" cellpadding="0" cellspacing="0">
        <tr>
            <td>
                <div class="company-logo">
                    @if (! empty($branchLogo) && ! empty($branchLogo->url))
                    <div
                        class="image-wrapper"
                        style="background-image: url({{ $branchLogo->url }});"
                    ></div>
                    @endif
                </div>
            </td>

            <td style="text-align: right; vertical-align: middle">
                <p class="contanct-data">{{ $leads?->branch?->address }}</p>
                <p class="contanct-data">{{ $leads?->branch?->phone }}</p>
            </td>
        </tr>
    </table>

    <h1>CREDIT APPLICATION</h1>

    <table style="margin-bottom: 12pt" cellpadding="0" cellspacing="0">
        <tr>
            <td>
                <table cellpadding="0" cellspacing="0">
                    <tr>
                        <td>
                            <span class="label">CUSTOMER NAME</span>
                        </td>
                        <td>
                            <div class="field-answer" style="width: 330pt">
                                {{ $message['personal']['first_name'] ?? '' }}
                                @if (isset($message['personal']['middle_name']))
                                {{ $message['personal']['middle_name'] }}
                                @endif
                                {{ $message['personal']['last_name'] ?? '' }}
                            </div>
                        </td>
                    </tr>
                </table>
            </td>

            <td>
                <table cellpadding="0" cellspacing="0">
                    <tr>
                        <td>
                            <span class="label">DEAL #</span>
                        </td>
                        <td>
                            <div class="field-answer" style="width: 94pt; height: 20pt"></div>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>

    <h3>PERSONAL INFO</h3>

    <table cellpadding="0" cellspacing="0">
        <tr>
            <td>
                <table cellpadding="0" cellspacing="0">
                    <tr>
                        <td>
                            <span class="label">EMAIL</span>
                        </td>
                        <td>
                            <div class="field-answer" style="width: 245pt">
                                {{ $message['personal']['email'] ?? '' }}
                            </div>
                        </td>
                    </tr>
                </table>
            </td>
            <td>
                <table cellpadding="0" cellspacing="0">
                    <tr>
                        <td>
                            <span class="label">DATE OF BIRTH</span>
                        </td>
                        <td>
                            <div class="field-answer" style="width: 194.5pt">
                                {{ $message['personal']['dob'] ?? '' }}
                            </div>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>

    @php($ssn = $message['personal']['ssn'] ?? '')
    @if (! empty($ssn))
    <hr />
    <table cellpadding="0" cellspacing="0">
        <tr>
            <td>
                <table cellpadding="0" cellspacing="0">
                    <tr>
                        <td>
                            <span class="label">SNN</span>
                        </td>
                        <td>
                            <div class="field-answer" style="width: 245pt">
                                {{ substr($ssn, 0, 2) . '-' . substr($ssn, 3, 4) . '-' . substr($ssn, 5, 9) }}
                            </div>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
    @endif

    <hr />

    <table cellpadding="0" cellspacing="0">
        <tr>
            <td>
                <table cellpadding="0" cellspacing="0">
                    <tr>
                        <td>
                            <span class="label">MOBILE NUMBER</span>
                        </td>
                        <td>
                            <div class="field-answer" style="width: 200pt">
                                {{ $message['personal']['mobile_number'] ?? '' }}
                            </div>
                        </td>
                    </tr>
                </table>
            </td>

            @if (! empty($message['personal']['home_number']))
            <td>
                <table cellpadding="0" cellspacing="0">
                    <tr>
                        <td>
                            <span class="label">HOME NUMBER</span>
                        </td>
                        <td>
                            <div class="field-answer" style="width: 194pt">
                                {{ $message['personal']['home_number'] }}
                            </div>
                        </td>
                    </tr>
                </table>
            </td>
            @endif
        </tr>
    </table>

    @if (! empty($message['personal']['drivers_license']))
    <hr />
    <table cellpadding="0" cellspacing="0">
        <tr>
            <td>
                <table cellpadding="0" cellspacing="0">
                    <tr>
                        <td>
                            <span class="label">DRIVER'S<br />LICENSE NO.</span>
                        </td>
                        <td>
                            <div class="field-answer" style="width: 245pt">
                                {{ $message['personal']['drivers_license'] }}
                            </div>
                        </td>
                    </tr>
                </table>
            </td>
            <td>
                <table cellpadding="0" cellspacing="0">
                    <tr>
                        <td>
                            <span class="label">DRIVER'S<br />LICENSE STATE</span>
                        </td>
                        <td>
                            <div class="field-answer" style="width: 194.5pt">
                                {{ $message['personal']['drivers_license_state']['name'] ?? '' }}
                            </div>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
    @endif

    <h3 style="margin-top: 10pt">HOUSING INFORMATION</h3>
    <table cellpadding="0" cellspacing="0">
        <tr>
            <td>
                <table cellpadding="0" cellspacing="0">
                    <tr>
                        <td>
                            <span class="label">CURRENT<br />RESIDENCE</span>
                        </td>
                        <td>
                            <div class="field-answer" style="width: 494pt">
                                {{ $message['housing']['address'] ?? '' }}
                                {{ $message['housing']['address_line2'] ?? '' }}
                            </div>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>

    <hr />

    <table cellpadding="0" cellspacing="0">
        <tr>
            <td>
                <table cellpadding="0" cellspacing="0">
                    <tr>
                        <td>
                            <span class="label">COUNTY</span>
                        </td>
                        <td>
                            <div class="field-answer" style="width: 155pt">
                                {{ $message['housing']['county'] ?? '' }}
                            </div>
                        </td>
                    </tr>
                </table>
            </td>

            <td>
                <table cellpadding="0" cellspacing="0">
                    <tr>
                        <td>
                            <span class="label">STATE</span>
                        </td>
                        <td>
                            <div class="field-answer" style="width: 120pt">
                                {{ $message['housing']['state']['name'] ?? '' }}
                            </div>
                        </td>
                    </tr>
                </table>
            </td>

            <td>
                <table cellpadding="0" cellspacing="0">
                    <tr>
                        <td>
                            <span class="label">CITY</span>
                        </td>
                        <td>
                            <div class="field-answer" style="width: 156.5pt">
                                {{ $message['housing']['city'] ?? '' }}
                            </div>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>

    <hr />

    <table cellpadding="0" cellspacing="0">
        <tr>
            <td>
                <table cellpadding="0" cellspacing="0">
                    <tr>
                        <td>
                            <span class="label">ZIP CODE</span>
                        </td>
                        <td>
                            <div class="field-answer" style="width: 150pt">
                                {{ $message['housing']['zip_code'] ?? '' }}
                            </div>
                        </td>
                    </tr>
                </table>
            </td>

            <td>
                <table cellpadding="0" cellspacing="0">
                    <tr>
                        <td>
                            <span class="label">Residence<br />type</span>
                        </td>
                        <td>
                            <div class="field-answer" style="width: 96pt">
                                {{ $message['housing']['residence_type'] ?? '' }}
                            </div>
                        </td>
                    </tr>
                </table>
            </td>

            @if (! empty($message['housing']['rent']))
            <td>
                <table cellpadding="0" cellspacing="0">
                    <tr>
                        <td>
                            <span class="label">RENT/<br />MORTGAGE</span>
                        </td>
                        <td>
                            <div class="field-answer" style="width: 127pt">
                                {{ $message['housing']['rent'] }}
                            </div>
                        </td>
                    </tr>
                </table>
            </td>
            @endif
        </tr>
    </table>

    <hr />

    <table style="margin-bottom: 10pt" cellpadding="0" cellspacing="0">
        <tr>
            <td>
                <table cellpadding="0" cellspacing="0">
                    <tr>
                        <td>
                            <span class="label">
                                Time at Current Address
                            </span>
                        </td>
                        <td>
                            <div class="field-answer" style="width: 158pt">
                                {{ $message['housing']['time_at_address'] ?? '' }}
                            </div>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>

    @if (($message['housing']['time_at_address'] ?? 0) < 2)
    <hr />

    <table cellpadding="0" cellspacing="0">
        <tr>
            <td>
                <table cellpadding="0" cellspacing="0">
                    <tr>
                        <td>
                            <span class="label">
                                PREVIOUS <br />
                                RESIDENCE
                            </span>
                        </td>
                        <td>
                            <div class="field-answer" style="width: 494pt">
                                {{ $message['housing']['previous_address'] ?? '' }}
                                {{ $message['housing']['previous_address_line2'] ?? '' }}
                            </div>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>

    <hr />

    <table cellpadding="0" cellspacing="0">
        <tr>
            <td>
                <table cellpadding="0" cellspacing="0">
                    <tr>
                        <td>
                            <span class="label">COUNTY</span>
                        </td>
                        <td>
                            <div class="field-answer" style="width: 150pt">
                                {{ $message['housing']['previous_county'] ?? '' }}
                            </div>
                        </td>
                    </tr>
                </table>
            </td>

            <td>
                <table cellpadding="0" cellspacing="0">
                    <tr>
                        <td>
                            <span class="label">STATE</span>
                        </td>
                        <td>
                            <div class="field-answer" style="width: 125pt">
                                {{ $message['housing']['previous_state']['name'] ?? '' }}
                            </div>
                        </td>
                    </tr>
                </table>
            </td>

            <td>
                <table cellpadding="0" cellspacing="0">
                    <tr>
                        <td>
                            <span class="label">CITY</span>
                        </td>
                        <td>
                            <div class="field-answer" style="width: 156.5pt">
                                {{ $message['housing']['previous_city'] ?? '' }}
                            </div>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>

    <hr />

    <table style="margin-bottom: 12pt" cellpadding="0" cellspacing="0">
        <tr>
            <td>
                <table cellpadding="0" cellspacing="0">
                    <tr>
                        <td>
                            <span class="label">ZIP CODE</span>
                        </td>
                        <td>
                            <div class="field-answer" style="width: 147pt">
                                {{ $message['housing']['previous_zip_code'] ?? '' }}
                            </div>
                        </td>
                    </tr>
                </table>
            </td>

            @if (! empty($message['housing']['previous_time_at_address']))
            <td>
                <table cellpadding="0" cellspacing="0">
                    <tr>
                        <td>
                            <span class="label">TIME AT PREVIOUS ADDRESS</span>
                        </td>
                        <td>
                            <div class="field-answer" style="width: 221pt">
                                {{ $message['housing']['previous_time_at_address'] }}
                            </div>
                        </td>
                    </tr>
                </table>
            </td>
            @endif
        </tr>
    </table>
    @endif

    <h3>FINANCIAL INFORMATION</h3>

    <table cellpadding="0" cellspacing="0">
        <tr>
            <td>
                <table cellpadding="0" cellspacing="0">
                    <tr>
                        <td>
                            <span class="label">Current<br />Employment Status</span>
                        </td>
                        <td>
                            <div class="field-answer" style="width: 85pt">
                                {{ $message['financial']['employment_status'] ?? '' }}
                            </div>
                        </td>
                    </tr>
                </table>
            </td>

            <td>
                <table cellpadding="0" cellspacing="0">
                    <tr>
                        <td>
                            <span class="label">Employment<br />Title</span>
                        </td>
                        <td>
                            <div class="field-answer" style="width: 120pt">
                                {{ $message['financial']['current_employment_title'] ?? '' }}
                            </div>
                        </td>
                    </tr>
                </table>
            </td>

            <td>
                <table cellpadding="0" cellspacing="0">
                    <tr>
                        <td>
                            <span class="label">Current<br />Employer</span>
                        </td>
                        <td>
                            <div class="field-answer" style="width: 107.5pt">
                                {{ $message['financial']['current_employer'] ?? '' }}
                            </div>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>

    @if (! empty($message['financial']['current_employer_address_line1']))
    <hr />

    <table cellpadding="0" cellspacing="0">
        <tr>
            <td>
                <table cellpadding="0" cellspacing="0">
                    <tr>
                        <td>
                            <span class="label">EMPLOYER ADDRESS</span>
                        </td>
                        <td>
                            <div class="field-answer" style="width: 454pt">
                                {{ $message['financial']['current_employer_address_line1'] }}
                                {{ $message['financial']['current_employer_address_line2'] ?? '' }}
                            </div>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
    @endif

    <hr />

    <table cellpadding="0" cellspacing="0">
        <tr>
            <td>
                <table cellpadding="0" cellspacing="0">
                    <tr>
                        <td>
                            <span class="label">STATE</span>
                        </td>
                        <td>
                            <div class="field-answer" style="width: 143pt">
                                {{ $message['financial']['state']['name'] ?? '' }}
                            </div>
                        </td>
                    </tr>
                </table>
            </td>

            <td>
                <table cellpadding="0" cellspacing="0">
                    <tr>
                        <td>
                            <span class="label">CITY</span>
                        </td>
                        <td>
                            <div class="field-answer" style="width: 143pt">
                                {{ $message['financial']['city'] ?? '' }}
                            </div>
                        </td>
                    </tr>
                </table>
            </td>

            <td>
                <table cellpadding="0" cellspacing="0">
                    <tr>
                        <td>
                            <span class="label">ZIP CODE</span>
                        </td>
                        <td>
                            <div class="field-answer" style="width: 141pt">
                                {{ $message['financial']['zip_code'] ?? '' }}
                            </div>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>

    <hr />

    <table style="margin-bottom: 10pt" cellpadding="0" cellspacing="0">
        <tr>
            <td>
                <table cellpadding="0" cellspacing="0">
                    <tr>
                        <td>
                            <span class="label">EMPLOYER PHONE NUMBER</span>
                        </td>
                        <td>
                            <div class="field-answer" style="width: 160pt">
                                {{ $message['financial']['current_employer_phone'] ?? '' }}
                            </div>
                        </td>
                    </tr>
                </table>
            </td>

            <td>
                <table cellpadding="0" cellspacing="0">
                    <tr>
                        <td>
                            <span class="label">TIME AT<br />CURRENT EMPLOYMENT</span>
                        </td>
                        <td>
                            <div class="field-answer" style="width: 150pt">
                                {{ $message['financial']['years_at_current_employment'] ?? '' }}
                            </div>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>

    <hr />

    @if (($message['financial']['years_at_current_employment'] ?? 0) < 2)
    <table cellpadding="0" cellspacing="0">
        <tr>
            <td>
                <table cellpadding="0" cellspacing="0">
                    <tr>
                        <td>
                            <span class="label">PREVIOUS EMPLOYER</span>
                        </td>
                        <td>
                            <div class="field-answer" style="width: 451pt">
                                {{ $message['financial']['previous_employer'] ?? '' }}
                            </div>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>

    <hr />

    <table cellpadding="0" cellspacing="0">
        <tr>
            <td>
                <table cellpadding="0" cellspacing="0">
                    <tr>
                        <td>
                            <span class="label">Previous<br />Employment Title</span>
                        </td>
                        <td>
                            <div class="field-answer" style="width: 451pt">
                                {{ $message['financial']['previous_employment_title'] ?? '' }}
                            </div>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>

    <hr />

    <table cellpadding="0" cellspacing="0">
        <tr>
            <td>
                <table cellpadding="0" cellspacing="0">
                    <tr>
                        <td>
                            <span class="label">STATE</span>
                        </td>
                        <td>
                            <div class="field-answer" style="width: 143pt">
                                {{ $message['financial']['previous_state']['name'] ?? '' }}
                            </div>
                        </td>
                    </tr>
                </table>
            </td>

            <td>
                <table cellpadding="0" cellspacing="0">
                    <tr>
                        <td>
                            <span class="label">CITY</span>
                        </td>
                        <td>
                            <div class="field-answer" style="width: 143pt">
                                {{ $message['financial']['previous_city'] ?? '' }}
                            </div>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>

    <hr />

    <table cellpadding="0" cellspacing="0">
        <tr>
            @if (! empty($message['financial']['previous_employer_phone']))
            <td>
                <table cellpadding="0" cellspacing="0">
                    <tr>
                        <td>
                            <span class="label">PREVIOUS EMPLOYER<br />PHONE NUMBER</span>
                        </td>
                        <td>
                            <div class="field-answer" style="width: 165pt">
                                {{ $message['financial']['previous_employer_phone'] }}
                            </div>
                        </td>
                    </tr>
                </table>
            </td>
            @endif

            <td>
                <table cellpadding="0" cellspacing="0">
                    <tr>
                        <td>
                            <span class="label">TIME AT<br />PREVIOUS EMPLOYMENT</span>
                        </td>
                        <td>
                            <div class="field-answer" style="width: 165.5pt">
                                {{ $message['financial']['years_at_previous_employment'] ?? '' }}
                            </div>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>

    <hr />
    @endif

    <table cellpadding="0" cellspacing="0">
        <tr>
            <td>
                <table cellpadding="0" cellspacing="0">
                    <tr>
                        <td>
                            <span class="label">Monthly Gross Income</span>
                        </td>
                        <td>
                            <div class="field-answer" style="width: 185.5pt">
                                {{ $message['financial']['gross_income'] ?? '' }}
                            </div>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>

    @if (! empty($message['financial']['other_income_source']) || ! empty($message['financial']['other_income']))
    <hr />
    <table cellpadding="0" cellspacing="0">
        <tr>
            @if (! empty($message['financial']['other_income_source']))
            <td>
                <table cellpadding="0" cellspacing="0">
                    <tr>
                        <td>
                            <span class="label">Other Income Source</span>
                        </td>
                        <td>
                            <div class="field-answer" style="width: 200pt">
                                {{ $message['financial']['other_income_source'] }}
                            </div>
                        </td>
                    </tr>
                </table>
            </td>
            @endif

            @if (! empty($message['financial']['other_income']))
            <td>
                <table cellpadding="0" cellspacing="0">
                    <tr>
                        <td>
                            <span class="label">Other Income</span>
                        </td>
                        <td>
                            <div class="field-answer" style="width: 161.5pt">
                                {{ $message['financial']['other_income'] }}
                            </div>
                        </td>
                    </tr>
                </table>
            </td>
            @endif
        </tr>
    </table>
    @endif

    @if (! empty($message['credit-references']))
    <h3 style="margin-top: 10pt">PERSONAL REFERENCE 1</h3>

    <table cellpadding="0" cellspacing="0">
        <tr>
            <td>
                <table cellpadding="0" cellspacing="0">
                    <tr>
                        <td>
                            <span class="label">NAME</span>
                        </td>
                        <td>
                            <div class="field-answer" style="width: 245pt">
                                {{ $message['credit-references']['cr_1_first_name'] ?? '' }}
                                {{ $message['credit-references']['cr_1_last_name'] ?? '' }}
                            </div>
                        </td>
                    </tr>
                </table>
            </td>
            <td>
                <table cellpadding="0" cellspacing="0">
                    <tr>
                        <td>
                            <span class="label">PHONE #</span>
                        </td>
                        <td>
                            <div class="field-answer" style="width: 194.5pt">
                                {{ $message['credit-references']['cr_1_phone_number'] ?? '' }}
                            </div>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
    <hr />

    <table cellpadding="0" cellspacing="0">
        <tr>
            <td>
                <table cellpadding="0" cellspacing="0">
                    <tr>
                        <td>
                            <span class="label">ADDRESS</span>
                        </td>
                        <td>
                            <div class="field-answer" style="width: 494pt">
                                {{ $message['credit-references']['cr_1_address'] ?? '' }}
                                {{ $message['credit-references']['cr_1_address'] ?? '' }}
                            </div>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
    <hr />

    <table cellpadding="0" cellspacing="0">
        <tr>
            <td>
                <table cellpadding="0" cellspacing="0">
                    <tr>
                        <td>
                            <span class="label">STATE</span>
                        </td>
                        <td>
                            <div class="field-answer" style="width: 143pt">
                                {{ $message['credit-references']['cr_1_state']['name'] ?? '' }}
                            </div>
                        </td>
                    </tr>
                </table>
            </td>
            <td>
                <table cellpadding="0" cellspacing="0">
                    <tr>
                        <td>
                            <span class="label">CITY</span>
                        </td>
                        <td>
                            <div class="field-answer" style="width: 143pt">
                                {{ $message['credit-references']['cr_1_city'] ?? '' }}
                            </div>
                        </td>
                    </tr>
                </table>
            </td>
            <td>
                <table cellpadding="0" cellspacing="0">
                    <tr>
                        <td>
                            <span class="label">ZIP CODE</span>
                        </td>
                        <td>
                            <div class="field-answer" style="width: 141pt">
                                {{ $message['credit-references']['cr_1_zip_code'] ?? '' }}
                            </div>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
    <hr />

    <table cellpadding="0" cellspacing="0">
        <tr>
            <td>
                <table cellpadding="0" cellspacing="0">
                    <tr>
                        <td>
                            <span class="label">RELATIONSHIP</span>
                        </td>
                        <td>
                            <div class="field-answer" style="width: 158pt">
                                {{ $message['credit-references']['cr_1_relationship'] ?? '' }}
                            </div>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>

    @if (! empty($message['credit-references']['cr_2_first_name']))
    <h3 style="margin-top: 10pt">PERSONAL REFERENCE 2</h3>
    <table cellpadding="0" cellspacing="0">
        <tr>
            <td>
                <table cellpadding="0" cellspacing="0">
                    <tr>
                        <td>
                            <span class="label">NAME</span>
                        </td>
                        <td>
                            <div class="field-answer" style="width: 245pt">
                                {{ $message['credit-references']['cr_2_first_name'] ?? '' }}
                                {{ $message['credit-references']['cr_2_last_name'] ?? '' }}
                            </div>
                        </td>
                    </tr>
                </table>
            </td>
            <td>
                <table cellpadding="0" cellspacing="0">
                    <tr>
                        <td>
                            <span class="label">PHONE #</span>
                        </td>
                        <td>
                            <div class="field-answer" style="width: 194.5pt">
                                {{ $message['credit-references']['cr_2_phone_number'] ?? '' }}
                            </div>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
    <hr />

    <table cellpadding="0" cellspacing="0">
        <tr>
            <td>
                <table cellpadding="0" cellspacing="0">
                    <tr>
                        <td>
                            <span class="label">ADDRESS</span>
                        </td>
                        <td>
                            <div class="field-answer" style="width: 494pt">
                                {{ $message['credit-references']['cr_2_address'] ?? '' }}
                                {{ $message['credit-references']['cr_2_address'] ?? '' }}
                            </div>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
    <hr />

    <table cellpadding="0" cellspacing="0">
        <tr>
            <td>
                <table cellpadding="0" cellspacing="0">
                    <tr>
                        <td>
                            <span class="label">STATE</span>
                        </td>
                        <td>
                            <div class="field-answer" style="width: 143pt">
                                {{ $message['credit-references']['cr_2_state']['name'] ?? '' }}
                            </div>
                        </td>
                    </tr>
                </table>
            </td>
            <td>
                <table cellpadding="0" cellspacing="0">
                    <tr>
                        <td>
                            <span class="label">CITY</span>
                        </td>
                        <td>
                            <div class="field-answer" style="width: 143pt">
                                {{ $message['credit-references']['cr_2_city'] ?? '' }}
                            </div>
                        </td>
                    </tr>
                </table>
            </td>
            <td>
                <table cellpadding="0" cellspacing="0">
                    <tr>
                        <td>
                            <span class="label">ZIP CODE</span>
                        </td>
                        <td>
                            <div class="field-answer" style="width: 141pt">
                                {{ $message['credit-references']['cr_2_zip_code'] ?? '' }}
                            </div>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
    <hr />

    <table style="margin-bottom: 10pt" cellpadding="0" cellspacing="0">
        <tr>
            <td>
                <table cellpadding="0" cellspacing="0">
                    <tr>
                        <td>
                            <span class="label">RELATIONSHIP</span>
                        </td>
                        <td>
                            <div class="field-answer" style="width: 158pt">
                                {{ $message['credit-references']['cr_2_relationship'] ?? '' }}
                            </div>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
    @endif

    @if (! empty($message['credit-references']['cr_3_first_name']))
    <h3 style="margin-top: 10pt">PERSONAL REFERENCE 3</h3>
    <table cellpadding="0" cellspacing="0">
        <tr>
            <td>
                <table cellpadding="0" cellspacing="0">
                    <tr>
                        <td>
                            <span class="label">NAME</span>
                        </td>
                        <td>
                            <div class="field-answer" style="width: 245pt">
                                {{ $message['credit-references']['cr_3_first_name'] ?? '' }}
                                {{ $message['credit-references']['cr_3_last_name'] ?? '' }}
                            </div>
                        </td>
                    </tr>
                </table>
            </td>
            <td>
                <table cellpadding="0" cellspacing="0">
                    <tr>
                        <td>
                            <span class="label">PHONE #</span>
                        </td>
                        <td>
                            <div class="field-answer" style="width: 194.5pt">
                                {{ $message['credit-references']['cr_3_phone_number'] ?? '' }}
                            </div>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
    <hr />

    <table cellpadding="0" cellspacing="0">
        <tr>
            <td>
                <table cellpadding="0" cellspacing="0">
                    <tr>
                        <td>
                            <span class="label">ADDRESS</span>
                        </td>
                        <td>
                            <div class="field-answer" style="width: 494pt">
                                {{ $message['credit-references']['cr_3_address'] ?? '' }}
                                {{ $message['credit-references']['cr_3_address'] ?? '' }}
                            </div>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
    <hr />

    <table cellpadding="0" cellspacing="0">
        <tr>
            <td>
                <table cellpadding="0" cellspacing="0">
                    <tr>
                        <td>
                            <span class="label">STATE</span>
                        </td>
                        <td>
                            <div class="field-answer" style="width: 143pt">
                                {{ $message['credit-references']['cr_3_state']['name'] ?? '' }}
                            </div>
                        </td>
                    </tr>
                </table>
            </td>
            <td>
                <table cellpadding="0" cellspacing="0">
                    <tr>
                        <td>
                            <span class="label">CITY</span>
                        </td>
                        <td>
                            <div class="field-answer" style="width: 143pt">
                                {{ $message['credit-references']['cr_3_city'] ?? '' }}
                            </div>
                        </td>
                    </tr>
                </table>
            </td>
            <td>
                <table cellpadding="0" cellspacing="0">
                    <tr>
                        <td>
                            <span class="label">ZIP CODE</span>
                        </td>
                        <td>
                            <div class="field-answer" style="width: 141pt">
                                {{ $message['credit-references']['cr_3_zip_code'] ?? '' }}
                            </div>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
    <hr />

    <table style="margin-bottom: 10pt" cellpadding="0" cellspacing="0">
        <tr>
            <td>
                <table cellpadding="0" cellspacing="0">
                    <tr>
                        <td>
                            <span class="label">RELATIONSHIP</span>
                        </td>
                        <td>
                            <div class="field-answer" style="width: 158pt">
                                {{ $message['credit-references']['cr_3_relationship'] ?? '' }}
                            </div>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
    @endif
    @endif

    @if (! empty($message['signature']) && ! empty($message['signature']['url']))
    <table cellpadding="0" cellspacing="0" style="margin-top: 4pt">
        <tr>
            <td>
                <span class="label">Customer signature</span>
            </td>
            <td>
                <div class="company-logo">
                    <div class="image-wrapper" style="background-image: url({{ $message['signature']['url'] }});">
                    </div>
                </div>
            </td>
        </tr>
    </table>
    <hr />
    @endif

    <table cellpadding="0" cellspacing="0" style="margin-top: 4pt">
        <tr>
            <td>
                <table cellpadding="0" cellspacing="0">
                    <tr>
                        <td>
                            <span class="label underline">DATE</span>
                        </td>
                        <td>
                            <div class="field-answer" style="width: 245pt">
                                {{ date('m-d-Y') }}
                            </div>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
    <hr />
</body>

</html>
