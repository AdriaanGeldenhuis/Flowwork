<?php
// crm/lib/CrmTransfer.php
//
// Getting data in and out of the CRM.
//   Import (CSV, up to 5 000 rows): parse() keeps the file's rows for an hour
//   under storage/crm/imports/ and suggests which column is which; run()
//   previews (dry run) or imports customers or suppliers with their first
//   contact, address, tags and custom fields — skipping, updating or adding
//   when an account already exists (same name, email or VAT number) — and
//   records the result in crm_import_history.
//   Export (CSV): accounts with contacts, addresses, tags and custom fields;
//   contacts; deals; tasks; activity.

require_once __DIR__ . '/Crm.php';
require_once __DIR__ . '/CrmAccounts.php';
require_once __DIR__ . '/CrmFields.php';

final class CrmTransfer
{
    public const MAX_ROWS = 5000;

    /** Import targets: key => [label, group, aliases] */
    public const TARGETS = [
        'name'         => ['Name', 'Account', ['name', 'company', 'company name', 'account', 'account name', 'customer', 'customer name', 'supplier', 'supplier name', 'business', 'business name', 'organisation', 'organization', 'trading name']],
        'legal_name'   => ['Legal name', 'Account', ['legal name', 'registered name', 'legal entity']],
        'type'         => ['Customer or supplier', 'Account', ['type', 'account type', 'kind']],
        'status'       => ['Status', 'Account', ['status', 'account status']],
        'email'        => ['Account email', 'Account', ['email', 'e-mail', 'company email', 'account email', 'email address', 'accounts email']],
        'phone'        => ['Account phone', 'Account', ['phone', 'telephone', 'tel', 'company phone', 'office phone', 'phone number', 'landline']],
        'website'      => ['Website', 'Account', ['website', 'web', 'url', 'site', 'web site']],
        'vat_no'       => ['VAT number', 'Account', ['vat', 'vat number', 'vat no', 'vat nr', 'btw', 'tax number', 'vat reg']],
        'reg_no'       => ['Registration number', 'Account', ['reg', 'reg no', 'registration', 'registration number', 'company registration', 'cipc', 'reg number']],
        'industry'     => ['Industry', 'Account', ['industry', 'sector']],
        'region'       => ['Region', 'Account', ['region', 'area', 'territory']],
        'owner'        => ['Owner (email or name)', 'Account', ['owner', 'account owner', 'account manager', 'rep', 'sales rep', 'salesperson']],
        'tags'         => ['Tags (comma separated)', 'Account', ['tags', 'tag', 'labels', 'groups', 'category', 'categories']],
        'currency'     => ['Currency', 'Account', ['currency', 'ccy']],
        'notes'        => ['Notes', 'Account', ['notes', 'note', 'comments', 'description', 'remarks']],
        'contact_name' => ['Contact full name', 'Contact', ['contact', 'contact name', 'contact person', 'full name', 'person']],
        'first_name'   => ['Contact first name', 'Contact', ['first name', 'firstname', 'given name', 'contact first name', 'name first']],
        'last_name'    => ['Contact last name', 'Contact', ['last name', 'lastname', 'surname', 'family name', 'contact last name']],
        'role'         => ['Contact job title', 'Contact', ['title', 'job title', 'role', 'position', 'designation']],
        'contact_email'=> ['Contact email', 'Contact', ['contact email', 'person email', 'email 2']],
        'contact_phone'=> ['Contact phone', 'Contact', ['contact phone', 'direct line', 'work phone']],
        'mobile'       => ['Contact mobile', 'Contact', ['mobile', 'cell', 'cellphone', 'cell phone', 'mobile phone', 'whatsapp']],
        'line1'        => ['Street address', 'Address', ['address', 'street', 'address 1', 'address line 1', 'street address', 'physical address', 'line1']],
        'line2'        => ['Address line 2', 'Address', ['address 2', 'address line 2', 'suburb', 'line2']],
        'city'         => ['City / town', 'Address', ['city', 'town']],
        'province'     => ['Province / state', 'Address', ['province', 'state', 'county']],
        'postal_code'  => ['Postal code', 'Address', ['postal code', 'postcode', 'zip', 'zip code', 'code']],
        'country'      => ['Country (2 letters)', 'Address', ['country', 'country code']],
    ];

