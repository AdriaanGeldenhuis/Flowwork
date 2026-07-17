package app.flowwork.drive;

import android.content.Context;
import android.content.SharedPreferences;
import android.os.SystemClock;

/**
 * Stores the FlowDrive connection settings in the app's private preferences.
 * The app sandbox keeps this file inaccessible to other apps (and the
 * manifest's backup rules keep it out of cloud/adb backups).
 */
public final class DriveStore {

    public static final String DEFAULT_URL = "https://flowdrive.co.za/dav.php/";

    private static final String PREFS = "flowdrive";
    private static final String KEY_URL = "url";
    private static final String KEY_EMAIL = "email";
    private static final String KEY_PASSWORD = "password";

    private DriveStore() {
    }

    private static SharedPreferences prefs(Context ctx) {
        return ctx.getSharedPreferences(PREFS, Context.MODE_PRIVATE);
    }

    public static boolean isConfigured(Context ctx) {
        return !getEmail(ctx).isEmpty() && !getPassword(ctx).isEmpty();
    }

    public static String getUrl(Context ctx) {
        String url = prefs(ctx).getString(KEY_URL, DEFAULT_URL);
        return url == null || url.isEmpty() ? DEFAULT_URL : url;
    }

    public static String getEmail(Context ctx) {
        String v = prefs(ctx).getString(KEY_EMAIL, "");
        return v == null ? "" : v;
    }

    public static String getPassword(Context ctx) {
        String v = prefs(ctx).getString(KEY_PASSWORD, "");
        return v == null ? "" : v;
    }

    public static void save(Context ctx, String url, String email, String password) {
        prefs(ctx).edit()
                .putString(KEY_URL, url)
                .putString(KEY_EMAIL, email)
                .putString(KEY_PASSWORD, password)
                .apply();
        invalidateClient();
        clearAuthBlock();
    }

    public static void clear(Context ctx) {
        prefs(ctx).edit().clear().apply();
        invalidateClient();
        clearAuthBlock();
    }

    // One shared client so SAF operations reuse pooled HTTP connections
    // instead of paying a DNS+TCP+TLS handshake per call.
    private static volatile DavClient cachedClient;
    private static volatile String cachedKey;

    /** A client built from the saved settings, or null when not configured. */
    public static DavClient client(Context ctx) {
        if (!isConfigured(ctx)) {
            return null;
        }
        String key = getUrl(ctx) + "\n" + getEmail(ctx) + "\n" + getPassword(ctx).hashCode();
        DavClient existing = cachedClient;
        if (existing != null && key.equals(cachedKey)) {
            return existing;
        }
        synchronized (DriveStore.class) {
            if (cachedClient == null || !key.equals(cachedKey)) {
                cachedClient = new DavClient(getUrl(ctx), getEmail(ctx), getPassword(ctx));
                cachedKey = key;
            }
            return cachedClient;
        }
    }

    private static void invalidateClient() {
        synchronized (DriveStore.class) {
            cachedClient = null;
            cachedKey = null;
        }
    }

    // After a 401, stop hammering the server: every failed Basic-auth attempt
    // lands in the shared login_attempts table and would soon rate-limit the
    // user's IP out of the FlowDrive WEBSITE as well.
    private static volatile long authBlockUntil;

    public static void noteAuthFailure() {
        authBlockUntil = SystemClock.elapsedRealtime() + 60_000L;
    }

    public static void clearAuthBlock() {
        authBlockUntil = 0L;
    }

    public static boolean isAuthBlocked() {
        return SystemClock.elapsedRealtime() < authBlockUntil;
    }
}
