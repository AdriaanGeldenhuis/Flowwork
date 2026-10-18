<?php
// qi/services/CalendarHook.php
// Keeps the calendar in step with quotes and invoices.
//
// Invoice due dates and quote expiry dates are shown on the calendar live
// (calendar/lib/CalendarSources.php): unpaid invoices on their due date,
// sent quotes on their expiry date, always matching the document as it is
// now. This hook used to copy those dates into calendar events — into the
// company's lowest-numbered calendar, often someone's private one — and the
// copies went stale or duplicated. It now removes any such copy when a
// document is saved, converted or deleted. Events people created themselves
// and linked to a document are never touched.

require_once __DIR__ . '/../../calendar/lib/autoload.php';

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
     * Called after a quote is saved or duplicated.
     *
     * @param int         $companyId
     * @param int         $quoteId
     * @param string      $quoteNumber
     * @param string|null $expiryDate
     * @param int         $userId
     */
    public function handleQuoteEvent($companyId, $quoteId, $quoteNumber, $expiryDate, $userId)
    {
        CalendarLink::removeMirrors($this->db, (int)$companyId, 'quote', (int)$quoteId);
    }

    /**
     * Called after an invoice is saved or created from a quote.
     *
     * @param int         $companyId
     * @param int         $invoiceId
     * @param string      $invoiceNumber
     * @param string|null $dueDate
     * @param int         $userId
     */
    public function handleInvoiceEvent($companyId, $invoiceId, $invoiceNumber, $dueDate, $userId)
    {
        CalendarLink::removeMirrors($this->db, (int)$companyId, 'invoice', (int)$invoiceId);
    }

    /**
     * Called when a quote or invoice is deleted.
     *
     * @param string $linkedType 'quote' or 'invoice'
     * @param int    $linkedId
     */
    public function deleteEvent($linkedType, $linkedId)
    {
        if (!in_array($linkedType, ['quote', 'invoice'], true)) return;
        $companyId = (int)($_SESSION['company_id'] ?? 0);
        if ($companyId <= 0) {
            $table = $linkedType === 'quote' ? 'quotes' : 'invoices';
            $st = $this->db->prepare("SELECT company_id FROM `$table` WHERE id = ?");
            $st->execute([(int)$linkedId]);
            $companyId = (int)$st->fetchColumn();
        }
        if ($companyId > 0) CalendarLink::removeMirrors($this->db, $companyId, $linkedType, (int)$linkedId);
    }
}
