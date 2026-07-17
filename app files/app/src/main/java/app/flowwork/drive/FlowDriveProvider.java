package app.flowwork.drive;

import android.app.Notification;
import android.app.NotificationChannel;
import android.app.NotificationManager;
import android.content.Context;
import android.content.res.AssetFileDescriptor;
import android.database.Cursor;
import android.database.MatrixCursor;
import android.graphics.Point;
import android.os.Build;
import android.os.CancellationSignal;
import android.os.Handler;
import android.os.HandlerThread;
import android.os.Looper;
import android.os.ParcelFileDescriptor;
import android.provider.DocumentsContract.Document;
import android.provider.DocumentsContract.Root;
import android.provider.DocumentsContract;
import android.provider.DocumentsProvider;
import android.webkit.MimeTypeMap;
import android.widget.Toast;

import java.io.File;
import java.io.FileNotFoundException;
import java.io.IOException;
import java.util.List;

/**
 * Exposes FlowDrive (the company's WebDAV server, dav.php) to Android's
 * Storage Access Framework: after signing in once in the FlowDrive setup
 * screen, "FlowDrive" appears as a browsable location in the Files app and
 * in every app's file picker - like a network drive folder on Windows.
 *
 * Document IDs are the decoded DAV paths with a leading slash:
 *   "/"                          the FlowDrive root
 *   "/My Drive"                  a drive
 *   "/My Drive/Reports/q1.pdf"   a file
 */
public class FlowDriveProvider extends DocumentsProvider {

    public static final String AUTHORITY = "app.flowwork.drive.documents";
    public static final String ROOT_ID = "flowdrive";
    private static final String ROOT_DOC_ID = "/";

    /** The server exposes this drive read-only. */
    private static final String READONLY_DRIVE = "FlowWork Drive";

    private static final String[] ROOT_PROJECTION = new String[]{
            Root.COLUMN_ROOT_ID, Root.COLUMN_FLAGS, Root.COLUMN_ICON,
            Root.COLUMN_TITLE, Root.COLUMN_SUMMARY, Root.COLUMN_DOCUMENT_ID,
    };

    private static final String[] DOC_PROJECTION = new String[]{
            Document.COLUMN_DOCUMENT_ID, Document.COLUMN_DISPLAY_NAME,
            Document.COLUMN_MIME_TYPE, Document.COLUMN_SIZE,
            Document.COLUMN_LAST_MODIFIED, Document.COLUMN_FLAGS,
    };

    private HandlerThread ioThread;
    private Handler ioHandler;
    private Handler mainHandler;

    @Override
    public boolean onCreate() {
        ioThread = new HandlerThread("flowdrive-io");
        ioThread.start();
        ioHandler = new Handler(ioThread.getLooper());
        mainHandler = new Handler(Looper.getMainLooper());
        // Uploads that could not be delivered before the process died are
        // kept on disk - retry them shortly after startup.
        ioHandler.postDelayed(new Runnable() {
            @Override
            public void run() {
                retryAllPendingUploads();
            }
        }, 15_000L);
        return true;
    }

    // ------------------------------------------------------------------
    // Roots
    // ------------------------------------------------------------------

    @Override
    public Cursor queryRoots(String[] projection) {
        MatrixCursor cursor = new MatrixCursor(projection != null ? projection : ROOT_PROJECTION);
        Context ctx = getContext();
        if (ctx == null || !DriveStore.isConfigured(ctx)) {
            return cursor; // not signed in yet -> no root shown
        }
        MatrixCursor.RowBuilder row = cursor.newRow();
        row.add(Root.COLUMN_ROOT_ID, ROOT_ID);
        row.add(Root.COLUMN_FLAGS,
                Root.FLAG_SUPPORTS_CREATE | Root.FLAG_SUPPORTS_IS_CHILD);
        row.add(Root.COLUMN_ICON, app.flowwork.R.mipmap.ic_launcher);
        row.add(Root.COLUMN_TITLE, "FlowDrive");
        row.add(Root.COLUMN_SUMMARY, DriveStore.getEmail(ctx));
        row.add(Root.COLUMN_DOCUMENT_ID, ROOT_DOC_ID);
        return cursor;
    }

