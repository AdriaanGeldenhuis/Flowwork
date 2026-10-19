<?php
// /finances/api/files.php — supporting documents (see FinFiles).
//   GET  ?id=                       download (inline for PDF and images)
//   GET  ?entity=&entity_id=         list
//   POST multipart {entity, entity_id, file}   upload (writers)
//   POST {op: remove, id}                       remove (writers; not once the document is in the books)
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../lib/FinFiles.php';

if (!fin_is_post()) {
    if (isset($_GET['id'])) {
        $f = FinFiles::open($DB, $FIN_COMPANY_ID, fin_int($_GET['id']));
        $inline = preg_match('#^(application/pdf|image/(png|jpeg|webp|gif))$#', $f['mime']) && empty($_GET['download']);
        header_remove('Content-Type');
        header('Content-Type: ' . $f['mime']);
        header('Content-Length: ' . filesize($f['path']));
        header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . str_replace(['"', "\\"], '', $f['name']) . '"; filename*=UTF-8\'\'' . rawurlencode($f['name']));
        header("Content-Security-Policy: default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; sandbox");
        header('Cache-Control: private, max-age=300');
        readfile($f['path']);
        exit;
    }
    fin_json(['files' => FinFiles::list($DB, $FIN_COMPANY_ID, fin_str($_GET['entity'] ?? '', 20), fin_int($_GET['entity_id'] ?? 0))]);
}

fin_require_writer();
if (fin_op() === 'remove') {
    FinFiles::remove($DB, $FIN_COMPANY_ID, $FIN_USER_ID, fin_int(fin_input()['id'] ?? 0));
    fin_json(['removed' => true]);
}
fin_rate_limit('upload', 30, 300);
$entity = fin_str($_POST['entity'] ?? '', 20);
$entityId = fin_int($_POST['entity_id'] ?? 0);
if (empty($_FILES['file'])) throw new FinUserError('Choose a file to upload.');
$file = FinFiles::store($DB, $FIN_COMPANY_ID, $FIN_USER_ID, $entity, $entityId, $_FILES['file']);
fin_json(['file' => $file, 'files' => FinFiles::list($DB, $FIN_COMPANY_ID, $entity, $entityId)]);
