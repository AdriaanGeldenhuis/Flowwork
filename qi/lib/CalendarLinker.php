<?php
/**
 * CalendarLinker
 * Creates a calendar event and links it to a quote/invoice.
 *
 * Drop-in calls:
 *   require_once __DIR__ . '/../lib/CalendarLinker.php';
 *   CalendarLinker::linkQuote($pdo, (int)$company_id, (int)$quote_id);
 *   CalendarLinker::linkInvoice($pdo, (int)$company_id, (int)$invoice_id);
 */
class CalendarLinker {
    public static function linkQuote(PDO $db, int $companyId, int $quoteId): ?int {
        $q = $db->prepare("SELECT q.quote_number, q.expiry_date, q.company_id, c.id AS calendar_id
                           FROM quotes q
                           LEFT JOIN calendars c ON c.company_id=q.company_id AND c.is_active=1
                           WHERE q.id=? LIMIT 1");
        $q->execute([$quoteId]);
        $row = $q->fetch(PDO::FETCH_ASSOC);
        if (!$row) return null;

        $start = ($row['expiry_date'] ?: date('Y-m-d')) . " 09:00:00";
        $end   = ($row['expiry_date'] ?: date('Y-m-d')) . " 10:00:00";
        $calendarId = $row['calendar_id'] ?: self::createCalendar($db, $companyId, 'Q&I');
        $title = "Quote due: " . $row['quote_number'];

        $evtId = self::createEvent($db, $companyId, $calendarId, $title, null, $start, $end);
        self::link($db, $evtId, 'quote', $quoteId);
        return $evtId;
    }

    public static function linkInvoice(PDO $db, int $companyId, int $invoiceId): ?int {
        $q = $db->prepare("SELECT i.invoice_number, i.due_date, i.company_id, c.id AS calendar_id
                           FROM invoices i
                           LEFT JOIN calendars c ON c.company_id=i.company_id AND c.is_active=1
                           WHERE i.id=? LIMIT 1");
        $q->execute([$invoiceId]);
        $row = $q->fetch(PDO::FETCH_ASSOC);
        if (!$row) return null;

        $start = ($row['due_date'] ?: date('Y-m-d')) . " 09:00:00";
        $end   = ($row['due_date'] ?: date('Y-m-d')) . " 10:00:00";
        $calendarId = $row['calendar_id'] ?: self::createCalendar($db, $companyId, 'Q&I');
        $title = "Invoice due: " . $row['invoice_number'];

        $evtId = self::createEvent($db, $companyId, $calendarId, $title, null, $start, $end);
        self::link($db, $evtId, 'invoice', $invoiceId);
        return $evtId;
    }

    private static function createCalendar(PDO $db, int $companyId, string $name): int {
        $ins = $db->prepare("INSERT INTO calendars (company_id, calendar_type, name, color, is_active) VALUES (?, 'personal', ?, '#06b6d4', 1)");
        $ins->execute([$companyId, $name]);
        return (int)$db->lastInsertId();
    }

    private static function createEvent(PDO $db, int $companyId, int $calendarId, string $title, ?string $description, string $start, string $end): int {
        $ins = $db->prepare("INSERT INTO calendar_events (company_id, calendar_id, title, description, start_datetime, end_datetime, all_day, created_by) VALUES (?, ?, ?, ?, ?, ?, 0, 1)");
        $ins->execute([$companyId, $calendarId, $title, $description, $start, $end]);
        return (int)$db->lastInsertId();
    }

    private static function link(PDO $db, int $eventId, string $type, int $id): void {
        $ins = $db->prepare("INSERT INTO calendar_event_links (event_id, linked_type, linked_id) VALUES (?, ?, ?)");
        $ins->execute([$eventId, $type, $id]);
    }
}
