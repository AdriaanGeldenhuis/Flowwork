package app.flowwork.push;

import android.Manifest;
import android.app.Notification;
import android.app.NotificationManager;
import android.app.PendingIntent;
import android.content.Context;
import android.content.Intent;
import android.content.pm.PackageManager;
import android.graphics.Bitmap;
import android.graphics.Canvas;
import android.graphics.Paint;
import android.graphics.Rect;
import android.graphics.Typeface;
import android.net.Uri;
import android.os.Build;
import android.os.Bundle;
import android.os.Handler;
import android.os.Looper;
import android.service.notification.StatusBarNotification;
import android.text.SpannableStringBuilder;
import android.text.Spanned;
import android.text.style.StyleSpan;
import android.util.TypedValue;

import androidx.core.app.NotificationCompat;
import androidx.core.app.NotificationManagerCompat;
import androidx.core.content.ContextCompat;

import java.util.ArrayList;
import java.util.Collections;
import java.util.HashMap;
import java.util.HashSet;
import java.util.List;
import java.util.Locale;
import java.util.Map;
import java.util.Set;

import app.flowwork.Links;
import app.flowwork.MainActivity;
import app.flowwork.R;
import app.flowwork.WebShellActivity;
import app.flowwork.mail.MailActivity;

/**
 * Shows, groups and removes FlowWork notifications.
 *
 * The server sends data-only FCM messages (so the app controls how every
 * notification looks, even when it is closed). Keys, all strings:
 *
 *   t      "n" show (default) or "d" dismiss
 *   ch     channel: mail | mail_reminders | calendar | projects | general
 *   title  first line (mail: the sender)
 *   body   second line (mail: the subject)
 *   big    expanded text (mail: subject + preview), optional
 *   sub    small header text (mail: the mailbox address), optional
 *   url    same-site path to open on tap, e.g. /mail/?open=t42
 *   tag    replace key: a newer message with the same tag replaces the old
 *   grp    bundle key: mail | mail_reminders | calendar | projects | general
 *   who    person's name, drawn as the letter avatar
 *   wkey   stable key for the avatar colour (an email address)
 *   nid    in-app notification id (marked read when the tap is opened)
 *   ts     unix seconds of the event
 *   app    "mail" opens in FlowMail, anything else in FlowWork
 *   act    signed token for Mark as read / Archive (mail only)
 *   eid    email id for the Reply button (mail only)
 *   tags   dismiss only: comma-separated tags to remove
 */
public final class Notifier {

    public static final String EXTRA_URL = "app.flowwork.extra.URL";
    public static final String EXTRA_TAG = "app.flowwork.extra.TAG";
    public static final String EXTRA_NID = "app.flowwork.extra.NID";
    public static final String EXTRA_GROUP = "app.flowwork.extra.GROUP";

    static final int ID = 1;
    private static final String SUMMARY_PREFIX = "summary:";
    private static final String OPEN_ACTION = "app.flowwork.action.OPEN:";
    private static final int MAX_LINES = 6;
    static final long SUMMARY_DELAY_MS = 900;

    private Notifier() {
    }

    // ------------------------------------------------------------------ show

