<?php
namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Kanvas\Templates\Models\Templates;

class TemplateSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        Templates::create([
            'apps_id' => 1,
            'users_id' => 1,
            'companies_id' => 1,
            'name' => 'user-signup',
            'template' => '{{$name}}',
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        Templates::create([
            'apps_id' => 1,
            'users_id' => 1,
            'companies_id' => 1,
            'name' => 'users-invite',
            'template' => '{{$name}}',
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        $defaultTemplate = '<!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="UTF-8">
            <meta http-equiv="X-UA-Compatible" content="IE=edge">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>Invitation Email</title>
            <link rel="preconnect" href="https://fonts.gstatic.com">
            <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;700&display=swap" rel="stylesheet">
            <style type="text/css" media="screen">
                [style*=\'Inter\'] {
                    font-family: \'Inter\', Arial, sans-serif !important
                }
            </style>
        </head>
        <body style="font-family: Arial, sans-serif, \'Inter\';">
        ​
            <table
                cellpadding="0"
                cellspacing="0"
                width="700"
                style="padding: 35px 25px 35px 30px; margin-top: 30px; border: 1px solid #ECECEC; border-radius: 10px;"
            >
            <tr>
                <td style="padding-bottom: 15px;">
                    <img src="https://kanvas.dev/img/logo.png" alt="Kanvas Logo" width="120px">
                </td>
            </tr>
        ​
                <tr>
                    <td>
                        <table border="0" cellpadding="0" cellspacing="0" class="divider" role="presentation" style="table-layout: fixed; vertical-align: top; border-spacing: 0; border-collapse: collapse; mso-table-lspace: 0pt; mso-table-rspace: 0pt; min-width: 100%; -ms-text-size-adjust: 100%; -webkit-text-size-adjust: 100%;
                        " valign="top" width="100%">
                            <tbody>
                                <tr style="vertical-align: top;" valign="top">
                                    <td class="divider_inner" style="word-break: break-word; vertical-align: top; min-width: 100%; -ms-text-size-adjust: 100%; -webkit-text-size-adjust: 100%; padding-top: 0px;" valign="top">
                                        <table align="center" border="0" cellpadding="0" cellspacing="0" class="divider_content" height="1" role="presentation" style="table-layout: fixed; vertical-align: top; border-spacing: 0; border-collapse: collapse; mso-table-lspace: 0pt; mso-table-rspace: 0pt; border-top: 1px solid #f7f7f7; height: 1px; width: 100%;" valign="top" width="100%">
                                            <tbody>
                                                <tr style="vertical-align: top;" valign="top">
                                                    <td height="1" style="word-break: break-word; vertical-align: top; -ms-text-size-adjust: 100%; -webkit-text-size-adjust: 100%;" valign="top">
                                                        <span></span>
                                                    </td>
                                                </tr>
                                            </tbody>
                                        </table>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </td>
                </tr>
        ​
                <tr>
                    <td style="padding-right: 120px;">
                        <p style="color: #9b9b9b; font-size: 14px; ">
                            Kanvas Default Email Templates {{change it}}
                        </h2>
                    </td>
                </tr>
        ​
                <tr>
                    <td>
                        <table style="margin: 17px 0 0px" cellspacing="0" cellpadding="0">
                            <tr>
                                <td>

                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>
        ​
                <tr>
                    <td style="padding-right: 120px;">
                        <p style="color: #9b9b9b; font-size: 14px; margin: 20px 0;">
                            Thanks,
                            <br>Kanvas
                        </p>
                    </td>
                </tr>
        ​
                <tr>
                    <td>
                        <table border="0" cellpadding="0" cellspacing="0" class="divider" role="presentation" style="table-layout: fixed; vertical-align: top; border-spacing: 0; border-collapse: collapse; mso-table-lspace: 0pt; mso-table-rspace: 0pt; min-width: 100%; -ms-text-size-adjust: 100%; -webkit-text-size-adjust: 100%;
                        " valign="top" width="100%">
                            <tbody>
                                <tr style="vertical-align: top;" valign="top">
                                    <td class="divider_inner" style="word-break: break-word; vertical-align: top; min-width: 100%; -ms-text-size-adjust: 100%; -webkit-text-size-adjust: 100%; padding-top: 0px;" valign="top">
                                        <table align="center" border="0" cellpadding="0" cellspacing="0" class="divider_content" height="1" role="presentation" style="table-layout: fixed; vertical-align: top; border-spacing: 0; border-collapse: collapse; mso-table-lspace: 0pt; mso-table-rspace: 0pt; border-top: 1px solid #f7f7f7; height: 1px; width: 100%;" valign="top" width="100%">
                                            <tbody>
                                                <tr style="vertical-align: top;" valign="top">
                                                    <td height="1" style="word-break: break-word; vertical-align: top; -ms-text-size-adjust: 100%; -webkit-text-size-adjust: 100%;" valign="top">
                                                        <span></span>
                                                    </td>
                                                </tr>
                                            </tbody>
                                        </table>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </td>
                </tr>
        ​
                <tr>
                    <td style="padding-right: 120px;">
                        <p style="margin: 20px 0 0; color: #C9C9C9; font-size: 11px;">
                            If you’re having trouble with the button above, copy and paste the URL below into your web browser.
                        </p>
                    </td>
                </tr>
        ​
            </table>
        ​
            <table cellpadding="0" cellspacing="0" width="700">
                <tr>
                    <td style="padding: 0px 100px;">
                        <p style="margin: 20px auto 10px; color: #C9C9C9; font-size: 11px; text-align: center;">
                            Copyright © {{date(\'Y\')}} mctekk, LLC. All rights reserved.
                        </p>
                    </td>
                </tr>
        ​
                <tr>
                    <td style="text-align: center; padding-top: 5px;">
                        <img src="https://kanvas.dev/img/logo.png" alt="Kanvas Logo" width="90px">
                    </td>
                </tr>
            </table>

        </body>
        </html>';

        Templates::create([
            'apps_id' => 1,
            'users_id' => 1,
            'companies_id' => 1,
            'name' => 'change-password',
            'template' => '{{$name}}',
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        Templates::create([
            'apps_id' => 1,
            'users_id' => 1,
            'companies_id' => 1,
            'name' => 'Default',
            'template' => $defaultTemplate,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }
}
