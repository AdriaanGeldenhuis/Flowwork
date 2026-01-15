<?php
// /calendar/event_view.php
require_once __DIR__ . '/../init.php';
require_once __DIR__ . '/../auth_gate.php';

define('ASSET_VERSION', '2025-01-21-CAL-1');

$companyId = $_SESSION['company_id'];
$userId = $_SESSION['user_id'];
$eventId = $_GET['id'] ?? null;

if (!$eventId) {
    header('Location: /calendar/');
    exit;
}

// Fetch event
$stmt = $DB->prepare("
    SELECT 
        e.*,
        c.name as calendar_name,
        c.color as calendar_color,
        c.owner_id as calendar_owner_id,
        u.first_name, u.last_name
    FROM calendar_events e
    JOIN calendars c ON e.calendar_id = c.id
    JOIN users u ON e.created_by = u.id
    WHERE e.id = ? AND e.company_id = ?
");
$stmt->execute([$eventId, $companyId]);
$event = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$event) {
    header('Location: /calendar/');
    exit;
}

// Check permissions
$canEdit = ($event['created_by'] == $userId || $event['calendar_owner_id'] == $userId || in_array($_SESSION['role'], ['admin']));

// Fetch user info
$stmt = $DB->prepare("SELECT first_name FROM users WHERE id = ?");
$stmt->execute([$userId]);
$user = $stmt->fetch();
$firstName = $user['first_name'] ?? 'User';

// Fetch company name
$stmt = $DB->prepare("SELECT name FROM companies WHERE id = ?");
$stmt->execute([$companyId]);
$company = $stmt->fetch();
$companyName = $company['name'] ?? 'Company';

