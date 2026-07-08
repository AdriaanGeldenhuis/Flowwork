<?php
// finances/lib/JournalSource.php
//
// Maps a journal's ref/source type to a link back to the originating source
// document (invoice, bill, vendor credit, asset). Returns
//   ['url' => ..., 'label' => ...]
// or null when there is no reliable per-record viewer for that type — most
// system journals (bank_tx, payroll, depreciation, vat_*) only have list
// pages with no deep-link param, so linking them would produce a dead link.
//
// Values come from the posting engine (PostingService), the AR/AP flows and
// the fixed-asset disposal path — see the ref_type/source_type literals there.
//
// Each mapping also declares the roles that may OPEN the target viewer, so the
// resolver can suppress a link the current user could not follow (the Fixed
// Assets module is admin/bookkeeper-only, whereas the AR/AP viewers allow the
// viewer role). This keeps the link honest instead of redirecting to
// access_denied on click.

if (!function_exists('journal_source_link')) {
    /**
     * @param string|null $type A ref_type or source_type value.
     * @param mixed       $id   The matching ref_id / source_id.
     * @return array|null ['url' => string, 'label' => string, 'roles' => string[]] or null.
     */
    function journal_source_link(?string $type, $id): ?array
    {
        $id = (int)$id;
        if ($type === null || $type === '' || $id <= 0) {
            return null;
        }

        $viewerOk = ['admin', 'bookkeeper', 'viewer'];

        switch ($type) {
            case 'invoice':
            case 'invoice_write_off':
                return ['url' => '/finances/ar/invoice_view.php?id=' . $id, 'label' => 'View Invoice', 'roles' => $viewerOk];

            case 'ap_bill':
                return ['url' => '/finances/ap/bill_view.php?id=' . $id, 'label' => 'View Supplier Bill', 'roles' => $viewerOk];

            case 'vendor_credit':
                return ['url' => '/finances/ap/vendor_credit_view.php?id=' . $id, 'label' => 'View Vendor Credit', 'roles' => $viewerOk];

            case 'fa_disposal':
                // The Fixed Assets module is admin/bookkeeper-only.
                return ['url' => '/finances/fa/asset_view.php?aid=' . $id, 'label' => 'View Asset', 'roles' => ['admin', 'bookkeeper']];

            default:
                return null;
        }
    }

    /**
     * Resolve the best source link for a journal header row. Prefers the
     * ref_type/ref_id pair (set by the posting engine) and falls back to
     * source_type/source_id (e.g. QI invoices posted via receipts, which set
     * only the source_* columns).
     *
     * @param array       $journal Journal header row.
     * @param string|null $role    Current user's role; when supplied, a link
     *                             whose target the role cannot open is dropped.
     * @return array|null ['url' => string, 'label' => string]
     */
    function journal_resolve_source_link(array $journal, ?string $role = null): ?array
    {
        $link = journal_source_link($journal['ref_type'] ?? null, $journal['ref_id'] ?? null);
        if (!$link) {
            $link = journal_source_link($journal['source_type'] ?? null, $journal['source_id'] ?? null);
        }
        if (!$link) {
            return null;
        }

        // Suppress the link if the current user could not open the target
        // (admin bypasses the check, matching requireRoles semantics).
        if ($role !== null && $role !== 'admin'
            && isset($link['roles']) && !in_array($role, $link['roles'], true)) {
            return null;
        }

        unset($link['roles']); // internal only — not part of the client payload
        return $link;
    }
}
