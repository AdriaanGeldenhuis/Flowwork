<?php
// /calendar/event_new.php
require_once __DIR__ . '/../init.php';
require_once __DIR__ . '/../auth_gate.php';

define('ASSET_VERSION', '2026-07-10-calendar-crm-parity');

$companyId = $_SESSION['company_id'];
$userId = $_SESSION['user_id'];
$eventId = $_GET['id'] ?? null;
$isEdit = !empty($eventId);

$event = null;
$calendar = null;

if ($isEdit) {
    // Fetch event for editing
    $stmt = $DB->prepare("
        SELECT 
            e.*,
            c.name as calendar_name,
            c.color as calendar_color,
            c.owner_id as calendar_owner_id
        FROM calendar_events e
        JOIN calendars c ON e.calendar_id = c.id
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
    if (!$canEdit) {
        header('Location: /calendar/event_view.php?id=' . $eventId);
        exit;
    }

    // Fetch participants
    $stmt = $DB->prepare("
        SELECT user_id, role FROM calendar_event_participants WHERE event_id = ?
    ");
    $stmt->execute([$eventId]);
    $participants = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Fetch reminders
    $stmt = $DB->prepare("
        SELECT minutes_before, channel FROM calendar_event_reminders 
        WHERE event_id = ? AND user_id = ?
    ");
    $stmt->execute([$eventId, $userId]);
    $reminders = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

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

// Fetch user's calendars
$stmt = $DB->prepare("
    SELECT id, name, color, calendar_type
    FROM calendars
    WHERE company_id = ? 
    AND (owner_id = ? OR calendar_type IN ('team', 'resource'))
    AND is_active = 1
    ORDER BY calendar_type, name
");
$stmt->execute([$companyId, $userId]);
$calendars = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch company users for participants
$stmt = $DB->prepare("
    SELECT u.id, u.first_name, u.last_name, u.email
    FROM users u
    JOIN user_companies uc ON u.id = uc.user_id
    WHERE uc.company_id = ? AND u.status = 'active'
    ORDER BY u.first_name, u.last_name
");
$stmt->execute([$companyId]);
$companyUsers = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $isEdit ? 'Edit Event' : 'New Event' ?> – <?= htmlspecialchars($companyName) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <meta name="csrf-token" content="<?= htmlspecialchars(Csrf::token()) ?>">
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
                        <circle cx="8" cy="14" r="1" fill="currentColor"/>
                        <circle cx="12" cy="14" r="1" fill="currentColor"/>
                        <circle cx="16" cy="14" r="1" fill="currentColor"/>
                        <circle cx="8" cy="18" r="1" fill="currentColor"/>
                        <circle cx="12" cy="18" r="1" fill="currentColor"/>
                    </svg>
                </div>
                <div class="fw-calendar__brand-text">
                    <div class="fw-calendar__company-name"><?= htmlspecialchars($companyName) ?></div>
                    <div class="fw-calendar__app-name">Calendar – <?= $isEdit ? 'Edit Event' : 'New Event' ?></div>
                </div>
            </div>

            <div class="fw-calendar__greeting">
                Hello, <span class="fw-calendar__greeting-name"><?= htmlspecialchars($firstName) ?></span>
            </div>

            <div class="fw-calendar__controls">
                <a href="<?= $isEdit ? '/calendar/event_view.php?id=' . $eventId : '/calendar/' ?>" class="fw-calendar__back-btn" title="Cancel">
                    <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <path d="M19 12H5M12 19l-7-7 7-7" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                </a>

                <a href="/" class="fw-calendar__home-btn" title="Home">
                    <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                        <polyline points="9 22 9 12 15 12 15 22" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                </a>

                <button class="fw-calendar__theme-toggle" id="themeToggle" aria-label="Toggle theme">
                    <svg class="fw-calendar__theme-icon fw-calendar__theme-icon--light" viewBox="0 0 24 24" fill="none">
                        <circle cx="12" cy="12" r="5" stroke="currentColor" stroke-width="2"/>
                        <line x1="12" y1="1" x2="12" y2="3" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                        <line x1="12" y1="21" x2="12" y2="23" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                        <line x1="4.22" y1="4.22" x2="5.64" y2="5.64" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                        <line x1="18.36" y1="18.36" x2="19.78" y2="19.78" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                        <line x1="1" y1="12" x2="3" y2="12" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                        <line x1="21" y1="12" x2="23" y2="12" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                        <line x1="4.22" y1="19.78" x2="5.64" y2="18.36" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                        <line x1="18.36" y1="5.64" x2="19.78" y2="4.22" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                    </svg>
                    <svg class="fw-calendar__theme-icon fw-calendar__theme-icon--dark" viewBox="0 0 24 24" fill="none">
                        <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                </button>

                <div class="fw-calendar__menu-wrapper">
                    <button class="fw-calendar__kebab-toggle" id="kebabToggle" aria-label="Menu">
                        <svg viewBox="0 0 24 24" fill="none">
                            <circle cx="12" cy="5" r="1.5" fill="currentColor"/>
                            <circle cx="12" cy="12" r="1.5" fill="currentColor"/>
                            <circle cx="12" cy="19" r="1.5" fill="currentColor"/>
                        </svg>
                    </button>
                    <nav class="fw-calendar__kebab-menu" id="kebabMenu" aria-hidden="true">
                        <a href="/calendar/" class="fw-calendar__kebab-item">Calendar</a>
                        <a href="/calendar/event_new.php" class="fw-calendar__kebab-item">New Event</a>
                        <a href="/calendar/settings.php" class="fw-calendar__kebab-item">Settings</a>
                    </nav>
                </div>
            </div>
        </header>

        <!-- Main Content -->
        <main class="fw-calendar__main">

            <div class="fw-calendar__page-header">
                <h1 class="fw-calendar__page-title"><?= $isEdit ? 'Edit Event' : 'New Event' ?></h1>
                <p class="fw-calendar__page-subtitle">
                    <?= $isEdit ? 'Update the details of this event' : 'Schedule a meeting, reminder or company event' ?>
                </p>
            </div>

            <div class="fw-calendar__form-container">
                
                <form id="eventForm" class="fw-calendar__event-form">
                    
                    <!-- Basic Info -->
                    <div class="fw-calendar__form-section">
                        <h2 class="fw-calendar__form-section-title">Basic Information</h2>
                        
                        <div class="fw-calendar__form-row">
                            <div class="fw-calendar__form-group">
                                <label class="fw-calendar__label">
                                    Calendar <span class="fw-calendar__required">*</span>
                                </label>
                                <select name="calendar_id" id="calendarId" class="fw-calendar__input" required>
                                    <option value="">Select calendar...</option>
                                    <?php foreach ($calendars as $cal): ?>
                                    <option value="<?= $cal['id'] ?>" 
                                            data-color="<?= htmlspecialchars($cal['color']) ?>"
                                            <?= ($event && $event['calendar_id'] == $cal['id']) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($cal['name']) ?> (<?= ucfirst($cal['calendar_type']) ?>)
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="fw-calendar__form-group">
                                <label class="fw-calendar__label">Event Color</label>
                                <input type="color" 
                                       name="color" 
                                       id="eventColor" 
                                       class="fw-calendar__color-picker"
                                       value="<?= htmlspecialchars($event['color'] ?? '#06b6d4') ?>">
                            </div>
                        </div>

                        <div class="fw-calendar__form-group">
                            <label class="fw-calendar__label">
                                Title <span class="fw-calendar__required">*</span>
                            </label>
                            <input type="text" 
                                   name="title" 
                                   id="eventTitle" 
                                   class="fw-calendar__input"
                                   placeholder="Event title"
                                   value="<?= htmlspecialchars($event['title'] ?? '') ?>"
                                   required>
                        </div>

                        <div class="fw-calendar__form-group">
                            <label class="fw-calendar__label">Description</label>
                            <textarea name="description" 
                                      id="eventDescription" 
                                      class="fw-calendar__textarea"
                                      rows="4"
                                      placeholder="Add event description..."><?= htmlspecialchars($event['description'] ?? '') ?></textarea>
                        </div>

                        <div class="fw-calendar__form-group">
                            <label class="fw-calendar__label">Location</label>
                            <input type="text" 
                                   name="location" 
                                   id="eventLocation" 
                                   class="fw-calendar__input"
                                   placeholder="Add location"
                                   value="<?= htmlspecialchars($event['location'] ?? '') ?>">
                        </div>
                    </div>

                    <!-- Date & Time -->
                    <div class="fw-calendar__form-section">
                        <h2 class="fw-calendar__form-section-title">Date & Time</h2>
                        
                        <div class="fw-calendar__form-group">
                            <label class="fw-calendar__checkbox-wrapper">
                                <input type="checkbox" 
                                       name="all_day" 
                                       id="allDay" 
                                       class="fw-calendar__checkbox"
                                       <?= ($event && $event['all_day']) ? 'checked' : '' ?>>
                                <span>All Day Event</span>
                            </label>
                        </div>

                        <div class="fw-calendar__form-row">
                            <div class="fw-calendar__form-group">
                                <label class="fw-calendar__label">
                                    Start Date <span class="fw-calendar__required">*</span>
                                </label>
                                <input type="date" 
                                       name="start_date" 
                                       id="startDate" 
                                       class="fw-calendar__input"
                                       value="<?= $event ? date('Y-m-d', strtotime($event['start_datetime'])) : date('Y-m-d') ?>"
                                       required>
                            </div>

                            <div class="fw-calendar__form-group" id="startTimeGroup">
                                <label class="fw-calendar__label">
                                    Start Time <span class="fw-calendar__required">*</span>
                                </label>
                                <input type="time" 
                                       name="start_time" 
                                       id="startTime" 
                                       class="fw-calendar__input"
                                       value="<?= $event ? date('H:i', strtotime($event['start_datetime'])) : '09:00' ?>"
                                       required>
                            </div>
                        </div>

                        <div class="fw-calendar__form-row">
                            <div class="fw-calendar__form-group">
                                <label class="fw-calendar__label">
                                    End Date <span class="fw-calendar__required">*</span>
                                </label>
                                <input type="date" 
                                       name="end_date" 
                                       id="endDate" 
                                       class="fw-calendar__input"
                                       value="<?= $event ? date('Y-m-d', strtotime($event['end_datetime'])) : date('Y-m-d') ?>"
                                       required>
                            </div>

                            <div class="fw-calendar__form-group" id="endTimeGroup">
                                <label class="fw-calendar__label">
                                    End Time <span class="fw-calendar__required">*</span>
                                </label>
                                <input type="time" 
                                       name="end_time" 
                                       id="endTime" 
                                       class="fw-calendar__input"
                                       value="<?= $event ? date('H:i', strtotime($event['end_datetime'])) : '10:00' ?>"
                                       required>
                            </div>
                        </div>
                    </div>

                    <!-- Recurrence -->
                    <div class="fw-calendar__form-section">
                        <h2 class="fw-calendar__form-section-title">Recurrence</h2>
                        
                        <div class="fw-calendar__form-group">
                            <label class="fw-calendar__label">Repeat</label>
                            <select name="recurrence_type" id="recurrenceType" class="fw-calendar__input">
                                <option value="">Does not repeat</option>
                                <option value="daily">Daily</option>
                                <option value="weekly">Weekly</option>
                                <option value="monthly">Monthly</option>
                                <option value="yearly">Yearly</option>
                                <option value="custom">Custom...</option>
                            </select>
                        </div>

                        <div id="recurrenceOptions" style="display: none;">
                            <div class="fw-calendar__form-row">
                                <div class="fw-calendar__form-group">
                                    <label class="fw-calendar__label">Repeat every</label>
                                    <div style="display: flex; gap: 8px; align-items: center;">
                                        <input type="number" 
                                               name="recurrence_interval" 
                                               id="recurrenceInterval" 
                                               class="fw-calendar__input"
                                               min="1"
                                               value="1"
                                               style="width: 80px;">
                                        <span id="recurrenceIntervalLabel">day(s)</span>
                                    </div>
                                </div>

                                <div class="fw-calendar__form-group">
                                    <label class="fw-calendar__label">Ends</label>
                                    <select name="recurrence_end_type" id="recurrenceEndType" class="fw-calendar__input">
                                        <option value="never">Never</option>
                                        <option value="on">On date</option>
                                        <option value="after">After occurrences</option>
                                    </select>
                                </div>
                            </div>

                            <div class="fw-calendar__form-group" id="recurrenceEndDateGroup" style="display: none;">
                                <label class="fw-calendar__label">End date</label>
                                <input type="date" 
                                       name="recurrence_end_date" 
                                       id="recurrenceEndDate" 
                                       class="fw-calendar__input">
                            </div>

                            <div class="fw-calendar__form-group" id="recurrenceCountGroup" style="display: none;">
                                <label class="fw-calendar__label">Number of occurrences</label>
                                <input type="number" 
                                       name="recurrence_count" 
                                       id="recurrenceCount" 
                                       class="fw-calendar__input"
                                       min="1"
                                       value="10">
                            </div>
                        </div>
                    </div>

                    <!-- Participants -->
                    <div class="fw-calendar__form-section">
                        <h2 class="fw-calendar__form-section-title">Participants</h2>
                        
                        <div class="fw-calendar__form-group">
                            <label class="fw-calendar__label">Add people</label>
                            <select id="participantSelector" class="fw-calendar__input">
                                <option value="">Select person...</option>
                                <?php foreach ($companyUsers as $u): ?>
                                <option value="<?= $u['id'] ?>" data-email="<?= htmlspecialchars($u['email']) ?>">
                                    <?= htmlspecialchars($u['first_name'] . ' ' . $u['last_name']) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div id="participantsList" class="fw-calendar__participants-list">
                            <!-- Participants will be added here dynamically -->
                        </div>
                    </div>

                    <!-- Reminders -->
                    <div class="fw-calendar__form-section">
                        <h2 class="fw-calendar__form-section-title">Reminders</h2>
                        
                        <div id="remindersList">
                            <!-- Reminders will be added here -->
                        </div>

                        <button type="button" class="fw-calendar__btn fw-calendar__btn--secondary" id="btnAddReminder">
                            + Add Reminder
                        </button>
                    </div>

                    <!-- Privacy & Options -->
                    <div class="fw-calendar__form-section">
                        <h2 class="fw-calendar__form-section-title">Options</h2>
                        
                        <div class="fw-calendar__form-group">
                            <label class="fw-calendar__label">Visibility</label>
                            <select name="visibility" id="visibility" class="fw-calendar__input">
                                <option value="default" <?= ($event && $event['visibility'] === 'default') ? 'selected' : '' ?>>
                                    Default
                                </option>
                                <option value="busy" <?= ($event && $event['visibility'] === 'busy') ? 'selected' : '' ?>>
                                    Show as busy
                                </option>
                                <option value="private" <?= ($event && $event['visibility'] === 'private') ? 'selected' : '' ?>>
                                    Private
                                </option>
                            </select>
                            <small class="fw-calendar__help-text">
                                Private events hide details from other users
                            </small>
                        </div>
                    </div>

                    <!-- Form Actions -->
                    <div class="fw-calendar__form-actions">
                        <a href="<?= $isEdit ? '/calendar/event_view.php?id=' . $eventId : '/calendar/' ?>" 
                           class="fw-calendar__btn fw-calendar__btn--secondary">
                            Cancel
                        </a>
                        <button type="submit" class="fw-calendar__btn fw-calendar__btn--primary" id="btnSubmit">
                            <?= $isEdit ? 'Update Event' : 'Create Event' ?>
                        </button>
                    </div>

                    <div id="formMessage" class="fw-calendar__form-message" style="display: none;"></div>

                </form>

            </div>

        </main>

        <!-- Footer -->
        <footer class="fw-calendar__footer">
            <span>Calendar v<?= ASSET_VERSION ?></span>
            <span id="themeIndicator">Theme: Dark</span>
        </footer>

    </div>

    <script>
        window.EVENT_FORM_CONFIG = {
            isEdit: <?= $isEdit ? 'true' : 'false' ?>,
            eventId: <?= $eventId ? $eventId : 'null' ?>,
            companyId: <?= $companyId ?>,
            userId: <?= $userId ?>,
            participants: <?= $isEdit && isset($participants) ? json_encode($participants) : '[]' ?>,
            reminders: <?= $isEdit && isset($reminders) ? json_encode($reminders) : '[]' ?>,
            companyUsers: <?= json_encode($companyUsers) ?>
        };
    </script>
    <script src="/calendar/assets/calendar.js?v=<?= ASSET_VERSION ?>"></script>
    <script src="/calendar/assets/event_form.js?v=<?= ASSET_VERSION ?>"></script>
</body>
</html>