    public static void show(Context ctx, Map<String, String> d) {
        String title = clip(d.get("title"), 140);
        String body = clip(d.get("body"), 400);
        if (title.isEmpty() && body.isEmpty()) {
            return;
        }
        if (!canPost(ctx)) {
            return;
        }
        Channels.ensure(ctx);

        String channel = Channels.fromServer(d.get("ch"));
        boolean mail = "mail".equals(d.get("app")) || Channels.isMail(channel);
        if (Channels.MAIL.equals(channel) && WebShellActivity.isMailOnScreen()) {
            return; // the user is looking at the mailbox; it shows the new message itself
        }
        String nid = digits(d.get("nid"));
        String tag = safeKey(d.get("tag"));
        if (tag.isEmpty()) {
            tag = "n:" + (nid.isEmpty() ? String.valueOf(System.currentTimeMillis()) : nid);
        }
        String group = safeKey(d.get("grp"));
        String url = Links.resolve(d.get("url"));
        if (url == null) {
            url = mail ? Links.MAIL : Links.HOME;
        }
        long when = seconds(d.get("ts"));
        String big = clip(d.get("big"), 1200);
        String sub = clip(d.get("sub"), 120);
        String who = clip(d.get("who"), 120);

        NotificationCompat.Builder b = base(ctx, channel, mail)
                .setContentTitle(title)
                .setContentText(body)
                .setStyle(new NotificationCompat.BigTextStyle().bigText(
                        big.isEmpty() ? body : emphasiseFirstLine(big)))
                .setWhen(when)
                .setShowWhen(true)
                .setAutoCancel(true)
                .setCategory(category(channel))
                .setContentIntent(openIntent(ctx, mail, url, tag, group, nid, tag))
                .setDeleteIntent(NotificationActionReceiver.dismissedIntent(ctx, tag, group))
                .setPublicVersion(publicVersion(ctx, channel, mail));
        if (!sub.isEmpty()) {
            b.setSubText(sub);
        }
        if (!who.isEmpty()) {
            b.setLargeIcon(avatar(ctx, who, d.get("wkey")));
        }
        if (!group.isEmpty()) {
            b.setGroup(group);
        }

        if (mail) {
            String act = clip(d.get("act"), 1000);
            if (!act.isEmpty()) {
                b.addAction(0, ctx.getString(R.string.notif_mark_read),
                        NotificationActionReceiver.mailIntent(ctx, "read", act, tag, group, title, url));
                b.addAction(0, ctx.getString(R.string.notif_archive),
                        NotificationActionReceiver.mailIntent(ctx, "archive", act, tag, group, title, url));
            }
            String eid = digits(d.get("eid"));
            if (!eid.isEmpty()) {
                String reply = Links.BASE + "/mail/compose.php?mode=reply&email_id=" + eid;
                String co = Uri.parse(url).getQueryParameter("fw_co"); // same company as the conversation
                if (co != null && co.matches("\\d{1,10}")) {
                    reply += "&fw_co=" + co;
                }
                b.addAction(0, ctx.getString(R.string.notif_reply),
                        openIntent(ctx, true, reply, tag, group, nid, tag + ":reply"));
            }
        }

        try {
            NotificationManagerCompat.from(ctx).notify(tag, ID, b.build());
        } catch (SecurityException e) {
            return; // permission revoked between the check and the post
        }
        if (!group.isEmpty()) {
            scheduleSummary(ctx, group, channel, mail);
        }
    }

    private static final Handler MAIN = new Handler(Looper.getMainLooper());
    private static final Map<String, Runnable> PENDING_SUMMARIES = new HashMap<>();

    /**
     * Refresh a bundle's summary once things settle. A mail sync can deliver
     * several pushes within a second, and Android drops notification updates
     * beyond ~5 a second, so one summary update per burst keeps it accurate.
     */
    private static void scheduleSummary(Context ctx, String group, String channel, boolean mail) {
        final Context app = ctx.getApplicationContext();
        Runnable run = () -> {
            synchronized (PENDING_SUMMARIES) {
                PENDING_SUMMARIES.remove(group);
            }
            refreshSummary(app, group, channel, mail, null);
        };
        synchronized (PENDING_SUMMARIES) {
            Runnable old = PENDING_SUMMARIES.put(group, run);
            if (old != null) {
                MAIN.removeCallbacks(old);
            }
        }
        MAIN.postDelayed(run, SUMMARY_DELAY_MS);
    }

