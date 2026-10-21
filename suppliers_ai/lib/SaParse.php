<?php
// /suppliers_ai/lib/SaParse.php — reads a request in plain words (English or
// Afrikaans) without any AI and without the database (unit tested):
//   "Need 3 plumbers in Vanderbijlpark for geysers tomorrow under R5k"
//   → trade plumbing, 3 plumbers, Vanderbijlpark, budget R 5 000, needed tomorrow.
// What it finds: the trade (and others it touches), the words that matter, the
// place (also misspelt: "vandebijlpark"), a quantity, a budget, when it is
// needed, and requirements such as a COC, B-BBEE level, CIDB grade or insurance.
// The company's own words (Settings → Words) add synonyms and trades.

final class SaParse
{
    /** key => [label, search term, icon, colour, words]. A word finds words that start with it; "word$" only the whole word. */
    public const TRADES = [
        'plumbing'     => ['Plumbing', 'plumber', 'is-drop', '#2f5c84', ['plumb', 'geyser', 'pipe', 'pvc$', 'valve', 'drain', 'blocked drain', 'water heater', 'leak', 'burst pipe', 'tap$', 'taps$', 'toilet', 'sewer', 'loodgieter', 'lekkasie', 'warmwatersilinder']],
        'electrical'   => ['Electrical', 'electrician', 'is-bolt', '#9a6512', ['electric', 'wiring', 'cable', 'extension cord', 'extension lead', 'db board', 'distribution board', 'circuit', 'breaker', 'rewir', 'lighting install', 'plug point', 'elektris', 'bedrading']],
        'building'     => ['Building & renovations', 'building contractor', 'is-helmet', '#7a1f30', ['builder', 'building contractor', 'construction', 'renovat', 'extension', 'alteration', 'bricklay', 'plaster', 'contractor', 'bouer', 'boukontrakteur', 'aanbou', 'opknap']],
        'hardware'     => ['Hardware & building supplies', 'hardware store', 'is-crate', '#6f7a35', ['hardware', 'building suppl', 'building material', 'tools$', 'power tool', 'ysterwinkel', 'hardeware', 'boumateriaal']],
        'roofing'      => ['Roofing', 'roofing contractor', 'i-home', '#a4552c', ['roof', 'roof leak', 'leaking roof', 'ibr$', 'corrugated', 'gutter', 'fascia', 'dakwerk', 'dak$', 'geut']],
        'ceilings'     => ['Ceilings & partitions', 'ceiling contractor', 'i-layers', '#4f5d6b', ['ceiling', 'partition', 'drywall', 'rhino board', 'bulkhead', 'plafon']],
        'painting'     => ['Painting', 'painter', 'isa-roller', '#6b3a6e', ['paint', 'coating', 'primer', 'spray paint', 'verf', 'skilder']],
        'waterproofing' => ['Waterproofing', 'waterproofing contractor', 'is-drop', '#2f6b6a', ['waterproof', 'damp proof', 'rising damp', 'torch on', 'torch-on', 'waterdig']],
        'hvac'         => ['Air-conditioning', 'air conditioning installer', 'isa-fan', '#2f6b6a', ['hvac', 'aircon', 'air con', 'air-con', 'air conditioning', 'ventilation', 'heat pump', 'lugversorg']],
        'solar'        => ['Solar & backup power', 'solar installer', 'isa-sun', '#9a6512', ['solar', 'inverter', 'backup power', 'back-up power', 'battery backup', 'lithium batter', 'load shedding', 'loadshedding', 'pv panel', 'sonkrag', 'sonpaneel']],
        'generators'   => ['Generators', 'generator supplier', 'i-zap', '#a4552c', ['generator', 'genset', 'kragopwekker']],
        'security'     => ['Security & access', 'security company', 'i-shield', '#3d5a8a', ['security', 'alarm', 'cctv', 'camera', 'access control', 'electric fenc', 'armed response', 'guard', 'intercom', 'sekuriteit']],
        'fencing'      => ['Fencing & gates', 'fencing contractor', 'isa-fence', '#6f7a35', ['fence', 'fencing', 'palisade', 'clearvu', 'gate motor', 'gates$', 'boundary wall', 'heining', 'hek$']],
        'steel'        => ['Steel & fabrication', 'steel fabricator', 'is-brick', '#4f5d6b', ['weld', 'fabricat', 'steel', 'metal work', 'metalwork', 'burglar bar', 'staal', 'sweis']],
        'glass'        => ['Glass & aluminium', 'glass and aluminium', 'isa-window', '#2f5c84', ['glass', 'glazing', 'window', 'aluminium', 'shopfront', 'mirror', 'shower door', 'glas$', 'venster']],
        'carpentry'    => ['Carpentry & joinery', 'carpenter', 'isa-saw', '#a4552c', ['carpent', 'joiner', 'cupboard', 'kitchen unit', 'built-in', 'built in', 'shelving', 'skrynwerk', 'kaste$']],
        'flooring'     => ['Flooring & tiling', 'tiler', 'i-grid', '#6b3a6e', ['floor', 'carpet', 'vinyl', 'laminate', 'tiling', 'tiler', 'tiles$', 'tile$', 'epoxy', 'teël', 'vloer']],
        'aggregates'   => ['Sand, stone & aggregates', 'sand and stone supplier', 'isa-stone', '#9a6512', ['aggregate', 'gravel', 'crushed stone', 'river sand', 'building sand', 'plaster sand', 'filling sand', 'sand$', 'stone$', 'rock$', 'crusher', 'quarry', 'g5$', 'g7$', 'klip$', 'klippe$', 'gruis']],
        'concrete'     => ['Concrete & cement', 'ready mix concrete', 'is-brick', '#4f5d6b', ['concrete', 'cement', 'ready-mix', 'readymix', 'ready mix', 'screed', 'mortar', 'beton', 'sement']],
        'bricks'       => ['Bricks & blocks', 'brick supplier', 'is-brick', '#a4552c', ['brick', 'blocks$', 'block$', 'paver', 'paving', 'maxi$', 'maxis$', 'stene', 'baksteen', 'plaveisel']],
        'timber'       => ['Timber & boards', 'timber supplier', 'isa-saw', '#6f7a35', ['timber', 'lumber', 'wood$', 'wooden', 'woodwork', 'plank', 'poles$', 'pole$', 'shutterboard', 'plywood', 'chipboard', 'hout$', 'balke']],
        'plant'        => ['Plant hire & earthworks', 'plant hire', 'i-truck', '#9a6512', ['earthwork', 'excavat', 'tipper', 'tlb', 'bobcat', 'bulldozer', 'grader', 'plant hire', 'roller', 'compactor', 'uitgraw', 'grondwerk']],
        'transport'    => ['Transport & logistics', 'transport company', 'i-truck', '#3d5a8a', ['transport', 'logistic', 'courier', 'trucking', 'freight', 'furniture removal', 'removals company', 'vervoer', 'koerier']],
        'waste'        => ['Waste & skips', 'skip hire', 'is-crate', '#4f5d6b', ['waste', 'rubble', 'skip$', 'skips$', 'bin hire', 'refuse removal', 'refuse bin', 'recycl', 'vullis', 'rommel']],
        'scaffolding'  => ['Scaffolding & formwork', 'scaffolding hire', 'i-layers', '#7a1f30', ['scaffold', 'formwork', 'shoring', 'steier']],
        'cleaning'     => ['Cleaning & hygiene', 'cleaning company', 'is-spray', '#2f7457', ['clean', 'janitor', 'hygiene', 'sanit', 'carpet clean', 'skoonmaak']],
        'pest'         => ['Pest control', 'pest control', 'i-shield', '#6f7a35', ['pest', 'termite', 'fumigat', 'rodent', 'cockroach', 'plaagbeheer']],
        'garden'       => ['Garden & landscaping', 'landscaper', 'isa-leaf', '#2f7457', ['garden', 'landscap', 'irrigation', 'lawn', 'tree fell', 'tree cutt', 'tuin', 'grasdak']],
        'boreholes'    => ['Boreholes, pumps & tanks', 'borehole drilling', 'is-drop', '#2f6b6a', ['borehole', 'pump$', 'pumps$', 'jojo', 'water tank', 'boorgat', 'pomp$']],
        'gas'          => ['Gas', 'gas installer', 'is-flame', '#a4552c', ['gas$', 'lpg', 'gas install', 'gas bottle', 'gas cylinder']],
        'signage'      => ['Signage & printing', 'signage company', 'i-flag', '#7a1f30', ['signage', 'sign writ', 'signs$', 'printing', 'printer$', 'banner', 'branding', 'vinyl wrap', 'drukwerk']],
        'safety'       => ['Safety & PPE', 'safety equipment supplier', 'is-helmet', '#9a6512', ['ppe', 'safety boot', 'hard hat', 'overalls$', 'hi-vis', 'reflective vest', 'safety glove', 'fire extinguish', 'veiligheid']],
        'office'       => ['Office supplies', 'office supplies', 'i-file-text', '#3d5a8a', ['stationery', 'office suppl', 'office furniture', 'toner', 'ink cartridge', 'printer paper', 'kantoor']],
        'it'           => ['IT & networks', 'IT support', 'isa-chip', '#2f5c84', ['it support', 'it services', 'computer', 'laptop', 'software', 'network', 'server', 'wifi', 'wi-fi', 'fibre', 'rekenaar']],
        'vehicles'     => ['Vehicles, tyres & parts', 'auto parts', 'is-car', '#4f5d6b', ['tyre', 'tires$', 'tire$', 'mechanic', 'auto part', 'spare part', 'panel beat', 'vehicle service', 'fleet', 'bande$', 'werktuigkundige']],
        'catering'     => ['Catering', 'caterer', 'is-basket', '#2f7457', ['cater', 'food platter', 'lunch for', 'function', 'spyseniering']],
    ];

