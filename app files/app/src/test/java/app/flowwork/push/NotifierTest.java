package app.flowwork.push;

import static org.junit.Assert.assertEquals;
import static org.junit.Assert.assertFalse;
import static org.junit.Assert.assertNotNull;
import static org.junit.Assert.assertTrue;
import static org.robolectric.Shadows.shadowOf;

import android.Manifest;
import android.app.Application;
import android.app.Notification;
import android.app.NotificationChannel;
import android.app.NotificationManager;
import android.content.Intent;
import android.os.Looper;
import android.service.notification.StatusBarNotification;

import androidx.test.core.app.ApplicationProvider;

import org.junit.Before;
import org.junit.Test;
import org.junit.runner.RunWith;
import org.robolectric.RobolectricTestRunner;
import org.robolectric.annotation.Config;

import java.time.Duration;
import java.util.ArrayList;
import java.util.Collections;
import java.util.HashMap;
import java.util.List;
import java.util.Map;

import app.flowwork.Links;
import app.flowwork.MainActivity;
import app.flowwork.mail.MailActivity;

/** What actually lands in the notification shade for the server's payloads. */
@RunWith(RobolectricTestRunner.class)
@Config(sdk = 35)
public class NotifierTest {

    private Application app;
    private NotificationManager nm;

    @Before
    public void setUp() {
        app = ApplicationProvider.getApplicationContext();
        shadowOf(app).grantPermissions(Manifest.permission.POST_NOTIFICATIONS);
        nm = app.getSystemService(NotificationManager.class);
    }

    private static Map<String, String> mail(String key, String who, String subject) {
        Map<String, String> d = new HashMap<>();
        d.put("ch", "mail");
        d.put("grp", "mail");
        d.put("app", "mail");
        d.put("tag", "mail:" + key);
        d.put("title", who);
        d.put("body", subject);
        d.put("big", subject + "\nPreview text");
        d.put("sub", "me@example.com");
        d.put("who", who);
        d.put("wkey", who.toLowerCase() + "@example.com");
        d.put("url", "/mail/?open=" + key + "&fw_co=1");
        d.put("act", "signed.token");
        d.put("eid", "103");
        d.put("nid", "");
        d.put("ts", String.valueOf(System.currentTimeMillis() / 1000));
        return d;
    }

    /** Let the debounced bundle summary run. */
    private static void settle() {
        shadowOf(Looper.getMainLooper()).idleFor(Duration.ofMillis(Notifier.SUMMARY_DELAY_MS + 100));
    }

    private List<StatusBarNotification> active() {
        List<StatusBarNotification> out = new ArrayList<>();
        Collections.addAll(out, nm.getActiveNotifications());
        return out;
    }

    private StatusBarNotification byTag(String tag) {
        for (StatusBarNotification s : active()) {
            if (tag.equals(s.getTag())) {
                return s;
            }
        }
        return null;
    }

    private static boolean isSummary(StatusBarNotification s) {
        return (s.getNotification().flags & Notification.FLAG_GROUP_SUMMARY) != 0;
    }

