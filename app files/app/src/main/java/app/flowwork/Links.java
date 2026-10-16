package app.flowwork;

import android.net.Uri;

/**
 * Which URLs belong inside the app, and safe conversion of the paths the
 * server puts in notifications ("/mail/?open=t12") into absolute URLs.
 */
public final class Links {

    public static final String BASE = "https://www.flowwork.app";
    public static final String HOME = BASE + "/";
    public static final String MAIL = BASE + "/mail/";

    /** FlowWork itself: the push-token cookie and the JS bridge live here. */
    private static final String[] APP_HOSTS = {"flowwork.app", "www.flowwork.app"};

    /** Also kept inside the WebView (FlowDrive open/save/upload). */
    private static final String[] DRIVE_HOSTS = {"flowdrive.co.za", "www.flowdrive.co.za"};

    /** Origins allowed to talk to the native bridge. */
    public static final String[] BRIDGE_ORIGINS = {
            "https://www.flowwork.app", "https://flowwork.app",
            "https://www.flowdrive.co.za", "https://flowdrive.co.za",
    };

    private static final String[] AUTH_PATHS = {
            "/login.php", "/register.php", "/logout.php", "/accept-invite.php",
            "/shared/password_reset_request.php", "/shared/password_reset_verify.php",
    };

    private Links() {
    }

    private static boolean hostIn(String host, String[] hosts) {
        if (host == null) {
            return false;
        }
        for (String h : hosts) {
            if (host.equalsIgnoreCase(h)) {
                return true;
            }
        }
        return false;
    }

    public static boolean isAppHost(String host) {
        return hostIn(host, APP_HOSTS);
    }

    /** https URL on FlowWork or FlowDrive. */
    public static boolean isInternal(Uri uri) {
        return uri != null && "https".equalsIgnoreCase(uri.getScheme())
                && (hostIn(uri.getHost(), APP_HOSTS) || hostIn(uri.getHost(), DRIVE_HOSTS));
    }

    public static boolean isInternal(String url) {
        return url != null && isInternal(Uri.parse(url));
    }

    public static boolean isMail(Uri uri) {
        if (uri == null || !"https".equalsIgnoreCase(uri.getScheme()) || !isAppHost(uri.getHost())) {
            return false;
        }
        String path = uri.getPath();
        return path != null && (path.equals("/mail") || path.startsWith("/mail/"));
    }

    public static boolean isMail(String url) {
        return url != null && isMail(Uri.parse(url));
    }

    /** Sign-in / sign-up pages: nobody is signed in yet. */
    public static boolean isAuthPage(String url) {
        if (url == null) {
            return true;
        }
        Uri uri = Uri.parse(url);
        String path = uri.getPath();
        if (!isAppHost(uri.getHost()) || path == null) {
            return true;
        }
        if (path.isEmpty() || path.equals("/") || path.equals("/index.php")) {
            return true; // redirects to /login.php or /home.php
        }
        for (String p : AUTH_PATHS) {
            if (path.equalsIgnoreCase(p)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Absolute FlowWork URL for a server-supplied link, or null when it is not
     * a plain same-site path (protects against a crafted payload or intent
     * opening some other site inside the app).
     */
    public static String resolve(String link) {
        if (link == null) {
            return null;
        }
        link = link.trim();
        if (link.isEmpty() || link.length() > 2000) {
            return null;
        }
        for (int i = 0; i < link.length(); i++) {
            char c = link.charAt(i);
            if (c < 0x20 || c == '\\') {
                return null;
            }
        }
        if (link.startsWith("/") && !link.startsWith("//")) {
            return BASE + link;
        }
        return isInternal(link) ? link : null;
    }

    /** Append one query parameter, keeping any #fragment at the end. */
    public static String withParam(String url, String key, String value) {
        int hash = url.indexOf('#');
        String head = hash >= 0 ? url.substring(0, hash) : url;
        String frag = hash >= 0 ? url.substring(hash) : "";
        return head + (head.contains("?") ? "&" : "?") + Uri.encode(key) + "=" + Uri.encode(value) + frag;
    }
}