    /** Shown when a Mark as read / Archive tap could not reach the server. */
    static void showActionFailed(Context ctx, String tag, String title, String url) {
        if (!canPost(ctx)) {
            return;
        }
        Channels.ensure(ctx);
        String text = ctx.getString(R.string.notif_action_failed_text);
        String u = Links.resolve(url);
        NotificationCompat.Builder b = base(ctx, Channels.MAIL, true)
                .setContentTitle(ctx.getString(R.string.notif_action_failed_title))
                .setContentText(title.isEmpty() ? text : title)
                .setStyle(new NotificationCompat.BigTextStyle().bigText(
                        (title.isEmpty() ? "" : title + "\n") + text))
                .setAutoCancel(true)
                .setOnlyAlertOnce(true)
                .setContentIntent(openIntent(ctx, true, u == null ? Links.MAIL : u, tag + ":failed", "", "",
                        tag + ":failed"));
        try {
            NotificationManagerCompat.from(ctx).notify(tag + ":failed", ID, b.build());
        } catch (SecurityException ignored) {
        }
    }

    // ------------------------------------------------------------------ remove

    /** Server says these were dealt with elsewhere (read on another device, ...). */
    public static void dismiss(Context ctx, Map<String, String> d) {
        Set<String> tags = new HashSet<>();
        String list = d.get("tags");
        if (list != null) {
            for (String t : list.split(",")) {
                String k = safeKey(t);
                if (!k.isEmpty()) {
                    tags.add(k);
                }
            }
        }
        String group = safeKey(d.get("grp"));
        if (tags.isEmpty() && !group.isEmpty()) {
            cancelGroup(ctx, group);
            return;
        }
        cancelTags(ctx, tags);
    }

    public static void cancelTags(Context ctx, Set<String> tags) {
        if (tags.isEmpty()) {
            return;
        }
        NotificationManager nm = ctx.getSystemService(NotificationManager.class);
        if (nm == null) {
            return;
        }
        Set<String> groups = new HashSet<>();
        for (StatusBarNotification sbn : active(nm)) {
            if (sbn.getTag() != null && tags.contains(sbn.getTag())) {
                String g = sbn.getNotification().getGroup();
                if (g != null) {
                    groups.add(g);
                }
                nm.cancel(sbn.getTag(), sbn.getId());
            }
        }
        for (String g : groups) {
            refreshSummary(ctx, g, null, Channels.isMail(g), tags);
        }
    }

    /** Remove every notification in a bundle, e.g. all new-mail ones when the inbox is opened. */
    public static void cancelGroup(Context ctx, String group) {
        NotificationManager nm = ctx.getSystemService(NotificationManager.class);
        if (nm == null || group == null || group.isEmpty()) {
            return;
        }
        for (StatusBarNotification sbn : active(nm)) {
            if (group.equals(sbn.getNotification().getGroup())) {
                nm.cancel(sbn.getTag(), sbn.getId());
            }
        }
        nm.cancel(SUMMARY_PREFIX + group, ID);
    }

    /** The user opened something in the app: drop the notification that pointed at it. */
    public static void cancelForUrl(Context ctx, String url) {
        if (url == null || !Links.isMail(url)) {
            return;
        }
        Uri uri = Uri.parse(url);
        String open = uri.getQueryParameter("open");
        String frag = uri.getFragment();
        if ((open == null || open.isEmpty()) && frag != null) {
            // The mail app routes with "#inbox?open=t12": read that query part.
            int q = frag.indexOf('?');
            if (q >= 0) {
                open = Uri.parse("https://x/?" + frag.substring(q + 1)).getQueryParameter("open");
            }
        }
        if (open != null && open.matches("[te]\\d{1,12}")) {
            Set<String> tags = new HashSet<>();
            tags.add("mail:" + open);
            tags.add("sla:" + open);
            cancelTags(ctx, tags);
        }
    }

    /** Called when the user swipes one notification away. */
    static void onDismissed(Context ctx, String tag, String group) {
        if (group == null || group.isEmpty()) {
            return;
        }
        Set<String> gone = new HashSet<>();
        gone.add(tag);
        refreshSummary(ctx, group, null, Channels.isMail(group), gone);
    }

    /** A notification (or one of its buttons) opened the app. */
    public static void onOpened(Context ctx, String tag, String group) {
        String t = safeKey(tag);
        if (t.isEmpty()) {
            if (!safeKey(group).isEmpty()) {
                cancelGroup(ctx, safeKey(group)); // the bundle summary itself was tapped
            }
            return;
        }
        Set<String> tags = new HashSet<>();
        tags.add(t);
        cancelTags(ctx, tags);
        String g = safeKey(group);
        if (!g.isEmpty()) {
            refreshSummary(ctx, g, null, Channels.isMail(g), tags);
        }
    }

