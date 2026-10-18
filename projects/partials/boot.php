<?php
// /projects/partials/boot.php — what every Projects page hands its script
// (<script id="prBoot">): the signed-in person, their role, the company's
// people, today's date and the templates. Pages add their own data.

function pr_boot_base(PDO $db, ProjectRepo $repo): array
{
    $me = $repo->people()[$repo->userId] ?? ['id' => $repo->userId, 'name' => '', 'email' => ''];
    return [
        'me' => ['id' => $repo->userId, 'name' => $me['name'], 'email' => $me['email']],
        'role' => $repo->role,
        'admin' => $repo->isAdmin(),
        'can_create' => $repo->canCreateProjects(),
        'people' => array_values($repo->people()),
        'today' => ProjectRepo::today(),
        'templates' => Templates::catalogue(),
    ];
}

function pr_boot_json(array $boot): string
{
    return json_encode($boot, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
}

function pr_company_name(PDO $db, int $companyId): string
{
    $st = $db->prepare("SELECT name FROM companies WHERE id = ?");
    $st->execute([$companyId]);
    return (string)($st->fetchColumn() ?: 'Company');
}

/** A Maison-styled error page that keeps people inside the app (missing / forbidden). */
function pr_error_page(string $title, string $message, int $code = 404): void
{
    http_response_code($code);
    $pageTitle = $title;
    $companyName = 'Flowwork';
    $bodyExtraClass = '';
    include __DIR__ . '/head.php';
    echo '<main class="fwm-page"><div class="fwm-empty" style="min-height:100vh"><div class="fwm-empty__art"><svg class="i"><use href="#i-lock"/></svg></div>'
       . '<div class="fwm-empty__eyebrow">Projects</div><h3>' . htmlspecialchars($title) . '</h3><p>' . htmlspecialchars($message) . '</p>'
       . '<a class="btn btn--primary" href="/projects/"><svg class="i"><use href="#i-arrow-left"/></svg>All projects</a></div></main></body></html>';
    exit;
}

/** The keyboard-shortcuts dialog every page shows on "?". */
function pr_shortcuts_modal(array $sections): string
{
    $h = fn($s) => htmlspecialchars((string)$s);
    $out = '<div class="fwm-modal" id="shortcutsModal" hidden><div class="fwm-dialog fwm-dialog--wide" role="dialog" aria-modal="true" aria-labelledby="shortcutsTitle">'
         . '<div class="fwm-dialog__head"><h2 id="shortcutsTitle">Keyboard shortcuts</h2><button class="icon-btn" data-close aria-label="Close"><svg class="i"><use href="#i-x"/></svg></button></div>'
         . '<div class="fwm-dialog__body"><div class="fwm-kbd-grid">';
    foreach ($sections as $title => $rows) {
        $out .= '<h4>' . $h($title) . '</h4>';
        foreach ($rows as [$label, $keys]) {
            $out .= '<div class="fwm-kbd-row"><span>' . $h($label) . '</span><span>' . implode('', array_map(fn($k) => '<kbd class="fwm-kbd">' . $h($k) . '</kbd>', (array)$keys)) . '</span></div>';
        }
    }
    return $out . '</div></div></div></div>';
}
