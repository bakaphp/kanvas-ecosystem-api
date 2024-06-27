<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class CountriesSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * @return void
     */
    public function run()
    {
        DB::table('countries')->insert(
            [
                [
                    'id' => 1,
                    'name' => 'Andorra',
                    'code' => 'ad',
                    'created_at' => date('Y-m-d H:i:s'),
                ],
                [
                    'id' => 2,
                    'name' => 'United Arab Emirates',
                    'code' => 'ae', 'created_at' => date('Y-m-d H:i:s')],
                [
                    'id' => 3,
                    'name' => 'Afghanistan',
                    'code' => 'af', 'created_at' => date('Y-m-d H:i:s')],
                [
                    'id' => 4,
                    'name' => 'Antigua and Barbuda',
                    'code' => 'ag', 'created_at' => date('Y-m-d H:i:s')],
                [
                    'id' => 5,
                    'name' => 'Anguilla',
                    'code' => 'ai', 'created_at' => date('Y-m-d H:i:s')],
                [
                    'id' => 6,
                    'name' => 'Albania',
                    'code' => 'al', 'created_at' => date('Y-m-d H:i:s')],
                [
                    'id' => 7,
                    'name' => 'Armenia',
                    'code' => 'am', 'created_at' => date('Y-m-d H:i:s')],
                [
                    'id' => 8,
                    'name' => 'Netherlands Antilles',
                    'code' => 'an', 'created_at' => date('Y-m-d H:i:s')],
                [
                    'id' => 9,
                    'name' => 'Angola',
                    'code' => 'ao', 'created_at' => date('Y-m-d H:i:s')],
                [
                    'id' => 10,
                    'name' => 'Argentina',
                    'code' => 'ar', 'created_at' => date('Y-m-d H:i:s')],
                [
                    'id' => 11,
                    'name' => 'Austria',
                    'code' => 'at', 'created_at' => date('Y-m-d H:i:s')],
                [
                    'id' => 12,
                    'name' => 'Australia',
                    'code' => 'au', 'created_at' => date('Y-m-d H:i:s')],
                [
                    'id' => 13,
                    'name' => 'Aruba',
                    'code' => 'aw', 'created_at' => date('Y-m-d H:i:s')],
                [
                    'id' => 14,
                    'name' => 'Azerbaijan',
                    'code' => 'az', 'created_at' => date('Y-m-d H:i:s')],
                [
                    'id' => 15,
                    'name' => 'Bosnia and Herzegovina',
                    'code' => 'ba', 'created_at' => date('Y-m-d H:i:s')],
                [
                    'id' => 16,
                    'name' => 'Barbados',
                    'code' => 'bb', 'created_at' => date('Y-m-d H:i:s')],
                [
                    'id' => 17,
                    'name' => 'Bangladesh',
                    'code' => 'bd', 'created_at' => date('Y-m-d H:i:s')],
                [
                    'id' => 18,
                    'name' => 'Belgium',
                    'code' => 'be', 'created_at' => date('Y-m-d H:i:s')],
                [
                    'id' => 19,
                    'name' => 'Burkina Faso',
                    'code' => 'bf', 'created_at' => date('Y-m-d H:i:s')],
                [
                    'id' => 20,
                    'name' => 'Bulgaria',
                    'code' => 'bg', 'created_at' => date('Y-m-d H:i:s')],
                [
                    'id' => 21,
                    'name' => 'Bahrain',
                    'code' => 'bh', 'created_at' => date('Y-m-d H:i:s')],
                [
                    'id' => 22,
                    'name' => 'Burundi',
                    'code' => 'bi', 'created_at' => date('Y-m-d H:i:s')],
                [
                    'id' => 23,
                    'name' => 'Benin',
                    'code' => 'bj', 'created_at' => date('Y-m-d H:i:s')],
                [
                    'id' => 24,
                    'name' => 'Bermuda',
                    'code' => 'bm', 'created_at' => date('Y-m-d H:i:s')],
                [
                    'id' => 25,
                    'name' => 'Brunei Darussalam',
                    'code' => 'bn', 'created_at' => date('Y-m-d H:i:s')],
                [
                    'id' => 26,
                    'name' => 'Bolivia',
                    'code' => 'bo', 'created_at' => date('Y-m-d H:i:s')],
                [
                    'id' => 27,
                    'name' => 'Brazil',
                    'code' => 'br', 'created_at' => date('Y-m-d H:i:s')],
                [
                    'id' => 28,
                    'name' => 'Bahamas',
                    'code' => 'bs', 'created_at' => date('Y-m-d H:i:s')],
                [
                    'id' => 29,
                    'name' => 'Bhutan',
                    'code' => 'bt', 'created_at' => date('Y-m-d H:i:s')],
                [
                    'id' => 30,
                    'name' => 'Botswana',
                    'code' => 'bw', 'created_at' => date('Y-m-d H:i:s')],
                [
                    'id' => 31,
                    'name' => 'Belarus',
                    'code' => 'by', 'created_at' => date('Y-m-d H:i:s')],
                [
                    'id' => 32,
                    'name' => 'Belize',
                    'code' => 'bz', 'created_at' => date('Y-m-d H:i:s')],
                [
                    'id' => 33,
                    'name' => 'Canada',
                    'code' => 'ca', 'created_at' => date('Y-m-d H:i:s')],
                [
                    'id' => 34,
                    'name' => 'Cocos (Keeling) Islands',
                    'code' => 'cc', 'created_at' => date('Y-m-d H:i:s')],
                [
                    'id' => 35,
                    'name' => 'Democratic Republic of the Congo',
                    'code' => 'cd', 'created_at' => date('Y-m-d H:i:s')],
                [
                    'id' => 36,
                    'name' => 'Central African Republic',
                    'code' => 'cf', 'created_at' => date('Y-m-d H:i:s')],
                [
                    'id' => 37,
                    'name' => 'Congo',
                    'code' => 'cg', 'created_at' => date('Y-m-d H:i:s')],
                [
                    'id' => 38,
                    'name' => 'Switzerland',
                    'code' => 'ch', 'created_at' => date('Y-m-d H:i:s')],
                [
                    'id' => 39,
                    'name' => "Cote D'Ivoire (Ivory Coast)",
                    'code' => 'ci', 'created_at' => date('Y-m-d H:i:s')],
                [
                    'id' => 40,
                    'name' => 'Cook Islands',
                    'code' => 'ck', 'created_at' => date('Y-m-d H:i:s')],
                [
                    'id' => 41,
                    'name' => 'Chile',
                    'code' => 'cl', 'created_at' => date('Y-m-d H:i:s')],
                [
                    'id' => 42,
                    'name' => 'Cameroon',
                    'code' => 'cm', 'created_at' => date('Y-m-d H:i:s')],
                [
                    'id' => 43,
                    'name' => 'China',
                    'code' => 'cn', 'created_at' => date('Y-m-d H:i:s')],
                [
                    'id' => 44,
                    'name' => 'Colombia',
                    'code' => 'co', 'created_at' => date('Y-m-d H:i:s')],
                [
                    'id' => 45,
                    'name' => 'Costa Rica',
                    'code' => 'cr', 'created_at' => date('Y-m-d H:i:s')],
                [
                    'id' => 46,
                    'name' => 'Cuba',
                    'code' => 'cu', 'created_at' => date('Y-m-d H:i:s')],
                [
                    'id' => 47,
                    'name' => 'Cape Verde',
                    'code' => 'cv', 'created_at' => date('Y-m-d H:i:s')],
                [
                    'id' => 48,
                    'name' => 'Christmas Island',
                    'code' => 'cx', 'created_at' => date('Y-m-d H:i:s')],
                [
                    'id' => 49,
                    'name' => 'Cyprus',
                    'code' => 'cy', 'created_at' => date('Y-m-d H:i:s')],
                [
                    'id' => 50,
                    'name' => 'Czech Republic',
                    'code' => 'cz', 'created_at' => date('Y-m-d H:i:s')],
                [
                    'id' => 51,
                    'name' => 'Germany',
                    'code' => 'de', 'created_at' => date('Y-m-d H:i:s')],
                [
                    'id' => 52,
                    'name' => 'Djibouti',
                    'code' => 'dj', 'created_at' => date('Y-m-d H:i:s')],
                [
                    'id' => 53,
                    'name' => 'Denmark',
                    'code' => 'dk', 'created_at' => date('Y-m-d H:i:s')],
                [
                    'id' => 54,
                    'name' => 'Dominica',
                    'code' => 'dm', 'created_at' => date('Y-m-d H:i:s')],
                [
                    'id' => 55,
                    'name' => 'Dominican Republic',
                    'code' => 'do', 'created_at' => date('Y-m-d H:i:s')],
                [
                    'id' => 56,
                    'name' => 'Algeria',
                    'code' => 'dz', 'created_at' => date('Y-m-d H:i:s')],
                [
                    'id' => 57,
                    'name' => 'Ecuador',
                    'code' => 'ec', 'created_at' => date('Y-m-d H:i:s')],
                [
                    'id' => 58,
                    'name' => 'Estonia',
                    'code' => 'ee', 'created_at' => date('Y-m-d H:i:s')],
                [
                    'id' => 59,
                    'name' => 'Egypt',
                    'code' => 'eg', 'created_at' => date('Y-m-d H:i:s')],
                [
                    'id' => 60,
                    'name' => 'Western Sahara',
                    'code' => 'eh', 'created_at' => date('Y-m-d H:i:s')],
                [
                    'id' => 61,
                    'name' => 'Eritrea',
                    'code' => 'er', 'created_at' => date('Y-m-d H:i:s')],
                [
                    'id' => 62,
                    'name' => 'Spain',
                    'code' => 'es', 'created_at' => date('Y-m-d H:i:s')],
                [
                    'id' => 63,
                    'name' => 'Ethiopia',
                    'code' => 'et', 'created_at' => date('Y-m-d H:i:s')],
                [
                    'id' => 64,
                    'name' => 'Finland',
                    'code' => 'fi', 'created_at' => date('Y-m-d H:i:s')],
                [
                    'id' => 65,
                    'name' => 'Fiji',
                    'code' => 'fj', 'created_at' => date('Y-m-d H:i:s')],
                [
                    'id' => 66,
                    'name' => 'Falkland Islands (Malvinas)',
                    'code' => 'fk', 'created_at' => date('Y-m-d H:i:s')],
                [
                    'id' => 67,
                    'name' => 'Federated States of Micronesia',
                    'code' => 'fm', 'created_at' => date('Y-m-d H:i:s')],
                [
                    'id' => 68,
                    'name' => 'Faroe Islands',
                    'code' => 'fo', 'created_at' => date('Y-m-d H:i:s')],
                [
                    'id' => 69,
                    'name' => 'France',
                    'code' => 'fr', 'created_at' => date('Y-m-d H:i:s')],
                [
                    'id' => 70,
                    'name' => 'Gabon',
                    'code' => 'ga', 'created_at' => date('Y-m-d H:i:s')],
                [
                    'id' => 71,
                    'name' => 'Great Britain (UK)',
                    'code' => 'gb', 'created_at' => date('Y-m-d H:i:s')],
                [
                    'id' => 72,
                    'name' => 'Grenada',
                    'code' => 'gd', 'created_at' => date('Y-m-d H:i:s')],
                [
                    'id' => 73,
                    'name' => 'Georgia',
                    'code' => 'ge', 'created_at' => date('Y-m-d H:i:s')],
                [
                    'id' => 74,
                    'name' => 'French Guiana',
                    'code' => 'gf', 'created_at' => date('Y-m-d H:i:s')],
                [
                    'id' => 75,
                    'name' => 'NULL',
                    'code' => 'gg', 'created_at' => date('Y-m-d H:i:s')],
                [
                    'id' => 76,
                    'name' => 'Ghana',
                    'code' => 'gh', 'created_at' => date('Y-m-d H:i:s')],
                [
                    'id' => 77,
                    'name' => 'Gibraltar',
                    'code' => 'gi', 'created_at' => date('Y-m-d H:i:s')],
                [
                    'id' => 78,
                    'name' => 'Greenland',
                    'code' => 'gl', 'created_at' => date('Y-m-d H:i:s')],
                [
                    'id' => 79,
                    'name' => 'Gambia',
                    'code' => 'gm', 'created_at' => date('Y-m-d H:i:s')],
                [
                    'id' => 80,
                    'name' => 'Guinea',
                    'code' => 'gn', 'created_at' => date('Y-m-d H:i:s')],
                [
                    'id' => 81,
                    'name' => 'Guadeloupe',
                    'code' => 'gp', 'created_at' => date('Y-m-d H:i:s')],
                [
                    'id' => 82,
                    'name' => 'Equatorial Guinea',
                    'code' => 'gq', 'created_at' => date('Y-m-d H:i:s')],
                [
                    'id' => 83,
                    'name' => 'Greece',
                    'code' => 'gr', 'created_at' => date('Y-m-d H:i:s')],
                [
                    'id' => 84,
                    'name' => 'S. Georgia and S. Sandwich Islands',
                    'code' => 'gs', 'created_at' => date('Y-m-d H:i:s')],
                [
                    'id' => 85,
                    'name' => 'Guatemala',
                    'code' => 'gt', 'created_at' => date('Y-m-d H:i:s')],
                [
                    'id' => 86,
                    'name' => 'Guinea-Bissau',
                    'code' => 'gw', 'created_at' => date('Y-m-d H:i:s')],
                [
                    'id' => 87,
                    'name' => 'Guyana',
                    'code' => 'gy', 'created_at' => date('Y-m-d H:i:s')],
                [
                    'id' => 88,
                    'name' => 'Hong Kong',
                    'code' => 'hk', 'created_at' => date('Y-m-d H:i:s')],
                [
                    'id' => 89,
                    'name' => 'Honduras',
                    'code' => 'hn', 'created_at' => date('Y-m-d H:i:s')],
                [
                    'id' => 90,
                    'name' => 'Croatia (Hrvatska)',
                    'code' => 'hr', 'created_at' => date('Y-m-d H:i:s')],
                [
                    'id' => 91,
                    'name' => 'Haiti',
                    'code' => 'ht', 'created_at' => date('Y-m-d H:i:s')],
                [
                    'id' => 92,
                    'name' => 'Hungary',
                    'code' => 'hu', 'created_at' => date('Y-m-d H:i:s')],
                [
                    'id' => 93,
                    'name' => 'Indonesia',
                    'code' => 'id', 'created_at' => date('Y-m-d H:i:s')],
                [
                    'id' => 94,
                    'name' => 'Ireland',
                    'code' => 'ie', 'created_at' => date('Y-m-d H:i:s')],
                [
                    'id' => 95,
                    'name' => 'Israel',
                    'code' => 'il', 'created_at' => date('Y-m-d H:i:s')],
                [
                    'id' => 96,
                    'name' => 'India',
                    'code' => 'in', 'created_at' => date('Y-m-d H:i:s')],
                [
                    'id' => 97,
                    'name' => 'Iraq',
                    'code' => 'iq', 'created_at' => date('Y-m-d H:i:s')],
                [
                    'id' => 98,
                    'name' => 'Iran',
                    'code' => 'ir', 'created_at' => date('Y-m-d H:i:s')],
                [
                    'id' => 99,
                    'name' => 'Iceland',
                    'code' => 'is', 'created_at' => date('Y-m-d H:i:s')],
                [
                    'id' => 100,
                    'name' => 'Italy',
                    'code' => 'it', 'created_at' => date('Y-m-d H:i:s')],
                [
                    'id' => 101,
                    'name' => 'Jamaica',
                    'code' => 'jm', 'created_at' => date('Y-m-d H:i:s')],
                [
                    'id' => 102,
                    'name' => 'Jordan',
                    'code' => 'jo', 'created_at' => date('Y-m-d H:i:s')],
                [
                    'id' => 103,
                    'name' => 'Japan',
                    'code' => 'jp', 'created_at' => date('Y-m-d H:i:s')],
                [
                    'id' => 104,
                    'name' => 'Kenya',
                    'code' => 'ke', 'created_at' => date('Y-m-d H:i:s')],
                [
                    'id' => 105,
                    'name' => 'Kyrgyzstan',
                    'code' => 'kg', 'created_at' => date('Y-m-d H:i:s')],
                [
                    'id' => 106,
                    'name' => 'Cambodia',
                    'code' => 'kh', 'created_at' => date('Y-m-d H:i:s')],
                [
                    'id' => 107,
                    'name' => 'Kiribati',
                    'code' => 'ki', 'created_at' => date('Y-m-d H:i:s')],
                [
                    'id' => 108,
                    'name' => 'Comoros',
                    'code' => 'km', 'created_at' => date('Y-m-d H:i:s')],
                [
                    'id' => 109,
                    'name' => 'Saint Kitts and Nevis',
                    'code' => 'kn', 'created_at' => date('Y-m-d H:i:s')],
                [
                    'id' => 110,
                    'name' => 'Korea (North)',
                    'code' => 'kp', 'created_at' => date('Y-m-d H:i:s')],
                [
                    'id' => 111,
                    'name' => 'Korea (South)',
                    'code' => 'kr', 'created_at' => date('Y-m-d H:i:s')],
                [
                    'id' => 112,
                    'name' => 'Kuwait',
                    'code' => 'kw', 'created_at' => date('Y-m-d H:i:s')],
                [
                    'id' => 113,
                    'name' => 'Cayman Islands',
                    'code' => 'ky', 'created_at' => date('Y-m-d H:i:s')],
                [
                    'id' => 114,
                    'name' => 'Kazakhstan',
                    'code' => 'kz', 'created_at' => date('Y-m-d H:i:s')],
                [
                    'id' => 115,
                    'name' => 'Laos',
                    'code' => 'la', 'created_at' => date('Y-m-d H:i:s')],
                [
                    'id' => 116,
                    'name' => 'Lebanon',
                    'code' => 'lb', 'created_at' => date('Y-m-d H:i:s')],
                [
                    'id' => 117,
                    'name' => 'Saint Lucia',
                    'code' => 'lc', 'created_at' => date('Y-m-d H:i:s')],
                [
                    'id' => 118,
                    'name' => 'Liechtenstein',
                    'code' => 'li', 'created_at' => date('Y-m-d H:i:s')],
                [
                    'id' => 119,
                    'name' => 'Sri Lanka',
                    'code' => 'lk', 'created_at' => date('Y-m-d H:i:s')],
                [
                    'id' => 120,
                    'name' => 'Liberia',
                    'code' => 'lr', 'created_at' => date('Y-m-d H:i:s')],
                [
                    'id' => 121,
                    'name' => 'Lesotho',
                    'code' => 'ls', 'created_at' => date('Y-m-d H:i:s')],
                [
                    'id' => 122,
                    'name' => 'Lithuania',
                    'code' => 'lt', 'created_at' => date('Y-m-d H:i:s')],
                [
                    'id' => 123,
                    'name' => 'Luxembourg',
                    'code' => 'lu', 'created_at' => date('Y-m-d H:i:s')],
                [
                    'id' => 124,
                    'name' => 'Latvia',
                    'code' => 'lv', 'created_at' => date('Y-m-d H:i:s')],
                [
                    'id' => 125,
                    'name' => 'Libya',
                    'code' => 'ly', 'created_at' => date('Y-m-d H:i:s')],
                [
                    'id' => 126,
                    'name' => 'Morocco',
                    'code' => 'ma', 'created_at' => date('Y-m-d H:i:s')],
                [
                    'id' => 127,
                    'name' => 'Monaco',
                    'code' => 'mc', 'created_at' => date('Y-m-d H:i:s')],
                [
                    'id' => 128,
                    'name' => 'Moldova',
                    'code' => 'md', 'created_at' => date('Y-m-d H:i:s')],
                [
                    'id' => 129,
                    'name' => 'Madagascar',
                    'code' => 'mg', 'created_at' => date('Y-m-d H:i:s')],
                [
                    'id' => 130,
                    'name' => 'Marshall Islands',
                    'code' => 'mh', 'created_at' => date('Y-m-d H:i:s')],
                [
                    'id' => 131,
                    'name' => 'Macedonia',
                    'code' => 'mk', 'created_at' => date('Y-m-d H:i:s')],
                [
                    'id' => 132,
                    'name' => 'Mali',
                    'code' => 'ml', 'created_at' => date('Y-m-d H:i:s')],
                [
                    'id' => 133,
                    'name' => 'Myanmar',
                    'code' => 'mm', 'created_at' => date('Y-m-d H:i:s')],
                [
                    'id' => 134,
                    'name' => 'Mongolia',
                    'code' => 'mn', 'created_at' => date('Y-m-d H:i:s')],
                [
                    'id' => 135,
                    'name' => 'Macao',
                    'code' => 'mo', 'created_at' => date('Y-m-d H:i:s')],
                [
                    'id' => 136,
                    'name' => 'Northern Mariana Islands',
                    'code' => 'mp', 'created_at' => date('Y-m-d H:i:s')],
                [
                    'id' => 137,
                    'name' => 'Martinique',
                    'code' => 'mq', 'created_at' => date('Y-m-d H:i:s')],
                [
                    'id' => 138,
                    'name' => 'Mauritania',
                    'code' => 'mr', 'created_at' => date('Y-m-d H:i:s')],
                [
                    'id' => 139,
                    'name' => 'Montserrat',
                    'code' => 'ms', 'created_at' => date('Y-m-d H:i:s')],
                [
                    'id' => 140,
                    'name' => 'Malta',
                    'code' => 'mt', 'created_at' => date('Y-m-d H:i:s')],
                [
                    'id' => 141,
                    'name' => 'Mauritius',
                    'code' => 'mu', 'created_at' => date('Y-m-d H:i:s')],
                [
                    'id' => 142,
                    'name' => 'Maldives',
                    'code' => 'mv', 'created_at' => date('Y-m-d H:i:s')],
                [
                    'id' => 143,
                    'name' => 'Malawi',
                    'code' => 'mw', 'created_at' => date('Y-m-d H:i:s')],
                [
                    'id' => 144,
                    'name' => 'Mexico',
                    'code' => 'mx', 'created_at' => date('Y-m-d H:i:s')],
                [
                    'id' => 145,
                    'name' => 'Malaysia',
                    'code' => 'my', 'created_at' => date('Y-m-d H:i:s')],
                [
                    'id' => 146,
                    'name' => 'Mozambique',
                    'code' => 'mz', 'created_at' => date('Y-m-d H:i:s')],
                [
                    'id' => 147,
                    'name' => 'Namibia',
                    'code' => 'na', 'created_at' => date('Y-m-d H:i:s')],
                [
                    'id' => 148,
                    'name' => 'New Caledonia',
                    'code' => 'nc', 'created_at' => date('Y-m-d H:i:s')],
                [
                    'id' => 149,
                    'name' => 'Niger',
                    'code' => 'ne', 'created_at' => date('Y-m-d H:i:s')],
                [
                    'id' => 150,
                    'name' => 'Norfolk Island',
                    'code' => 'nf', 'created_at' => date('Y-m-d H:i:s')],
                [
                    'id' => 151,
                    'name' => 'Nigeria',
                    'code' => 'ng', 'created_at' => date('Y-m-d H:i:s')],
                [
                    'id' => 152,
                    'name' => 'Nicaragua',
                    'code' => 'ni', 'created_at' => date('Y-m-d H:i:s')],
                [
                    'id' => 153,
                    'name' => 'Netherlands',
                    'code' => 'nl', 'created_at' => date('Y-m-d H:i:s')],
                [
                    'id' => 154,
                    'name' => 'Norway',
                    'code' => 'no', 'created_at' => date('Y-m-d H:i:s')],
                [
                    'id' => 155,
                    'name' => 'Nepal',
                    'code' => 'np', 'created_at' => date('Y-m-d H:i:s')],
                [
                    'id' => 156,
                    'name' => 'Nauru',
                    'code' => 'nr', 'created_at' => date('Y-m-d H:i:s')],
                [
                    'id' => 157,
                    'name' => 'Niue',
                    'code' => 'nu', 'created_at' => date('Y-m-d H:i:s')],
                [
                    'id' => 158,
                    'name' => 'New Zealand (Aotearoa)',
                    'code' => 'nz', 'created_at' => date('Y-m-d H:i:s')],
                [
                    'id' => 159,
                    'name' => 'Oman',
                    'code' => 'om', 'created_at' => date('Y-m-d H:i:s')],
                [
                    'id' => 160,
                    'name' => 'Panama',
                    'code' => 'pa', 'created_at' => date('Y-m-d H:i:s')],
                [
                    'id' => 161,
                    'name' => 'Peru',
                    'code' => 'pe', 'created_at' => date('Y-m-d H:i:s')],
                [
                    'id' => 162,
                    'name' => 'French Polynesia',
                    'code' => 'pf', 'created_at' => date('Y-m-d H:i:s')],
                [
                    'id' => 163,
                    'name' => 'Papua New Guinea',
                    'code' => 'pg', 'created_at' => date('Y-m-d H:i:s')],
                [
                    'id' => 164,
                    'name' => 'Philippines',
                    'code' => 'ph', 'created_at' => date('Y-m-d H:i:s')],
                [
                    'id' => 165,
                    'name' => 'Pakistan',
                    'code' => 'pk', 'created_at' => date('Y-m-d H:i:s')],
                [
                    'id' => 166,
                    'name' => 'Poland',
                    'code' => 'pl', 'created_at' => date('Y-m-d H:i:s')],
                [
                    'id' => 167,
                    'name' => 'Saint Pierre and Miquelon',
                    'code' => 'pm', 'created_at' => date('Y-m-d H:i:s')],
                [
                    'id' => 168,
                    'name' => 'Pitcairn',
                    'code' => 'pn', 'created_at' => date('Y-m-d H:i:s')],
                [
                    'id' => 169,
                    'name' => 'Palestinian Territory',
                    'code' => 'ps', 'created_at' => date('Y-m-d H:i:s')],
                [
                    'id' => 170,
                    'name' => 'Portugal',
                    'code' => 'pt', 'created_at' => date('Y-m-d H:i:s')],
                [
                    'id' => 171,
                    'name' => 'Palau',
                    'code' => 'pw', 'created_at' => date('Y-m-d H:i:s')],
                [
                    'id' => 172,
                    'name' => 'Paraguay',
                    'code' => 'py', 'created_at' => date('Y-m-d H:i:s')],
                [
                    'id' => 173,
                    'name' => 'Qatar',
                    'code' => 'qa', 'created_at' => date('Y-m-d H:i:s')],
                [
                    'id' => 174,
                    'name' => 'Reunion',
                    'code' => 're', 'created_at' => date('Y-m-d H:i:s')],
                [
                    'id' => 175,
                    'name' => 'Romania',
                    'code' => 'ro', 'created_at' => date('Y-m-d H:i:s')],
                [
                    'id' => 176,
                    'name' => 'Russian Federation',
                    'code' => 'ru', 'created_at' => date('Y-m-d H:i:s')],
                [
                    'id' => 177,
                    'name' => 'Rwanda',
                    'code' => 'rw', 'created_at' => date('Y-m-d H:i:s')],
                [
                    'id' => 178,
                    'name' => 'Saudi Arabia',
                    'code' => 'sa', 'created_at' => date('Y-m-d H:i:s')],
                [
                    'id' => 179,
                    'name' => 'Solomon Islands',
                    'code' => 'sb', 'created_at' => date('Y-m-d H:i:s')],
                [
                    'id' => 180,
                    'name' => 'Seychelles',
                    'code' => 'sc', 'created_at' => date('Y-m-d H:i:s')],
                [
                    'id' => 181,
                    'name' => 'Sudan',
                    'code' => 'sd', 'created_at' => date('Y-m-d H:i:s')],
                [
                    'id' => 182,
                    'name' => 'Sweden',
                    'code' => 'se', 'created_at' => date('Y-m-d H:i:s')],
                [
                    'id' => 183,
                    'name' => 'Singapore',
                    'code' => 'sg', 'created_at' => date('Y-m-d H:i:s')],
                [
                    'id' => 184,
                    'name' => 'Saint Helena',
                    'code' => 'sh', 'created_at' => date('Y-m-d H:i:s')],
                [
                    'id' => 185,
                    'name' => 'Slovenia',
                    'code' => 'si', 'created_at' => date('Y-m-d H:i:s')],
                [
                    'id' => 186,
                    'name' => 'Svalbard and Jan Mayen',
                    'code' => 'sj', 'created_at' => date('Y-m-d H:i:s')],
                [
                    'id' => 187,
                    'name' => 'Slovakia',
                    'code' => 'sk', 'created_at' => date('Y-m-d H:i:s')],
                [
                    'id' => 188,
                    'name' => 'Sierra Leone',
                    'code' => 'sl', 'created_at' => date('Y-m-d H:i:s')],
                [
                    'id' => 189,
                    'name' => 'San Marino',
                    'code' => 'sm', 'created_at' => date('Y-m-d H:i:s')],
                [
                    'id' => 190,
                    'name' => 'Senegal',
                    'code' => 'sn', 'created_at' => date('Y-m-d H:i:s')],
                [
                    'id' => 191,
                    'name' => 'Somalia',
                    'code' => 'so', 'created_at' => date('Y-m-d H:i:s')],
                [
                    'id' => 192,
                    'name' => 'Suriname',
                    'code' => 'sr', 'created_at' => date('Y-m-d H:i:s')],
                [
                    'id' => 193,
                    'name' => 'Sao Tome and Principe',
                    'code' => 'st', 'created_at' => date('Y-m-d H:i:s')],
                [
                    'id' => 194,
                    'name' => 'El Salvador',
                    'code' => 'sv', 'created_at' => date('Y-m-d H:i:s')],
                [
                    'id' => 195,
                    'name' => 'Syria',
                    'code' => 'sy', 'created_at' => date('Y-m-d H:i:s')],
                [
                    'id' => 196,
                    'name' => 'Swaziland',
                    'code' => 'sz', 'created_at' => date('Y-m-d H:i:s')],
                [
                    'id' => 197,
                    'name' => 'Turks and Caicos Islands',
                    'code' => 'tc', 'created_at' => date('Y-m-d H:i:s')],
                [
                    'id' => 198,
                    'name' => 'Chad',
                    'code' => 'td', 'created_at' => date('Y-m-d H:i:s')],
                [
                    'id' => 199,
                    'name' => 'French Southern Territories',
                    'code' => 'tf', 'created_at' => date('Y-m-d H:i:s')],
                [
                    'id' => 200,
                    'name' => 'Togo',
                    'code' => 'tg', 'created_at' => date('Y-m-d H:i:s')],
                [
                    'id' => 201,
                    'name' => 'Thailand',
                    'code' => 'th', 'created_at' => date('Y-m-d H:i:s')],
                [
                    'id' => 202,
                    'name' => 'Tajikistan',
                    'code' => 'tj', 'created_at' => date('Y-m-d H:i:s')],
                [
                    'id' => 203,
                    'name' => 'Tokelau',
                    'code' => 'tk', 'created_at' => date('Y-m-d H:i:s')],
                [
                    'id' => 204,
                    'name' => 'Turkmenistan',
                    'code' => 'tm', 'created_at' => date('Y-m-d H:i:s')],
                [
                    'id' => 205,
                    'name' => 'Tunisia',
                    'code' => 'tn', 'created_at' => date('Y-m-d H:i:s')],
                [
                    'id' => 206,
                    'name' => 'Tonga',
                    'code' => 'to', 'created_at' => date('Y-m-d H:i:s')],
                [
                    'id' => 207,
                    'name' => 'Turkey',
                    'code' => 'tr', 'created_at' => date('Y-m-d H:i:s')],
                [
                    'id' => 208,
                    'name' => 'Trinidad and Tobago',
                    'code' => 'tt', 'created_at' => date('Y-m-d H:i:s')],
                [
                    'id' => 209,
                    'name' => 'Tuvalu',
                    'code' => 'tv', 'created_at' => date('Y-m-d H:i:s')],
                [
                    'id' => 210,
                    'name' => 'Taiwan',
                    'code' => 'tw', 'created_at' => date('Y-m-d H:i:s')],
                [
                    'id' => 211,
                    'name' => 'Tanzania',
                    'code' => 'tz', 'created_at' => date('Y-m-d H:i:s')],
                [
                    'id' => 212,
                    'name' => 'Ukraine',
                    'code' => 'ua', 'created_at' => date('Y-m-d H:i:s')],
                [
                    'id' => 213,
                    'name' => 'Uganda',
                    'code' => 'ug', 'created_at' => date('Y-m-d H:i:s')],
                [
                    'id' => 214,
                    'name' => 'Uruguay',
                    'code' => 'uy', 'created_at' => date('Y-m-d H:i:s')],
                [
                    'id' => 215,
                    'name' => 'Uzbekistan',
                    'code' => 'uz', 'created_at' => date('Y-m-d H:i:s')],
                [
                    'id' => 216,
                    'name' => 'Saint Vincent and the Grenadines',
                    'code' => 'vc', 'created_at' => date('Y-m-d H:i:s')],
                [
                    'id' => 217,
                    'name' => 'Venezuela',
                    'code' => 've', 'created_at' => date('Y-m-d H:i:s')],
                [
                    'id' => 218,
                    'name' => 'Virgin Islands (British)',
                    'code' => 'vg', 'created_at' => date('Y-m-d H:i:s')],
                [
                    'id' => 219,
                    'name' => 'Virgin Islands (U.S.)',
                    'code' => 'vi', 'created_at' => date('Y-m-d H:i:s')],
                [
                    'id' => 220,
                    'name' => 'Viet Nam',
                    'code' => 'vn', 'created_at' => date('Y-m-d H:i:s')],
                [
                    'id' => 221,
                    'name' => 'Vanuatu',
                    'code' => 'vu', 'created_at' => date('Y-m-d H:i:s')],
                [
                    'id' => 222,
                    'name' => 'Wallis and Futuna',
                    'code' => 'wf', 'created_at' => date('Y-m-d H:i:s')],
                [
                    'id' => 223,
                    'name' => 'Samoa',
                    'code' => 'ws', 'created_at' => date('Y-m-d H:i:s')],
                [
                    'id' => 224,
                    'name' => 'Yemen',
                    'code' => 'ye', 'created_at' => date('Y-m-d H:i:s')],
                [
                    'id' => 225,
                    'name' => 'Mayotte',
                    'code' => 'yt', 'created_at' => date('Y-m-d H:i:s')],
                [
                    'id' => 226,
                    'name' => 'South Africa',
                    'code' => 'za', 'created_at' => date('Y-m-d H:i:s')],
                [
                    'id' => 227,
                    'name' => 'Zambia',
                    'code' => 'zm', 'created_at' => date('Y-m-d H:i:s')],
                [
                    'id' => 228,
                    'name' => 'Zaire (former)',
                    'code' => 'zr', 'created_at' => date('Y-m-d H:i:s')],
                [
                    'id' => 229,
                    'name' => 'Zimbabwe',
                    'code' => 'zw', 'created_at' => date('Y-m-d H:i:s')],
                [
                    'id' => 230,
                    'name' => 'United States',
                    'code' => 'us', 'created_at' => date('Y-m-d H:i:s')]
            ]
        );
    }
}