    // ----------------------------------------------------------------- import

    /** Read an uploaded CSV. @return array{token: string, headers: array, sample: array, rows: int, mapping: array} */
    public static function parse(PDO $db, int $companyId, int $userId, array $file): array
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string)$file['tmp_name'])) throw new CrmUserError('The file did not upload. Please try again.');
        if ((int)$file['size'] > 5 * 1024 * 1024) throw new CrmUserError('The file is too large (5 MB at most).');
        $ext = strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['csv', 'txt', 'tsv'], true)) throw new CrmUserError('Save the spreadsheet as CSV (File › Save as › CSV) and upload that.');
        $raw = (string)file_get_contents((string)$file['tmp_name']);
        return self::parseText($db, $companyId, $userId, $raw, (string)$file['name']);
    }

    public static function parseText(PDO $db, int $companyId, int $userId, string $raw, string $name): array
    {
        if (str_starts_with($raw, "\xEF\xBB\xBF")) $raw = substr($raw, 3);
        if (!mb_check_encoding($raw, 'UTF-8')) $raw = mb_convert_encoding($raw, 'UTF-8', 'Windows-1252');
        $raw = str_replace(["\r\n", "\r"], "\n", $raw);
        $firstLine = strtok($raw, "\n") ?: '';
        $delims = [',' => substr_count($firstLine, ','), ';' => substr_count($firstLine, ';'), "\t" => substr_count($firstLine, "\t"), '|' => substr_count($firstLine, '|')];
        arsort($delims);
        $delim = (string)array_key_first($delims);
        $fh = fopen('php://temp', 'r+');
        fwrite($fh, $raw);
        rewind($fh);
        $headers = null;
        $rows = [];
        while (($r = fgetcsv($fh, 0, $delim, '"', '')) !== false) {
            if ($r === [null] || !array_filter($r, fn($c) => trim((string)$c) !== '')) continue;
            if ($headers === null) { $headers = array_map(fn($h) => mb_substr(trim((string)$h), 0, 80), $r); continue; }
            $rows[] = array_map(fn($c) => trim((string)$c), array_pad(array_slice($r, 0, count($headers)), count($headers), ''));
            if (count($rows) > self::MAX_ROWS) throw new CrmUserError('The file has more than ' . number_format(self::MAX_ROWS) . ' rows. Split it into smaller files.');
        }
        fclose($fh);
        if (!$headers) throw new CrmUserError('The file is empty.');
        if (!$rows) throw new CrmUserError('The file has a header row but no data.');
        foreach ($headers as $i => $h) if ($h === '') $headers[$i] = 'Column ' . ($i + 1);
        $token = bin2hex(random_bytes(12));
        $dir = self::dir($companyId);
        file_put_contents($dir . '/' . $token . '.json', json_encode(['user' => $userId, 'name' => mb_substr($name, 0, 200), 'headers' => $headers, 'rows' => $rows], JSON_UNESCAPED_UNICODE));
        foreach (glob($dir . '/*.json') ?: [] as $old) if (filemtime($old) < time() - 3600) @unlink($old);
        return ['token' => $token, 'name' => $name, 'headers' => $headers, 'sample' => array_slice($rows, 0, 8), 'rows' => count($rows),
                'mapping' => self::guess($db, $companyId, $headers)];
    }

    /** Suggested target per column ('' = ignore). */
    public static function guess(PDO $db, int $companyId, array $headers): array
    {
        $fields = CrmFields::defs($db, $companyId, 'account');
        $out = [];
        $used = [];
        $norm = fn(string $s): string => strtolower(trim(preg_replace('/[^a-z0-9]+/i', ' ', $s)));
        foreach ($headers as $i => $h) {
            $n = $norm($h);
            $best = '';
            foreach (self::TARGETS as $key => [$label, $group, $aliases]) {
                if (isset($used[$key])) continue;
                if (in_array($n, array_map($norm, $aliases), true) || $n === $norm($label)) { $best = $key; break; }
            }
            if ($best === '') {
                foreach ($fields as $f) {
                    if (!isset($used['cf_' . $f['id']]) && ($n === strtolower($f['label']) || $n === str_replace('_', ' ', $f['key']))) { $best = 'cf_' . $f['id']; break; }
                }
            }
            // A second "email" column is the contact's
            if ($best === '' && in_array($n, ['email', 'e mail', 'email address'], true) && isset($used['email'])) $best = 'contact_email';
            if ($best === '' && in_array($n, ['phone', 'telephone', 'tel'], true) && isset($used['phone'])) $best = 'contact_phone';
            if ($best !== '') $used[$best] = true;
            $out[$i] = $best;
        }
        return $out;
    }

    /**
     * Preview or import. $opt: token, type (customer|supplier), mapping [col index => target],
     * existing (skip|update|add), dry (bool). Returns counts and up to 200 row messages.
     */
    public static function run(PDO $db, int $companyId, int $userId, string $role, array $opt): array
    {
        $token = preg_replace('/[^a-f0-9]/', '', (string)($opt['token'] ?? ''));
        $path = self::dir($companyId) . '/' . $token . '.json';
        if ($token === '' || !is_file($path)) throw new CrmUserError('The uploaded file has expired. Upload it again.');
        $data = json_decode((string)file_get_contents($path), true);
        if (!is_array($data) || (int)($data['user'] ?? 0) !== $userId) throw new CrmUserError('The uploaded file has expired. Upload it again.');
        $type = in_array($opt['type'] ?? '', Crm::TYPES, true) ? $opt['type'] : 'customer';
        $existing = in_array($opt['existing'] ?? '', ['skip', 'update', 'add'], true) ? $opt['existing'] : 'skip';
        $dry = !empty($opt['dry']);
        $map = [];
        foreach ((array)($opt['mapping'] ?? []) as $col => $target) {
            $target = (string)$target;
            if ($target === '' || (!isset(self::TARGETS[$target]) && !preg_match('/^cf_\d+$/', $target))) continue;
            if (in_array($target, $map, true)) throw new CrmUserError('“' . (self::TARGETS[$target][0] ?? $target) . '” is chosen for two columns.');
            $map[(int)$col] = $target;
        }
        if (!in_array('name', $map, true)) throw new CrmUserError('Choose which column holds the account name.');

        $people = Crm::people($db, $companyId);
        $byEmail = [];
        $byName = [];
        foreach ($people as $p) { $byEmail[strtolower($p['email'])] = $p['id']; $byName[mb_strtolower($p['name'])] = $p['id']; }
        $lookups = CrmAccounts::lookups($db, $companyId);
        $ind = []; foreach ($lookups['industries'] as $x) $ind[mb_strtolower($x['name'])] = $x['id'];
        $reg = []; foreach ($lookups['regions'] as $x) $reg[mb_strtolower($x['name'])] = $x['id'];
        $defs = [];
        foreach (CrmFields::defs($db, $companyId, 'account') as $f) $defs['cf_' . $f['id']] = $f;
        $statusLabels = [];
        foreach (Crm::config($db, $companyId)['statuses'] as $k => $s) { $statusLabels[mb_strtolower($s['label'])] = $k; $statusLabels[$k] = $k; }
        $statusLabels += ['blocked' => 'banned', 'lead' => 'prospect', 'archived' => 'inactive', 'dormant' => 'inactive', 'aktief' => 'active'];

        $find = $db->prepare("SELECT id FROM crm_accounts WHERE company_id = ? AND type = ? AND deleted_at IS NULL AND (name = ? OR (? <> '' AND LOWER(email) = ?) OR (? <> '' AND REPLACE(vat_no, ' ', '') = ?)) ORDER BY (name = ?) DESC LIMIT 1");
        $counts = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'failed' => 0, 'contacts' => 0];
        $messages = [];
        $seenNames = [];
        $historyId = null;
        if (!$dry) {
            $db->prepare("INSERT INTO crm_import_history (company_id, user_id, filename, type, total_rows, status, started_at, created_at) VALUES (?, ?, ?, 'accounts', ?, 'processing', NOW(), NOW())")
               ->execute([$companyId, $userId, $data['name'] ?? 'import.csv', count($data['rows'])]);
            $historyId = (int)$db->lastInsertId();
        }
        foreach ($data['rows'] as $n => $row) {
            $line = $n + 2;
            $v = [];
            foreach ($map as $col => $target) $v[$target] = trim((string)($row[$col] ?? ''));
            try {
                $name = mb_substr($v['name'] ?? '', 0, 255);
                if ($name === '') throw new CrmUserError('No name.');
                $rowType = $type;
                if (!empty($v['type'])) {
                    $t = strtolower($v['type']);
                    $rowType = str_starts_with($t, 's') || str_starts_with($t, 'v') ? 'supplier' : (str_starts_with($t, 'c') || str_starts_with($t, 'k') ? 'customer' : $type);
                }
                $key = $rowType . '|' . mb_strtolower($name);
                if (isset($seenNames[$key])) throw new CrmUserError('The same name appears earlier in the file (row ' . $seenNames[$key] . ').');
                $seenNames[$key] = $line;
                $acc = ['type' => $rowType, 'name' => $name, 'confirm_duplicate' => 1];
                foreach (['legal_name', 'email', 'phone', 'website', 'vat_no', 'reg_no', 'notes', 'currency'] as $k) if (($v[$k] ?? '') !== '') $acc[$k] = $v[$k];
                if (($v['status'] ?? '') !== '') {
                    $s = $statusLabels[mb_strtolower($v['status'])] ?? null;
                    if ($s === null) throw new CrmUserError('Unknown status “' . $v['status'] . '”.');
                    $acc['status'] = $s;
                }
                if (($v['industry'] ?? '') !== '') {
                    if (!isset($ind[mb_strtolower($v['industry'])])) throw new CrmUserError('Unknown industry “' . $v['industry'] . '” (add it in settings first).');
                    $acc['industry_id'] = $ind[mb_strtolower($v['industry'])];
                }
                if (($v['region'] ?? '') !== '') {
                    if (!isset($reg[mb_strtolower($v['region'])])) throw new CrmUserError('Unknown region “' . $v['region'] . '” (add it in settings first).');
                    $acc['region_id'] = $reg[mb_strtolower($v['region'])];
                }
                if (($v['owner'] ?? '') !== '') {
                    $o = $byEmail[strtolower($v['owner'])] ?? $byName[mb_strtolower($v['owner'])] ?? null;
                    if (!$o) throw new CrmUserError('Unknown owner “' . $v['owner'] . '”.');
                    $acc['owner_id'] = $o;
                }
                $custom = [];
                foreach ($defs as $k => $f) if (array_key_exists($k, $v) && $v[$k] !== '') $custom[$f['id']] = $v[$k];
                if ($custom) $acc['custom'] = $custom;
                $contact = [];
                if (($v['contact_name'] ?? '') !== '' && ($v['first_name'] ?? '') === '' && ($v['last_name'] ?? '') === '') {
                    $parts = preg_split('/\s+/', $v['contact_name'], 2);
                    $contact['first_name'] = $parts[0];
                    $contact['last_name'] = $parts[1] ?? '';
                }
                foreach (['first_name' => 'first_name', 'last_name' => 'last_name', 'role' => 'role', 'contact_email' => 'email', 'contact_phone' => 'phone', 'mobile' => 'mobile'] as $src => $dst) {
                    if (($v[$src] ?? '') !== '') $contact[$dst] = $v[$src];
                }
                $address = [];
                foreach (['line1' => 'line1', 'line2' => 'line2', 'city' => 'city', 'province' => 'region', 'postal_code' => 'postal_code', 'country' => 'country'] as $src => $dst) {
                    if (($v[$src] ?? '') !== '') $address[$dst] = $v[$src];
                }
                if ($address && empty($address['line1'])) $address['line1'] = trim(($address['line2'] ?? '') ?: ($address['city'] ?? ''));
                if (isset($address['country']) && strlen($address['country']) !== 2) $address['country'] = self::country($address['country']);
                $tags = ($v['tags'] ?? '') !== '' ? array_slice(array_filter(array_map('trim', preg_split('/[,;|]/', $v['tags']))), 0, 20) : [];

                $email = strtolower($acc['email'] ?? '');
                $vat = preg_replace('/\s+/', '', (string)($acc['vat_no'] ?? ''));
                $find->execute([$companyId, $rowType, $name, $email, $email, $vat, $vat, $name]);
                $match = (int)$find->fetchColumn();
                if ($match && $existing === 'skip') { $counts['skipped']++; if (count($messages) < 200) $messages[] = ['row' => $line, 'name' => $name, 'result' => 'skipped', 'text' => 'Already in the CRM']; continue; }
                if ($dry) {
                    // validate as far as possible without writing
                    if (isset($acc['email']) && !filter_var($acc['email'], FILTER_VALIDATE_EMAIL)) throw new CrmUserError('The email address does not look right.');
                    if (isset($contact['email']) && !filter_var($contact['email'], FILTER_VALIDATE_EMAIL)) throw new CrmUserError('The contact email does not look right.');
                    if ($custom) CrmFields::validate(array_values(array_filter($defs, fn($f) => isset($custom[$f['id']]))), $custom, false);
                    $counts[$match && $existing === 'update' ? 'updated' : 'created']++;
                    if ($contact) $counts['contacts']++;
                    continue;
                }
                $db->beginTransaction();
                try {
                    if ($match && $existing === 'update') {
                        unset($acc['type'], $acc['confirm_duplicate']);
                        CrmAccounts::save($db, $companyId, $userId, $role, ['id' => $match] + $acc);
                        $id = $match;
                        if ($contact) {
                            $c = $db->prepare("SELECT id FROM crm_contacts WHERE company_id = ? AND account_id = ? AND ((? <> '' AND LOWER(email) = ?) OR (first_name = ? AND COALESCE(last_name, '') = ?)) LIMIT 1");
                            $ce = strtolower($contact['email'] ?? '');
                            $c->execute([$companyId, $id, $ce, $ce, $contact['first_name'] ?? '', $contact['last_name'] ?? '']);
                            CrmAccounts::saveContact($db, $companyId, $userId, ['id' => (int)$c->fetchColumn(), 'account_id' => $id] + $contact, false);
                            $counts['contacts']++;
                        }
                        foreach ($tags as $t) CrmAccounts::addTag($db, $companyId, $id, $t, null);
                        $counts['updated']++;
                    } else {
                        if ($match) $acc['name'] = self::freeName($db, $companyId, $rowType, $name);
                        $res = CrmAccounts::save($db, $companyId, $userId, $role, $acc + ['contact' => $contact, 'address' => $address, 'tags' => $tags]);
                        if ($contact) $counts['contacts']++;
                        $counts['created']++;
                    }
                    $db->commit();
                } catch (Throwable $e) {
                    if ($db->inTransaction()) $db->rollBack();
                    throw $e;
                }
            } catch (CrmUserError | CrmForbidden $e) {
                $counts['failed']++;
                if (count($messages) < 200) $messages[] = ['row' => $line, 'name' => $v['name'] ?? '', 'result' => 'failed', 'text' => $e->getMessage()];
            } catch (Throwable $e) {
                error_log('[crm] import row ' . $line . ': ' . $e->getMessage());
                $counts['failed']++;
                if (count($messages) < 200) $messages[] = ['row' => $line, 'name' => $v['name'] ?? '', 'result' => 'failed', 'text' => 'Could not be saved.'];
            }
        }
        if ($historyId) {
            $db->prepare("UPDATE crm_import_history SET successful_rows = ?, failed_rows = ?, status = 'completed', error_log = ?, completed_at = NOW() WHERE id = ?")
               ->execute([$counts['created'] + $counts['updated'], $counts['failed'], json_encode($messages, JSON_UNESCAPED_UNICODE), $historyId]);
            Crm::audit($db, $companyId, $userId, 'crm_import', 'crm_import', $historyId, $counts);
            @unlink($path);
        }
        return ['counts' => $counts, 'messages' => $messages, 'dry' => $dry, 'total' => count($data['rows'])];
    }

    /** Recent imports. */
    public static function history(PDO $db, int $companyId): array
    {
        $st = $db->prepare("SELECT h.*, TRIM(CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, ''))) AS who
                              FROM crm_import_history h LEFT JOIN users u ON u.id = h.user_id WHERE h.company_id = ? ORDER BY h.id DESC LIMIT 20");
        $st->execute([$companyId]);
        return array_map(fn($h) => ['id' => (int)$h['id'], 'file' => $h['filename'], 'rows' => (int)$h['total_rows'], 'ok' => (int)$h['successful_rows'],
                                    'failed' => (int)$h['failed_rows'], 'status' => $h['status'], 'who' => $h['who'], 'at' => $h['created_at']], $st->fetchAll(PDO::FETCH_ASSOC));
    }

    private static function freeName(PDO $db, int $companyId, string $type, string $name): string
    {
        $st = $db->prepare("SELECT 1 FROM crm_accounts WHERE company_id = ? AND type = ? AND name = ?");
        for ($i = 2; $i < 100; $i++) {
            $try = mb_substr($name, 0, 245) . ' (' . $i . ')';
            $st->execute([$companyId, $type, $try]);
            if (!$st->fetchColumn()) return $try;
        }
        return mb_substr($name, 0, 230) . ' ' . bin2hex(random_bytes(3));
    }

    private static function country(string $s): string
    {
        $m = ['south africa' => 'ZA', 'suid-afrika' => 'ZA', 'rsa' => 'ZA', 'namibia' => 'NA', 'botswana' => 'BW', 'zimbabwe' => 'ZW', 'mozambique' => 'MZ',
              'lesotho' => 'LS', 'eswatini' => 'SZ', 'swaziland' => 'SZ', 'zambia' => 'ZM', 'united kingdom' => 'GB', 'uk' => 'GB', 'united states' => 'US', 'usa' => 'US',
              'germany' => 'DE', 'netherlands' => 'NL', 'china' => 'CN', 'india' => 'IN', 'australia' => 'AU', 'kenya' => 'KE', 'nigeria' => 'NG'];
        return $m[mb_strtolower(trim($s))] ?? 'ZA';
    }

    private static function dir(int $companyId): string
    {
        $dir = dirname(__DIR__, 2) . '/storage/crm/imports/' . $companyId;
        if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) throw new RuntimeException('import folder missing');
        return $dir;
    }

    /** A template people can fill in. */
    public static function templateCsv(PDO $db, int $companyId): string
    {
        $cols = ['Name', 'Legal name', 'Email', 'Phone', 'Website', 'VAT number', 'Registration number', 'Status', 'Industry', 'Region', 'Tags',
                 'Contact first name', 'Contact last name', 'Job title', 'Contact email', 'Mobile', 'Street address', 'City', 'Province', 'Postal code', 'Country', 'Notes'];
        foreach (CrmFields::defs($db, $companyId, 'account') as $f) $cols[] = $f['label'];
        $ex = ['Acme Engineering', 'Acme Engineering (Pty) Ltd', 'accounts@acme.co.za', '011 555 0100', 'acme.co.za', '4123456789', '2015/123456/07', 'Active', '', '', 'Key account, Gauteng',
               'Thandi', 'Mokoena', 'Procurement manager', 'thandi@acme.co.za', '082 555 0101', '12 Main Road', 'Johannesburg', 'Gauteng', '2001', 'ZA', ''];
        return self::csv([$cols, array_pad($ex, count($cols), '')]);
    }

    // ----------------------------------------------------------------- export

    /** @return array{0: string filename, 1: string csv} */
    public static function export(PDO $db, int $companyId, string $what, array $p): array
    {
        $date = date('Y-m-d');
        $cfg = Crm::config($db, $companyId);
        if ($what === 'customers' || $what === 'suppliers') {
            $type = $what === 'customers' ? 'customer' : 'supplier';
            $defs = CrmFields::forAccountType($db, $companyId, $type);
            $names = CrmAccounts::lookupNames($db, $companyId);
            $sql = "SELECT a.* FROM crm_accounts a WHERE a.company_id = ? AND a.type = ? AND a.deleted_at IS NULL";
            $params = [$companyId, $type];
            if (in_array($p['status'] ?? '', Crm::STATUSES, true)) { $sql .= " AND a.status = ?"; $params[] = $p['status']; }
            if ((int)($p['tag'] ?? 0) > 0) { $sql .= " AND EXISTS (SELECT 1 FROM crm_account_tags at WHERE at.account_id = a.id AND at.tag_id = ?)"; $params[] = (int)$p['tag']; }
            if (!empty($p['ids']) && is_array($p['ids'])) { $sql .= " AND a.id IN (" . implode(',', array_map('intval', $p['ids'])) . ")"; }
            $st = $db->prepare($sql . " ORDER BY a.name LIMIT 50000");
            $st->execute($params);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
            $ids = array_map(fn($r) => (int)$r['id'], $rows);
            $tags = CrmAccounts::tagsFor($db, $ids);
            $contacts = [];
            $addresses = [];
            if ($ids) {
                foreach (array_chunk($ids, 1000) as $chunk) {
                    $in = implode(',', $chunk);
                    foreach ($db->query("SELECT * FROM crm_contacts WHERE company_id = " . (int)$companyId . " AND account_id IN ($in) ORDER BY is_primary DESC, id")->fetchAll(PDO::FETCH_ASSOC) as $c) {
                        $contacts[(int)$c['account_id']] ??= $c;
                    }
                    foreach ($db->query("SELECT * FROM crm_addresses WHERE company_id = " . (int)$companyId . " AND account_id IN ($in) ORDER BY FIELD(type, 'head_office','billing','shipping','site'), id")->fetchAll(PDO::FETCH_ASSOC) as $a) {
                        $addresses[(int)$a['account_id']] ??= $a;
                    }
                }
            }
            $values = $defs ? CrmFields::values($db, $companyId, array_column($defs, 'id'), $ids) : [];
            $head = ['Name', 'Legal name', 'Status', 'Email', 'Phone', 'Website', 'VAT number', 'Registration number', 'Industry', 'Region', 'Owner', 'Tags', 'Currency',
                     'Contact first name', 'Contact last name', 'Job title', 'Contact email', 'Contact phone', 'Mobile', 'Street address', 'Address line 2', 'City', 'Province', 'Postal code', 'Country', 'Notes', 'Added'];
            foreach ($defs as $f) $head[] = $f['label'];
            $out = [$head];
            foreach ($rows as $r) {
                $id = (int)$r['id'];
                $c = $contacts[$id] ?? [];
                $a = $addresses[$id] ?? [];
                $line = [$r['name'], $r['legal_name'], $cfg['statuses'][$r['status']]['label'] ?? $r['status'], $r['email'], $r['phone'], $r['website'], $r['vat_no'], $r['reg_no'],
                         $names['industries'][(int)$r['industry_id']] ?? '', $names['regions'][(int)$r['region_id']] ?? '',
                         $r['owner_id'] ? Crm::personName($db, $companyId, (int)$r['owner_id']) : '', implode(', ', array_column($tags[$id] ?? [], 'name')), $r['currency'],
                         $c['first_name'] ?? '', $c['last_name'] ?? '', $c['role_title'] ?? '', $c['email'] ?? '', $c['phone'] ?? '', $c['mobile'] ?? '',
                         $a['line1'] ?? '', $a['line2'] ?? '', $a['city'] ?? '', $a['region'] ?? '', $a['postal_code'] ?? '', $a['country'] ?? '', $r['notes'], substr((string)$r['created_at'], 0, 10)];
                foreach ($defs as $f) $line[] = CrmFields::display($f, $values[$id][$f['id']] ?? null);
                $out[] = $line;
            }
            return [strtolower($cfg['labels'][$what]) . '-' . $date . '.csv', self::csv($out)];
        }
        if ($what === 'contacts') {
            $defs = CrmFields::defs($db, $companyId, 'contact');
            $st = $db->prepare("SELECT c.*, a.name AS account_name, a.type AS account_type FROM crm_contacts c JOIN crm_accounts a ON a.id = c.account_id AND a.deleted_at IS NULL
                                 WHERE c.company_id = ? ORDER BY a.name, c.is_primary DESC, c.first_name LIMIT 100000");
            $st->execute([$companyId]);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
            $values = $defs ? CrmFields::values($db, $companyId, array_column($defs, 'id'), array_column($rows, 'id')) : [];
            $head = ['First name', 'Last name', 'Job title', 'Email', 'Phone', 'Mobile', 'Primary', 'Account', 'Account type', 'Notes'];
            foreach ($defs as $f) $head[] = $f['label'];
            $out = [$head];
            foreach ($rows as $c) {
                $line = [$c['first_name'], $c['last_name'], $c['role_title'], $c['email'], $c['phone'], $c['mobile'] ?? '', $c['is_primary'] ? 'Yes' : 'No', $c['account_name'],
                         $cfg['labels'][$c['account_type']] ?? $c['account_type'], $c['notes'] ?? ''];
                foreach ($defs as $f) $line[] = CrmFields::display($f, $values[(int)$c['id']][$f['id']] ?? null);
                $out[] = $line;
            }
            return ['contacts-' . $date . '.csv', self::csv($out)];
        }
        if ($what === 'deals') {
            $defs = CrmFields::defs($db, $companyId, 'deal');
            $stages = Crm::stageMap($db, $companyId);
            $st = $db->prepare("SELECT o.*, a.name AS account_name FROM crm_opportunities o JOIN crm_accounts a ON a.id = o.account_id WHERE o.company_id = ? ORDER BY o.created_at DESC LIMIT 100000");
            $st->execute([$companyId]);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
            $values = $defs ? CrmFields::values($db, $companyId, array_column($defs, 'id'), array_column($rows, 'id')) : [];
            $head = ['Deal', 'Account', 'Value', 'Stage', 'Probability %', 'Expected close', 'Owner', 'Loss reason', 'Created', 'Stage changed'];
            foreach ($defs as $f) $head[] = $f['label'];
            $out = [$head];
            foreach ($rows as $o) {
                $line = [$o['title'], $o['account_name'], number_format((float)$o['amount'], 2, '.', ''), $stages[$o['stage']]['label'] ?? $o['stage'], (float)$o['probability'],
                         $o['close_date'], Crm::personName($db, $companyId, (int)$o['owner_id']), $o['loss_reason'], substr((string)$o['created_at'], 0, 10), substr((string)$o['stage_changed_at'], 0, 10)];
                foreach ($defs as $f) $line[] = CrmFields::display($f, $values[(int)$o['id']][$f['id']] ?? null);
                $out[] = $line;
            }
            return ['deals-' . $date . '.csv', self::csv($out)];
        }
        if ($what === 'tasks') {
            $st = $db->prepare("SELECT t.*, a.name AS account_name FROM crm_tasks t LEFT JOIN crm_accounts a ON a.id = t.account_id WHERE t.company_id = ? ORDER BY t.due_at IS NULL, t.due_at LIMIT 100000");
            $st->execute([$companyId]);
            $out = [['Task', 'Account', 'Due', 'Priority', 'Assigned to', 'Done', 'Notes']];
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $t) {
                $out[] = [$t['title'], $t['account_name'], $t['due_at'] ? ((int)$t['due_all_day'] ? substr((string)$t['due_at'], 0, 10) : substr((string)$t['due_at'], 0, 16)) : '',
                          ucfirst((string)$t['priority']), Crm::personName($db, $companyId, $t['assigned_to'] ? (int)$t['assigned_to'] : null), $t['done_at'] ? substr((string)$t['done_at'], 0, 16) : '', $t['notes']];
            }
            return ['tasks-' . $date . '.csv', self::csv($out)];
        }
        if ($what === 'activity') {
            $st = $db->prepare("SELECT i.*, a.name AS account_name FROM crm_interactions i JOIN crm_accounts a ON a.id = i.account_id WHERE i.company_id = ?
                                   AND i.created_at >= ? ORDER BY i.created_at DESC LIMIT 100000");
            $from = Crm::ymd($p['from'] ?? '') ?? date('Y-m-d', strtotime('-12 months'));
            $st->execute([$companyId, $from]);
            $out = [['When', 'Type', 'Account', 'Subject', 'Details', 'By']];
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $i) {
                $out[] = [substr((string)$i['created_at'], 0, 16), ucfirst((string)$i['type']), $i['account_name'], $i['subject'], $i['body'], Crm::personName($db, $companyId, $i['created_by'] ? (int)$i['created_by'] : null)];
            }
            return ['activity-' . $date . '.csv', self::csv($out)];
        }
        throw new CrmUserError('Choose what to export.');
    }

    /** CSV text (UTF-8 with BOM so Excel reads accents), formula-safe. */
    public static function csv(array $rows): string
    {
        $fh = fopen('php://temp', 'r+');
        fwrite($fh, "\xEF\xBB\xBF");
        foreach ($rows as $r) {
            fputcsv($fh, array_map(function ($c) {
                $c = (string)($c ?? '');
                // A cell that starts like a formula is quoted so spreadsheets do not run it.
                return preg_match('/^[=+\-@\t\r]/', $c) && !preg_match('/^[+-]?\d[\d\s.,]*$/', $c) ? "'" . $c : $c;
            }, $r), ',', '"', '');
        }
        rewind($fh);
        $s = stream_get_contents($fh);
        fclose($fh);
        return (string)$s;
    }
}
