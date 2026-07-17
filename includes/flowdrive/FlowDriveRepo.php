<?php
/**
 * FlowDriveRepo — low-level writer for the FlowDrive (flowdrive.co.za) tables.
 *
 * Flowwork and FlowDrive share one MySQL database (see config.php), so
 * documents are published to "FlowWork Drive" by writing fd_drives / fd_nodes /
 * fd_blobs rows directly — no HTTP call and no shared filesystem is needed.
 * File bytes are stored in fd_blobs.data (deduplicated by sha256); FlowDrive's
 * api/nodes/download.php serves inline blob data as its first strategy, and its
 * listing/tree/search UIs read fd_nodes, so anything written here is
 * immediately visible at flowdrive.co.za/flowworkdrive.php?drive_id=N.
 *
 * Conventions (matched to the FlowDrive UI, not to its unused seed helpers):
 *  - Top-level folders have parent_id NULL — the UI's root listing shows only
 *    parent_id IS NULL rows, so anything hung under the legacy '/' node is
 *    invisible in the main pane.
 *  - fd_nodes.name excludes the extension; ext is stored separately.
 *  - Nodes written here are is_system=1, is_readonly=1 (the drive is
 *    presented read-only; changes must happen in the originating module).
 *
 * Every method is best-effort: failures are logged and reported as null/false,
 * never thrown — publishing to the drive must never break invoicing.
 */
class FlowDriveRepo
{
    /** Files larger than this are not mirrored into fd_blobs.data (keeps well
     *  under typical max_allowed_packet limits on shared hosting). */
    const MAX_BLOB_BYTES = 15728640; // 15 MB

    /** @var bool|null Cached result of the fd_* tables existence check. */
    private static $tablesOk = null;

    /** Last error surfaced by a write — read by the diagnostic. */
    public static $lastError = null;

    /**
     * The fd_* tables live in the same database but belong to the FlowDrive
     * app; skip silently (e.g. in a dev environment restored without them).
     */
    public static function available(PDO $db): bool
    {
        if (self::$tablesOk !== null) {
            return self::$tablesOk;
        }
        try {
            $stmt = $db->query("SHOW TABLES LIKE 'fd_drives'");
            self::$tablesOk = (bool)$stmt->fetchColumn();
        } catch (Throwable $e) {
            error_log('FlowDriveRepo::available: ' . $e->getMessage());
            self::$tablesOk = false;
        }
        return self::$tablesOk;
    }

    /**
     * Serialise drive/folder creation per company. fd_drives has no unique
     * key on (type, subtype, company_id) and fd_nodes' uq_parent_name does
     * not fire for parent_id NULL rows (SQL NULLs never collide), so
     * SELECT-then-INSERT alone can duplicate the drive or the top-level
     * folders under concurrency (e.g. backfill running next to live saves).
     * GET_LOCK is connection-scoped, i.e. a cross-request mutex. On lock
     * failure the callback still runs — publishing stays best-effort.
     */
    public static function withCompanyLock(PDO $db, int $companyId, callable $fn)
    {
        $lockName = 'fw_flowdrive_pub_' . $companyId;
        $locked = false;
        try {
            $stmt = $db->prepare("SELECT GET_LOCK(?, 10)");
            $stmt->execute([$lockName]);
            $locked = ((int)$stmt->fetchColumn() === 1);
        } catch (Throwable $e) {
            error_log('FlowDriveRepo::withCompanyLock acquire: ' . $e->getMessage());
        }
        try {
            return $fn();
        } finally {
            if ($locked) {
                try {
                    $stmt = $db->prepare("SELECT RELEASE_LOCK(?)");
                    $stmt->execute([$lockName]);
                } catch (Throwable $e) {
                    error_log('FlowDriveRepo::withCompanyLock release: ' . $e->getMessage());
                }
            }
        }
    }

    /**
     * Find or create the company's "FlowWork Drive" (type=company,
     * subtype=flowwork). Returns the drive id, or null on failure.
     */
    public static function ensureDrive(PDO $db, int $companyId): ?int
    {
        try {
            $stmt = $db->prepare(
                "SELECT id FROM fd_drives
                  WHERE type = 'company' AND subtype = 'flowwork' AND company_id = ?
                  ORDER BY id LIMIT 1"
            );
            $stmt->execute([$companyId]);
            $driveId = $stmt->fetchColumn();
            if ($driveId) {
                return (int)$driveId;
            }

            // Mirror fw_get_flowwork_drive() in the FlowDrive repo: drive row,
            // '/' root node and a company-wide read-only permission. disk_path
            // is metadata only — bytes live in fd_blobs.data.
            $diskPath = "/storage/{$companyId}/flowwork/";
            $stmt = $db->prepare(
                "INSERT INTO fd_drives (type, subtype, company_id, name, disk_path, created_at)
                 VALUES ('company', 'flowwork', ?, 'FlowWork Drive', ?, NOW())"
            );
            $stmt->execute([$companyId, $diskPath]);
            $driveId = (int)$db->lastInsertId();

            $stmt = $db->prepare(
                "INSERT INTO fd_nodes (drive_id, parent_id, type, name, disk_path, is_system, is_readonly, created_at)
                 VALUES (?, NULL, 'folder', '/', ?, 1, 1, NOW())"
            );
            $stmt->execute([$driveId, $diskPath]);
            $rootId = (int)$db->lastInsertId();

            $stmt = $db->prepare(
                "INSERT INTO fd_permissions (node_id, subject_type, subject_id, can_read, can_write, can_share, created_at)
                 VALUES (?, 'company', ?, 1, 0, 0, NOW())"
            );
            $stmt->execute([$rootId, $companyId]);

            return $driveId;
        } catch (Throwable $e) {
            self::$lastError = 'ensureDrive: ' . $e->getMessage() . ' [' . basename($e->getFile()) . ':' . $e->getLine() . ']';
            error_log('FlowDriveRepo::' . self::$lastError);
            return null;
        }
    }

