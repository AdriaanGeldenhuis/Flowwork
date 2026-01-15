<?php
// mail/lib/RulesEngine.php
class RulesEngine {
  public static function detectIntent(string $subject, string $body): string {
    $hay = strtolower($subject.' '.$body);
    if (preg_match('/\brfq\b|request for quotation|quote needed/i', $hay)) return 'rfq';
    if (preg_match('/\binvoice\b|inv[\d-]+|statement/i', $hay)) return 'invoice_query';
    if (preg_match('/proof of payment|pop\b|eft|bank confirmation/i', $hay)) return 'payment_proof';
    return 'general';
  }
  public static function applyToMessage(PDO $DB, int $emailId, int $companyId): void {
    // Fetch subject/body
    $stmt = $DB->prepare("SELECT subject, body_text FROM emails WHERE email_id=? AND company_id=?");
    $stmt->execute([$emailId, $companyId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) return;
    $intent = self::detectIntent($row['subject'] ?? '', $row['body_text'] ?? '');

    // Map intent to tag
    $tagName = null;
    if ($intent === 'rfq') $tagName = 'RFQ';
    elseif ($intent === 'invoice_query') $tagName = 'Invoice';
    elseif ($intent === 'payment_proof') $tagName = 'Payment Proof';

    if ($tagName) {
      // Find tag id (company 0 = global)
      $stmt = $DB->prepare("SELECT id FROM email_tags WHERE (company_id=? OR company_id=0) AND name=? ORDER BY company_id DESC LIMIT 1");
      $stmt->execute([$companyId, $tagName]);
      $tagId = ($stmt->fetchColumn()) ?: 0;
      if ($tagId) {
        $DB->prepare("INSERT IGNORE INTO email_tag_map (email_id, tag_id) VALUES (?,?)")->execute([$emailId, $tagId]);
      }
    }
  }
}