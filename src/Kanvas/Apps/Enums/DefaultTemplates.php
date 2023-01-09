<?php

declare(strict_types=1);

namespace Kanvas\Apps\Enums;

enum DefaultTemplates : string
{
    case USERS_INVITE = 'Hi {{entity[\'email\']}} you have been invite to the app {{app.name}} to create you account please <a href=\'{{invitationUrl}}\'>click here</a> <br /><br />\r\n \n\n Thanks {{fromUser.firstname}} {{fromUser.lastname}} ( {{fromUser.getDefaultCompany().name}} )';
    case USERS_REGISTRATION = 'Hi {{entity[\'displayname\']}} Welcome to the new app {{app.name}}\r\n<br /><br />>\r\nLet us know if you need any help\r\n\r\nThanks';
    case USERS_FORGOT_PASSWORD = 'Hi {{fromUser.firstname}} {{fromUser.lastname}}, click the following link to reset your password: <a href=\'{{resetPasswordUrl}}\'>Reset Password</a> <br /><br />\r\nThanks';
}