    /**
     * Find or create a folder. $parentId NULL means drive root (visible
     * top level). Restores the folder if it was soft-deleted. Returns the
     * node id, or null on failure.
     */
    public static function ensureFolder(PDO $db, int $driveId, ?int $parentId, string $name, ?array $meta = null): ?int
    {
        try {
            $stmt = $db->prepare(
                "SELECT id, deleted_at FROM fd_nodes
                  WHERE drive_id = ? AND parent_id <=> ? AND name = ? AND type = 'folder'
                  ORDER BY id LIMIT 1"
            );
            $stmt->execute([$driveId, $parentId, $name]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                if ($row['deleted_at'] !== null) {
                    $db->prepare("UPDATE fd_nodes SET deleted_at = NULL, updated_at = NOW() WHERE id = ?")
                       ->execute([(int)$row['id']]);
                }
                return (int)$row['id'];
            }

            $stmt = $db->prepare(
                "INSERT INTO fd_nodes (drive_id, parent_id, type, name, is_system, is_readonly, meta_json, created_at)
                 VALUES (?, ?, 'folder', ?, 1, 1, ?, NOW())"
            );
            $stmt->execute([
                $driveId,
                $parentId,
                $name,
                $meta !== null ? json_encode($meta, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null,
            ]);
            return (int)$db->lastInsertId();
        } catch (Throwable $e) {
            // Unique-key race (uq_parent_name): another request created it — reuse.
            try {
                $stmt = $db->prepare(
                    "SELECT id FROM fd_nodes
                      WHERE drive_id = ? AND parent_id <=> ? AND name = ? AND type = 'folder'
                      ORDER BY id LIMIT 1"
                );
                $stmt->execute([$driveId, $parentId, $name]);
                $id = $stmt->fetchColumn();
                if ($id) {
                    return (int)$id;
                }
            } catch (Throwable $e2) {
                // fall through to log
            }
            error_log('FlowDriveRepo::ensureFolder: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * List immediate child folders (id, name, meta_json) of a parent.
     * Includes soft-deleted rows so callers can restore/reuse them.
     */
    public static function childFolders(PDO $db, int $driveId, ?int $parentId): array
    {
        try {
            $stmt = $db->prepare(
                "SELECT id, name, meta_json, deleted_at FROM fd_nodes
                  WHERE drive_id = ? AND parent_id <=> ? AND type = 'folder'"
            );
            $stmt->execute([$driveId, $parentId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            error_log('FlowDriveRepo::childFolders: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Create or replace a file node. Blob bytes are deduplicated by sha256;
     * replacing content snapshots the previous blob into fd_node_versions so
     * the drive UI's version history works. Returns the node id or null.
     */
    public static function putFile(
        PDO $db,
        int $driveId,
        ?int $parentId,
        string $filename,
        string $bytes,
        string $mime,
        ?int $userId = null,
        ?array $meta = null
    ): ?int {
        if (strlen($bytes) === 0 || strlen($bytes) > self::MAX_BLOB_BYTES) {
            error_log('FlowDriveRepo::putFile: skipped "' . $filename . '" (' . strlen($bytes) . ' bytes)');
            return null;
        }
        try {
            $dot  = strrpos($filename, '.');
            $name = $dot === false ? $filename : substr($filename, 0, $dot);
            $ext  = $dot === false ? '' : strtolower(substr($filename, $dot + 1));

            $sha  = hash('sha256', $bytes);
            $size = strlen($bytes);

            // Blob upsert: sha256 is UNIQUE. On duplicate, reuse the existing
            // row and backfill data if that row was disk-based (data NULL).
            $stmt = $db->prepare(
                "INSERT INTO fd_blobs (sha256, size_bytes, data, mime_type, storage_path, created_at)
                 VALUES (?, ?, ?, ?, '', NOW())
                 ON DUPLICATE KEY UPDATE
                     id = LAST_INSERT_ID(id),
                     data = COALESCE(data, VALUES(data))"
            );
            $stmt->bindValue(1, $sha);
            $stmt->bindValue(2, $size, PDO::PARAM_INT);
            $stmt->bindValue(3, $bytes, PDO::PARAM_LOB);
            $stmt->bindValue(4, $mime);
            $stmt->execute();
            $blobId = (int)$db->lastInsertId();

            $metaJson = $meta !== null ? json_encode($meta, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null;

            // Node upsert on (drive, parent, name, ext) — the table's unique
            // key includes soft-deleted rows, so match those too and restore.
            $stmt = $db->prepare(
                "SELECT id, blob_id, size_bytes, deleted_at, updated_at, created_at
                   FROM fd_nodes
                  WHERE drive_id = ? AND parent_id <=> ? AND name = ? AND ext = ? AND type = 'file'
                  ORDER BY id LIMIT 1"
            );
            $stmt->execute([$driveId, $parentId, $name, $ext]);
            $node = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($node) {
                $nodeId = (int)$node['id'];
                $contentChanged = (int)$node['blob_id'] !== $blobId;
                if ($contentChanged) {
                    self::recordVersion($db, $nodeId, $node);
                }
                if ($contentChanged || $node['deleted_at'] !== null) {
                    $upd = $db->prepare(
                        "UPDATE fd_nodes
                            SET blob_id = ?, size_bytes = ?, deleted_at = NULL,
                                updated_by = ?, updated_at = NOW(),
                                meta_json = COALESCE(?, meta_json)
                          WHERE id = ?"
                    );
                    $upd->execute([$blobId, $size, $userId, $metaJson, $nodeId]);
                }
                return $nodeId;
            }

            $stmt = $db->prepare(
                "INSERT INTO fd_nodes (drive_id, parent_id, type, name, ext, blob_id, size_bytes,
                                       is_system, is_readonly, created_by, meta_json, created_at)
                 VALUES (?, ?, 'file', ?, ?, ?, ?, 1, 1, ?, ?, NOW())"
            );
            $stmt->execute([$driveId, $parentId, $name, $ext, $blobId, $size, $userId, $metaJson]);
            $nodeId = (int)$db->lastInsertId();

            self::audit($db, $driveId, $nodeId, 'flowwork_generated', ['filename' => $filename], $userId);
            return $nodeId;
        } catch (Throwable $e) {
            self::$lastError = 'putFile("' . $filename . '"): ' . $e->getMessage() . ' [' . basename($e->getFile()) . ':' . $e->getLine() . ']';
            error_log('FlowDriveRepo::' . self::$lastError);
            return null;
        }
    }

    /**
     * Soft-delete a file node by its location (used when the source document
     * is deleted in Flowwork). Best-effort.
     */
    public static function removeFile(PDO $db, int $driveId, ?int $parentId, string $filename): void
    {
        try {
            $dot  = strrpos($filename, '.');
            $name = $dot === false ? $filename : substr($filename, 0, $dot);
            $ext  = $dot === false ? '' : strtolower(substr($filename, $dot + 1));
            $stmt = $db->prepare(
                "UPDATE fd_nodes SET deleted_at = NOW()
                  WHERE drive_id = ? AND parent_id <=> ? AND name = ? AND ext = ?
                    AND type = 'file' AND deleted_at IS NULL"
            );
            $stmt->execute([$driveId, $parentId, $name, $ext]);
        } catch (Throwable $e) {
            error_log('FlowDriveRepo::removeFile: ' . $e->getMessage());
        }
    }

    /** Snapshot a node's current blob into fd_node_versions (best-effort). */
    private static function recordVersion(PDO $db, int $nodeId, array $node): void
    {
        try {
            if (empty($node['blob_id'])) {
                return;
            }
            $stmt = $db->prepare("SELECT COALESCE(MAX(version_no), 0) + 1 FROM fd_node_versions WHERE node_id = ?");
            $stmt->execute([$nodeId]);
            $versionNo = (int)$stmt->fetchColumn();

            $createdAt = $node['updated_at'] ?: ($node['created_at'] ?: null);
            $stmt = $db->prepare(
                "INSERT INTO fd_node_versions (node_id, version_no, blob_id, size_bytes, created_by, created_at)
                 VALUES (?, ?, ?, ?, NULL, COALESCE(?, NOW()))"
            );
            $stmt->execute([$nodeId, $versionNo, (int)$node['blob_id'], (int)$node['size_bytes'], $createdAt]);
        } catch (Throwable $e) {
            error_log('FlowDriveRepo::recordVersion: ' . $e->getMessage());
        }
    }

    /** Write an fd_audit row (best-effort; company derived from the drive). */
    private static function audit(PDO $db, int $driveId, int $nodeId, string $action, array $meta, ?int $userId): void
    {
        try {
            $stmt = $db->prepare("SELECT company_id FROM fd_drives WHERE id = ? LIMIT 1");
            $stmt->execute([$driveId]);
            $companyId = $stmt->fetchColumn();
            if ($companyId === false) {
                return;
            }
            $stmt = $db->prepare(
                "INSERT INTO fd_audit (user_id, company_id, drive_id, node_id, action, ip_address, user_agent, meta, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, NULL, ?, NOW())"
            );
            $stmt->execute([
                $userId,
                (int)$companyId,
                $driveId,
                $nodeId,
                $action,
                $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0',
                json_encode($meta, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ]);
        } catch (Throwable $e) {
            error_log('FlowDriveRepo::audit: ' . $e->getMessage());
        }
    }
}