    // ------------------------------------------------------------------ bundles

    private static final class Child {
        final CharSequence title;
        final CharSequence text;
        final CharSequence sub;
        final long when;

        Child(CharSequence title, CharSequence text, CharSequence sub, long when) {
            this.title = title == null ? "" : title;
            this.text = text == null ? "" : text;
            this.sub = sub == null ? "" : sub;
            this.when = when;
        }
    }

    /**
     * Keep one summary per bundle that lists what is still showing. Android
     * only bundles notifications that have a summary; with a single item
     * left the summary is removed so it shows on its own.
     *
     * @param gone tags just cancelled (may still be listed for a moment)
     */
    private static void refreshSummary(Context ctx, String group, String channel, boolean mail,
            Set<String> gone) {
        NotificationManager nm = ctx.getSystemService(NotificationManager.class);
        if (nm == null) {
            return;
        }
        List<Child> kids = new ArrayList<>();
        for (StatusBarNotification sbn : active(nm)) {
            Notification n = sbn.getNotification();
            String tag = sbn.getTag();
            if (!group.equals(n.getGroup()) || (n.flags & Notification.FLAG_GROUP_SUMMARY) != 0
                    || tag == null || (gone != null && gone.contains(tag))) {
                continue;
            }
            if (channel == null && Build.VERSION.SDK_INT >= 26) {
                channel = n.getChannelId();
            }
            Bundle x = n.extras;
            kids.add(new Child(x.getCharSequence(Notification.EXTRA_TITLE),
                    x.getCharSequence(Notification.EXTRA_TEXT), x.getCharSequence(Notification.EXTRA_SUB_TEXT),
                    n.when));
        }
        if (kids.size() < 2) {
            nm.cancel(SUMMARY_PREFIX + group, ID);
            return;
        }
        if (channel == null) {
            channel = Channels.fromServer(group);
        }
        Collections.sort(kids, (a, b) -> Long.compare(b.when, a.when));

        NotificationCompat.InboxStyle style = new NotificationCompat.InboxStyle();
        for (int i = 0; i < kids.size() && i < MAX_LINES; i++) {
            Child c = kids.get(i);
            SpannableStringBuilder line = new SpannableStringBuilder(c.title);
            line.setSpan(new StyleSpan(Typeface.BOLD), 0, line.length(), Spanned.SPAN_EXCLUSIVE_EXCLUSIVE);
            if (c.text.length() > 0) {
                line.append("  ").append(c.text);
            }
            style.addLine(line);
        }
        if (kids.size() > MAX_LINES) {
            style.setSummaryText("+" + (kids.size() - MAX_LINES) + " more");
        }
        String title = summaryTitle(group, kids.size());
        style.setBigContentTitle(title);

        // Same mailbox for every item? Show it in the header, like the children.
        CharSequence sub = kids.get(0).sub;
        for (Child c : kids) {
            if (!c.sub.toString().equals(sub.toString())) {
                sub = "";
                break;
            }
        }

        NotificationCompat.Builder b = base(ctx, channel, mail)
                .setContentTitle(title)
                .setContentText(kids.get(0).title + (kids.get(0).text.length() > 0 ? "  " + kids.get(0).text : ""))
                .setStyle(style)
                .setGroup(group)
                .setGroupSummary(true)
                .setGroupAlertBehavior(NotificationCompat.GROUP_ALERT_CHILDREN)
                .setOnlyAlertOnce(true)
                .setAutoCancel(true)
                .setNumber(kids.size())
                .setWhen(kids.get(0).when)
                .setCategory(category(channel))
                .setContentIntent(openIntent(ctx, mail, groupUrl(group), "", group, "", SUMMARY_PREFIX + group))
                .setPublicVersion(publicVersion(ctx, channel, mail));
        if (sub.length() > 0) {
            b.setSubText(sub);
        }
        try {
            NotificationManagerCompat.from(ctx).notify(SUMMARY_PREFIX + group, ID, b.build());
        } catch (SecurityException ignored) {
        }
    }