    @Test
    public void newEmailNotification() {
        Notifier.show(app, mail("t5", "Ann Lee", "Quote 1042"));
        settle();

        StatusBarNotification sbn = byTag("mail:t5");
        assertNotNull(sbn);
        Notification n = sbn.getNotification();
        assertEquals("mail", n.getChannelId());
        assertEquals("Ann Lee", n.extras.getCharSequence(Notification.EXTRA_TITLE).toString());
        assertEquals("Quote 1042", n.extras.getCharSequence(Notification.EXTRA_TEXT).toString());
        assertEquals("me@example.com", n.extras.getCharSequence(Notification.EXTRA_SUB_TEXT).toString());
        assertEquals("Quote 1042\nPreview text", n.extras.getCharSequence(Notification.EXTRA_BIG_TEXT).toString());
        assertEquals("mail", n.getGroup());
        assertEquals(Notification.CATEGORY_EMAIL, n.category);
        assertNotNull("sender avatar", n.getLargeIcon());
        assertTrue(n.flags == (n.flags | Notification.FLAG_AUTO_CANCEL));

        assertEquals(3, n.actions.length);
        assertEquals("Mark as read", n.actions[0].title.toString());
        assertEquals("Archive", n.actions[1].title.toString());
        assertEquals("Reply", n.actions[2].title.toString());

        // Lock screen with hidden content: no sender or subject.
        assertEquals(Notification.VISIBILITY_PRIVATE, n.visibility);
        assertEquals("FlowMail", n.publicVersion.extras.getCharSequence(Notification.EXTRA_TITLE).toString());
        assertEquals("New email", n.publicVersion.extras.getCharSequence(Notification.EXTRA_TEXT).toString());

        Intent open = shadowOf(n.contentIntent).getSavedIntent();
        assertEquals(MailActivity.class.getName(), open.getComponent().getClassName());
        assertEquals(Links.BASE + "/mail/?open=t5&fw_co=1", open.getStringExtra(Notifier.EXTRA_URL));
        assertEquals("mail:t5", open.getStringExtra(Notifier.EXTRA_TAG));
        assertTrue(shadowOf(n.contentIntent).isActivityIntent());

        Intent reply = shadowOf(n.actions[2].actionIntent).getSavedIntent();
        assertEquals("Reply opens in the conversation's company",
                Links.BASE + "/mail/compose.php?mode=reply&email_id=103&fw_co=1", reply.getStringExtra(Notifier.EXTRA_URL));
        assertTrue(shadowOf(n.actions[0].actionIntent).isBroadcastIntent());

        assertEquals("one email, no bundle summary yet", 1, active().size());
    }

    @Test
    public void channelsAreCreated() {
        Notifier.show(app, mail("t1", "Ann", "Hi"));
        NotificationChannel mailChannel = nm.getNotificationChannel(Channels.MAIL);
        assertNotNull(mailChannel);
        assertEquals(NotificationManager.IMPORTANCE_HIGH, mailChannel.getImportance());
        assertEquals("flowmail", mailChannel.getGroup());
        for (String id : new String[]{Channels.MAIL_REMINDERS, Channels.CALENDAR, Channels.PROJECTS,
                Channels.GENERAL, Channels.DRIVE_UPLOADS}) {
            assertNotNull(id, nm.getNotificationChannel(id));
        }
    }

    @Test
    public void conversationsBundleWithASummary() {
        Notifier.show(app, mail("t5", "Ann Lee", "Quote 1042"));
        Notifier.show(app, mail("t6", "Bob", "Invoice"));
        assertEquals("summary waits for the burst to settle", 2, active().size());
        settle();

        assertEquals(3, active().size());
        StatusBarNotification summary = byTag("summary:mail");
        assertNotNull(summary);
        assertTrue(isSummary(summary));
        Notification s = summary.getNotification();
        assertEquals("2 new emails", s.extras.getCharSequence(Notification.EXTRA_TITLE).toString());
        CharSequence[] lines = s.extras.getCharSequenceArray(Notification.EXTRA_TEXT_LINES);
        assertEquals(2, lines.length);
        assertEquals("me@example.com", s.extras.getCharSequence(Notification.EXTRA_SUB_TEXT).toString());
        assertEquals(Notification.GROUP_ALERT_CHILDREN, s.getGroupAlertBehavior());
    }

    @Test
    public void aNewerMessageReplacesTheConversation() {
        Notifier.show(app, mail("t5", "Ann Lee", "Quote 1042"));
        Notifier.show(app, mail("t5", "Ann Lee", "Re: Quote 1042"));
        assertEquals(1, active().size());
        assertEquals("Re: Quote 1042", byTag("mail:t5").getNotification().extras
                .getCharSequence(Notification.EXTRA_TEXT).toString());
    }

    @Test
    public void readElsewhereRemovesItAndTheSummary() {
        Notifier.show(app, mail("t5", "Ann Lee", "Quote 1042"));
        Notifier.show(app, mail("t6", "Bob", "Invoice"));
        settle();
        assertNotNull(byTag("summary:mail"));
        Map<String, String> d = new HashMap<>();
        d.put("t", "d");
        d.put("tags", "mail:t5,sla:t5");
        Notifier.dismiss(app, d);

        assertEquals(null, byTag("mail:t5"));
        assertNotNull(byTag("mail:t6"));
        assertEquals("summary removed when one is left", null, byTag("summary:mail"));
    }