    // ------------------------------------------------------------------
    // Documents
    // ------------------------------------------------------------------

    @Override
    public Cursor queryDocument(String documentId, String[] projection)
            throws FileNotFoundException {
        requireSafeDocId(documentId);
        MatrixCursor cursor = new MatrixCursor(projection != null ? projection : DOC_PROJECTION);
        if (ROOT_DOC_ID.equals(documentId)) {
            MatrixCursor.RowBuilder row = cursor.newRow();
            row.add(Document.COLUMN_DOCUMENT_ID, ROOT_DOC_ID);
            row.add(Document.COLUMN_DISPLAY_NAME, "FlowDrive");
            row.add(Document.COLUMN_MIME_TYPE, Document.MIME_TYPE_DIR);
            row.add(Document.COLUMN_SIZE, 0L);
            row.add(Document.COLUMN_LAST_MODIFIED, null);
            row.add(Document.COLUMN_FLAGS, 0);
            return cursor;
        }
        DavEntry entry = statOrThrow(documentId);
        addEntryRow(cursor, entry);
        return cursor;
    }

    @Override
    public Cursor queryChildDocuments(String parentDocumentId, String[] projection,
            String sortOrder) throws FileNotFoundException {
        requireSafeDocId(parentDocumentId);
        MatrixCursor cursor = new MatrixCursor(projection != null ? projection : DOC_PROJECTION);
        String parentPath = toDavPath(parentDocumentId);
        try {
            List<DavEntry> entries = client().list(parentPath);
            for (DavEntry entry : entries) {
                // PROPFIND Depth:1 includes the parent itself - skip it.
                if (entry.path.equals(parentPath)) {
                    continue;
                }
                addEntryRow(cursor, entry);
            }
        } catch (IOException e) {
            throw asFileNotFound(e);
        }
        return cursor;
    }

    @Override
    public boolean isChildDocument(String parentDocumentId, String documentId) {
        // Reject dot segments here: OkHttp would collapse "/My Drive/../X"
        // client-side, silently escaping the granted subtree before the
        // server's own ".." guard could ever see it.
        if (!isSafeDocId(parentDocumentId) || !isSafeDocId(documentId)) {
            return false;
        }
        String parent = parentDocumentId.endsWith("/") ? parentDocumentId : parentDocumentId + "/";
        return documentId.startsWith(parent);
    }

    /** True when the docId contains no ".", "..", empty or control segments. */
    private static boolean isSafeDocId(String documentId) {
        if (documentId == null || documentId.isEmpty() || documentId.indexOf('\\') >= 0) {
            return false;
        }
        if (ROOT_DOC_ID.equals(documentId)) {
            return true;
        }
        String p = documentId;
        while (p.startsWith("/")) {
            p = p.substring(1);
        }
        while (p.endsWith("/")) {
            p = p.substring(0, p.length() - 1);
        }
        if (p.isEmpty()) {
            return false;
        }
        for (String seg : p.split("/", -1)) {
            if (seg.isEmpty() || seg.equals(".") || seg.equals("..")) {
                return false;
            }
            for (int i = 0; i < seg.length(); i++) {
                if (seg.charAt(i) < 0x20) {
                    return false;
                }
            }
        }
        return true;
    }

    /** Refuses crafted document IDs at every provider entry point. */
    private static String requireSafeDocId(String documentId) throws FileNotFoundException {
        if (!isSafeDocId(documentId)) {
            throw new FileNotFoundException("Invalid FlowDrive path");
        }
        return documentId;
    }

    @Override
    public String getDocumentType(String documentId) throws FileNotFoundException {
        requireSafeDocId(documentId);
        if (ROOT_DOC_ID.equals(documentId)) {
            return Document.MIME_TYPE_DIR;
        }
        DavEntry entry = statOrThrow(documentId);
        return mimeFor(entry);
    }

    // ------------------------------------------------------------------
    // Content
    // ------------------------------------------------------------------

