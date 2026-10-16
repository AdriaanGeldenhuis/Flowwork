package app.flowwork;

import android.app.Application;

import app.flowwork.push.Channels;

/**
 * Creates the notification categories at start-up, so a push that arrives
 * before any screen was opened (or one sent from the Firebase console) lands
 * in the right category. Kept light: the FlowDrive provider starts this
 * process too, often without any screen.
 */
public class FlowWorkApp extends Application {

    @Override
    public void onCreate() {
        super.onCreate();
        Channels.ensure(this);
    }
}