    /** The old module's trade names (still in some companies' saved words) → today's keys. */
    public const LEGACY = ['plumber' => 'plumbing', 'electrician' => 'electrical', 'contractor' => 'building', 'landscaping' => 'garden', 'welding' => 'steel',
                           'plumbing_supplies' => 'plumbing', 'earthworks' => 'plant', 'hvac' => 'hvac', 'roofing' => 'roofing', 'painting' => 'painting'];

    /** Places in South Africa people search by (the misspelt ones are found too). */
    public const PLACES = [
        'Johannesburg', 'Pretoria', 'Cape Town', 'Durban', 'Gqeberha', 'Port Elizabeth', 'East London', 'Bloemfontein', 'Polokwane', 'Mbombela', 'Nelspruit',
        'Kimberley', 'Rustenburg', 'Pietermaritzburg', 'George', 'Stellenbosch', 'Paarl', 'Somerset West', 'Strand', 'Centurion', 'Midrand', 'Sandton', 'Randburg',
        'Roodepoort', 'Soweto', 'Germiston', 'Boksburg', 'Benoni', 'Kempton Park', 'Alberton', 'Springs', 'Brakpan', 'Krugersdorp', 'Randfontein', 'Edenvale',
        'Bedfordview', 'Fourways', 'Vanderbijlpark', 'Vereeniging', 'Sasolburg', 'Meyerton', 'Heidelberg', 'Potchefstroom', 'Klerksdorp', 'Welkom', 'Secunda',
        'Emalahleni', 'Witbank', 'Middelburg', 'Bethlehem', 'Upington', 'Mossel Bay', 'Knysna', 'Hermanus', 'Worcester', 'Ballito', 'Umhlanga', 'Richards Bay',
        'Newcastle', 'Ladysmith', 'Mthatha', 'Brits', 'Hartbeespoort', 'Bronkhorstspruit', 'Cullinan', 'Mokopane', 'Tzaneen', 'Thohoyandou', 'Lephalale',
        'Musina', 'Kroonstad', 'Parys', 'Vryburg', 'Mahikeng', 'Zeerust', 'Ermelo', 'Standerton', 'Kathu', 'Saldanha', 'Vredenburg', 'Malmesbury', 'Bellville',
        'Durbanville', 'Milnerton', 'Pinetown', 'Westville', 'Amanzimtoti', 'Port Shepstone', 'Kariega', 'Uitenhage', 'Makhanda', 'Grahamstown', 'Komani',
        'Queenstown', 'Oudtshoorn', 'Plettenberg Bay', 'Langebaan', 'Ceres', 'Robertson', 'Swellendam', 'Graaff-Reinet', 'Aliwal North', 'Harrismith',
        'Sasolburg', 'Vaal Triangle', 'Vaal', 'West Rand', 'East Rand', 'Lowveld', 'Highveld', 'Gauteng', 'Western Cape', 'Eastern Cape', 'Northern Cape',
        'KwaZulu-Natal', 'Free State', 'Limpopo', 'Mpumalanga', 'North West',
    ];
    private const PLACE_ALIASES = ['jhb' => 'Johannesburg', 'joburg' => 'Johannesburg', 'jozi' => 'Johannesburg', 'pta' => 'Pretoria', 'cpt' => 'Cape Town',
                                   'kaapstad' => 'Cape Town', 'dbn' => 'Durban', 'kzn' => 'KwaZulu-Natal', 'pmb' => 'Pietermaritzburg', 'vdbp' => 'Vanderbijlpark'];