    @Override
    public ParcelFileDescriptor openDocument(final String documentId, String mode,
            CancellationSignal signal) throws FileNotFoundException {
        requireSafeDocId(documentId);
        final String davPath = toDavPath(documentId);
        int pfdMode;
        try {
            pfdMode = ParcelFileDescriptor.parseMode(mode);
        } catch (IllegalArgumentException e) {
            throw new FileNotFoundException("Unsupported open mode: " + mode);
        }
        boolean wantsWrite = (pfdMode & ParcelFileDescriptor.MODE_WRITE_ONLY) != 0
                || (pfdMode & ParcelFileDescriptor.MODE_READ_WRITE) != 0;

        File dir = new File(cacheDir(), "dav");
        //noinspection ResultOfMethodCallIgnored
        dir.mkdirs();
        final File tmp = new File(dir,
                "doc-" + System.nanoTime() + "-" + Thread.currentThread().getId());

        try {
            // Pre-fill with current content unless the caller truncates anyway.
            boolean truncates = mode.contains("t") || mode.equals("w");
            if (!wantsWrite || !truncates) {
                DavEntry entry = client().stat(davPath);
                if (entry != null && !entry.directory) {
                    client().download(davPath, tmp, null);
                } else if (entry == null && !wantsWrite) {
                    throw new FileNotFoundException("Not found on FlowDrive: " + documentId);
                }
            }

            if (!wantsWrite) {
                ParcelFileDescriptor pfd =
                        ParcelFileDescriptor.open(tmp, ParcelFileDescriptor.MODE_READ_ONLY);
                // The kernel keeps the unlinked file readable via the fd;
                // deleting now means no cache cleanup debt later.
                //noinspection ResultOfMethodCallIgnored
                tmp.delete();
                return pfd;
            }

            final String contentType = mimeGuess(nameOf(documentId));
            return ParcelFileDescriptor.open(tmp,
                    pfdMode | ParcelFileDescriptor.MODE_CREATE,
                    ioHandler,
                    new ParcelFileDescriptor.OnCloseListener() {
                        @Override
                        public void onClose(IOException e) {
                            if (e != null) {
                                // The writing app failed/crashed before a clean
                                // close; its content is not trustworthy.
                                //noinspection ResultOfMethodCallIgnored
                                tmp.delete();
                                return;
                            }
                            // Park the edit on disk first so it survives a
                            // failed upload or process death, then send it.
                            File pending = persistPendingUpload(davPath, contentType, tmp);
                            if (pending != null) {
                                attemptPendingUpload(pending, 0);
                            } else {
                                // Could not persist - upload directly as a
                                // last resort so the edit still has a chance.
                                try {
                                    client().put(davPath, tmp, contentType);
                                    notifyChildrenChanged(parentOf(documentId));
                                } catch (IOException uploadError) {
                                    noteIfAuthFailure(uploadError);
                                    notifyUser("FlowDrive upload failed",
                                            nameOf(documentId) + ": " + uploadError.getMessage());
                                } finally {
                                    //noinspection ResultOfMethodCallIgnored
                                    tmp.delete();
                                }
                            }
                        }
                    });
        } catch (FileNotFoundException e) {
            //noinspection ResultOfMethodCallIgnored
            tmp.delete();
            throw e;
        } catch (IOException e) {
            //noinspection ResultOfMethodCallIgnored
            tmp.delete();
            throw asFileNotFound(e);
        }
    }

    @Override
    public AssetFileDescriptor openDocumentThumbnail(String documentId, Point sizeHint,
            CancellationSignal signal) throws FileNotFoundException {
        // No server-side thumbnails; the Files app falls back to type icons.
        throw new FileNotFoundException("No thumbnail");
    }

    // ------------------------------------------------------------------
    // Mutations
    // ------------------------------------------------------------------

    @Override
    public String createDocument(String parentDocumentId, String mimeType, String displayName)
            throws FileNotFoundException {
        requireSafeDocId(parentDocumentId);
        String name = sanitizeName(displayName);
        try {
            String candidate = uniqueName(toDavPath(parentDocumentId), name);
            String childDocId = childDocId(parentDocumentId, candidate);
            String davPath = toDavPath(childDocId);
            if (Document.MIME_TYPE_DIR.equals(mimeType)) {
                client().mkcol(davPath);
            } else {
                client().putEmpty(davPath);
            }
            notifyChildrenChanged(parentDocumentId);
            return childDocId;
        } catch (IOException e) {
            throw asFileNotFound(e);
        }
    }

