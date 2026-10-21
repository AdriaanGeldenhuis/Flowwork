<?php
// /suppliers_ai/lib/SaPlaces.php — real businesses near the site from Google
// (Places API (New), Text Search), with the company's own Google key: name,
// address, phone, website, rating and where they are. Places are located once
// and kept for 30 days in storage/suppliers_ai (so the radius works without a
// second paid call every search). Nothing here is guessed: what Google does not
// list stays empty.

final class SaPlaces
{
    public const ENDPOINT = 'https://places.googleapis.com/v1/places:searchText';
    public const FIELDS = 'places.id,places.displayName,places.formattedAddress,places.location,places.nationalPhoneNumber,places.internationalPhoneNumber,places.websiteUri,places.rating,places.userRatingCount,places.googleMapsUri,places.businessStatus,places.primaryTypeDisplayName';

    /**
     * Businesses for a search ("plumber geyser") near a place ("Vanderbijlpark").
     * @return array{center: ?array, places: array}
     */
    public static function search(string $key, string $query, string $place, float $radiusKm, int $max = 12): array
    {
        $center = $place !== '' ? self::locate($key, $place) : null;
        $body = ['textQuery' => trim($query . ($place !== '' ? ' in ' . $place : '')), 'regionCode' => 'ZA', 'languageCode' => 'en', 'pageSize' => max(1, min(20, $max))];
        if ($center) {
            $body['locationBias'] = ['circle' => ['center' => ['latitude' => $center['lat'], 'longitude' => $center['lng']], 'radius' => min(50000.0, max(1000.0, $radiusKm * 1000))]];
        }
        $res = self::post($key, $body, self::FIELDS);
        $out = [];
        foreach ((array)($res['places'] ?? []) as $p) {
            if (!is_array($p)) continue;
            $name = SaKit::text($p['displayName']['text'] ?? '', 255);
            if ($name === '') continue;
            $status = (string)($p['businessStatus'] ?? 'OPERATIONAL');
            if ($status === 'CLOSED_PERMANENTLY') continue;
            $lat = isset($p['location']['latitude']) ? (float)$p['location']['latitude'] : null;
            $lng = isset($p['location']['longitude']) ? (float)$p['location']['longitude'] : null;
            $website = (string)($p['websiteUri'] ?? '');
            $out[] = [
                'place_id' => SaKit::text($p['id'] ?? '', 190),
                'name' => $name,
                'address' => SaKit::text($p['formattedAddress'] ?? '', 255),
                'phone' => SaKit::text($p['nationalPhoneNumber'] ?? ($p['internationalPhoneNumber'] ?? ''), 64),
                'phone_intl' => SaKit::text($p['internationalPhoneNumber'] ?? '', 64),
                'website' => preg_match('#^https?://#i', $website) ? mb_substr($website, 0, 255) : '',
                'rating' => isset($p['rating']) ? round((float)$p['rating'], 1) : null,
                'rating_count' => isset($p['userRatingCount']) ? (int)$p['userRatingCount'] : null,
                'maps_url' => preg_match('#^https://#i', (string)($p['googleMapsUri'] ?? '')) ? mb_substr((string)$p['googleMapsUri'], 0, 500) : '',
                'type' => SaKit::text($p['primaryTypeDisplayName']['text'] ?? '', 80),
                'lat' => $lat, 'lng' => $lng,
                'distance_km' => $center && $lat !== null && $lng !== null ? round(self::distanceKm($center['lat'], $center['lng'], $lat, $lng), 1) : null,
                'temporarily_closed' => $status === 'CLOSED_TEMPORARILY',
            ];
        }
        return ['center' => $center, 'places' => $out];
    }

    /** Where a place is (cached 30 days). Null when Google does not know it; errors are not cached. */
    public static function locate(string $key, string $place): ?array
    {
        $place = SaKit::text($place, 160);
        if ($place === '') return null;
        $file = null;
        $dir = SaKit::storageDir();
        if ($dir !== null && is_writable($dir)) {
            $file = $dir . '/geo-' . sha1(mb_strtolower($place)) . '.json';
            if (is_file($file) && filemtime($file) > time() - 30 * 86400) {
                $c = json_decode((string)@file_get_contents($file), true);
                if (is_array($c)) return $c['lat'] === null ? null : $c;
            }
        }
        $res = self::post($key, ['textQuery' => $place . ', South Africa', 'regionCode' => 'ZA', 'languageCode' => 'en', 'pageSize' => 1], 'places.location,places.formattedAddress');
        $p = $res['places'][0] ?? null;
        $out = isset($p['location']['latitude'], $p['location']['longitude'])
            ? ['lat' => (float)$p['location']['latitude'], 'lng' => (float)$p['location']['longitude'], 'label' => SaKit::text($p['formattedAddress'] ?? $place, 160)]
            : ['lat' => null, 'lng' => null, 'label' => $place];
        if ($file !== null) @file_put_contents($file, json_encode($out));
        return $out['lat'] === null ? null : $out;
    }

    /** Is the key accepted? One small search near the company's town. */
    public static function test(string $key, string $town): array
    {
        $t0 = microtime(true);
        $r = self::search($key, 'hardware store', $town !== '' ? $town : 'Johannesburg', 25, 3);
        return ['ok' => true, 'ms' => (int)round((microtime(true) - $t0) * 1000), 'found' => count($r['places']),
                'sample' => array_map(fn($p) => $p['name'], array_slice($r['places'], 0, 3))];
    }

    /** Great-circle distance. */
    public static function distanceKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $r = 6371.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
        return $r * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    private static function post(string $key, array $body, string $fields): array
    {
        [$code, $json] = SaHttp::postJson(self::ENDPOINT, ['X-Goog-Api-Key: ' . $key, 'X-Goog-FieldMask: ' . $fields], $body, 15);
        if ($code === 200 && is_array($json)) return $json;
        $msg = SaKit::text($json['error']['message'] ?? '', 300);
        error_log('[suppliers_ai] google ' . $code . ': ' . $msg);
        if ($code === 0) throw new SaProblem('Google could not be reached — try again in a moment.', 502);
        if ($code === 403 || $code === 401) throw new SaProblem('Google refused the key' . ($msg !== '' ? ': ' . $msg : '') . ' In Google Cloud, switch on “Places API (New)” for this key.', 502);
        if ($code === 429) throw new SaProblem('Google’s quota for this key is used up for now — try again later.', 502);
        if ($code === 400) throw new SaProblem('Google could not read the search' . ($msg !== '' ? ': ' . $msg : '.'), 502);
        throw new SaProblem('Google did not answer properly (' . $code . ').', 502);
    }
}
