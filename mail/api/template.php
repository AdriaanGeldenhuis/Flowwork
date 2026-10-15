<?php
// /mail/api/template.php — reusable messages (company-wide).
//   GET  ?id=…                          one template
//   GET  ?op=render&id=…&to=a@b         subject/body with {placeholders} filled for the recipient
//   POST {op:"save", template_id?, name, category, subject, body_html}
//   POST {op:"delete", template_id}
require_once __DIR__ . '/_bootstrap.php';

$load = function (int $id) use ($DB, $MAIL_COMPANY_ID): array {
    $st = $DB->prepare("SELECT * FROM email_templates WHERE template_id = ? AND company_id = ?");
    $st->execute([$id, $MAIL_COMPANY_ID]);
    $t = $st->fetch(PDO::FETCH_ASSOC);
    if (!$t) mail_error('Template not found', 404);
    return $t;
};

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    $t = $load((int)($_GET['id'] ?? 0));
    if (($_GET['op'] ?? '') !== 'render') {
        mail_json(['template' => [
            'template_id' => (int)$t['template_id'], 'name' => $t['name'], 'category' => (string)$t['category'],
            'subject' => (string)$t['subject'],
            'body_html' => HtmlSanitizer::sanitize(HtmlSanitizer::ensureHtml((string)$t['body_html']), ['block_remote' => false])['html'],
        ]]);
    }

    // Context: recipient (CRM contact if known) + me + my company.
    $to = MimeBuilder::normalizeList([(string)($_GET['to'] ?? '')])[0] ?? null;
    $ctx = ['email' => $to['email'] ?? '', 'first_name' => '', 'last_name' => '', 'full_name' => $to['name'] ?? '', 'company_name' => ''];
    if ($to) {
        try {
            $st = $DB->prepare("SELECT c.first_name, c.last_name, a.name AS org FROM crm_contacts c
                                  LEFT JOIN crm_accounts a ON a.id = c.account_id AND a.company_id = c.company_id
                                 WHERE c.company_id = ? AND LOWER(c.email) = ? LIMIT 1");
            $st->execute([$MAIL_COMPANY_ID, $to['email']]);
            if ($c = $st->fetch(PDO::FETCH_ASSOC)) {
                $ctx['first_name'] = (string)$c['first_name'];
                $ctx['last_name'] = (string)$c['last_name'];
                $ctx['company_name'] = (string)$c['org'];
                $ctx['full_name'] = trim($c['first_name'] . ' ' . $c['last_name']);
            }
        } catch (Throwable $e) { /* CRM optional */ }
        if ($ctx['first_name'] === '' && $ctx['full_name'] !== '') {
            $ctx['first_name'] = explode(' ', $ctx['full_name'])[0];
        }
    }
    $me = mail_user_name($DB, $MAIL_USER_ID);
    $st = $DB->prepare("SELECT name FROM companies WHERE id = ?");
    $st->execute([$MAIL_COMPANY_ID]);
    $ctx += [
        'my_first_name' => (string)$me['first_name'], 'my_last_name' => (string)$me['last_name'],
        'my_name' => trim($me['first_name'] . ' ' . $me['last_name']), 'my_email' => (string)$me['email'],
        'my_company' => (string)$st->fetchColumn(), 'date' => date('j F Y'),
    ];
    // HTML-escape values going into the body; the subject is plain text.
    $htmlCtx = array_map(fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'), array_filter($ctx, fn($v) => $v !== ''));
    $body = TemplateEngine::render(HtmlSanitizer::ensureHtml((string)$t['body_html']), $htmlCtx);
    $body = HtmlSanitizer::sanitize($body, ['block_remote' => false])['html'];
    $subject = TemplateEngine::render((string)$t['subject'], array_filter($ctx, fn($v) => $v !== ''));
    preg_match_all('/\{([A-Za-z0-9_]+)\}/', $subject . ' ' . $body, $m);
    mail_json(['subject' => $subject, 'body_html' => $body, 'unresolved' => array_values(array_unique($m[0]))]);
}

mail_require_post();
$in = mail_input();
$op = mail_str($in, 'op', 10);

if ($op === 'delete') {
    $t = $load((int)($in['template_id'] ?? 0));
    $DB->prepare("DELETE FROM email_templates WHERE template_id = ? AND company_id = ?")->execute([(int)$t['template_id'], $MAIL_COMPANY_ID]);
    mail_json();
}

if ($op === 'save') {
    $name = mail_str($in, 'name', 120);
    $subject = MimeBuilder::clean(mail_str($in, 'subject', 500));
    $category = mail_str($in, 'category', 64);
    $body = HtmlSanitizer::sanitize((string)($in['body_html'] ?? ''), ['block_remote' => false])['html'];
    if ($name === '') mail_error('Give the template a name');
    if (trim(strip_tags($body, '<img>')) === '') mail_error('The template body is empty');
    $id = (int)($in['template_id'] ?? 0);
    if ($id) {
        $load($id);
        $DB->prepare("UPDATE email_templates SET name = ?, subject = ?, body_html = ?, category = ?, updated_at = NOW()
                       WHERE template_id = ? AND company_id = ?")
           ->execute([$name, $subject, $body, $category, $id, $MAIL_COMPANY_ID]);
    } else {
        $DB->prepare("INSERT INTO email_templates (company_id, name, subject, body_html, category, created_by, created_at)
                      VALUES (?, ?, ?, ?, ?, ?, NOW())")
           ->execute([$MAIL_COMPANY_ID, $name, $subject, $body, $category, $MAIL_USER_ID]);
        $id = (int)$DB->lastInsertId();
    }
    mail_json(['template_id' => $id]);
}
mail_error('Unknown operation');