    @Override
    public void deleteDocument(String documentId) throws FileNotFoundException {
        requireSafeDocId(documentId);
        try {
            DavEntry entry = statOrThrow(documentId);
            client().delete(toDavPath(documentId), entry.directory);
            revokeDocumentPermission(documentId);
            notifyChildrenChanged(parentOf(documentId));
        } catch (IOException e) {
            throw asFileNotFound(e);
        }
    }

    @Override
    public String renameDocument(String documentId, String displayName)
            throws FileNotFoundException {
        requireSafeDocId(documentId);
        String name = sanitizeName(displayName);
        try {
            DavEntry entry = statOrThrow(documentId);
            String parent = parentOf(documentId);
            String candidate = uniqueName(toDavPath(parent), name);
            String newDocId = childDocId(parent, candidate);
            client().move(toDavPath(documentId), toDavPath(newDocId), entry.directory);
            notifyChildrenChanged(parent);
            return newDocId;
        } catch (IOException e) {
            throw asFileNotFound(e);
        }
    }

    @Override
    public String moveDocument(String sourceDocumentId, String sourceParentDocumentId,
            String targetParentDocumentId) throws FileNotFoundException {
        requireSafeDocId(sourceDocumentId);
        requireSafeDocId(targetParentDocumentId);
        try {
            DavEntry entry = statOrThrow(sourceDocumentId);
            String candidate = uniqueName(toDavPath(targetParentDocumentId), nameOf(sourceDocumentId));
            String newDocId = childDocId(targetParentDocumentId, candidate);
            client().move(toDavPath(sourceDocumentId), toDavPath(newDocId), entry.directory);
            revokeDocumentPermission(sourceDocumentId);
            notifyChildrenChanged(sourceParentDocumentId);
            notifyChildrenChanged(targetParentDocumentId);
            return newDocId;
        } catch (IOException e) {
            throw asFileNotFound(e);
        }
    }

    @Override
    public String copyDocument(String sourceDocumentId, String targetParentDocumentId)
            throws FileNotFoundException {
        requireSafeDocId(sourceDocumentId);
        requireSafeDocId(targetParentDocumentId);
        try {
            DavEntry entry = statOrThrow(sourceDocumentId);
            String candidate = uniqueName(toDavPath(targetParentDocumentId), nameOf(sourceDocumentId));
            String newDocId = childDocId(targetParentDocumentId, candidate);
            client().copy(toDavPath(sourceDocumentId), toDavPath(newDocId), entry.directory);
            notifyChildrenChanged(targetParentDocumentId);
            return newDocId;
        } catch (IOException e) {
            throw asFileNotFound(e);
        }
    }

    // ------------------------------------------------------------------
    // Pending uploads (write-backs that must survive failures)
    // ------------------------------------------------------------------

    private File pendingDir() {
        Context ctx = getContext();
        File dir = new File(ctx != null ? ctx.getFilesDir() : cacheDir(), "flowdrive-pending");
        //noinspection ResultOfMethodCallIgnored
        dir.mkdirs();
        return dir;
    }

    /**
     * Moves an edited temp file into persistent storage together with its
     * target path, replacing any older pending upload for the same target.
     * Returns the persisted data file, or null when persisting failed.
     */
    private File persistPendingUpload(String davPath, String contentType, File tmp) {
        try {
            File dir = pendingDir();
            // A newer edit of the same file supersedes an older pending one.
            File[] metas = dir.listFiles();
            if (metas != null) {
                for (File meta : metas) {
                    if (meta.getName().endsWith(".meta")
                            && davPath.equals(readMetaLine(meta, 0))) {
                        //noinspection ResultOfMethodCallIgnored
                        new File(dir, meta.getName().replace(".meta", ".data")).delete();
                        //noinspection ResultOfMethodCallIgnored
                        meta.delete();
                    }
                }
            }
            String id = "u" + System.nanoTime();
            File data = new File(dir, id + ".data");
            if (!tmp.renameTo(data)) {
                return null;
            }
            File meta = new File(dir, id + ".meta");
            java.io.Writer w = new java.io.OutputStreamWriter(
                    new java.io.FileOutputStream(meta), java.nio.charset.StandardCharsets.UTF_8);
            try {
                w.write(davPath + "\n" + (contentType == null ? "" : contentType) + "\n");
            } finally {
                w.close();
            }
            return data;
        } catch (Exception e) {
            return null;
        }
    }