    /** Requirements: key => [label, pattern]. */
    public const REQUIREMENTS = [
        'coc'       => ['Certificate of compliance (COC)', '/\bcoc\b|certificate of compliance|\bcoc\'?s\b/iu'],
        'bee'       => ['B-BBEE certificate', '/\bb-?bbee\b|\bbee\b|\bbbbee\b/iu'],
        'tax'       => ['Tax compliant (SARS pin)', '/tax clearance|tax compliant|tax pin|\btcs\b|belastingklaring/iu'],
        'insured'   => ['Insured (public liability)', '/\binsur|public liability|versekering|versekerd/iu'],
        'cidb'      => ['CIDB registered', '/\bcidb\b/iu'],
        'pirb'      => ['PIRB-registered plumber', '/\bpirb\b|registered plumber|licensed plumber/iu'],
        'electrician' => ['Registered electrician', '/registered electrician|master installation electrician|\bmie\b|wireman\'?s licen/iu'],
        'sabs'      => ['SABS / SANS approved', '/\bsabs\b|\bsans\s?\d{2,5}\b/iu'],
        'delivery'  => ['Delivers to site', '/\bdeliver|delivered|with transport|on site delivery|aflewer/iu'],
        'afterhours' => ['After hours or weekends', '/24\/7|24 hours|24-hour|after hours|after-hours|weekend|naweek/iu'],
        'warranty'  => ['Warranty or guarantee', '/warrant|guarantee|waarborg/iu'],
    ];

    private const NUMBER_WORDS = ['one' => 1, 'two' => 2, 'three' => 3, 'four' => 4, 'five' => 5, 'six' => 6, 'seven' => 7, 'eight' => 8, 'nine' => 9, 'ten' => 10,
                                  'een' => 1, 'twee' => 2, 'drie' => 3, 'vier' => 4, 'vyf' => 5, 'ses' => 6, 'sewe' => 7, 'agt' => 8, 'nege' => 9, 'tien' => 10];
    private const PEOPLE = ['people', 'guys', 'workers', 'teams', 'team', 'contractors', 'suppliers', 'companies', 'quotes', 'quotations', 'mense', 'werkers', 'kwotasies'];