// Fetch participants
$stmt = $DB->prepare("
    SELECT 
        p.user_id, p.role, p.response, p.response_at,
        u.first_name, u.last_name, u.email, u.avatar_path
    FROM calendar_event_participants p
    JOIN users u ON p.user_id = u.id
    WHERE p.event_id = ?
    ORDER BY p.role DESC
");
$stmt->execute([$eventId]);
$participants = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch attachments
$stmt = $DB->prepare("
    SELECT id, file_name, file_size, mime_type, uploaded_at
    FROM calendar_event_attachments
    WHERE event_id = ?
    ORDER BY uploaded_at DESC
");
$stmt->execute([$eventId]);
$attachments = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch links
$stmt = $DB->prepare("
    SELECT linked_type, linked_id
    FROM calendar_event_links
    WHERE event_id = ?
");
$stmt->execute([$eventId]);
$links = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($event['title']) ?> – Calendar</title>
    <link rel="stylesheet" href="/calendar/assets/calendar.css?v=<?= ASSET_VERSION ?>">
</head>
<body class="fw-calendar">
    <div class="fw-calendar__container">
        
        <!-- Header -->
        <header class="fw-calendar__header">
            <div class="fw-calendar__brand">
                <div class="fw-calendar__logo-tile">
                    <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <rect x="3" y="4" width="18" height="18" rx="2" stroke="currentColor" stroke-width="2"/>
                        <line x1="16" y1="2" x2="16" y2="6" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                        <line x1="8" y1="2" x2="8" y2="6" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                        <line x1="3" y1="10" x2="21" y2="10" stroke="currentColor" stroke-width="2"/>
                    </svg>
                </div>
                <div class="fw-calendar__brand-text">
                    <div class="fw-calendar__company-name"><?= htmlspecialchars($companyName) ?></div>
                    <div class="fw-calendar__app-name">Calendar</div>
                </div>
            </div>

            <div class="fw-calendar__greeting">
                Event Details
            </div>

            <div class="fw-calendar__controls">
                <a href="/calendar/" class="fw-calendar__home-btn" title="Back to Calendar">
                    <svg viewBox="0 0 24 24" fill="none">
                        <path d="M19 12H5M12 19l-7-7 7-7" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                </a>
                
                <button class="fw-calendar__theme-toggle" id="themeToggle" aria-label="Toggle theme">
                    <svg class="fw-calendar__theme-icon fw-calendar__theme-icon--light" viewBox="0 0 24 24" fill="none">
                        <circle cx="12" cy="12" r="5" stroke="currentColor" stroke-width="2"/>
                        <line x1="12" y1="1" x2="12" y2="3" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                        <line x1="12" y1="21" x2="12" y2="23" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                    </svg>
                    <svg class="fw-calendar__theme-icon fw-calendar__theme-icon--dark" viewBox="0 0 24 24" fill="none">
                        <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z" stroke="currentColor" stroke-width="2"/>
                    </svg>
                </button>
            </div>
        </header>

        <!-- Main Content -->
        <main class="fw-calendar__main">
            
            <div class="fw-calendar__event-view">
                
                <!-- Event Header -->
                <div class="fw-calendar__event-header">
                    <div class="fw-calendar__event-header-left">
                        <div class="fw-calendar__event-color-bar" style="background: <?= htmlspecialchars($event['color'] ?: $event['calendar_color']) ?>"></div>
                        <div class="fw-calendar__event-header-content">
                            <h1 class="fw-calendar__event-title" id="eventTitle" <?= $canEdit ? 'contenteditable="true"' : '' ?>>
                                <?= htmlspecialchars($event['title']) ?>
                            </h1>
                            <div class="fw-calendar__event-meta">
                                <span class="fw-calendar__badge" style="background: <?= htmlspecialchars($event['calendar_color']) ?>20; color: <?= htmlspecialchars($event['calendar_color']) ?>">
                                    <?= htmlspecialchars($event['calendar_name']) ?>
                                </span>
                                <?php if ($event['visibility'] === 'private'): ?>
                                <span class="fw-calendar__badge fw-calendar__badge--private">Private</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    
                    <?php if ($canEdit): ?>
                    <div class="fw-calendar__event-actions">
                        <button class="fw-calendar__btn fw-calendar__btn--secondary" id="btnEdit">
                            Edit
                        </button>
                        <button class="fw-calendar__btn" style="background: var(--accent-danger); color: white;" id="btnDelete">
                            Delete
                        </button>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Event Details Grid -->
                <div class="fw-calendar__event-grid">
                    
                    <!-- Left Column -->
                    <div class="fw-calendar__event-column">
                        
                        <!-- Time & Date -->
                        <div class="fw-calendar__info-card">
                            <h3 class="fw-calendar__info-card-title">📅 When</h3>
                            <div class="fw-calendar__event-time">
                                <?php 
                                $start = new DateTime($event['start_datetime']);
                                $end = new DateTime($event['end_datetime']);
                                
                                if ($event['all_day']) {
                                    echo $start->format('l, F j, Y') . ' (All Day)';
                                } else {
                                    if ($start->format('Y-m-d') === $end->format('Y-m-d')) {
                                        echo $start->format('l, F j, Y') . '<br>';
                                        echo $start->format('g:i A') . ' - ' . $end->format('g:i A');
                                    } else {
                                        echo $start->format('l, F j, Y g:i A') . '<br>to<br>';
                                        echo $end->format('l, F j, Y g:i A');
                                    }
                                }
                                ?>
                            </div>
                            <?php if ($event['recurrence']): ?>
                            <div class="fw-calendar__recurrence-badge">
                                🔁 Recurring: <?= htmlspecialchars($event['recurrence']) ?>
                            </div>
                            <?php endif; ?>
                        </div>

                        <!-- Location -->
                        <?php if ($event['location']): ?>
                        <div class="fw-calendar__info-card">
                            <h3 class="fw-calendar__info-card-title">📍 Location</h3>
                            <div class="fw-calendar__event-location">
                                <?= nl2br(htmlspecialchars($event['location'])) ?>
                            </div>
                        </div>
                        <?php endif; ?>

                        <!-- Description -->
                        <?php if ($event['description']): ?>
                        <div class="fw-calendar__info-card">
                            <h3 class="fw-calendar__info-card-title">📝 Description</h3>
                            <div class="fw-calendar__event-description">
                                <?= nl2br(htmlspecialchars($event['description'])) ?>
                            </div>
                        </div>
                        <?php endif; ?>

                        <!-- Participants -->
                        <?php if (count($participants) > 0): ?>
                        <div class="fw-calendar__info-card">
                            <h3 class="fw-calendar__info-card-title">👥 Participants (<?= count($participants) ?>)</h3>
                            <div class="fw-calendar__participants-list">
                                <?php foreach ($participants as $p): ?>
                                <div class="fw-calendar__participant">
                                    <div class="fw-calendar__participant-avatar">
                                        <?= strtoupper(substr($p['first_name'], 0, 1) . substr($p['last_name'], 0, 1)) ?>
                                    </div>
                                    <div class="fw-calendar__participant-info">
                                        <div class="fw-calendar__participant-name">
                                            <?= htmlspecialchars($p['first_name'] . ' ' . $p['last_name']) ?>
                                            <?php if ($p['role'] === 'organizer'): ?>
                                            <span class="fw-calendar__badge fw-calendar__badge--small">Organizer</span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="fw-calendar__participant-email"><?= htmlspecialchars($p['email']) ?></div>
                                    </div>
                                    <div class="fw-calendar__participant-response">
                                        <?php
                                        $responseColors = [
                                            'accepted' => '#10b981',
                                            'declined' => '#ef4444',
                                            'tentative' => '#f59e0b',
                                            'pending' => '#64748b'
                                        ];
                                        $responseLabels = [
                                            'accepted' => '✓ Accepted',
                                            'declined' => '✗ Declined',
                                            'tentative' => '? Maybe',
                                            'pending' => '⋯ Pending'
                                        ];
                                        ?>
                                        <span class="fw-calendar__badge" style="background: <?= $responseColors[$p['response']] ?>20; color: <?= $responseColors[$p['response']] ?>">
                                            <?= $responseLabels[$p['response']] ?>
                                        </span>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <?php if ($canEdit): ?>
                            <button class="fw-calendar__btn fw-calendar__btn--small" style="margin-top: 12px;" id="btnAddParticipant">
                                + Add Participant
                            </button>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>

                    </div>

                    <!-- Right Column -->
                    <div class="fw-calendar__event-column">
                        
                        <!-- Attachments -->
                        <div class="fw-calendar__info-card">
                            <h3 class="fw-calendar__info-card-title">📎 Attachments (<?= count($attachments) ?>)</h3>
                            <?php if (count($attachments) > 0): ?>
                            <div class="fw-calendar__attachments-list">
                                <?php foreach ($attachments as $att): ?>
                                <div class="fw-calendar__attachment-item">
                                    <div class="fw-calendar__attachment-icon">📄</div>
                                    <div class="fw-calendar__attachment-info">
                                        <div class="fw-calendar__attachment-name"><?= htmlspecialchars($att['file_name']) ?></div>
                                        <div class="fw-calendar__attachment-meta">
                                            <?= number_format($att['file_size'] / 1024, 1) ?> KB • 
                                            <?= date('M j, Y', strtotime($att['uploaded_at'])) ?>
                                        </div>
                                    </div>
                                    <a href="/calendar/ajax/attachment_download.php?id=<?= $att['id'] ?>" class="fw-calendar__btn fw-calendar__btn--small">
                                        Download
                                    </a>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <?php else: ?>
                            <div style="font-size: 13px; color: var(--fw-text-muted); padding: 12px 0;">
                                No attachments
                            </div>
                            <?php endif; ?>
                            <?php if ($canEdit): ?>
                            <button class="fw-calendar__btn fw-calendar__btn--small" style="margin-top: 12px;" id="btnAddAttachment">
                                + Add Attachment
                            </button>
                            <?php endif; ?>
                        </div>

                        <!-- Linked Items -->
                        <?php if (count($links) > 0): ?>
                        <div class="fw-calendar__info-card">
                            <h3 class="fw-calendar__info-card-title">🔗 Linked Items (<?= count($links) ?>)</h3>
                            <div class="fw-calendar__links-list">
                                <?php foreach ($links as $link): ?>
                                <div class="fw-calendar__link-item">
                                    <span class="fw-calendar__badge"><?= strtoupper($link['linked_type']) ?></span>
                                    <a href="#" class="fw-calendar__link-text">View #<?= $link['linked_id'] ?></a>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <?php endif; ?>

                        <!-- Meta Info -->
                        <div class="fw-calendar__info-card">
                            <h3 class="fw-calendar__info-card-title">ℹ️ Info</h3>
                            <dl class="fw-calendar__info-list">
                                <div class="fw-calendar__info-item">
                                    <dt>Created by</dt>
                                    <dd><?= htmlspecialchars($event['first_name'] . ' ' . $event['last_name']) ?></dd>
                                </div>
                                <div class="fw-calendar__info-item">
                                    <dt>Created at</dt>
                                    <dd><?= date('M j, Y g:i A', strtotime($event['created_at'])) ?></dd>
                                </div>
                                <div class="fw-calendar__info-item">
                                    <dt>Last updated</dt>
                                    <dd><?= date('M j, Y g:i A', strtotime($event['updated_at'])) ?></dd>
                                </div>
                            </dl>
                        </div>

                    </div>

                </div>

            </div>

        </main>

        <!-- Footer -->
        <footer class="fw-calendar__footer">
            <span>Event ID: <?= $eventId ?></span>
            <span id="themeIndicator">Theme: Light</span>
        </footer>

    </div>

    <script>
        window.EVENT_CONFIG = {
            eventId: <?= $eventId ?>,
            canEdit: <?= $canEdit ? 'true' : 'false' ?>,
            companyId: <?= $companyId ?>,
            userId: <?= $userId ?>
        };
    </script>
    <script src="/calendar/assets/calendar.js?v=<?= ASSET_VERSION ?>"></script>
    <script src="/calendar/assets/event_view.js?v=<?= ASSET_VERSION ?>"></script>
</body>
</html>