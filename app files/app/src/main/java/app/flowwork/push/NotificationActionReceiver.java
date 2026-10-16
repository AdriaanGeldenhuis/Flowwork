package app.flowwork.push;

import android.app.PendingIntent;
import android.content.BroadcastReceiver;
import android.content.Context;
import android.content.Intent;

import org.json.JSONObject;

import java.util.HashSet;
import java.util.Set;
import java.util.concurrent.TimeUnit;

import app.flowwork.BuildConfig;
import app.flowwork.Links;
import okhttp3.MediaType;
import okhttp3.OkHttpClient;
import okhttp3.Request;
import okhttp3.RequestBody;
import okhttp3.Response;

/**
 * Notification buttons that work without opening the app (Mark as read,
 * Archive) and swipe-away bookkeeping for bundled notifications.
 *
 * The buttons carry a short-lived token the server signed for this one
 * conversation and device, so no cookie or session is needed; signing the
 * device out invalidates it.
 */
public class NotificationActionReceiver extends BroadcastReceiver {

    private static final String ACTION_MAIL = "app.flowwork.push.MAIL_ACTION";
    private static final String ACTION_DISMISSED = "app.flowwork.push.DISMISSED";
    private static final String EXTRA_OP = "op";
    private static final String EXTRA_TOKEN = "token";
    private static final String EXTRA_TAG = "tag";
    private static final String EXTRA_GROUP = "group";
    private static final String EXTRA_TITLE = "title";
    private static final String EXTRA_URL = "url";

    private static final String ENDPOINT = Links.BASE + "/mail/api/push_action.php";
    private static final MediaType JSON = MediaType.get("application/json; charset=utf-8");

    private static volatile OkHttpClient http;

    static PendingIntent mailIntent(Context ctx, String op, String token, String tag, String group,
            String title, String url) {
        Intent i = new Intent(ctx, NotificationActionReceiver.class)
                .setAction(ACTION_MAIL + ":" + op + ":" + tag)
                .putExtra(EXTRA_OP, op)
                .putExtra(EXTRA_TOKEN, token)
                .putExtra(EXTRA_TAG, tag)
                .putExtra(EXTRA_GROUP, group)
                .putExtra(EXTRA_TITLE, title)
                .putExtra(EXTRA_URL, url);
        return PendingIntent.getBroadcast(ctx, (op + tag).hashCode(), i,
                PendingIntent.FLAG_UPDATE_CURRENT | PendingIntent.FLAG_IMMUTABLE);
    }

    static PendingIntent dismissedIntent(Context ctx, String tag, String group) {
        Intent i = new Intent(ctx, NotificationActionReceiver.class)
                .setAction(ACTION_DISMISSED + ":" + tag)
                .putExtra(EXTRA_TAG, tag)
                .putExtra(EXTRA_GROUP, group);
        return PendingIntent.getBroadcast(ctx, ("dismissed" + tag).hashCode(), i,
                PendingIntent.FLAG_UPDATE_CURRENT | PendingIntent.FLAG_IMMUTABLE);
    }

    @Override
    public void onReceive(Context context, Intent intent) {
        final Context ctx = context.getApplicationContext();
        String action = intent.getAction();
        if (action == null) {
            return;
        }
        final String tag = Notifier.safeKey(intent.getStringExtra(EXTRA_TAG));
        final String group = Notifier.safeKey(intent.getStringExtra(EXTRA_GROUP));

        if (action.startsWith(ACTION_DISMISSED)) {
            Notifier.onDismissed(ctx, tag, group);
            return;
        }
        if (!action.startsWith(ACTION_MAIL)) {
            return;
        }
        final String op = intent.getStringExtra(EXTRA_OP);
        final String token = intent.getStringExtra(EXTRA_TOKEN);
        final String title = Notifier.clip(intent.getStringExtra(EXTRA_TITLE), 140);
        final String url = intent.getStringExtra(EXTRA_URL);
        if (!("read".equals(op) || "archive".equals(op)) || token == null || token.isEmpty()) {
            return;
        }

        // Remove it right away (feels instant); put up a notice if the server says no.
        Set<String> tags = new HashSet<>();
        tags.add(tag);
        Notifier.cancelTags(ctx, tags);

        final PendingResult pending = goAsync();
        new Thread(() -> {
            try {
                if (!send(op, token)) {
                    Notifier.showActionFailed(ctx, tag, title, url);
                }
            } finally {
                pending.finish();
            }
        }, "flowwork-mail-action").start();
    }

    private static boolean send(String op, String token) {
        try {
            JSONObject body = new JSONObject().put("action", op).put("token", token);
            Request req = new Request.Builder()
                    .url(ENDPOINT)
                    .header("Accept", "application/json")
                    .header("User-Agent", "FlowWorkApp/" + BuildConfig.VERSION_NAME + " (Android)")
                    .post(RequestBody.create(body.toString(), JSON))
                    .build();
            try (Response res = client().newCall(req).execute()) {
                if (!res.isSuccessful() || res.body() == null) {
                    return false;
                }
                return new JSONObject(res.body().string()).optBoolean("ok", false);
            }
        } catch (Exception e) {
            return false;
        }
    }

    private static OkHttpClient client() {
        if (http == null) {
            synchronized (NotificationActionReceiver.class) {
                if (http == null) {
                    // A broadcast must finish within ~10 s.
                    http = new OkHttpClient.Builder()
                            .connectTimeout(5, TimeUnit.SECONDS)
                            .readTimeout(7, TimeUnit.SECONDS)
                            .callTimeout(8, TimeUnit.SECONDS)
                            .build();
                }
            }
        }
        return http;
    }
}