    private const STOP = [
        'a', 'an', 'the', 'and', 'or', 'for', 'of', 'to', 'in', 'on', 'at', 'by', 'with', 'from', 'near', 'around', 'need', 'needs', 'needed', 'want', 'wants', 'looking',
        'look', 'find', 'get', 'some', 'any', 'someone', 'somebody', 'who', 'can', 'could', 'would', 'should', 'please', 'pls', 'me', 'my', 'our', 'we', 'us', 'i', 'you',
        'your', 'is', 'are', 'be', 'been', 'have', 'has', 'do', 'does', 'this', 'that', 'these', 'those', 'there', 'here', 'which', 'what', 'where', 'when', 'how',
        'about', 'under', 'below', 'over', 'than', 'less', 'more', 'most', 'max', 'maximum', 'budget', 'up', 'not', 'very', 'good', 'best', 'cheap', 'cheapest',
        'reliable', 'local', 'nearby', 'available', 'asap', 'urgent', 'urgently', 'today', 'tomorrow', 'week', 'weeks', 'next', 'month', 'months', 'day', 'days',
        'rand', 'also', 'just', 'like', 'job', 'jobs', 'quote', 'quotes', 'quotation', 'quotations', 'price', 'prices', 'pricing', 'urgently', 'emergency',
        'immediately', 'before', 'after', 'end', 'within', 'into', 'out', 'it', 'its', 'all', 'each', 'per', 'plus', 'least', 'only', 'same', 'other', 'company',
        'companies', 'business', 'someone', 'help', 'fix', 'install', 'installation', 'repair', 'repairs', 'replace', 'replacement', 'new', 'site',
        "'n", 'n', 'die', 'en', 'vir', 'van', 'op', 'met', 'na', 'naby', 'rondom', 'ek', 'ons', 'jy', 'julle', 'benodig', 'nodig', 'soek', 'kry', 'iemand', 'wat', 'wie',
        'waar', 'wanneer', 'asseblief', 'onder', 'minder', 'as', 'begroting', 'vandag', 'môre', 'oormôre', 'volgende', 'hierdie', 'maand', 'dae', 'dag', 'het',
        'wil', 'kan', 'moet', 'goeie', 'beste', 'goedkoop', 'dringend', 'binne', 'teen', 'voor', 'ook', 'net', 'baie', 'is', 'om', 'te', 'so', 'gou', 'moontlik',
        'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday', 'maandag', 'dinsdag', 'woensdag', 'donderdag', 'vrydag', 'saterdag', 'sondag',
    ];
    private const MONTHS = ['jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4, 'may' => 5, 'jun' => 6, 'jul' => 7, 'aug' => 8, 'sep' => 9, 'oct' => 10, 'nov' => 11, 'dec' => 12,
                            'januarie' => 1, 'februarie' => 2, 'maart' => 3, 'mei' => 5, 'junie' => 6, 'julie' => 7, 'augustus' => 8, 'oktober' => 10, 'desember' => 12];
    private const WEEKDAYS = ['monday' => 1, 'tuesday' => 2, 'wednesday' => 3, 'thursday' => 4, 'friday' => 5, 'saturday' => 6, 'sunday' => 7,
                              'maandag' => 1, 'dinsdag' => 2, 'woensdag' => 3, 'donderdag' => 4, 'vrydag' => 5, 'saterdag' => 6, 'sondag' => 7];
    private const UNITS = [
        'm³' => '(?:m³|m3|cubes?|cubic\s+met(?:re|er)s?|kubieke?\s+meter|kubes?)', 't' => '(?:tons?|tonnes?|ton|t)', 'm²' => '(?:m²|m2|sqm|square\s+met(?:re|er)s?|vierkante\s+meter)',
        'm' => '(?:metres?|meters?|lm|m)', 'km' => '(?:km)', 'bags' => '(?:bags?|sakke?)', 'pallets' => '(?:pallets?|palette?)', 'loads' => '(?:loads?|vragte?)',
        'trucks' => '(?:trucks?|truckloads?|vragmotors?)', 'units' => '(?:units?|eenhede)', 'pieces' => '(?:pcs|pieces?|stuks?)', 'litres' => '(?:litres?|liters?|l)',
        'kg' => '(?:kgs?|kilograms?)', 'rolls' => '(?:rolls?|rolle)', 'sheets' => '(?:sheets?|plate)', 'lengths' => '(?:lengths?)', 'bricks' => '(?:bricks?|stene)',
        'boxes' => '(?:boxes|box|bokse)', 'hours' => '(?:hours?|hrs?|ure)',
    ];

    // ------------------------------------------------------------------- read

    /**
     * @param array $rules the company's words (SaKit::rules)
     * @param ?string $today Y-m-d (tests); defaults to today
     */
    public static function read(string $text, array $rules = [], ?string $today = null): array
    {
        $today = $today && preg_match('/^\d{4}-\d{2}-\d{2}$/', $today) ? $today : date('Y-m-d');
        $clean = trim(preg_replace('/\s+/u', ' ', preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $text) ?? '') ?? '');
        $clean = mb_substr($clean, 0, 500);
        $lower = mb_strtolower($clean);

        // The company's synonyms: "geyser" also means "water heater, plumbing"
        $expanded = $lower;
        foreach ($rules['synonyms'] ?? [] as $word => $syns) {
            if (self::has($lower, (string)$word)) $expanded .= ' ' . implode(' ', (array)$syns);
        }

        $rest = $lower; // what is left for keywords once the other parts are read
        $budget = self::budget($rest, $budgetPhrase);
        $when = self::when($rest, $today);
        $qty = self::quantity($rest);
        $place = self::place($clean, $rest);
        $reqs = [];
        foreach (self::REQUIREMENTS as $key => [$label, $re]) {
            if (preg_match($re, $lower)) {
                if ($key === 'bee' && preg_match('/\b(?:b-?bbee|bee)\s*(?:level|lvl|vlak)\s*(\d)|\blevel\s*(\d)\s*(?:b-?bbee|bee)/iu', $lower, $m)) {
                    $label = 'B-BBEE level ' . ($m[1] ?: $m[2]);
                    $rest = str_replace($m[0], ' ', $rest);
                }
                if ($key === 'cidb' && preg_match('/\bcidb\s*(?:grade|graad)?\s*(\d{1,2}\s*[a-z]{0,2})\b/iu', $lower, $m)) {
                    $label = 'CIDB grade ' . strtoupper(str_replace(' ', '', $m[1]));
                    $rest = str_replace($m[0], ' ', $rest);
                }
                $rest = preg_replace($re, ' ', $rest) ?? $rest;
                $reqs[] = ['key' => $key, 'label' => $label];
            }
        }

        $trades = self::trades($expanded, $rules);
        $trade = $trades ? self::trade($trades[0], $rules) : null;
        $keywords = self::keywords($rest, $place);
        foreach ($rules['synonyms'] ?? [] as $word => $syns) {
            if (self::has($lower, (string)$word)) foreach ((array)$syns as $s) if (count($keywords) < 10 && !in_array($s, $keywords, true)) $keywords[] = (string)$s;
        }
        $reqWords = ['coc', 'bee', 'bbbee', 'b-bbee', 'cidb', 'pirb', 'sabs', 'insured', 'insurance', 'deliver', 'delivered', 'delivery', 'warranty', 'guarantee', 'tax', 'clearance', 'level', 'grade', 'registered', 'licensed', 'weekend', 'hour'];
        $search = array_values(array_filter($keywords, fn($w) => !in_array($w, $reqWords, true) && !preg_match('/^(level|grade)\d/', $w)));
        $query = implode(' ', array_slice($search, 0, 4));
        if ($query === '' && $trade) $query = $trade['term'];