    private String readMetaLine(File meta, int index) {
        try {
            java.io.BufferedReader r = new java.io.BufferedReader(new java.io.InputStreamReader(
                    new java.io.FileInputStream(meta), java.nio.charset.StandardCharsets.UTF_8));
            try {
                String line = null;
                for (int i = 0; i <= index; i++) {
                    line = r.readLine();
                }
                return line;
            } finally {
                r.close();
            }
        } catch (IOException e) {
            return null;
        }
    }

    /** Uploads one persisted edit; retries with backoff on transient failures. */
    private void attemptPendingUpload(final File dataFile, final int attempt) {
        File meta = new File(dataFile.getPath().replace(".data", ".meta"));
        final String davPath = readMetaLine(meta, 0);
        String contentType = readMetaLine(meta, 1);
        if (davPath == null || davPath.isEmpty() || !dataFile.exists()) {
            //noinspection ResultOfMethodCallIgnored
            dataFile.delete();
            //noinspection ResultOfMethodCallIgnored
            meta.delete();
            return;
        }
        String fileName = davPath.substring(davPath.lastIndexOf('/') + 1);
        try {
            client().put(davPath, dataFile, contentType);
            //noinspection ResultOfMethodCallIgnored
            dataFile.delete();
            //noinspection ResultOfMethodCallIgnored
            meta.delete();
            notifyChildrenChanged(parentOf("/" + davPath));
            if (attempt > 0) {
                notifyUser("FlowDrive", "\"" + fileName + "\" was uploaded successfully.");
            }
        } catch (IOException e) {
            noteIfAuthFailure(e);
            boolean unrecoverable = false;
            if (e instanceof DavClient.DavException) {
                int s = ((DavClient.DavException) e).status;
                unrecoverable = (s == 400 || s == 413);
            }
            if (unrecoverable) {
                // Retrying cannot help (invalid name / file too large).
                //noinspection ResultOfMethodCallIgnored
                dataFile.delete();
                //noinspection ResultOfMethodCallIgnored
                meta.delete();
                notifyUser("FlowDrive upload failed",
                        "\"" + fileName + "\" could not be saved: " + e.getMessage());
                return;
            }
            if (attempt < 4) {
                // 30s, 60s, 2m, 4m - while the provider process lives.
                ioHandler.postDelayed(new Runnable() {
                    @Override
                    public void run() {
                        attemptPendingUpload(dataFile, attempt + 1);
                    }
                }, 30_000L << attempt);
                if (attempt == 0) {
                    toast("FlowDrive upload of \"" + fileName + "\" is delayed - retrying in the background.");
                }
            } else {
                // Kept on disk; retried on the next provider start.
                notifyUser("FlowDrive upload waiting",
                        "\"" + fileName + "\" could not be uploaded yet (" + e.getMessage()
                                + "). It is kept safe and will be retried.");
            }
        }
    }

    private void retryAllPendingUploads() {
        File[] files = pendingDir().listFiles();
        if (files == null) {
            return;
        }
        for (File f : files) {
            if (f.getName().endsWith(".data")) {
                attemptPendingUpload(f, 1);
            }
        }
    }

    private void noteIfAuthFailure(IOException e) {
        if (e instanceof DavClient.DavException && ((DavClient.DavException) e).status == 401) {
            DriveStore.noteAuthFailure();
        }
    }

