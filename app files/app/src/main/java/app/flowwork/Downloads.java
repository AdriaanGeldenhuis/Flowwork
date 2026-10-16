package app.flowwork;

import android.app.DownloadManager;
import android.content.ContentResolver;
import android.content.ContentValues;
import android.content.Context;
import android.net.Uri;
import android.os.Build;
import android.os.Environment;
import android.provider.MediaStore;
import android.webkit.MimeTypeMap;

import java.io.File;
import java.io.FileOutputStream;
import java.io.IOException;
import java.io.OutputStream;
import java.util.Locale;

/** Writes files the page generated (CSV/JSON exports) into the public Downloads folder. */
final class Downloads {

    private Downloads() {
    }

    /** True when saving needs the legacy storage permission first (Android 9 and older). */
    static boolean needsLegacyPermission() {
        return Build.VERSION.SDK_INT < Build.VERSION_CODES.Q;
    }

    /** @return the saved file's name, or throws when it could not be written */
    @SuppressWarnings("deprecation") // addCompletedDownload: only used below Android 10
    static String save(Context ctx, String requestedName, String mime, byte[] bytes) throws IOException {
        String type = (mime == null || mime.trim().isEmpty()) ? "application/octet-stream"
                : mime.split(";")[0].trim().toLowerCase(Locale.ROOT);
        String name = fileName(requestedName, type);

        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.Q) {
            ContentResolver cr = ctx.getContentResolver();
            ContentValues v = new ContentValues();
            v.put(MediaStore.Downloads.DISPLAY_NAME, name);
            v.put(MediaStore.Downloads.MIME_TYPE, type);
            v.put(MediaStore.Downloads.IS_PENDING, 1);
            Uri uri = cr.insert(MediaStore.Downloads.EXTERNAL_CONTENT_URI, v);
            if (uri == null) {
                throw new IOException("MediaStore insert failed");
            }
            try (OutputStream out = cr.openOutputStream(uri)) {
                if (out == null) {
                    throw new IOException("No output stream");
                }
                out.write(bytes);
            } catch (IOException e) {
                cr.delete(uri, null, null);
                throw e;
            }
            v.clear();
            v.put(MediaStore.Downloads.IS_PENDING, 0);
            cr.update(uri, v, null, null);
            return name;
        }

        File dir = Environment.getExternalStoragePublicDirectory(Environment.DIRECTORY_DOWNLOADS);
        if (!dir.exists() && !dir.mkdirs()) {
            throw new IOException("Cannot create Downloads");
        }
        File file = unique(dir, name);
        try (OutputStream out = new FileOutputStream(file)) {
            out.write(bytes);
        }
        DownloadManager dm = (DownloadManager) ctx.getSystemService(Context.DOWNLOAD_SERVICE);
        if (dm != null) {
            // Lists it in the Downloads app, with the usual "download complete" notice.
            dm.addCompletedDownload(file.getName(), file.getName(), true, type,
                    file.getAbsolutePath(), file.length(), true);
        }
        return file.getName();
    }

    private static String fileName(String requested, String type) {
        String name = requested == null ? "" : requested.trim();
        name = name.replaceAll("[\\\\/:*?\"<>|\\x00-\\x1f]", "_");
        if (name.length() > 120) {
            name = name.substring(name.length() - 120);
        }
        if (name.isEmpty() || name.equals(".") || name.equals("..")) {
            name = "FlowWork-download";
        }
        if (name.indexOf('.') < 0) {
            String ext = MimeTypeMap.getSingleton().getExtensionFromMimeType(type);
            if (ext == null && "text/csv".equals(type)) {
                ext = "csv";
            }
            if (ext != null) {
                name = name + "." + ext;
            }
        }
        return name;
    }

    private static File unique(File dir, String name) {
        File f = new File(dir, name);
        int dot = name.lastIndexOf('.');
        String base = dot > 0 ? name.substring(0, dot) : name;
        String ext = dot > 0 ? name.substring(dot) : "";
        for (int i = 1; f.exists() && i < 1000; i++) {
            f = new File(dir, base + " (" + i + ")" + ext);
        }
        return f;
    }
}
