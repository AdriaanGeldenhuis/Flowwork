<?php
// /qi/lib/Currencies.php
// Single source of truth for the currencies a quote/invoice/credit note can be
// issued in. ISO 4217 active codes with display symbols. ZAR stays the base
// (accounting) currency — gl_exchange_rates stores 1 unit = X ZAR per date and
// CurrencyService (finances/lib) does the lookups.

class Currencies
{
    const BASE = 'ZAR';

    // code => [name, symbol]
    private static $list = [
        'ZAR' => ['South African Rand', 'R'],
        'USD' => ['US Dollar', '$'],
        'EUR' => ['Euro', '€'],
        'GBP' => ['British Pound', '£'],
        // Southern / Central Africa
        'ZWG' => ['Zimbabwe Gold', 'ZiG'],
        'ZWL' => ['Zimbabwean Dollar (legacy)', 'Z$'],
        'CDF' => ['Congolese Franc (DRC)', 'FC'],
        'BWP' => ['Botswana Pula', 'P'],
        'NAD' => ['Namibian Dollar', 'N$'],
        'SZL' => ['Swazi Lilangeni', 'E'],
        'LSL' => ['Lesotho Loti', 'L'],
        'MZN' => ['Mozambican Metical', 'MT'],
        'ZMW' => ['Zambian Kwacha', 'K'],
        'MWK' => ['Malawian Kwacha', 'MK'],
        'AOA' => ['Angolan Kwanza', 'Kz'],
        'TZS' => ['Tanzanian Shilling', 'TSh'],
        'KES' => ['Kenyan Shilling', 'KSh'],
        'UGX' => ['Ugandan Shilling', 'USh'],
        'RWF' => ['Rwandan Franc', 'FRw'],
        'BIF' => ['Burundian Franc', 'FBu'],
        'MGA' => ['Malagasy Ariary', 'Ar'],
        'MUR' => ['Mauritian Rupee', '₨'],
        'SCR' => ['Seychellois Rupee', '₨'],
        'KMF' => ['Comorian Franc', 'CF'],
        // Rest of Africa
        'NGN' => ['Nigerian Naira', '₦'],
        'GHS' => ['Ghanaian Cedi', '₵'],
        'XOF' => ['West African CFA Franc', 'CFA'],
        'XAF' => ['Central African CFA Franc', 'FCFA'],
        'EGP' => ['Egyptian Pound', 'E£'],
        'MAD' => ['Moroccan Dirham', 'DH'],
        'DZD' => ['Algerian Dinar', 'DA'],
        'TND' => ['Tunisian Dinar', 'DT'],
        'LYD' => ['Libyan Dinar', 'LD'],
        'ETB' => ['Ethiopian Birr', 'Br'],
        'ERN' => ['Eritrean Nakfa', 'Nfk'],
        'DJF' => ['Djiboutian Franc', 'Fdj'],
        'SOS' => ['Somali Shilling', 'Sh'],
        'SDG' => ['Sudanese Pound', 'SDG'],
        'SSP' => ['South Sudanese Pound', 'SSP'],
        'GMD' => ['Gambian Dalasi', 'D'],
        'GNF' => ['Guinean Franc', 'FG'],
        'SLE' => ['Sierra Leonean Leone', 'Le'],
        'LRD' => ['Liberian Dollar', 'L$'],
        'CVE' => ['Cape Verdean Escudo', 'Esc'],
        'STN' => ['São Tomé & Príncipe Dobra', 'Db'],
        'MRU' => ['Mauritanian Ouguiya', 'UM'],
        // Americas
        'CAD' => ['Canadian Dollar', 'C$'],
        'MXN' => ['Mexican Peso', 'Mex$'],
        'BRL' => ['Brazilian Real', 'R$'],
        'ARS' => ['Argentine Peso', 'AR$'],
        'CLP' => ['Chilean Peso', 'CL$'],
        'COP' => ['Colombian Peso', 'CO$'],
        'PEN' => ['Peruvian Sol', 'S/'],
        'UYU' => ['Uruguayan Peso', '$U'],
        'PYG' => ['Paraguayan Guaraní', '₲'],
        'BOB' => ['Bolivian Boliviano', 'Bs'],
        'VES' => ['Venezuelan Bolívar', 'Bs.S'],
        'GYD' => ['Guyanese Dollar', 'G$'],
        'SRD' => ['Surinamese Dollar', 'Sr$'],
        'TTD' => ['Trinidad & Tobago Dollar', 'TT$'],
        'JMD' => ['Jamaican Dollar', 'J$'],
        'BBD' => ['Barbadian Dollar', 'Bds$'],
        'BSD' => ['Bahamian Dollar', 'B$'],
        'BZD' => ['Belize Dollar', 'BZ$'],
        'XCD' => ['East Caribbean Dollar', 'EC$'],
        'HTG' => ['Haitian Gourde', 'G'],
        'DOP' => ['Dominican Peso', 'RD$'],
        'CUP' => ['Cuban Peso', '₱'],
        'GTQ' => ['Guatemalan Quetzal', 'Q'],
        'HNL' => ['Honduran Lempira', 'L'],
        'NIO' => ['Nicaraguan Córdoba', 'C$'],
        'CRC' => ['Costa Rican Colón', '₡'],
        'PAB' => ['Panamanian Balboa', 'B/.'],
        'KYD' => ['Cayman Islands Dollar', 'CI$'],
        'AWG' => ['Aruban Florin', 'ƒ'],
        'ANG' => ['Netherlands Antillean Guilder', 'ƒ'],
        'BMD' => ['Bermudian Dollar', 'BD$'],
        // Europe
        'CHF' => ['Swiss Franc', 'CHF'],
        'NOK' => ['Norwegian Krone', 'kr'],
        'SEK' => ['Swedish Krona', 'kr'],
        'DKK' => ['Danish Krone', 'kr'],
        'ISK' => ['Icelandic Króna', 'kr'],
        'PLN' => ['Polish Złoty', 'zł'],
        'CZK' => ['Czech Koruna', 'Kč'],
        'HUF' => ['Hungarian Forint', 'Ft'],
        'RON' => ['Romanian Leu', 'lei'],
        'BGN' => ['Bulgarian Lev', 'лв'],
        'RSD' => ['Serbian Dinar', 'din'],
        'MKD' => ['Macedonian Denar', 'ден'],
        'ALL' => ['Albanian Lek', 'L'],
        'BAM' => ['Bosnian Convertible Mark', 'KM'],
        'MDL' => ['Moldovan Leu', 'L'],
        'UAH' => ['Ukrainian Hryvnia', '₴'],
        'BYN' => ['Belarusian Ruble', 'Br'],
        'RUB' => ['Russian Ruble', '₽'],
        'TRY' => ['Turkish Lira', '₺'],
        'GEL' => ['Georgian Lari', '₾'],
        'AMD' => ['Armenian Dram', '֏'],
        'AZN' => ['Azerbaijani Manat', '₼'],
        'GIP' => ['Gibraltar Pound', '£'],
        // Middle East
        'AED' => ['UAE Dirham', 'د.إ'],
        'SAR' => ['Saudi Riyal', '﷼'],
        'QAR' => ['Qatari Riyal', '﷼'],
        'KWD' => ['Kuwaiti Dinar', 'KD'],
        'BHD' => ['Bahraini Dinar', 'BD'],
        'OMR' => ['Omani Rial', '﷼'],
        'JOD' => ['Jordanian Dinar', 'JD'],
        'ILS' => ['Israeli New Shekel', '₪'],
        'LBP' => ['Lebanese Pound', 'L£'],
        'IQD' => ['Iraqi Dinar', 'IQD'],
        'IRR' => ['Iranian Rial', '﷼'],
        'YER' => ['Yemeni Rial', '﷼'],
        'SYP' => ['Syrian Pound', 'S£'],
        // Asia & Pacific
        'CNY' => ['Chinese Yuan', '¥'],
        'JPY' => ['Japanese Yen', '¥'],
        'KRW' => ['South Korean Won', '₩'],
        'INR' => ['Indian Rupee', '₹'],
        'PKR' => ['Pakistani Rupee', '₨'],
        'BDT' => ['Bangladeshi Taka', '৳'],
        'LKR' => ['Sri Lankan Rupee', 'Rs'],
        'NPR' => ['Nepalese Rupee', '₨'],
        'BTN' => ['Bhutanese Ngultrum', 'Nu'],
        'MVR' => ['Maldivian Rufiyaa', 'Rf'],
        'THB' => ['Thai Baht', '฿'],
        'VND' => ['Vietnamese Đồng', '₫'],
        'IDR' => ['Indonesian Rupiah', 'Rp'],
        'MYR' => ['Malaysian Ringgit', 'RM'],
        'SGD' => ['Singapore Dollar', 'S$'],
        'PHP' => ['Philippine Peso', '₱'],
        'HKD' => ['Hong Kong Dollar', 'HK$'],
        'TWD' => ['New Taiwan Dollar', 'NT$'],
        'MMK' => ['Myanmar Kyat', 'K'],
        'KHR' => ['Cambodian Riel', '៛'],
        'LAK' => ['Lao Kip', '₭'],
        'BND' => ['Brunei Dollar', 'B$'],
        'MNT' => ['Mongolian Tögrög', '₮'],
        'KZT' => ['Kazakhstani Tenge', '₸'],
        'UZS' => ['Uzbekistani Soʻm', 'soʻm'],
        'KGS' => ['Kyrgyzstani Som', 'с'],
        'TJS' => ['Tajikistani Somoni', 'SM'],
        'TMT' => ['Turkmenistani Manat', 'm'],
        'AFN' => ['Afghan Afghani', '؋'],
        'AUD' => ['Australian Dollar', 'A$'],
        'NZD' => ['New Zealand Dollar', 'NZ$'],
        'FJD' => ['Fijian Dollar', 'FJ$'],
        'PGK' => ['Papua New Guinean Kina', 'K'],
        'SBD' => ['Solomon Islands Dollar', 'SI$'],
        'TOP' => ['Tongan Paʻanga', 'T$'],
        'WST' => ['Samoan Tālā', 'WS$'],
        'VUV' => ['Vanuatu Vatu', 'VT'],
        'XPF' => ['CFP Franc', '₣'],
    ];

