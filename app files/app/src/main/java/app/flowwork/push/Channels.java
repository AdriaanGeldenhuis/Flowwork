package app.flowwork.push;

import android.app.NotificationChannel;
import android.app.NotificationChannelGroup;
import android.app.NotificationManager;
import android.content.Context;
import android.graphics.Color;
import android.os.Build;

import androidx.annotation.RequiresApi;

import java.util.Arrays;

import app.flowwork.R;

/**
 * Notification categories, grouped as FlowWork / FlowMail / FlowDrive in
 * Settings › Apps › FlowWork › Notifications. The server picks the channel
 * (push payload key "ch"); anything unknown lands in "Updates".
 */
public final class Channels {

    public static final String MAIL = "mail";
    public static final String MAIL_REMINDERS = "mail_reminders";
    public static final String CALENDAR = "calendar";
    public static final String PROJECTS = "projects";
    public static final String GENERAL = "general";
    /** Created first by FlowDriveProvider; same id and importance here. */
    public static final String DRIVE_UPLOADS = "flowdrive_uploads";

    private static final String GROUP_WORK = "flowwork";
    private static final String GROUP_MAIL = "flowmail";
    private static final String GROUP_DRIVE = "flowdrive";

    private Channels() {
    }

    public static String fromServer(String ch) {
        if (ch == null) {
            return GENERAL;
        }
        switch (ch) {
            case MAIL:
            case MAIL_REMINDERS:
            case CALENDAR:
            case PROJECTS:
            case GENERAL:
                return ch;
            default:
                return GENERAL;
        }
    }

    public static boolean isMail(String channel) {
        return MAIL.equals(channel) || MAIL_REMINDERS.equals(channel);
    }

    /** Idempotent (existing channels keep the user's settings); safe before every notification. */
    public static void ensure(Context ctx) {
        if (Build.VERSION.SDK_INT < 26) {
            return;
        }
        NotificationManager nm = ctx.getSystemService(NotificationManager.class);
        if (nm == null) {
            return;
        }
        nm.createNotificationChannelGroups(Arrays.asList(
                new NotificationChannelGroup(GROUP_MAIL, ctx.getString(R.string.channel_group_flowmail)),
                new NotificationChannelGroup(GROUP_WORK, ctx.getString(R.string.channel_group_flowwork)),
                new NotificationChannelGroup(GROUP_DRIVE, ctx.getString(R.string.channel_group_flowdrive))));

        nm.createNotificationChannels(Arrays.asList(
                channel(ctx, MAIL, R.string.channel_mail, R.string.channel_mail_desc,
                        NotificationManager.IMPORTANCE_HIGH, GROUP_MAIL),
                channel(ctx, MAIL_REMINDERS, R.string.channel_mail_reminders,
                        R.string.channel_mail_reminders_desc,
                        NotificationManager.IMPORTANCE_DEFAULT, GROUP_MAIL),
                channel(ctx, CALENDAR, R.string.channel_calendar, R.string.channel_calendar_desc,
                        NotificationManager.IMPORTANCE_HIGH, GROUP_WORK),
                channel(ctx, PROJECTS, R.string.channel_projects, R.string.channel_projects_desc,
                        NotificationManager.IMPORTANCE_HIGH, GROUP_WORK),
                channel(ctx, GENERAL, R.string.channel_general, R.string.channel_general_desc,
                        NotificationManager.IMPORTANCE_DEFAULT, GROUP_WORK),
                channel(ctx, DRIVE_UPLOADS, R.string.channel_drive, R.string.channel_drive_desc,
                        NotificationManager.IMPORTANCE_DEFAULT, GROUP_DRIVE)));
    }

    @RequiresApi(26)
    private static NotificationChannel channel(Context ctx, String id, int name, int desc,
            int importance, String group) {
        NotificationChannel c = new NotificationChannel(id, ctx.getString(name), importance);
        c.setDescription(ctx.getString(desc));
        c.setGroup(group);
        c.setShowBadge(!DRIVE_UPLOADS.equals(id));
        c.enableLights(true);
        c.setLightColor(Color.rgb(0x7C, 0x2E, 0xA6));
        c.setLockscreenVisibility(android.app.Notification.VISIBILITY_PRIVATE);
        return c;
    }
}
