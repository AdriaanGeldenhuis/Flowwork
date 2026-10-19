<?php
// GET /crm/api/search.php?q= — the command palette: accounts, contacts and deals.
require_once __DIR__ . '/_bootstrap.php';

$q = mb_substr(trim((string)($_GET['q'] ?? '')), 0, 100);
if (mb_strlen($q) < 1) crm_json(['accounts' => [], 'contacts' => [], 'deals' => []]);
$like = '%' . Crm::likeEscape($q) . '%';
$start = Crm::likeEscape($q) . '%';
$digits = preg_replace('/\D+/', '', $q);
if (strlen($digits) >= 9) $digits = substr($digits, -9); // 082… finds +2782…

$st = $DB->prepare("SELECT id, name, type, status, email, phone FROM crm_accounts
                     WHERE company_id = ? AND deleted_at IS NULL AND (name LIKE ? OR legal_name LIKE ? OR email LIKE ? OR vat_no LIKE ?"
                   . (strlen($digits) >= 4 ? " OR REPLACE(REPLACE(phone, ' ', ''), '+', '') LIKE ?" : '') . ")
                  ORDER BY (name LIKE ?) DESC, name LIMIT 8");
$params = [$CRM_COMPANY_ID, $like, $like, $like, $like];
if (strlen($digits) >= 4) $params[] = '%' . $digits . '%';
$params[] = $start;
$st->execute($params);
$accounts = array_map(fn($r) => ['id' => (int)$r['id'], 'name' => $r['name'], 'type' => $r['type'], 'status' => $r['status'], 'sub' => (string)($r['email'] ?: $r['phone'])], $st->fetchAll(PDO::FETCH_ASSOC));

$st = $DB->prepare("SELECT c.id, c.first_name, c.last_name, c.email, c.phone, c.mobile, c.role_title, a.id AS account_id, a.name AS account_name
                      FROM crm_contacts c JOIN crm_accounts a ON a.id = c.account_id AND a.company_id = c.company_id AND a.deleted_at IS NULL
                     WHERE c.company_id = ? AND (CONCAT_WS(' ', c.first_name, c.last_name) LIKE ? OR c.email LIKE ?"
                   . (strlen($digits) >= 4 ? " OR REPLACE(REPLACE(c.phone, ' ', ''), '+', '') LIKE ? OR REPLACE(REPLACE(c.mobile, ' ', ''), '+', '') LIKE ?" : '') . ")
                  ORDER BY c.first_name LIMIT 6");
$params = [$CRM_COMPANY_ID, $like, $like];
if (strlen($digits) >= 4) array_push($params, '%' . $digits . '%', '%' . $digits . '%');
$st->execute($params);
$contacts = array_map(fn($r) => ['id' => (int)$r['id'], 'name' => trim($r['first_name'] . ' ' . $r['last_name']) ?: (string)$r['email'], 'email' => (string)$r['email'],
                                 'phone' => (string)($r['mobile'] ?: $r['phone']), 'role' => (string)$r['role_title'], 'account_id' => (int)$r['account_id'], 'account' => $r['account_name']],
                      $st->fetchAll(PDO::FETCH_ASSOC));

$st = $DB->prepare("SELECT o.id, o.title, o.amount, o.stage, a.name AS account_name FROM crm_opportunities o
                      JOIN crm_accounts a ON a.id = o.account_id AND a.company_id = o.company_id AND a.deleted_at IS NULL
                     WHERE o.company_id = ? AND (o.title LIKE ? OR a.name LIKE ?) ORDER BY (o.stage IN ('won','lost')), o.updated_at DESC LIMIT 6");
$st->execute([$CRM_COMPANY_ID, $like, $like]);
$stages = Crm::stageMap($DB, $CRM_COMPANY_ID);
$deals = array_map(fn($r) => ['id' => (int)$r['id'], 'title' => $r['title'], 'amount' => (float)$r['amount'], 'account' => $r['account_name'],
                              'stage' => $stages[$r['stage']]['label'] ?? $r['stage'], 'color' => $stages[$r['stage']]['color'] ?? '#85766b'], $st->fetchAll(PDO::FETCH_ASSOC));
crm_json(['accounts' => $accounts, 'contacts' => $contacts, 'deals' => $deals]);