    private static String summaryTitle(String group, int n) {
        switch (group) {
            case "mail":
                return n + " new emails";
            case "mail_reminders":
                return n + " replies overdue";
            case "calendar":
                return n + " event reminders";
            case "projects":
                return n + " mentions";
            default:
                return n + " notifications";
        }
    }

    private static String groupUrl(String group) {
        switch (group) {
            case "mail":
            case "mail_reminders":
                return Links.MAIL;
            case "calendar":
                return Links.BASE + "/calendar/";
            default:
                return Links.HOME;
        }
    }

    // ------------------------------------------------------------------ pieces

    private static NotificationCompat.Builder base(Context ctx, String channel, boolean mail) {
        return new NotificationCompat.Builder(ctx, channel)
                .setSmallIcon(mail ? R.drawable.ic_stat_mail : R.drawable.ic_stat_flowwork)
                .setColor(ContextCompat.getColor(ctx, R.color.brand))
                .setPriority(priority(channel))
                .setVisibility(NotificationCompat.VISIBILITY_PRIVATE);
    }

    /** What the lock screen shows when the user hides sensitive content. */
    private static Notification publicVersion(Context ctx, String channel, boolean mail) {
        return new NotificationCompat.Builder(ctx, channel)
                .setSmallIcon(mail ? R.drawable.ic_stat_mail : R.drawable.ic_stat_flowwork)
                .setColor(ContextCompat.getColor(ctx, R.color.brand))
                .setContentTitle(ctx.getString(mail ? R.string.flowmail_label : R.string.app_name))
                .setContentText(ctx.getString(mail ? R.string.notif_public_mail : R.string.notif_public_other))
                .build();
    }

    /**
     * @param tag   notification this tap dealt with (removed when the app opens)
     * @param group its bundle, so the summary can be brought up to date
     * @param key   unique per button, keeps PendingIntents from overwriting each other
     */
    static PendingIntent openIntent(Context ctx, boolean mail, String url, String tag, String group,
            String nid, String key) {
        Intent i = new Intent(ctx, mail ? MailActivity.class : MainActivity.class)
                .setAction(OPEN_ACTION + key)
                .putExtra(EXTRA_URL, url)
                .putExtra(EXTRA_TAG, tag)
                .putExtra(EXTRA_GROUP, group)
                .addFlags(Intent.FLAG_ACTIVITY_NEW_TASK);
        if (nid != null && !nid.isEmpty()) {
            i.putExtra(EXTRA_NID, nid);
        }
        return PendingIntent.getActivity(ctx, key.hashCode(), i,
                PendingIntent.FLAG_UPDATE_CURRENT | PendingIntent.FLAG_IMMUTABLE);
    }

    private static int priority(String channel) {
        switch (channel) {
            case Channels.MAIL:
            case Channels.CALENDAR:
            case Channels.PROJECTS:
                return NotificationCompat.PRIORITY_HIGH;
            default:
                return NotificationCompat.PRIORITY_DEFAULT;
        }
    }

    private static String category(String channel) {
        switch (channel) {
            case Channels.MAIL:
                return NotificationCompat.CATEGORY_EMAIL;
            case Channels.CALENDAR:
                return NotificationCompat.CATEGORY_EVENT;
            case Channels.PROJECTS:
                return NotificationCompat.CATEGORY_SOCIAL;
            case Channels.MAIL_REMINDERS:
                return NotificationCompat.CATEGORY_REMINDER;
            default:
                return NotificationCompat.CATEGORY_MESSAGE;
        }
    }

    private static boolean canPost(Context ctx) {
        if (Build.VERSION.SDK_INT >= 33 && ContextCompat.checkSelfPermission(ctx,
                Manifest.permission.POST_NOTIFICATIONS) != PackageManager.PERMISSION_GRANTED) {
            return false;
        }
        return NotificationManagerCompat.from(ctx).areNotificationsEnabled();
    }

