package app.flowwork.push;

import androidx.annotation.NonNull;

import com.google.firebase.messaging.FirebaseMessagingService;
import com.google.firebase.messaging.RemoteMessage;

import java.util.HashMap;
import java.util.Map;

/**
 * Receives FlowWork pushes from Firebase Cloud Messaging.
 *
 * The server sends data-only messages, which arrive here whether the app is
 * open, in the background or closed, so Notifier decides how they look.
 * Messages typed in the Firebase console carry a "notification" block
 * instead; those are shown here in the foreground and by Firebase (with the
 * manifest's default icon, colour and channel) in the background.
 */
public class FlowMessagingService extends FirebaseMessagingService {

    /** Token callback of firebase-messaging 25+. */
    @Override
    public void onRegistered(@NonNull String token) {
        PushToken.save(this, token);
    }

    /** Older callback, still delivered by some library/Play services versions. */
    @Override
    @SuppressWarnings("deprecation")
    public void onNewToken(@NonNull String token) {
        PushToken.save(this, token);
    }

    @Override
    public void onMessageReceived(@NonNull RemoteMessage message) {
        Map<String, String> data = message.getData();
        if ("d".equals(data.get("t"))) {
            Notifier.dismiss(this, data);
            return;
        }
        if (data.containsKey("title") || data.containsKey("body")) {
            Notifier.show(this, data);
            return;
        }
        RemoteMessage.Notification n = message.getNotification();
        if (n != null) {
            Map<String, String> d = new HashMap<>(data);
            d.put("title", n.getTitle() == null ? "" : n.getTitle());
            d.put("body", n.getBody() == null ? "" : n.getBody());
            if (n.getChannelId() != null) {
                d.put("ch", n.getChannelId());
            }
            Notifier.show(this, d);
        }
    }
}
