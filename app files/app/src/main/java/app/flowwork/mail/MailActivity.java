package app.flowwork.mail;

import android.content.Intent;
import android.net.Uri;

import app.flowwork.Links;
import app.flowwork.WebShellActivity;

/**
 * FlowMail launcher icon: opens straight into the mailbox, in its own task
 * (own Recents card and icon) next to FlowWork and FlowDrive. New-mail
 * notifications open here.
 *
 * It is also offered as an email app: tapping an email address in another
 * app (mailto:) can open a FlowMail draft to that address.
 */
public class MailActivity extends WebShellActivity {

    @Override
    protected String startUrl() {
        return Links.MAIL;
    }

    @Override
    protected String urlFromIntent(Intent intent) {
        if (intent != null && Intent.ACTION_SENDTO.equals(intent.getAction())) {
            Uri data = intent.getData();
            if (data != null && "mailto".equalsIgnoreCase(data.getScheme())) {
                String mailto = data.toString();
                // Some apps put the address/subject in extras instead of the URI.
                String[] to = intent.getStringArrayExtra(Intent.EXTRA_EMAIL);
                String subject = intent.getStringExtra(Intent.EXTRA_SUBJECT);
                if ((mailto.equalsIgnoreCase("mailto:") || mailto.equalsIgnoreCase("mailto"))
                        && to != null && to.length > 0) {
                    mailto = "mailto:" + Uri.encode(String.join(",", to), "@,");
                }
                if (subject != null && !mailto.contains("subject=")) {
                    mailto += (mailto.contains("?") ? "&" : "?") + "subject=" + Uri.encode(subject);
                }
                return composeUrl(mailto);
            }
        }
        return super.urlFromIntent(intent);
    }
}