    private static StatusBarNotification[] active(NotificationManager nm) {
        try {
            return nm.getActiveNotifications();
        } catch (Exception e) {
            return new StatusBarNotification[0];
        }
    }

    /** Bold first line ("Subject"), then the preview. */
    private static CharSequence emphasiseFirstLine(String text) {
        int nl = text.indexOf('\n');
        SpannableStringBuilder s = new SpannableStringBuilder(text);
        s.setSpan(new StyleSpan(Typeface.BOLD), 0, nl < 0 ? text.length() : nl,
                Spanned.SPAN_EXCLUSIVE_EXCLUSIVE);
        return s;
    }

    // ------------------------------------------------------------------ avatar

    private static final int[] AVATAR_COLORS = {
            0xFF7C2EA6, 0xFF2F6FDE, 0xFF0B8F6A, 0xFFD9480F, 0xFFC2255C,
            0xFF5F3DC4, 0xFF1098AD, 0xFFE67700, 0xFF495057, 0xFF2B8A3E,
    };

    /** Circular letter avatar for the sender, like Gmail's. */
    static Bitmap avatar(Context ctx, String name, String key) {
        int size = Math.round(TypedValue.applyDimension(TypedValue.COMPLEX_UNIT_DIP, 64,
                ctx.getResources().getDisplayMetrics()));
        Bitmap bmp = Bitmap.createBitmap(size, size, Bitmap.Config.ARGB_8888);
        Canvas c = new Canvas(bmp);
        String k = (key == null || key.isEmpty()) ? name : key.toLowerCase(Locale.ROOT);
        Paint bg = new Paint(Paint.ANTI_ALIAS_FLAG);
        bg.setColor(AVATAR_COLORS[(k.hashCode() & 0x7fffffff) % AVATAR_COLORS.length]);
        c.drawCircle(size / 2f, size / 2f, size / 2f, bg);

        String letter = "?";
        for (int i = 0; i < name.length(); i++) {
            int cp = name.codePointAt(i);
            if (Character.isLetterOrDigit(cp)) {
                letter = new String(Character.toChars(cp)).toUpperCase(Locale.getDefault());
                break;
            }
        }
        Paint fg = new Paint(Paint.ANTI_ALIAS_FLAG);
        fg.setColor(0xFFFFFFFF);
        fg.setTextSize(size * 0.44f);
        fg.setTypeface(Typeface.create(Typeface.SANS_SERIF, Typeface.BOLD));
        fg.setTextAlign(Paint.Align.CENTER);
        Rect r = new Rect();
        fg.getTextBounds(letter, 0, letter.length(), r);
        c.drawText(letter, size / 2f, size / 2f + r.height() / 2f - r.bottom, fg);
        return bmp;
    }

    // ------------------------------------------------------------------ input hygiene

    static String clip(String s, int max) {
        if (s == null) {
            return "";
        }
        s = s.trim();
        return s.length() <= max ? s : s.substring(0, max - 1).trim() + "…";
    }

    /** Tags/groups: letters, digits and : _ - . only. */
    static String safeKey(String s) {
        if (s == null) {
            return "";
        }
        s = s.trim();
        if (s.length() > 100) {
            return "";
        }
        for (int i = 0; i < s.length(); i++) {
            char c = s.charAt(i);
            boolean ok = (c >= 'A' && c <= 'Z') || (c >= 'a' && c <= 'z') || (c >= '0' && c <= '9')
                    || c == ':' || c == '_' || c == '-' || c == '.';
            if (!ok) {
                return "";
            }
        }
        return s;
    }

    private static String digits(String s) {
        return s != null && s.matches("\\d{1,18}") ? s : "";
    }

    private static long seconds(String s) {
        try {
            long v = Long.parseLong(s == null ? "" : s.trim());
            long ms = v * 1000L;
            long now = System.currentTimeMillis();
            return (v > 0 && ms < now + 86_400_000L) ? ms : now;
        } catch (NumberFormatException e) {
            return System.currentTimeMillis();
        }
    }
}
