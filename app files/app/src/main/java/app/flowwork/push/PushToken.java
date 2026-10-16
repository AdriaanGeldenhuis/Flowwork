package app.flowwork.push;

import android.content.Context;
import android.content.SharedPreferences;
import android.os.Handler;
import android.os.Looper;
import android.webkit.CookieManager;

import com.google.firebase.messaging.FirebaseMessaging;

/**
 * Hands this install's FCM token to the FlowWork server.
 *
 * The token travels as a first-party cookie (fw_push) on the WebView's own
 * requests. The server ties it to the signed-in device row (user_sessions)
 * on the next request, so:
 *   - nothing extra has to be posted, and no CSRF token is needed;
 *   - signing this device out (logout, "Signed-in devices", password reset)
 *     stops its notifications straight away;
 *   - a different person signing in on this phone takes the token over.
 */
public final class PushToken {

    public static final String COOKIE = "fw_push";
    private static final String PREFS = "push";
    private static final String KEY_TOKEN = "token";
    private static final String[] ORIGINS = {"https://www.flowwork.app", "https://flowwork.app"};

    private PushToken() {
    }

    private static SharedPreferences prefs(Context ctx) {
        return ctx.getApplicationContext().getSharedPreferences(PREFS, Context.MODE_PRIVATE);
    }

    public static String get(Context ctx) {
        String t = prefs(ctx).getString(KEY_TOKEN, "");
        return t == null ? "" : t;
    }

    /**
     * Ask Firebase for the current token (it is cached, so this is cheap).
     * getToken() is deprecated in firebase-messaging 25 in favour of
     * register() + FlowMessagingService.onRegistered, which is also handled;
     * getToken() still returns the token directly, which the first launch needs.
     */
    @SuppressWarnings("deprecation")
    public static void refresh(Context ctx) {
        final Context app = ctx.getApplicationContext();
        try {
            FirebaseMessaging.getInstance().getToken().addOnCompleteListener(task -> {
                if (task.isSuccessful() && task.getResult() != null) {
                    save(app, task.getResult());
                }
            });
        } catch (Exception ignored) {
            // Firebase not initialised (e.g. missing google-services.json in a dev build)
        }
    }

    /** New or rotated token from Firebase. */
    public static void save(Context ctx, String token) {
        if (!isValid(token)) {
            return;
        }
        prefs(ctx).edit().putString(KEY_TOKEN, token).apply();
        applyCookie(ctx);
    }

    /** (Re)write the cookie; call before the WebView makes its first request. */
    public static void applyCookie(Context ctx) {
        final String token = get(ctx);
        if (!isValid(token)) {
            return;
        }
        Runnable write = () -> {
            try {
                CookieManager cm = CookieManager.getInstance();
                for (String origin : ORIGINS) {
                    cm.setCookie(origin, COOKIE + "=" + token
                            + "; Path=/; Max-Age=31536000; Secure; HttpOnly; SameSite=Lax");
                }
                cm.flush();
            } catch (Exception ignored) {
                // WebView missing or updating; the next launch retries
            }
        };
        if (Looper.myLooper() == Looper.getMainLooper()) {
            write.run();
        } else {
            new Handler(Looper.getMainLooper()).post(write);
        }
    }

    /** FCM tokens are URL-safe base64 plus ':' — also safe as a cookie value. */
    static boolean isValid(String token) {
        if (token == null || token.length() < 32 || token.length() > 4096) {
            return false;
        }
        for (int i = 0; i < token.length(); i++) {
            char c = token.charAt(i);
            boolean ok = (c >= 'A' && c <= 'Z') || (c >= 'a' && c <= 'z') || (c >= '0' && c <= '9')
                    || c == '-' || c == '_' || c == ':';
            if (!ok) {
                return false;
            }
        }
        return true;
    }
}
