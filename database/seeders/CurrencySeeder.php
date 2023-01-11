<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class CurrencySeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        DB::unprepared("INSERT INTO `currencies` VALUES (1, 'Albania', 'Leke', 'ALL', 'Lek', '2018-12-05 01:00:00', NULL, 0);
                        INSERT INTO `currencies` VALUES (2, 'America', 'Dollars', 'USD', '$', '2018-12-05 01:00:00', NULL, 0);
                        INSERT INTO `currencies` VALUES (3, 'Afghanistan', 'Afghanis', 'AFN', '؋', '2018-12-05 01:00:00', NULL, 0);
                        INSERT INTO `currencies` VALUES (4, 'Argentina', 'Pesos', 'ARS', '$', '2018-12-05 01:00:00', NULL, 0);
                        INSERT INTO `currencies` VALUES (5, 'Aruba', 'Guilders', 'AWG', 'ƒ', '2018-12-05 01:00:00', NULL, 0);
                        INSERT INTO `currencies` VALUES (6, 'Australia', 'Dollars', 'AUD', '$', '2018-12-05 01:00:00', NULL, 0);
                        INSERT INTO `currencies` VALUES (7, 'Azerbaijan', 'New Manats', 'AZN', 'ман', '2018-12-05 01:00:00', NULL, 0);
                        INSERT INTO `currencies` VALUES (8, 'Bahamas', 'Dollars', 'BSD', '$', '2018-12-05 01:00:00', NULL, 0);
                        INSERT INTO `currencies` VALUES (9, 'Barbados', 'Dollars', 'BBD', '$', '2018-12-05 01:00:00', NULL, 0);
                        INSERT INTO `currencies` VALUES (10, 'Belarus', 'Rubles', 'BYR', 'p.', '2018-12-05 01:00:00', NULL, 0);
                        INSERT INTO `currencies` VALUES (11, 'Belgium', 'Euro', 'EUR', '€', '2018-12-05 01:00:00', NULL, 0);
                        INSERT INTO `currencies` VALUES (12, 'Beliz', 'Dollars', 'BZD', 'BZ$', '2018-12-05 01:00:00', NULL, 0);
                        INSERT INTO `currencies` VALUES (13, 'Bermuda', 'Dollars', 'BMD', '$', '2018-12-05 01:00:00', NULL, 0);
                        INSERT INTO `currencies` VALUES (14, 'Bolivia', 'Bolivianos', 'BOB', '$', '2018-12-05 01:00:00', NULL, 0);
                        INSERT INTO `currencies` VALUES (15, 'Bosnia and Herzegovina', 'Convertible Marka', 'BAM', 'KM', '2018-12-05 01:00:00', NULL, 0);
                        INSERT INTO `currencies` VALUES (16, 'Botswana', 'Pula', 'BWP', 'P', '2018-12-05 01:00:00', NULL, 0);
                        INSERT INTO `currencies` VALUES (17, 'Bulgaria', 'Leva', 'BGN', 'лв', '2018-12-05 01:00:00', NULL, 0);
                        INSERT INTO `currencies` VALUES (18, 'Brazil', 'Reais', 'BRL', 'R$', '2018-12-05 01:00:00', NULL, 0);
                        INSERT INTO `currencies` VALUES (19, 'Britain (United Kingdom)', 'Pounds', 'GBP', '£', '2018-12-05 01:00:00', NULL, 0);
                        INSERT INTO `currencies` VALUES (20, 'Brunei Darussalam', 'Dollars', 'BND', '$', '2018-12-05 01:00:00', NULL, 0);
                        INSERT INTO `currencies` VALUES (21, 'Cambodia', 'Riels', 'KHR', '៛', '2018-12-05 01:00:00', NULL, 0);
                        INSERT INTO `currencies` VALUES (22, 'Canada', 'Dollars', 'CAD', '$', '2018-12-05 01:00:00', NULL, 0);
                        INSERT INTO `currencies` VALUES (23, 'Cayman Islands', 'Dollars', 'KYD', '$', '2018-12-05 01:00:00', NULL, 0);
                        INSERT INTO `currencies` VALUES (24, 'Chile', 'Pesos', 'CLP', '$', '2018-12-05 01:00:00', NULL, 0);
                        INSERT INTO `currencies` VALUES (25, 'China', 'Yuan Renminbi', 'CNY', '¥', '2018-12-05 01:00:00', NULL, 0);
                        INSERT INTO `currencies` VALUES (26, 'Colombia', 'Pesos', 'COP', '$', '2018-12-05 01:00:00', NULL, 0);
                        INSERT INTO `currencies` VALUES (27, 'Costa Rica', 'Colón', 'CRC', '₡', '2018-12-05 01:00:00', NULL, 0);
                        INSERT INTO `currencies` VALUES (28, 'Croatia', 'Kuna', 'HRK', 'kn', '2018-12-05 01:00:00', NULL, 0);
                        INSERT INTO `currencies` VALUES (29, 'Cuba', 'Pesos', 'CUP', '₱', '2018-12-05 01:00:00', NULL, 0);
                        INSERT INTO `currencies` VALUES (30, 'Cyprus', 'Euro', 'EUR', '€', '2018-12-05 01:00:00', NULL, 0);
                        INSERT INTO `currencies` VALUES (31, 'Czech Republic', 'Koruny', 'CZK', 'Kč', '2018-12-05 01:00:00', NULL, 0);
                        INSERT INTO `currencies` VALUES (32, 'Denmark', 'Kroner', 'DKK', 'kr', '2018-12-05 01:00:00', NULL, 0);
                        INSERT INTO `currencies` VALUES (33, 'Dominican Republic', 'Pesos', 'DOP ', 'RD$', '2018-12-05 01:00:00', NULL, 0);
                        INSERT INTO `currencies` VALUES (34, 'East Caribbean', 'Dollars', 'XCD', '$', '2018-12-05 01:00:00', NULL, 0);
                        INSERT INTO `currencies` VALUES (35, 'Egypt', 'Pounds', 'EGP', '£', '2018-12-05 01:00:00', NULL, 0);
                        INSERT INTO `currencies` VALUES (36, 'El Salvador', 'Colones', 'SVC', '$', '2018-12-05 01:00:00', NULL, 0);
                        INSERT INTO `currencies` VALUES (37, 'England (United Kingdom)', 'Pounds', 'GBP', '£', '2018-12-05 01:00:00', NULL, 0);
                        INSERT INTO `currencies` VALUES (38, 'Euro', 'Euro', 'EUR', '€', '2018-12-05 01:00:00', NULL, 0);
                        INSERT INTO `currencies` VALUES (39, 'Falkland Islands', 'Pounds', 'FKP', '£', '2018-12-05 01:00:00', NULL, 0);
                        INSERT INTO `currencies` VALUES (40, 'Fiji', 'Dollars', 'FJD', '$', '2018-12-05 01:00:00', NULL, 0);
                        INSERT INTO `currencies` VALUES (41, 'France', 'Euro', 'EUR', '€', '2018-12-05 01:00:00', NULL, 0);
                        INSERT INTO `currencies` VALUES (42, 'Ghana', 'Cedis', 'GHC', '¢', '2018-12-05 01:00:00', NULL, 0);
                        INSERT INTO `currencies` VALUES (43, 'Gibraltar', 'Pounds', 'GIP', '£', '2018-12-05 01:00:00', NULL, 0);
                        INSERT INTO `currencies` VALUES (44, 'Greece', 'Euro', 'EUR', '€', '2018-12-05 01:00:00', NULL, 0);
                        INSERT INTO `currencies` VALUES (45, 'Guatemala', 'Quetzales', 'GTQ', 'Q', '2018-12-05 01:00:00', NULL, 0);
                        INSERT INTO `currencies` VALUES (46, 'Guernsey', 'Pounds', 'GGP', '£', '2018-12-05 01:00:00', NULL, 0);
                        INSERT INTO `currencies` VALUES (47, 'Guyana', 'Dollars', 'GYD', '$', '2018-12-05 01:00:00', NULL, 0);
                        INSERT INTO `currencies` VALUES (48, 'Holland (Netherlands)', 'Euro', 'EUR', '€', '2018-12-05 01:00:00', NULL, 0);
                        INSERT INTO `currencies` VALUES (49, 'Honduras', 'Lempiras', 'HNL', 'L', '2018-12-05 01:00:00', NULL, 0);
                        INSERT INTO `currencies` VALUES (50, 'Hong Kong', 'Dollars', 'HKD', '$', '2018-12-05 01:00:00', NULL, 0);
                        INSERT INTO `currencies` VALUES (51, 'Hungary', 'Forint', 'HUF', 'Ft', '2018-12-05 01:00:00', NULL, 0);
                        INSERT INTO `currencies` VALUES (52, 'Iceland', 'Kronur', 'ISK', 'kr', '2018-12-05 01:00:00', NULL, 0);
                        INSERT INTO `currencies` VALUES (53, 'India', 'Rupees', 'INR', 'Rp', '2018-12-05 01:00:00', NULL, 0);
                        INSERT INTO `currencies` VALUES (54, 'Indonesia', 'Rupiahs', 'IDR', 'Rp', '2018-12-05 01:00:00', NULL, 0);
                        INSERT INTO `currencies` VALUES (55, 'Iran', 'Rials', 'IRR', '﷼', '2018-12-05 01:00:00', NULL, 0);
                        INSERT INTO `currencies` VALUES (56, 'Ireland', 'Euro', 'EUR', '€', '2018-12-05 01:00:00', NULL, 0);
                        INSERT INTO `currencies` VALUES (57, 'Isle of Man', 'Pounds', 'IMP', '£', '2018-12-05 01:00:00', NULL, 0);
                        INSERT INTO `currencies` VALUES (58, 'Israel', 'New Shekels', 'ILS', '₪', '2018-12-05 01:00:00', NULL, 0);
                        INSERT INTO `currencies` VALUES (59, 'Italy', 'Euro', 'EUR', '€', '2018-12-05 01:00:00', NULL, 0);
                        INSERT INTO `currencies` VALUES (60, 'Jamaica', 'Dollars', 'JMD', 'J$', '2018-12-05 01:00:00', NULL, 0);
                        INSERT INTO `currencies` VALUES (61, 'Japan', 'Yen', 'JPY', '¥', '2018-12-05 01:00:00', NULL, 0);
                        INSERT INTO `currencies` VALUES (62, 'Jersey', 'Pounds', 'JEP', '£', '2018-12-05 01:00:00', NULL, 0);
                        INSERT INTO `currencies` VALUES (63, 'Kazakhstan', 'Tenge', 'KZT', 'лв', '2018-12-05 01:00:00', NULL, 0);
                        INSERT INTO `currencies` VALUES (64, 'Korea (North)', 'Won', 'KPW', '₩', '2018-12-05 01:00:00', NULL, 0);
                        INSERT INTO `currencies` VALUES (65, 'Korea (South)', 'Won', 'KRW', '₩', '2018-12-05 01:00:00', NULL, 0);
                        INSERT INTO `currencies` VALUES (66, 'Kyrgyzstan', 'Soms', 'KGS', 'лв', '2018-12-05 01:00:00', NULL, 0);
                        INSERT INTO `currencies` VALUES (67, 'Laos', 'Kips', 'LAK', '₭', '2018-12-05 01:00:00', NULL, 0);
                        INSERT INTO `currencies` VALUES (68, 'Latvia', 'Lati', 'LVL', 'Ls', '2018-12-05 01:00:00', NULL, 0);
                        INSERT INTO `currencies` VALUES (69, 'Lebanon', 'Pounds', 'LBP', '£', '2018-12-05 01:00:00', NULL, 0);
                        INSERT INTO `currencies` VALUES (70, 'Liberia', 'Dollars', 'LRD', '$', '2018-12-05 01:00:00', NULL, 0);
                        INSERT INTO `currencies` VALUES (71, 'Liechtenstein', 'Switzerland Francs', 'CHF', 'CHF', '2018-12-05 01:00:00', NULL, 0);
                        INSERT INTO `currencies` VALUES (72, 'Lithuania', 'Litai', 'LTL', 'Lt', '2018-12-05 01:00:00', NULL, 0);
                        INSERT INTO `currencies` VALUES (73, 'Luxembourg', 'Euro', 'EUR', '€', '2018-12-05 01:00:00', NULL, 0);
                        INSERT INTO `currencies` VALUES (74, 'Macedonia', 'Denars', 'MKD', 'ден', '2018-12-05 01:00:00', NULL, 0);
                        INSERT INTO `currencies` VALUES (75, 'Malaysia', 'Ringgits', 'MYR', 'RM', '2018-12-05 01:00:00', NULL, 0);
                        INSERT INTO `currencies` VALUES (76, 'Malta', 'Euro', 'EUR', '€', '2018-12-05 01:00:00', NULL, 0);
                        INSERT INTO `currencies` VALUES (77, 'Mauritius', 'Rupees', 'MUR', '₨', '2018-12-05 01:00:00', NULL, 0);
                        INSERT INTO `currencies` VALUES (78, 'Mexico', 'Pesos', 'MXN', '$', '2018-12-05 01:00:00', NULL, 0);
                        INSERT INTO `currencies` VALUES (79, 'Mongolia', 'Tugriks', 'MNT', '₮', '2018-12-05 01:00:00', NULL, 0);
                        INSERT INTO `currencies` VALUES (80, 'Mozambique', 'Meticais', 'MZN', 'MT', '2018-12-05 01:00:00', NULL, 0);
                        INSERT INTO `currencies` VALUES (81, 'Namibia', 'Dollars', 'NAD', '$', '2018-12-05 01:00:00', NULL, 0);
                        INSERT INTO `currencies` VALUES (82, 'Nepal', 'Rupees', 'NPR', '₨', '2018-12-05 01:00:00', NULL, 0);
                        INSERT INTO `currencies` VALUES (83, 'Netherlands Antilles', 'Guilders', 'ANG', 'ƒ', '2018-12-05 01:00:00', NULL, 0);
                        INSERT INTO `currencies` VALUES (84, 'Netherlands', 'Euro', 'EUR', '€', '2018-12-05 01:00:00', NULL, 0);
                        INSERT INTO `currencies` VALUES (85, 'New Zealand', 'Dollars', 'NZD', '$', '2018-12-05 01:00:00', NULL, 0);
                        INSERT INTO `currencies` VALUES (86, 'Nicaragua', 'Cordobas', 'NIO', 'C$', '2018-12-05 01:00:00', NULL, 0);
                        INSERT INTO `currencies` VALUES (87, 'Nigeria', 'Nairas', 'NGN', '₦', '2018-12-05 01:00:00', NULL, 0);
                        INSERT INTO `currencies` VALUES (88, 'North Korea', 'Won', 'KPW', '₩', '2018-12-05 01:00:00', NULL, 0);
                        INSERT INTO `currencies` VALUES (89, 'Norway', 'Krone', 'NOK', 'kr', '2018-12-05 01:00:00', NULL, 0);
                        INSERT INTO `currencies` VALUES (90, 'Oman', 'Rials', 'OMR', '﷼', '2018-12-05 01:00:00', NULL, 0);
                        INSERT INTO `currencies` VALUES (91, 'Pakistan', 'Rupees', 'PKR', '₨', '2018-12-05 01:00:00', NULL, 0);
                        INSERT INTO `currencies` VALUES (92, 'Panama', 'Balboa', 'PAB', 'B/.', '2018-12-05 01:00:00', NULL, 0);
                        INSERT INTO `currencies` VALUES (93, 'Paraguay', 'Guarani', 'PYG', 'Gs', '2018-12-05 01:00:00', NULL, 0);
                        INSERT INTO `currencies` VALUES (94, 'Peru', 'Nuevos Soles', 'PEN', 'S/.', '2018-12-05 01:00:00', NULL, 0);
                        INSERT INTO `currencies` VALUES (95, 'Philippines', 'Pesos', 'PHP', 'Php', '2018-12-05 01:00:00', NULL, 0);
                        INSERT INTO `currencies` VALUES (96, 'Poland', 'Zlotych', 'PLN', 'zł', '2018-12-05 01:00:00', NULL, 0);
                        INSERT INTO `currencies` VALUES (97, 'Qatar', 'Rials', 'QAR', '﷼', '2018-12-05 01:00:00', NULL, 0);
                        INSERT INTO `currencies` VALUES (98, 'Romania', 'New Lei', 'RON', 'lei', '2018-12-05 01:00:00', NULL, 0);
                        INSERT INTO `currencies` VALUES (99, 'Russia', 'Rubles', 'RUB', 'руб', '2018-12-05 01:00:00', NULL, 0);
                        INSERT INTO `currencies` VALUES (100, 'Saint Helena', 'Pounds', 'SHP', '£', '2018-12-05 01:00:00', NULL, 0);
                        INSERT INTO `currencies` VALUES (101, 'Saudi Arabia', 'Riyals', 'SAR', '﷼', '2018-12-05 01:00:00', NULL, 0);
                        INSERT INTO `currencies` VALUES (102, 'Serbia', 'Dinars', 'RSD', 'Дин.', '2018-12-05 01:00:00', NULL, 0);
                        INSERT INTO `currencies` VALUES (103, 'Seychelles', 'Rupees', 'SCR', '₨', '2018-12-05 01:00:00', NULL, 0);
                        INSERT INTO `currencies` VALUES (104, 'Singapore', 'Dollars', 'SGD', '$', '2018-12-05 01:00:00', NULL, 0);
                        INSERT INTO `currencies` VALUES (105, 'Slovenia', 'Euro', 'EUR', '€', '2018-12-05 01:00:00', NULL, 0);
                        INSERT INTO `currencies` VALUES (106, 'Solomon Islands', 'Dollars', 'SBD', '$', '2018-12-05 01:00:00', NULL, 0);
                        INSERT INTO `currencies` VALUES (107, 'Somalia', 'Shillings', 'SOS', 'S', '2018-12-05 01:00:00', NULL, 0);
                        INSERT INTO `currencies` VALUES (108, 'South Africa', 'Rand', 'ZAR', 'R', '2018-12-05 01:00:00', NULL, 0);
                        INSERT INTO `currencies` VALUES (109, 'South Korea', 'Won', 'KRW', '₩', '2018-12-05 01:00:00', NULL, 0);
                        INSERT INTO `currencies` VALUES (110, 'Spain', 'Euro', 'EUR', '€', '2018-12-05 01:00:00', NULL, 0);
                        INSERT INTO `currencies` VALUES (111, 'Sri Lanka', 'Rupees', 'LKR', '₨', '2018-12-05 01:00:00', NULL, 0);
                        INSERT INTO `currencies` VALUES (112, 'Sweden', 'Kronor', 'SEK', 'kr', '2018-12-05 01:00:00', NULL, 0);
                        INSERT INTO `currencies` VALUES (113, 'Switzerland', 'Francs', 'CHF', 'CHF', '2018-12-05 01:00:00', NULL, 0);
                        INSERT INTO `currencies` VALUES (114, 'Suriname', 'Dollars', 'SRD', '$', '2018-12-05 01:00:00', NULL, 0);
                        INSERT INTO `currencies` VALUES (115, 'Syria', 'Pounds', 'SYP', '£', '2018-12-05 01:00:00', NULL, 0);
                        INSERT INTO `currencies` VALUES (116, 'Taiwan', 'New Dollars', 'TWD', 'NT$', '2018-12-05 01:00:00', NULL, 0);
                        INSERT INTO `currencies` VALUES (117, 'Thailand', 'Baht', 'THB', '฿', '2018-12-05 01:00:00', NULL, 0);
                        INSERT INTO `currencies` VALUES (118, 'Trinidad and Tobago', 'Dollars', 'TTD', 'TT$', '2018-12-05 01:00:00', NULL, 0);
                        INSERT INTO `currencies` VALUES (119, 'Turkey', 'Lira', 'TRY', 'TL', '2018-12-05 01:00:00', NULL, 0);
                        INSERT INTO `currencies` VALUES (120, 'Turkey', 'Liras', 'TRL', '£', '2018-12-05 01:00:00', NULL, 0);
                        INSERT INTO `currencies` VALUES (121, 'Tuvalu', 'Dollars', 'TVD', '$', '2018-12-05 01:00:00', NULL, 0);
                        INSERT INTO `currencies` VALUES (122, 'Ukraine', 'Hryvnia', 'UAH', '₴', '2018-12-05 01:00:00', NULL, 0);
                        INSERT INTO `currencies` VALUES (123, 'United Kingdom', 'Pounds', 'GBP', '£', '2018-12-05 01:00:00', NULL, 0);
                        INSERT INTO `currencies` VALUES (124, 'United States of America', 'Dollars', 'USD', '$', '2018-12-05 01:00:00', NULL, 0);
                        INSERT INTO `currencies` VALUES (125, 'Uruguay', 'Pesos', 'UYU', 'U$', '2018-12-05 01:00:00', NULL, 0);
                        INSERT INTO `currencies` VALUES (126, 'Uzbekistan', 'Sums', 'UZS', 'лв', '2018-12-05 01:00:00', NULL, 0);
                        INSERT INTO `currencies` VALUES (127, 'Vatican City', 'Euro', 'EUR', '€', '2018-12-05 01:00:00', NULL, 0);
                        INSERT INTO `currencies` VALUES (128, 'Venezuela', 'Bolivares Fuertes', 'VEF', 'Bs', '2018-12-05 01:00:00', NULL, 0);
                        INSERT INTO `currencies` VALUES (129, 'Vietnam', 'Dong', 'VND', '₫', '2018-12-05 01:00:00', NULL, 0);
                        INSERT INTO `currencies` VALUES (130, 'Yemen', 'Rials', 'YER', '﷼', '2018-12-05 01:00:00', NULL, 0);
                        INSERT INTO `currencies` VALUES (131, 'Zimbabwe', 'Zimbabwe Dollars', 'ZWD', 'Z$', '2018-12-05 01:00:00', NULL, 0);");
    }
}