    @Test
    public void aBurstGetsOneAccurateSummary() {
        for (int i = 0; i < 8; i++) {
            Notifier.show(app, mail("t" + (100 + i), "Sender " + i, "Subject " + i));
        }
        settle();
        StatusBarNotification summary = byTag("summary:mail");
        assertNotNull(summary);
        Notification s = summary.getNotification();
        assertEquals("8 new emails", s.extras.getCharSequence(Notification.EXTRA_TITLE).toString());
        assertEquals(6, s.extras.getCharSequenceArray(Notification.EXTRA_TEXT_LINES).length);
        assertEquals("+2 more", s.extras.getCharSequence(Notification.EXTRA_SUMMARY_TEXT).toString());
        assertEquals(9, active().size());
    }

    @Test
    public void openingTheConversationInTheAppRemovesIt() {
        Notifier.show(app, mail("t5", "Ann Lee", "Quote 1042"));
        Notifier.cancelForUrl(app, "https://www.flowwork.app/mail/#inbox?open=t5");
        assertTrue(active().isEmpty());
    }

    @Test
    public void openingTheMailboxClearsNewMail() {
        Notifier.show(app, mail("t5", "Ann Lee", "Quote 1042"));
        Notifier.show(app, mail("t6", "Bob", "Invoice"));
        Map<String, String> other = new HashMap<>();
        other.put("ch", "calendar");
        other.put("grp", "calendar");
        other.put("title", "Site visit");
        other.put("body", "Event starts at 9:00 AM");
        other.put("url", "/calendar/event_view.php?id=4");
        Notifier.show(app, other);
        settle();

        Notifier.cancelGroup(app, "mail");
        List<StatusBarNotification> left = active();
        assertEquals(1, left.size());
        assertEquals("calendar", left.get(0).getNotification().getChannelId());
    }

    @Test
    public void otherNotificationsOpenFlowWork() {
        Map<String, String> d = new HashMap<>();
        d.put("ch", "projects");
        d.put("grp", "projects");
        d.put("title", "Anna mentioned you");
        d.put("body", "\"Site visit\": can you check?");
        d.put("who", "Anna Smith");
        d.put("nid", "42");
        d.put("url", "/projects/board.php?board_id=3&item=9&fw_co=1");
        Notifier.show(app, d);

        StatusBarNotification sbn = byTag("n:42");
        assertNotNull(sbn);
        Notification n = sbn.getNotification();
        assertEquals("projects", n.getChannelId());
        assertEquals(0, n.actions == null ? 0 : n.actions.length);
        Intent open = shadowOf(n.contentIntent).getSavedIntent();
        assertEquals(MainActivity.class.getName(), open.getComponent().getClassName());
        assertEquals("42", open.getStringExtra(Notifier.EXTRA_NID));
        assertEquals("FlowWork", n.publicVersion.extras.getCharSequence(Notification.EXTRA_TITLE).toString());
    }

    @Test
    public void unsafeLinksFallBackToTheApp() {
        Map<String, String> d = mail("t7", "Eve", "Click me");
        d.put("url", "https://evil.example/phish");
        Notifier.show(app, d);
        Intent open = shadowOf(byTag("mail:t7").getNotification().contentIntent).getSavedIntent();
        assertEquals(Links.MAIL, open.getStringExtra(Notifier.EXTRA_URL));
    }

    @Test
    public void badTagsAreNotUsed() {
        Map<String, String> d = mail("t8", "Ann", "Hi");
        d.put("tag", "../../etc");
        d.put("nid", "9");
        Notifier.show(app, d);
        assertNotNull("falls back to the bell id", byTag("n:9"));
    }

    @Test
    public void nothingWithoutPermission() {
        shadowOf(app).denyPermissions(Manifest.permission.POST_NOTIFICATIONS);
        Notifier.show(app, mail("t5", "Ann", "Hi"));
        // Robolectric runs this at API 35, where the permission is required.
        assertTrue(active().isEmpty());
    }

    @Test
    public void emptyPayloadIsIgnored() {
        Notifier.show(app, new HashMap<>());
        assertTrue(active().isEmpty());
    }

    @Test
    public void tokensAreValidated() {
        assertTrue(PushToken.isValid("dGVzdA_-:APA91b" + "x".repeat(60)));
        assertFalse(PushToken.isValid("short"));
        assertFalse(PushToken.isValid("has space " + "x".repeat(60)));
        assertFalse(PushToken.isValid("semi;colon" + "x".repeat(60)));
    }
}
