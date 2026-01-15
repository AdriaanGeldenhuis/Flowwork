<?php
// qi/services/CalendarHook.php
// Handles the creation, update and deletion of calendar events tied to QI documents (quotes and invoices).

class CalendarHook
{
    /**
     * @var PDO
     */
    private $db;

    public function __construct(PDO $pdo)
    {
        $this->db = $pdo;
    }

    /**
     * Retrieve the default calendar ID for a given company.
     * If multiple calendars exist, return the one with the lowest ID.
     *
     * @param int $companyId
     * @return int|null
     */
    private function getDefaultCalendarId($companyId)
    {
        $stmt = $this->db->prepare("SELECT id FROM calendars WHERE company_id = ? ORDER BY id ASC LIMIT 1");
        $stmt->execute([$companyId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? (int)$row['id'] : null;
    }

    /**
     * Create or update a calendar event for a given document (quote or invoice).
     *
     * @param int    $companyId
     * @param int    $userId
     * @param string $linkedType  'quote' or 'invoice'
     * @param int    $linkedId
     * @param string $title       Event title
     * @param string $startDatetime  Datetime string for event start (YYYY-MM-DD HH:MM:SS)
     * @param string $endDatetime    Datetime string for event end (YYYY-MM-DD HH:MM:SS)
     */
    private function upsertEvent($companyId, $userId, $linkedType, $linkedId, $title, $startDatetime, $endDatetime)
    {
        // Determine existing event via calendar_event_links
        $stmt = $this->db->prepare("SELECT event_id FROM calendar_event_links WHERE linked_type = ? AND linked_id = ? LIMIT 1");
        $stmt->execute([$linkedType, $linkedId]);
        $link = $stmt->fetch(PDO::FETCH_ASSOC);

        $calendarId = $this->getDefaultCalendarId($companyId);
        if (!$calendarId) {
            // No calendar to insert into
            return;
        }

        if ($link) {
            // Update existing event
            $eventId = (int)$link['event_id'];
            $stmtUpdate = $this->db->prepare("UPDATE calendar_events SET title = ?, start_datetime = ?, end_datetime = ?, updated_at = NOW() WHERE id = ? AND company_id = ?");
            $stmtUpdate->execute([$title, $startDatetime, $endDatetime, $eventId, $companyId]);
        } else {
            // Insert new event (all_day = 1, no recurrence)
            $stmtInsert = $this->db->prepare("INSERT INTO calendar_events (company_id, calendar_id, title, description, location, lat, lng, color, start_datetime, end_datetime, all_day, recurrence, exdates_json, visibility, created_by, created_at, updated_at) VALUES (?, ?, ?, NULL, NULL, NULL, NULL, NULL, ?, ?, 1, NULL, NULL, 'default', ?, NOW(), NOW())");
            $stmtInsert->execute([$companyId, $calendarId, $title, $startDatetime, $endDatetime, $userId]);
            $eventId = $this->db->lastInsertId();
            // Link the event to the document
            $stmtLink = $this->db->prepare("INSERT INTO calendar_event_links (event_id, linked_type, linked_id, created_at) VALUES (?, ?, ?, NOW())");
            $stmtLink->execute([$eventId, $linkedType, $linkedId]);
        }
    }

    /**
     * Public handler for creating/updating a quote expiry event.
     * If no expiry date provided, any existing event will be removed.
     *
     * @param int    $companyId
     * @param int    $quoteId
     * @param string $quoteNumber
     * @param string|null $expiryDate (YYYY-MM-DD or datetime)
     * @param int    $userId
     */
    public function handleQuoteEvent($companyId, $quoteId, $quoteNumber, $expiryDate, $userId)
    {
        if (empty($expiryDate)) {
            // Remove any existing event
            $this->deleteEvent('quote', $quoteId);
            return;
        }
        // Standardise to date string
        $date = date('Y-m-d', strtotime($expiryDate));
        $start = $date . ' 00:00:00';
        $end   = $start;
        $title = 'Quote ' . $quoteNumber . ' expires';
        $this->upsertEvent($companyId, $userId, 'quote', $quoteId, $title, $start, $end);
    }

    /**
     * Public handler for creating/updating an invoice due date event.
     * If no due date provided, remove existing event.
     *
     * @param int    $companyId
     * @param int    $invoiceId
     * @param string $invoiceNumber
     * @param string|null $dueDate
     * @param int    $userId
     */
    public function handleInvoiceEvent($companyId, $invoiceId, $invoiceNumber, $dueDate, $userId)
    {
        if (empty($dueDate)) {
            $this->deleteEvent('invoice', $invoiceId);
            return;
        }
        $date  = date('Y-m-d', strtotime($dueDate));
        $start = $date . ' 00:00:00';
        $end   = $start;
        $title = 'Invoice ' . $invoiceNumber . ' due';
        $this->upsertEvent($companyId, $userId, 'invoice', $invoiceId, $title, $start, $end);
    }

    /**
     * Delete calendar events associated with the given document.
     *
     * @param string $linkedType 'quote' or 'invoice'
     * @param int    $linkedId
     */
    public function deleteEvent($linkedType, $linkedId)
    {
        // Find linked events
        $stmt = $this->db->prepare("SELECT event_id FROM calendar_event_links WHERE linked_type = ? AND linked_id = ?");
        $stmt->execute([$linkedType, $linkedId]);
        $links = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($links as $link) {
            $eventId = (int)$link['event_id'];
            // Delete event entry
            $delEvent = $this->db->prepare("DELETE FROM calendar_events WHERE id = ?");
            $delEvent->execute([$eventId]);
            // Delete link
            $delLink = $this->db->prepare("DELETE FROM calendar_event_links WHERE linked_type = ? AND linked_id = ?");
            $delLink->execute([$linkedType, $linkedId]);
        }
    }
}