        return [
            'text' => $clean,
            // Never the budget (the title goes into the email suppliers get), nor "tomorrow" (it would be wrong by then)
            'title' => self::title(self::without($clean, array_filter([$budgetPhrase ?? null, ($when['phrase'] ?? '') !== 'as soon as possible' ? ($when['phrase'] ?? null) : null]))),
            'trade' => $trade,
            'trades' => $trades,
            'keywords' => $keywords,
            'location' => $place,
            'quantity' => $qty['text'] ?? null,
            'count' => $qty['count'] ?? null,
            'budget' => $budget,
            'needed_by' => $when['date'] ?? null,
            'when' => $when['phrase'] ?? null,
            'urgent' => !empty($when['urgent']),
            'requirements' => $reqs,
            'query' => $query,
        ];
    }

    /** Trade details for a key (built-in or the company's own). */
    public static function trade(string $key, array $rules = []): ?array
    {
        $key = self::LEGACY[$key] ?? $key;
        if (isset(self::TRADES[$key])) {
            [$label, $term, $icon, $color] = self::TRADES[$key];
            return ['key' => $key, 'label' => $label, 'term' => $term, 'icon' => $icon, 'color' => $color];
        }
        if (isset($rules['categories'][$key])) {
            return ['key' => $key, 'label' => mb_convert_case(str_replace('_', ' ', $key), MB_CASE_TITLE), 'term' => str_replace('_', ' ', $key), 'icon' => 'is-tag', 'color' => '#85766b'];
        }
        return null;
    }

    /** The words of a trade (built-in plus the company's), for matching suppliers. */
    public static function tradeWords(string $key, array $rules = []): array
    {
        $words = [];
        foreach (self::TRADES[$key][4] ?? [] as $w) $words[] = rtrim($w, '$');
        foreach ($rules['categories'] ?? [] as $k => $list) {
            if ((self::LEGACY[$k] ?? $k) === $key) foreach ($list as $w) $words[] = rtrim((string)$w, '$');
        }
        return array_values(array_unique(array_filter($words, fn($w) => mb_strlen($w) >= 3)));
    }

    /** Trades the text touches, the best match first. */
    public static function trades(string $lower, array $rules = []): array
    {
        $score = [];
        $order = 0;
        foreach (self::TRADES as $key => $t) {
            $n = 0;
            foreach ($t[4] as $w) if (self::has($lower, $w)) $n += str_contains($w, ' ') ? 2 : 1;
            if ($n) $score[$key] = [$n, $order];
            $order++;
        }
        foreach ($rules['categories'] ?? [] as $k => $list) {
            $key = self::LEGACY[$k] ?? (string)$k;
            $n = self::has($lower, str_replace('_', ' ', (string)$k)) ? 1 : 0;
            foreach ((array)$list as $w) if (self::has($lower, (string)$w)) $n += 2; // the company's own words count double
            if ($n) $score[$key] = [($score[$key][0] ?? 0) + $n, $score[$key][1] ?? $order++];
        }
        uksort($score, fn($a, $b) => $score[$b][0] <=> $score[$a][0] ?: $score[$a][1] <=> $score[$b][1]);
        // "contractor" alone points to building only when nothing more specific was found
        $keys = array_keys($score);
        if (count($keys) > 1 && $keys[0] === 'building' && $score['building'][0] <= 1) {
            array_shift($keys);
            $keys[] = 'building';
        }
        return array_slice($keys, 0, 4);
    }

    // ----------------------------------------------------------------- pieces

    /** Does $hay hold $word (a word that starts with it; "word$" the whole word)? */
    public static function has(string $hay, string $word): bool
    {
        $whole = str_ends_with($word, '$');
        $w = trim(mb_strtolower(rtrim($word, '$')));
        if ($w === '') return false;
        $re = str_replace(' ', '[\s\-]+', preg_quote($w, '/'));
        return (bool)preg_match('/(?<![\p{L}\p{N}])' . $re . ($whole ? '(?![\p{L}\p{N}])' : '') . '/iu', $hay);
    }

    /** A budget in cents ("under R5k", "budget R12 500", "max R1,2m", "begroting van R8000"); removed from $rest. */
    public static function budget(string &$rest, ?string &$phrase = null): ?int
    {
        // "k" and "m" count only when written against the number ("R5k", "R1.2m"): "R200 m of cable" is R 200
        $mult = '(\s?k(?![\p{L}])|m(?![\p{L}\d])|\s+(?:million|mil|thousand|duisend|miljoen)\b)?';
        $re = '/(?:\b(?:under|below|less\s+than|max(?:imum)?|budget(?:\s+of)?|up\s+to|not\s+more\s+than|no\s+more\s+than|around|about|onder|minder\s+as|hoogstens|begroting(?:\s+van)?|ongeveer)\s*:?\s*)?(?<![\p{L}\d])r\s?(\d{1,3}(?:[ ,\x{A0}]\d{3})+|\d+)(?:[.,](\d{1,2}))?' . $mult . '(?![\p{L}\d])/iu';
        $re2 = '/\b(?:budget|begroting)(?:\s+of|\s+van)?\s*:?\s*(\d{1,3}(?:[ ,]\d{3})+|\d+)(?:[.,](\d{1,2}))?' . $mult . '(?![\p{L}\d])/iu';
        if (!preg_match($re, $rest, $m) && !preg_match($re2, $rest, $m)) return null;
        $whole = (int)preg_replace('/\D+/', '', $m[1]);
        $cents = $whole * 100 + (int)str_pad((string)($m[2] ?? ''), 2, '0');
        $x = strtolower(trim((string)($m[3] ?? '')));
        if (in_array($x, ['k', 'thousand', 'duisend'], true)) $cents *= 1000;
        if (in_array($x, ['m', 'mil', 'million', 'miljoen'], true)) $cents *= 1000000;
        $rest = str_replace($m[0], ' ', $rest);
        $phrase = trim($m[0]);
        return $cents > 0 && $cents <= 100000000000 ? $cents : null;
    }

    /** When it is needed: a date (Y-m-d), the words that said so and whether it is urgent; removed from $rest. */
    public static function when(string &$rest, string $today): array
    {
        $t = strtotime($today);
        $out = [];
        $take = function (string $phrase, ?int $ts, bool $urgent = false) use (&$out, &$rest, $t) {
            if ($ts !== null && $ts < $t) $ts = $t;
            if (!isset($out['date']) && $ts !== null) { $out['date'] = date('Y-m-d', $ts); $out['phrase'] = trim($phrase); }
            if ($urgent) $out['urgent'] = true;
            $rest = str_replace($phrase, ' ', $rest);
        };
        if (preg_match('/\b(asap|a\.s\.a\.p|urgent(?:ly)?|emergency|immediately|right away|dringend|noodgeval|so gou as moontlik)\b/iu', $rest, $m)) {
            $take($m[0], $t, true);
            $out['phrase'] = 'as soon as possible';
        }
        $y = (int)date('Y', $t);
        if (preg_match('/\b(\d{4})-(\d{2})-(\d{2})\b/', $rest, $m) && checkdate((int)$m[2], (int)$m[3], (int)$m[1])) {
            $take($m[0], mktime(0, 0, 0, (int)$m[2], (int)$m[3], (int)$m[1]));
        } elseif ($dm = self::dayMonth($rest)) {
            [$phrase, $day, $mon, $yy, $yearGiven] = $dm;
            $yy = $yearGiven ? $yy : $y;
            if (checkdate($mon, $day, $yy)) {
                $ts = mktime(0, 0, 0, $mon, $day, $yy);
                if (!$yearGiven && $ts < $t) $ts = mktime(0, 0, 0, $mon, $day, $yy + 1);
                $take($phrase, $ts);
            }
        }
        if (preg_match('/\b(today|tonight|vandag|vanaand)\b/iu', $rest, $m)) $take($m[0], $t);
        if (preg_match('/\b(day after tomorrow|oormôre|oormore)\b/iu', $rest, $m)) $take($m[0], strtotime('+2 days', $t));
        if (preg_match('/\b(tomorrow|tmrw|môre)\b/iu', $rest, $m)) $take($m[0], strtotime('+1 day', $t));
        if (preg_match('/\b(this week|hierdie week|by the end of the week|end of (?:the )?week|einde van die week)\b/iu', $rest, $m)) {
            $fri = strtotime('friday this week', $t);
            $take($m[0], $fri >= $t ? $fri : strtotime('sunday this week', $t));
        }
        if (preg_match('/\b(next week|volgende week)\b/iu', $rest, $m)) $take($m[0], strtotime('monday next week', $t));
        if (preg_match('/\b(end of (?:the |this )?month|month[- ]end|einde van die maand|maandeinde|month end)\b/iu', $rest, $m)) $take($m[0], strtotime(date('Y-m-t', $t)));
        if (preg_match('/\b(next month|volgende maand)\b/iu', $rest, $m)) $take($m[0], strtotime('first day of next month', $t));
        if (preg_match('/\b(?:in|within|binne)\s+(\d{1,2}|' . implode('|', array_keys(self::NUMBER_WORDS)) . ')\s+(days?|weeks?|dae|dag|weke|week)\b/iu', $rest, $m)) {
            $n = ctype_digit($m[1]) ? (int)$m[1] : (self::NUMBER_WORDS[strtolower($m[1])] ?? 0);
            if ($n > 0) $take($m[0], strtotime('+' . ($n * (preg_match('/^we/i', $m[2]) ? 7 : 1)) . ' days', $t));
        }
        if (preg_match('/\b(next\s+|volgende\s+)?(' . implode('|', array_keys(self::WEEKDAYS)) . ')\b/iu', $rest, $m)) {
            $want = self::WEEKDAYS[strtolower($m[2])];
            $dow = (int)date('N', $t);
            $add = ($want - $dow + 7) % 7;
            if ($m[1] !== '') {
                if ($add === 0) $add = 7;
                if ($dow + $add <= 7) $add += 7; // "next Friday": the Friday of next week
            }
            $take($m[0], strtotime('+' . $add . ' days', $t));
        }
        return $out;
    }

    /** "12/11", "12/11/2026", "12.11.2026", "12 Nov", "12th of November", "Nov 12": [phrase, day, month, year, year given]. */
    private static function dayMonth(string $rest): ?array
    {
        if (preg_match_all('/(?<![\d\/.])(\d{1,2})\/(\d{1,2})(?:\/(\d{2,4}))?(?![\d\/])|(?<![\d.])(\d{1,2})\.(\d{1,2})\.(\d{4})(?![\d.])/u', $rest, $all, PREG_SET_ORDER)) {
            foreach ($all as $m) {
                if ($m[0] === '24/7') continue;
                $day = (int)($m[1] !== '' ? $m[1] : $m[4]);
                $mon = (int)($m[1] !== '' ? $m[2] : $m[5]);
                $yy = (string)($m[1] !== '' ? ($m[3] ?? '') : $m[6]);
                if ($mon < 1 || $mon > 12 || $day < 1 || $day > 31) continue;
                $year = $yy === '' ? 0 : ((int)$yy < 100 ? 2000 + (int)$yy : (int)$yy);
                return [$m[0], $day, $mon, $year, $yy !== ''];
            }
        }
        $months = 'january|jan|february|feb|march|mar|april|apr|may|june|jun|july|jul|august|aug|september|sept|sep|october|oct|november|nov|december|dec|januarie|februarie|maart|mei|junie|julie|augustus|oktober|desember';
        if (preg_match('/\b(\d{1,2})(?:st|nd|rd|th)?\s+(?:of\s+)?(' . $months . ')\.?(?:\s+(\d{4}))?(?![\p{L}])|\b(' . $months . ')\.?\s+(\d{1,2})(?:st|nd|rd|th)?(?:,?\s+(\d{4}))?(?![\p{L}\d])/iu', $rest, $m)) {
            $name = strtolower(($m[2] ?? '') !== '' ? $m[2] : $m[4]);
            $mon = self::MONTHS[$name] ?? self::MONTHS[substr($name, 0, 3)] ?? 0;
            $day = (int)(($m[1] ?? '') !== '' ? $m[1] : $m[5]);
            $yy = (string)(($m[3] ?? '') !== '' ? $m[3] : ($m[6] ?? ''));
            if ($mon) return [$m[0], $day, $mon, $yy === '' ? 0 : (int)$yy, $yy !== ''];
        }
        return null;
    }

    /** How many or how much ("3 plumbers", "20 cubes", "10 tons"); removed from $rest. */
    public static function quantity(string &$rest): array
    {
        $num = '(\d{1,5}(?:[.,]\d{1,3})?|' . implode('|', array_keys(self::NUMBER_WORDS)) . ')';
        foreach (self::UNITS as $label => $re) {
            if (preg_match('/(?<![\p{L}\d.,])' . $num . '\s*' . $re . '(?![\p{L}\d³²])/iu', $rest, $m)) {
                $n = self::number($m[1]);
                if ($n === null || $n <= 0) continue;
                $rest = str_replace($m[0], ' ', $rest);
                $shown = rtrim(rtrim(number_format($n, 3, '.', ' '), '0'), '.');
                return ['text' => $shown . ' ' . $label, 'count' => null];
            }
        }
        $trade = [];
        foreach (self::TRADES as $t) foreach ($t[4] as $w) if (!str_contains($w, ' ')) $trade[] = rtrim($w, '$');
        if (preg_match_all('/(?<![\p{L}\d.,])' . $num . '\s+([\p{L}\-]{3,})/iu', $rest, $all, PREG_SET_ORDER)) {
            foreach ($all as $m) {
                $word = mb_strtolower($m[2]);
                $isTrade = in_array($word, self::PEOPLE, true);
                foreach ($trade as $tw) if (!$isTrade && mb_strlen($tw) >= 4 && str_starts_with($word, $tw)) $isTrade = true;
                if (!$isTrade) continue;
                $n = self::number($m[1]);
                if ($n === null || $n < 1 || $n > 500) continue;
                $rest = str_replace($m[0], ' ' . $m[2] . ' ', $rest);
                return ['text' => (int)$n . ' ' . $m[2], 'count' => (int)$n];
            }
        }
        if (preg_match('/\b(?:need|want|benodig|soek)\s+(\d{1,3})\b(?!\s*(?:mm|cm|%))/iu', $rest, $m) && (int)$m[1] > 0) {
            $rest = str_replace($m[0], ' ', $rest);
            return ['text' => (string)(int)$m[1], 'count' => (int)$m[1]];
        }
        return [];
    }

    /** The place: "in Vanderbijlpark", "near Sandton", "naby Sasolburg", or a known place anywhere (also misspelt). */
    public static function place(string $original, string &$rest): ?string
    {
        $notPlace = ['stock', 'least', 'bulk', 'total', 'time', 'once', 'person', 'cash', 'advance', 'writing', 'general', 'future', 'the', 'a', 'an', 'my', 'our', 'your',
                     'site', 'house', 'home', 'office', 'store', 'shop', 'town', 'area', 'region', 'progress', 'place', 'stages', 'parts', 'full', 'detail', 'particular',
                     'die', 'n', "'n", 'ons', 'my', 'voorraad', 'kontant', 'totaal', 'een', 'keer', 'this', 'that', 'next', 'hand', 'order', 'touch', 'case', 'mind'];
        $stopAfter = '(?=\s*(?:[,.;!?]|$|\b(?:for|under|budget|by|with|tomorrow|today|next|this|before|asap|urgent|who|that|and|area|vir|met|onder|teen|voor|môre|vandag|wat|en)\b|\d))';
        if (preg_match('/\b(?:in|near|around|naby|rondom|close\s+to|outside|around\s+the|in\s+the)\s+([\p{L}][\p{L}\'’\-]*(?:\s+[\p{L}][\p{L}\'’\-]*){0,2}?)' . $stopAfter . '/iu', $original, $m)) {
            $cap = trim($m[1]);
            $first = mb_strtolower(explode(' ', $cap)[0]);
            if (!in_array($first, $notPlace, true) && !in_array($first, self::STOP, true) && self::trades(mb_strtolower($cap)) === []) {
                $known = self::knownPlace(mb_strtolower($cap));
                $rest = str_replace(mb_strtolower($m[0]), ' ', $rest);
                // One word: the known spelling ("vandebijlpark" → Vanderbijlpark); more: as written ("Pretoria East")
                if ($known !== null && (mb_strtolower($known) === mb_strtolower($cap) || !str_contains($cap, ' '))) return $known;
                return mb_convert_case($cap, MB_CASE_TITLE);
            }
        }
        $known = self::knownPlace(mb_strtolower($original), $hit);
        if ($known !== null) {
            $rest = str_replace($hit, ' ', $rest);
            return $known;
        }
        return null;
    }

    /** A known place in the text (multi-word first, then single words, allowing a small misspelling). */
    public static function knownPlace(string $lower, ?string &$hit = null): ?string
    {
        foreach (self::PLACE_ALIASES as $alias => $place) {
            if (preg_match('/(?<![\p{L}])' . preg_quote($alias, '/') . '(?![\p{L}])/u', $lower)) { $hit = $alias; return $place; }
        }
        $places = self::PLACES;
        usort($places, fn($a, $b) => mb_strlen($b) <=> mb_strlen($a));
        foreach ($places as $p) {
            $lp = mb_strtolower($p);
            if (preg_match('/(?<![\p{L}])' . str_replace([' ', '\-'], ['[\s\-]+', '[\s\-]*'], preg_quote($lp, '/')) . '(?:\s+(east|west|north|south|central|oos|wes|noord|suid|sentraal))?(?![\p{L}])/u', $lower, $m)) {
                $hit = $m[0];
                return isset($m[1]) && $m[1] !== '' ? $p . ' ' . mb_convert_case($m[1], MB_CASE_TITLE) : $p;
            }
        }
        preg_match_all('/[\p{L}]{6,}/u', $lower, $words);
        foreach ($words[0] as $w) {
            if (in_array($w, self::STOP, true) || self::trades($w) !== []) continue;
            foreach (self::PLACES as $p) {
                if (str_contains($p, ' ')) continue;
                $lp = mb_strtolower($p);
                if (abs(strlen($lp) - strlen($w)) > 2 || strlen($lp) < 6) continue;
                $d = levenshtein($w, $lp);
                if ($d <= (strlen($lp) >= 10 ? 2 : 1)) { $hit = $w; return $p; }
            }
        }
        return null;
    }

    /** The words that matter (for matching suppliers and for Google), singular, without places, numbers and filler. */
    public static function keywords(string $rest, ?string $place = null): array
    {
        $placeWords = $place ? preg_split('/[\s\-]+/u', mb_strtolower($place)) : [];
        preg_match_all('/[\p{L}][\p{L}\p{N}\'’\-]*|\d+[\p{L}]+[\p{L}\p{N}]*/u', mb_strtolower($rest), $m);
        $out = [];
        foreach ($m[0] as $w) {
            $w = trim($w, "-'’");
            if (mb_strlen($w) < 3 && !preg_match('/^[a-z]\d$/', $w)) continue;
            if (in_array($w, self::STOP, true) || in_array($w, $placeWords, true) || isset(self::NUMBER_WORDS[$w])) continue;
            $s = self::singular($w);
            if (in_array($s, self::STOP, true) || in_array($s, self::PEOPLE, true) && $s !== 'contractor' && $s !== 'supplier') continue;
            if (!in_array($s, $out, true)) $out[] = $s;
            if (count($out) >= 8) break;
        }
        return $out;
    }

    public static function singular(string $w): string
    {
        if (mb_strlen($w) <= 4 || !preg_match('/^[a-z\-]+$/', $w)) return $w;
        if (str_ends_with($w, 'ies')) return substr($w, 0, -3) . 'y';
        if (str_ends_with($w, 'sses')) return substr($w, 0, -2);
        if (preg_match('/(ches|shes|xes)$/', $w)) return substr($w, 0, -2);
        if (str_ends_with($w, 's') && !preg_match('/(ss|us|is|ys)$/', $w)) return substr($w, 0, -1);
        return $w;
    }

    /** The text without some phrases (and the little words they leave hanging). */
    private static function without(string $text, array $phrases): string
    {
        foreach ($phrases as $p) {
            if ($p === '') continue;
            $text = preg_replace('/(?:\s+(?:on|by|before|for|from|teen|voor|op|vir|under|onder))?\s*' . preg_quote($p, '/') . '/iu', ' ', $text) ?? $text;
        }
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
        // "in Vanderbijlpark , , COC" after "by Friday" and "under R20k" left → "in Vanderbijlpark, COC"
        $text = preg_replace(['/\s*([,;])(?:\s*[,;])+/u', '/\s+([,;.!?])/u'], ['$1', '$1'], $text) ?? $text;
        $text = trim($text, " ,;-");
        do {
            $before = $text;
            $text = trim(preg_replace('/[\s,;]+(?:available|needed|required|please|asseblief|nodig|by|on|for|from|and|en)$/iu', '', $text) ?? $text, " ,;-");
        } while ($text !== $before && $text !== '');
        return $text;
    }

    /** "3 plumbers for geysers in Vanderbijlpark" — a short title for a request. */
    public static function title(string $clean): string
    {
        $t = preg_replace('/^(?:hi|hello|please|pls)[,!.\s]+/iu', '', $clean) ?? $clean;
        $t = preg_replace('/^(?:i\s+need|we\s+need|need|i\'m\s+looking\s+for|we\'re\s+looking\s+for|looking\s+for|find\s+me|find|get\s+me|ons\s+soek|ek\s+soek|soek|benodig|ons\s+benodig)\s+(?:a\s+|an\s+|some\s+|[\'’]n\s+)?/iu', '', $t) ?? $t;
        $t = trim($t, " \t.!?,;:");
        if ($t === '') $t = $clean;
        if (mb_strlen($t) > 90) {
            $cut = mb_substr($t, 0, 90);
            $sp = mb_strrpos($cut, ' ');
            $t = ($sp > 50 ? mb_substr($cut, 0, $sp) : $cut) . '…';
        }
        return mb_strtoupper(mb_substr($t, 0, 1)) . mb_substr($t, 1);
    }

    private static function number(string $s): ?float
    {
        $s = mb_strtolower(trim($s));
        if (isset(self::NUMBER_WORDS[$s])) return (float)self::NUMBER_WORDS[$s];
        $s = str_replace(',', '.', $s);
        return is_numeric($s) ? (float)$s : null;
    }
}