    /** @return array code => ['name' => ..., 'symbol' => ...] */
    public static function all(): array
    {
        $out = [];
        foreach (self::$list as $code => $info) {
            $out[$code] = ['name' => $info[0], 'symbol' => $info[1]];
        }
        return $out;
    }

    public static function isValid(?string $code): bool
    {
        return $code !== null && isset(self::$list[strtoupper(trim($code))]);
    }

    public static function symbol(?string $code): string
    {
        $code = strtoupper(trim((string)$code));
        return self::$list[$code][1] ?? $code ?: 'R';
    }

    public static function name(?string $code): string
    {
        $code = strtoupper(trim((string)$code));
        return self::$list[$code][0] ?? $code;
    }

    /** Format an amount in a document's currency, e.g. "$ 1,234.56". */
    public static function format($amount, ?string $code = self::BASE): string
    {
        return self::symbol($code) . ' ' . number_format((float)$amount, 2);
    }

    /** code => symbol map for the client-side picker. */
    public static function symbolMap(): array
    {
        $out = [];
        foreach (self::$list as $code => $info) {
            $out[$code] = $info[1];
        }
        return $out;
    }

    /**
     * Render <option> tags for a currency <select>. Pinned popular currencies
     * first, then the rest alphabetically.
     */
    public static function options(string $selected = self::BASE): string
    {
        $selected = strtoupper(trim($selected));
        $pinned = ['ZAR', 'USD', 'EUR', 'GBP', 'ZWG', 'CDF', 'BWP', 'NAD', 'MZN', 'ZMW'];
        $html = '';
        foreach ($pinned as $code) {
            $html .= self::optionTag($code, $selected);
        }
        $html .= '<option disabled>──────────</option>';
        $rest = array_diff(array_keys(self::$list), $pinned);
        sort($rest);
        foreach ($rest as $code) {
            $html .= self::optionTag($code, $selected);
        }
        return $html;
    }

    private static function optionTag(string $code, string $selected): string
    {
        $sel = ($code === $selected) ? ' selected' : '';
        return '<option value="' . $code . '"' . $sel . '>' . $code . ' — '
            . htmlspecialchars(self::$list[$code][0]) . '</option>';
    }
}