    /** Posts a system notification (falls back silently if not permitted). */
    private void notifyUser(String title, String text) {
        Context ctx = getContext();
        if (ctx == null) {
            return;
        }
        try {
            NotificationManager nm =
                    (NotificationManager) ctx.getSystemService(Context.NOTIFICATION_SERVICE);
            if (nm == null) {
                return;
            }
            Notification.Builder builder;
            if (Build.VERSION.SDK_INT >= 26) {
                nm.createNotificationChannel(new NotificationChannel(
                        "flowdrive_uploads", "FlowDrive uploads",
                        NotificationManager.IMPORTANCE_DEFAULT));
                builder = new Notification.Builder(ctx, "flowdrive_uploads");
            } else {
                builder = new Notification.Builder(ctx);
            }
            builder.setSmallIcon(android.R.drawable.stat_sys_upload_done)
                    .setContentTitle(title)
                    .setContentText(text)
                    .setStyle(new Notification.BigTextStyle().bigText(text))
                    .setAutoCancel(true);
            nm.notify((title + text).hashCode(), builder.build());
        } catch (Exception ignored) {
        }
        toast(title + ": " + text);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private DavClient client() throws IOException {
        if (DriveStore.isAuthBlocked()) {
            // Do not hammer the server with known-bad credentials: each 401
            // also counts against the web login's IP rate limit.
            throw new IOException(
                    "FlowDrive sign-in is failing - open the FlowDrive app and reconnect.");
        }
        Context ctx = getContext();
        DavClient client;
        try {
            client = ctx != null ? DriveStore.client(ctx) : null;
        } catch (IllegalArgumentException e) {
            throw new IOException("FlowDrive settings are invalid - open the FlowDrive app and reconnect.", e);
        }
        if (client == null) {
            throw new IOException("FlowDrive is not signed in - open the FlowDrive app first.");
        }
        return client;
    }

    private File cacheDir() {
        Context ctx = getContext();
        return ctx != null ? ctx.getCacheDir() : new File("/data/local/tmp");
    }

    private static String toDavPath(String documentId) {
        String p = documentId;
        while (p.startsWith("/")) {
            p = p.substring(1);
        }
        while (p.endsWith("/")) {
            p = p.substring(0, p.length() - 1);
        }
        return p;
    }

    private static String parentOf(String documentId) {
        String p = toDavPath(documentId);
        int slash = p.lastIndexOf('/');
        return slash < 0 ? ROOT_DOC_ID : "/" + p.substring(0, slash);
    }

    private static String nameOf(String documentId) {
        String p = toDavPath(documentId);
        int slash = p.lastIndexOf('/');
        return slash < 0 ? p : p.substring(slash + 1);
    }

    private static String childDocId(String parentDocumentId, String name) {
        return ROOT_DOC_ID.equals(parentDocumentId)
                ? "/" + name
                : parentDocumentId + "/" + name;
    }

    private static String sanitizeName(String displayName) {
        // Mirror the server's fw_validate_name: it rejects / \ : * ? " < > |
        // and control characters with HTTP 400, which the Files app would
        // surface as an unexplained failure.
        String name = displayName == null ? "" : displayName;
        StringBuilder sb = new StringBuilder(name.length());
        for (int i = 0; i < name.length(); i++) {
            char c = name.charAt(i);
            if (c < 0x20 || "/\\:*?\"<>|".indexOf(c) >= 0) {
                sb.append('_');
            } else {
                sb.append(c);
            }
        }
        String out = sb.toString().trim();
        if (out.length() > 200) {
            out = out.substring(0, 200).trim();
        }
        if (out.isEmpty() || out.equals(".") || out.equals("..")) {
            out = "Untitled";
        }
        return out;
    }

    /** Appends " (n)" before the extension until the name is free in the folder. */
    private String uniqueName(String parentDavPath, String name) throws IOException {
        String candidate = name;
        for (int i = 1; i <= 32; i++) {
            String path = parentDavPath.isEmpty() ? candidate : parentDavPath + "/" + candidate;
            if (client().stat(path) == null) {
                return candidate;
            }
            int dot = name.lastIndexOf('.');
            if (dot > 0) {
                candidate = name.substring(0, dot) + " (" + i + ")" + name.substring(dot);
            } else {
                candidate = name + " (" + i + ")";
            }
        }
        return candidate;
    }

    private DavEntry statOrThrow(String documentId) throws FileNotFoundException {
        try {
            DavEntry entry = client().stat(toDavPath(documentId));
            if (entry == null) {
                throw new FileNotFoundException("Not found on FlowDrive: " + documentId);
            }
            entry.path = toDavPath(documentId); // stat href may omit self path details
            return entry;
        } catch (FileNotFoundException e) {
            throw e;
        } catch (IOException e) {
            throw asFileNotFound(e);
        }
    }

    private void addEntryRow(MatrixCursor cursor, DavEntry entry) {
        String docId = "/" + entry.path;
        MatrixCursor.RowBuilder row = cursor.newRow();
        row.add(Document.COLUMN_DOCUMENT_ID, docId);
        row.add(Document.COLUMN_DISPLAY_NAME,
                entry.name.isEmpty() ? nameOf(docId) : entry.name);
        row.add(Document.COLUMN_MIME_TYPE, mimeFor(entry));
        row.add(Document.COLUMN_SIZE, entry.size);
        row.add(Document.COLUMN_LAST_MODIFIED,
                entry.lastModified > 0 ? entry.lastModified : null);
        row.add(Document.COLUMN_FLAGS, flagsFor(docId, entry.directory));
        cursor.setNotificationUri(resolver(),
                DocumentsContract.buildChildDocumentsUri(AUTHORITY, parentOf(docId)));
    }

    private int flagsFor(String docId, boolean directory) {
        String path = toDavPath(docId);
        int depth = path.isEmpty() ? 0 : path.split("/").length;
        boolean readonly = path.equals(READONLY_DRIVE)
                || path.startsWith(READONLY_DRIVE + "/");
        if (readonly) {
            return 0;
        }
        int flags = 0;
        if (directory) {
            flags |= Document.FLAG_DIR_SUPPORTS_CREATE;
        } else {
            flags |= Document.FLAG_SUPPORTS_WRITE;
        }
        if (depth >= 2) {
            flags |= Document.FLAG_SUPPORTS_DELETE
                    | Document.FLAG_SUPPORTS_RENAME
                    | Document.FLAG_SUPPORTS_MOVE
                    | Document.FLAG_SUPPORTS_COPY;
        }
        return flags;
    }

    private static String mimeFor(DavEntry entry) {
        if (entry.directory) {
            return Document.MIME_TYPE_DIR;
        }
        if (entry.contentType != null && !entry.contentType.isEmpty()) {
            return entry.contentType;
        }
        return mimeGuess(entry.name);
    }

    private static String mimeGuess(String name) {
        int dot = name.lastIndexOf('.');
        if (dot >= 0 && dot < name.length() - 1) {
            String ext = name.substring(dot + 1).toLowerCase();
            String mime = MimeTypeMap.getSingleton().getMimeTypeFromExtension(ext);
            if (mime != null) {
                return mime;
            }
        }
        return "application/octet-stream";
    }

    private android.content.ContentResolver resolver() {
        Context ctx = getContext();
        return ctx != null ? ctx.getContentResolver() : null;
    }

    private void notifyChildrenChanged(String parentDocumentId) {
        android.content.ContentResolver cr = resolver();
        if (cr != null) {
            cr.notifyChange(
                    DocumentsContract.buildChildDocumentsUri(AUTHORITY, parentDocumentId), null);
        }
    }

    private static FileNotFoundException asFileNotFound(IOException e) {
        if (e instanceof DavClient.DavException && ((DavClient.DavException) e).status == 401) {
            DriveStore.noteAuthFailure();
        }
        FileNotFoundException fnf = new FileNotFoundException(
                e.getMessage() != null ? e.getMessage() : "FlowDrive error");
        fnf.initCause(e);
        return fnf;
    }

    private void toast(final String message) {
        final Context ctx = getContext();
        if (ctx == null) {
            return;
        }
        mainHandler.post(new Runnable() {
            @Override
            public void run() {
                Toast.makeText(ctx, message, Toast.LENGTH_LONG).show();
            }
        });
    }
}
