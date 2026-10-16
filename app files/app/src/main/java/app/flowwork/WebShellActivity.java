package app.flowwork;

import android.Manifest;
import android.annotation.SuppressLint;
import android.app.DownloadManager;
import android.content.ActivityNotFoundException;
import android.content.ClipData;
import android.content.Context;
import android.content.Intent;
import android.content.SharedPreferences;
import android.content.pm.PackageManager;
import android.graphics.Color;
import android.net.ConnectivityManager;
import android.net.MailTo;
import android.net.Network;
import android.net.NetworkCapabilities;
import android.net.Uri;
import android.os.Build;
import android.os.Bundle;
import android.os.Environment;
import android.os.Handler;
import android.os.Looper;
import android.os.Message;
import android.print.PrintAttributes;
import android.print.PrintManager;
import android.provider.MediaStore;
import android.provider.Settings;
import android.util.Base64;
import android.util.TypedValue;
import android.view.Gravity;
import android.view.View;
import android.view.ViewGroup;
import android.webkit.CookieManager;
import android.webkit.RenderProcessGoneDetail;
import android.webkit.URLUtil;
import android.webkit.ValueCallback;
import android.webkit.WebChromeClient;
import android.webkit.WebResourceError;
import android.webkit.WebResourceRequest;
import android.webkit.WebSettings;
import android.webkit.WebView;
import android.webkit.WebViewClient;
import android.widget.Button;
import android.widget.FrameLayout;
import android.widget.ImageView;
import android.widget.LinearLayout;
import android.widget.ProgressBar;
import android.widget.TextView;
import android.widget.Toast;

import androidx.activity.OnBackPressedCallback;
import androidx.activity.result.ActivityResult;
import androidx.activity.result.ActivityResultLauncher;
import androidx.activity.result.contract.ActivityResultContracts;
import androidx.annotation.NonNull;
import androidx.appcompat.app.AppCompatActivity;
import androidx.core.content.ContextCompat;
import androidx.core.content.FileProvider;
import androidx.core.graphics.Insets;
import androidx.core.view.ViewCompat;
import androidx.core.view.WindowCompat;
import androidx.core.view.WindowInsetsCompat;
import androidx.core.view.WindowInsetsControllerCompat;
import androidx.webkit.JavaScriptReplyProxy;
import androidx.webkit.WebMessageCompat;
import androidx.webkit.WebViewCompat;
import androidx.webkit.WebViewFeature;

import org.json.JSONException;
import org.json.JSONObject;

import java.io.File;
import java.io.IOException;
import java.io.InputStream;
import java.nio.charset.StandardCharsets;
import java.util.Arrays;
import java.util.HashSet;
import java.util.Locale;
import java.util.Set;
import java.util.regex.Matcher;
import java.util.regex.Pattern;

import app.flowwork.push.Notifier;
import app.flowwork.push.PushToken;

/**
 * The FlowWork web app in a WebView. MainActivity (FlowWork icon) and
 * MailActivity (FlowMail icon) are thin subclasses that only differ in
 * where they start; each runs in its own task with its own Recents card.
 *
 * Besides showing the site it: keeps sign-in cookies, hands the push token
 * to the server, opens notification taps, uploads files (with a camera
 * option), downloads (including files the page builds itself), prints,
 * opens mailto: links in FlowMail, colours the system bars to match the page,
 * shows an offline screen that reconnects on its own, and survives the page
 * renderer being killed.
 */
public abstract class WebShellActivity extends AppCompatActivity {

    private static final String PREFS = "shell";
    private static final String KEY_ASKED_NOTIFICATIONS = "asked_notifications";
    private static final Set<String> BRIDGE_ORIGINS = new HashSet<>(Arrays.asList(Links.BRIDGE_ORIGINS));

    /** A mailbox page is on screen right now (new-mail notifications would only repeat it). */
    private static volatile boolean mailOnScreen;

    public static boolean isMailOnScreen() {
        return mailOnScreen;
    }

    private FrameLayout root;
    private FrameLayout content;
    private View statusBg;
    private View navBg;
    private WebView webView;
    private ProgressBar progress;
    private View offlineView;
    private TextView offlineTitle;
    private TextView offlineText;
    private OnBackPressedCallback backCallback;

    private boolean bridgeSupported;
    private boolean docStartScript;
    private String bridgeJs = "";
    private String failedUrl;
    private boolean mainFrameError; // the current top-level load failed
    private boolean resumed;
    private boolean askedThisRun;
    private ConnectivityManager.NetworkCallback networkCallback;
    private final Handler main = new Handler(Looper.getMainLooper());

    // <input type="file">
    private ValueCallback<Uri[]> filePathCallback;
    private Uri cameraOutputUri;

    // Waiting for the legacy storage permission (Android 9 and older)
    private Runnable afterStoragePermission;

    private final ActivityResultLauncher<Intent> fileChooser =
            registerForActivityResult(new ActivityResultContracts.StartActivityForResult(), this::onFileChosen);

    private final ActivityResultLauncher<String> storagePermission =
            registerForActivityResult(new ActivityResultContracts.RequestPermission(), granted -> {
                Runnable next = afterStoragePermission;
                afterStoragePermission = null;
                if (granted && next != null) {
                    next.run();
                } else if (!granted) {
                    toast(getString(R.string.storage_permission_needed));
                }
            });

    private final ActivityResultLauncher<String> notificationPermission =
            registerForActivityResult(new ActivityResultContracts.RequestPermission(),
                    granted -> sendToPage(statusMessage()));

    /** Page to open when nothing else was asked for. */
    protected abstract String startUrl();

    /** FlowMail clears its new-mail notifications while it is on screen. */
    protected boolean clearsMailNotifications() {
        return true;
    }

    // ------------------------------------------------------------------ lifecycle

    @Override
    protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);
        WindowCompat.setDecorFitsSystemWindows(getWindow(), false);
        configureSystemBars();
        buildViews();
        setContentView(root);

        // Before the first request: the server reads the push token from this cookie.
        CookieManager.getInstance().setAcceptCookie(true);
        PushToken.applyCookie(this);
        PushToken.refresh(this);
        bridgeJs = readAsset("flowwork_bridge.js");

        createWebView();

        backCallback = new OnBackPressedCallback(false) {
            @Override
            public void handleOnBackPressed() {
                if (webView != null && webView.canGoBack()) {
                    webView.goBack();
                } else {
                    setEnabled(false);
                    getOnBackPressedDispatcher().onBackPressed();
                }
            }
        };
        getOnBackPressedDispatcher().addCallback(this, backCallback);

        // A recreated activity (process death) restores its history; a fresh
        // start (or a notification tap) opens the requested page.
        String target = savedInstanceState == null ? urlFromIntent(getIntent()) : null;
        if (savedInstanceState == null) {
            handleNotificationTap(getIntent());
        }
        boolean restored = false;
        if (target == null && savedInstanceState != null) {
            android.webkit.WebBackForwardList history = webView.restoreState(savedInstanceState);
            restored = history != null && history.getSize() > 0;
        }
        if (!restored) {
            webView.loadUrl(target != null ? target : startUrl());
        }
        watchNetwork();
    }

    @Override
    protected void onNewIntent(Intent intent) {
        super.onNewIntent(intent);
        setIntent(intent);
        handleNotificationTap(intent);
        String url = urlFromIntent(intent);
        if (url != null && webView != null) {
            cancelFileChooser();
            showOffline(false, false);
            webView.loadUrl(url);
        }
    }

    @Override
    protected void onResume() {
        super.onResume();
        resumed = true;
        if (webView != null) {
            webView.onResume();
            onPageVisible(webView.getUrl());
        }
        sendToPage(statusMessage()); // the user may have changed notification settings
    }

    @Override
    protected void onPause() {
        resumed = false;
        mailOnScreen = false;
        if (webView != null) {
            webView.onPause();
        }
        // Write the sign-in cookies to disk now. WebView otherwise saves them
        // on a timer, so closing the app right after signing in could lose
        // them and show the sign-in page again on the next launch.
        CookieManager.getInstance().flush();
        super.onPause();
    }

    @Override
    protected void onSaveInstanceState(@NonNull Bundle outState) {
        super.onSaveInstanceState(outState);
        if (webView != null) {
            webView.saveState(outState);
        }
    }

    @Override
    protected void onDestroy() {
        if (networkCallback != null) {
            try {
                ConnectivityManager cm = getSystemService(ConnectivityManager.class);
                if (cm != null) {
                    cm.unregisterNetworkCallback(networkCallback);
                }
            } catch (Exception ignored) {
            }
            networkCallback = null;
        }
        cancelFileChooser();
        if (webView != null) {
            content.removeView(webView);
            webView.destroy();
            webView = null;
        }
        super.onDestroy();
    }

    /**
     * Transparent bars over our own colour strips (statusBg / navBg). The
     * Window colour setters are deprecated from Android 15, where the bars
     * are always transparent; they are still needed below that.
     */
    @SuppressWarnings("deprecation")
    private void configureSystemBars() {
        if (Build.VERSION.SDK_INT >= 29) {
            getWindow().setNavigationBarContrastEnforced(false);
            getWindow().setStatusBarContrastEnforced(false);
        }
        if (Build.VERSION.SDK_INT < 35) {
            getWindow().setStatusBarColor(Color.TRANSPARENT);
            // No dark navigation-bar buttons before Android 8: keep that bar black.
            getWindow().setNavigationBarColor(Build.VERSION.SDK_INT < 26 ? Color.BLACK : Color.TRANSPARENT);
        }
    }

    // ------------------------------------------------------------------ intents

    /**
     * URL an incoming intent asks for, or null. Only FlowWork / FlowDrive
     * pages are accepted: the activity is exported, so another app could try
     * to open some other site inside it.
     */
    protected String urlFromIntent(Intent intent) {
        if (intent == null) {
            return null;
        }
        String url = Links.resolve(intent.getStringExtra(Notifier.EXTRA_URL));
        if (url == null && Intent.ACTION_VIEW.equals(intent.getAction()) && intent.getData() != null) {
            Uri data = intent.getData();
            if ("mailto".equalsIgnoreCase(data.getScheme())) {
                return composeUrl(data.toString());
            }
            url = Links.resolve(data.toString());
        }
        if (url == null) {
            return null;
        }
        String nid = intent.getStringExtra(Notifier.EXTRA_NID);
        if (nid != null && nid.matches("\\d{1,18}")) {
            url = Links.withParam(url, "fw_nid", nid); // marks the bell item read
        }
        return url;
    }

    private void handleNotificationTap(Intent intent) {
        if (intent != null && intent.hasExtra(Notifier.EXTRA_TAG)) {
            Notifier.onOpened(this, intent.getStringExtra(Notifier.EXTRA_TAG),
                    intent.getStringExtra(Notifier.EXTRA_GROUP));
        }
    }

    /** FlowMail composer pre-filled from a mailto: link. */
    protected static String composeUrl(String mailto) {
        StringBuilder url = new StringBuilder(Links.BASE + "/mail/compose.php?mode=new");
        try {
            MailTo m = MailTo.parse(mailto);
            if (m.getTo() != null && !m.getTo().isEmpty()) {
                url.append("&to=").append(Uri.encode(m.getTo()));
            }
            if (m.getSubject() != null && !m.getSubject().isEmpty()) {
                url.append("&subject=").append(Uri.encode(m.getSubject()));
            }
        } catch (Exception ignored) {
            // malformed mailto: just open an empty composer
        }
        return url.toString();
    }

    // ------------------------------------------------------------------ views

    private void buildViews() {
        root = new FrameLayout(this);
        root.setBackgroundColor(ContextCompat.getColor(this, R.color.shell_background));

        statusBg = new View(this);
        navBg = new View(this);
        int bg = ContextCompat.getColor(this, R.color.shell_background);
        statusBg.setBackgroundColor(bg);
        navBg.setBackgroundColor(bg);
        root.addView(statusBg, new FrameLayout.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT, 0, Gravity.TOP));
        root.addView(navBg, new FrameLayout.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT, 0, Gravity.BOTTOM));

        content = new FrameLayout(this);
        root.addView(content, new FrameLayout.LayoutParams(
                ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.MATCH_PARENT));

        progress = new ProgressBar(this, null, android.R.attr.progressBarStyleHorizontal);
        progress.setMax(100);
        progress.setIndeterminate(false);
        progress.setProgressTintList(android.content.res.ColorStateList.valueOf(
                ContextCompat.getColor(this, R.color.brand)));
        progress.setProgressBackgroundTintList(android.content.res.ColorStateList.valueOf(Color.TRANSPARENT));
        progress.setVisibility(View.GONE);

        offlineView = buildOfflineView();
        offlineView.setVisibility(View.GONE);

        // Draw edge-to-edge (required from Android 15) and keep the page clear
        // of the status bar, navigation bar, display cutout and keyboard.
        ViewCompat.setOnApplyWindowInsetsListener(root, (v, insets) -> {
            Insets bars = insets.getInsets(WindowInsetsCompat.Type.systemBars()
                    | WindowInsetsCompat.Type.displayCutout());
            Insets ime = insets.getInsets(WindowInsetsCompat.Type.ime());
            int bottom = Math.max(bars.bottom, ime.bottom);
            FrameLayout.LayoutParams lp = (FrameLayout.LayoutParams) content.getLayoutParams();
            lp.setMargins(bars.left, bars.top, bars.right, bottom);
            content.setLayoutParams(lp);
            statusBg.getLayoutParams().height = bars.top;
            statusBg.requestLayout();
            navBg.getLayoutParams().height = bars.bottom;
            navBg.requestLayout();
            return WindowInsetsCompat.CONSUMED;
        });
    }

    private View buildOfflineView() {
        int pad = dp(32);
        LinearLayout box = new LinearLayout(this);
        box.setOrientation(LinearLayout.VERTICAL);
        box.setGravity(Gravity.CENTER);
        box.setPadding(pad, pad, pad, pad);
        box.setBackgroundColor(ContextCompat.getColor(this, R.color.shell_background));
        box.setClickable(true); // swallow taps meant for the error page underneath

        ImageView icon = new ImageView(this);
        icon.setImageResource(R.drawable.ic_offline);
        icon.setImportantForAccessibility(View.IMPORTANT_FOR_ACCESSIBILITY_NO);
        box.addView(icon, new LinearLayout.LayoutParams(dp(64), dp(64)));

        offlineTitle = new TextView(this);
        offlineTitle.setTextColor(ContextCompat.getColor(this, R.color.shell_text));
        offlineTitle.setTextSize(TypedValue.COMPLEX_UNIT_SP, 20);
        offlineTitle.setTypeface(null, android.graphics.Typeface.BOLD);
        offlineTitle.setGravity(Gravity.CENTER);
        offlineTitle.setPadding(0, dp(20), 0, dp(8));
        box.addView(offlineTitle);

        offlineText = new TextView(this);
        offlineText.setTextColor(ContextCompat.getColor(this, R.color.shell_text_muted));
        offlineText.setTextSize(TypedValue.COMPLEX_UNIT_SP, 15);
        offlineText.setGravity(Gravity.CENTER);
        offlineText.setLineSpacing(0, 1.2f);
        box.addView(offlineText);

        Button retry = new Button(this);
        retry.setText(R.string.offline_retry);
        retry.setAllCaps(false);
        retry.setTextColor(Color.WHITE);
        retry.setBackgroundTintList(android.content.res.ColorStateList.valueOf(
                ContextCompat.getColor(this, R.color.brand)));
        retry.setOnClickListener(v -> retry());
        LinearLayout.LayoutParams lp = new LinearLayout.LayoutParams(
                ViewGroup.LayoutParams.WRAP_CONTENT, ViewGroup.LayoutParams.WRAP_CONTENT);
        lp.topMargin = dp(24);
        box.addView(retry, lp);
        return box;
    }

    private void showOffline(boolean show, boolean networkUp) {
        if (show) {
            offlineTitle.setText(networkUp ? R.string.offline_server_title : R.string.offline_title);
            offlineText.setText(networkUp ? R.string.offline_server_text : R.string.offline_text);
            progress.setVisibility(View.GONE);
        }
        offlineView.setVisibility(show ? View.VISIBLE : View.GONE);
    }

    private void retry() {
        showOffline(false, false);
        if (webView != null) {
            webView.loadUrl(failedUrl != null ? failedUrl : startUrl());
        }
    }

    // ------------------------------------------------------------------ WebView

    @SuppressLint("SetJavaScriptEnabled")
    private void createWebView() {
        webView = new WebView(this);
        webView.setBackgroundColor(ContextCompat.getColor(this, R.color.shell_background));
        content.removeAllViews();
        content.addView(webView, new FrameLayout.LayoutParams(
                ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.MATCH_PARENT));
        content.addView(progress, new FrameLayout.LayoutParams(
                ViewGroup.LayoutParams.MATCH_PARENT, dp(3), Gravity.TOP));
        content.addView(offlineView, new FrameLayout.LayoutParams(
                ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.MATCH_PARENT));
        WebView.setWebContentsDebuggingEnabled(BuildConfig.DEBUG);

        WebSettings s = webView.getSettings();
        s.setJavaScriptEnabled(true);
        s.setDomStorageEnabled(true);
        s.setCacheMode(WebSettings.LOAD_DEFAULT);
        // Like Chrome: no http scripts/frames on https pages; old emails with
        // http images still show them (upgraded to https where possible).
        s.setMixedContentMode(WebSettings.MIXED_CONTENT_COMPATIBILITY_MODE);
        s.setAllowFileAccess(false);
        // window.open() (Print/PDF buttons, mail print view)
        s.setSupportMultipleWindows(true);
        s.setJavaScriptCanOpenWindowsAutomatically(true);
        // Lets the server recognise the app ("Signed-in devices", push binding).
        s.setUserAgentString(s.getUserAgentString() + " FlowWorkApp/" + BuildConfig.VERSION_NAME);

        installBridge();
        webView.setWebViewClient(new ShellClient());
        webView.setWebChromeClient(new ShellChrome());
        webView.setDownloadListener((url, ua, disposition, mime, length) ->
                onDownload(url, disposition, mime));
    }

    /** Replace a WebView whose renderer died, without losing the user's place. */
    private void rebuildWebView() {
        String url = webView != null ? webView.getUrl() : null;
        if (webView != null) {
            content.removeView(webView);
            webView.destroy();
        }
        createWebView();
        webView.loadUrl(url != null && Links.isInternal(url) ? url : startUrl());
    }

    private final class ShellClient extends WebViewClient {
        @Override
        public boolean shouldOverrideUrlLoading(WebView view, WebResourceRequest request) {
            if (!request.isForMainFrame()) {
                return false; // iframes (e.g. Office viewer) load in place
            }
            return route(request.getUrl());
        }

        @Override
        public void onPageStarted(WebView view, String url, android.graphics.Bitmap favicon) {
            mainFrameError = false;
            progress.setProgress(5);
            progress.setVisibility(View.VISIBLE);
        }

        @Override
        public void onPageFinished(WebView view, String url) {
            progress.setVisibility(View.GONE);
            if (!mainFrameError) {
                showOffline(false, false); // e.g. Back to a page that loads fine
            }
            backCallback.setEnabled(view.canGoBack());
            if (!docStartScript && bridgeSupported && Links.isInternal(url) && !bridgeJs.isEmpty()) {
                view.evaluateJavascript(bridgeJs, null);
            }
            onPageVisible(url);
            maybeAskForNotifications(url);
        }

        @Override
        public void doUpdateVisitedHistory(WebView view, String url, boolean isReload) {
            // Also fires for in-page navigation (the mail app's #routes).
            backCallback.setEnabled(view.canGoBack());
            onPageVisible(url);
        }

        @Override
        public void onReceivedError(WebView view, WebResourceRequest request, WebResourceError error) {
            if (!request.isForMainFrame()) {
                return;
            }
            CharSequence what = error.getDescription();
            if (error.getErrorCode() == ERROR_UNSUPPORTED_SCHEME
                    || (what != null && what.toString().contains("ERR_ABORTED"))) {
                return; // a download or a link handed to another app, not a failure
            }
            failedUrl = request.getUrl().toString();
            mainFrameError = true;
            showOffline(true, isOnline());
        }

        @Override
        public boolean onRenderProcessGone(WebView view, RenderProcessGoneDetail detail) {
            // The page's renderer crashed or was killed to free memory. Without
            // this the whole app would crash; instead start a fresh WebView.
            if (view == webView) {
                main.post(WebShellActivity.this::rebuildWebView);
            }
            return true;
        }
    }

    /**
     * Where a top-level navigation goes: FlowWork/FlowDrive stay here, mailto:
     * opens the FlowMail composer, everything else goes to the right app.
     * @return true when handled (the WebView must not load it)
     */
    private boolean route(Uri url) {
        String scheme = url.getScheme() == null ? "" : url.getScheme().toLowerCase(Locale.ROOT);
        switch (scheme) {
            case "https":
                if (Links.isInternal(url)) {
                    return false;
                }
                openExternal(url);
                return true;
            case "http":
                if (Links.isInternal(url.buildUpon().scheme("https").build())) {
                    webView.loadUrl(url.buildUpon().scheme("https").build().toString());
                } else {
                    openExternal(url);
                }
                return true;
            case "mailto":
                webView.loadUrl(composeUrl(url.toString()));
                return true;
            case "blob":
            case "data":
            case "about":
            case "javascript":
                return false;
            case "intent":
                openIntentUri(url.toString());
                return true;
            default: // tel:, sms:, whatsapp:, geo:, market: ...
                openExternal(url);
                return true;
        }
    }

    private void openExternal(Uri url) {
        try {
            startActivity(new Intent(Intent.ACTION_VIEW, url).addCategory(Intent.CATEGORY_BROWSABLE));
        } catch (ActivityNotFoundException e) {
            toast(getString(R.string.no_app_for_link));
        } catch (Exception ignored) {
        }
    }

    /** intent:// links, restricted to what a browser would allow. */
    private void openIntentUri(String uri) {
        try {
            Intent i = Intent.parseUri(uri, Intent.URI_INTENT_SCHEME);
            i.addCategory(Intent.CATEGORY_BROWSABLE);
            i.setComponent(null);
            i.setSelector(null);
            try {
                startActivity(i);
            } catch (ActivityNotFoundException e) {
                String fallback = i.getStringExtra("browser_fallback_url");
                if (fallback != null) {
                    Uri f = Uri.parse(fallback);
                    if (!route(f) && Links.isInternal(f)) {
                        webView.loadUrl(fallback);
                    }
                }
            }
        } catch (Exception ignored) {
        }
    }

    private final class ShellChrome extends WebChromeClient {
        @Override
        public void onProgressChanged(WebView view, int newProgress) {
            if (newProgress >= 100) {
                progress.setVisibility(View.GONE);
            } else {
                progress.setVisibility(View.VISIBLE);
                progress.setProgress(Math.max(5, newProgress));
            }
        }

        /**
         * window.open(): FlowWork pages (quote/invoice PDF, mail print view)
         * open in this window so they keep the sign-in; other sites go to the
         * browser.
         */
        @Override
        public boolean onCreateWindow(WebView view, boolean isDialog, boolean isUserGesture, Message resultMsg) {
            if (!isUserGesture) {
                return false; // like a browser's popup blocker (e.g. an ad in a third-party viewer)
            }
            final WebView popup = new WebView(view.getContext());
            final boolean[] gone = {false};
            final Runnable dispose = () -> {
                if (!gone[0]) {
                    gone[0] = true;
                    popup.destroy();
                }
            };
            popup.setWebViewClient(new WebViewClient() {
                private boolean done;

                @Override
                public boolean shouldOverrideUrlLoading(WebView v, WebResourceRequest request) {
                    if (!done) {
                        done = true;
                        Uri u = request.getUrl();
                        if (!route(u) && webView != null && Links.isInternal(u)) {
                            webView.loadUrl(u.toString());
                        }
                        main.post(dispose);
                    }
                    return true;
                }
            });
            WebView.WebViewTransport transport = (WebView.WebViewTransport) resultMsg.obj;
            transport.setWebView(popup);
            resultMsg.sendToTarget();
            // window.open('') that never navigates: do not keep it forever.
            main.postDelayed(dispose, 15_000);
            return true;
        }

        @Override
        public boolean onShowFileChooser(WebView view, ValueCallback<Uri[]> callback, FileChooserParams params) {
            return openFileChooser(callback, params);
        }
    }

    // ------------------------------------------------------------------ page state

    /** A page (or in-page route) is on screen. */
    private void onPageVisible(String url) {
        if (url == null || !resumed) {
            return;
        }
        mailOnScreen = Links.isMail(url);
        if (Links.isMail(url) && clearsMailNotifications()) {
            try {
                // Looking at the mailbox: the new-mail notifications have done their job.
                Notifier.cancelGroup(this, "mail");
                Notifier.cancelForUrl(this, url);
            } catch (RuntimeException e) {
                android.util.Log.w("FlowWork", "clearing notifications failed", e); // never take the page down
            }
        }
    }

    /**
     * Ask once, after sign-in, for permission to show notifications
     * (Android 13+). Later the account page can ask again or open settings.
     */
    private void maybeAskForNotifications(String url) {
        if (Build.VERSION.SDK_INT < 33 || askedThisRun || Links.isAuthPage(url) || !Links.isInternal(url)) {
            return;
        }
        if (hasNotificationPermission()) {
            return;
        }
        SharedPreferences p = getSharedPreferences(PREFS, MODE_PRIVATE);
        if (p.getBoolean(KEY_ASKED_NOTIFICATIONS, false)) {
            return;
        }
        askedThisRun = true;
        p.edit().putBoolean(KEY_ASKED_NOTIFICATIONS, true).apply();
        notificationPermission.launch(Manifest.permission.POST_NOTIFICATIONS);
    }

    private boolean hasNotificationPermission() {
        return Build.VERSION.SDK_INT < 33 || ContextCompat.checkSelfPermission(this,
                Manifest.permission.POST_NOTIFICATIONS) == PackageManager.PERMISSION_GRANTED;
    }

    private boolean notificationsEnabled() {
        return hasNotificationPermission()
                && androidx.core.app.NotificationManagerCompat.from(this).areNotificationsEnabled();
    }

    private void openNotificationSettings() {
        Intent i;
        if (Build.VERSION.SDK_INT >= 26) {
            i = new Intent(Settings.ACTION_APP_NOTIFICATION_SETTINGS)
                    .putExtra(Settings.EXTRA_APP_PACKAGE, getPackageName());
        } else {
            i = new Intent(Settings.ACTION_APPLICATION_DETAILS_SETTINGS,
                    Uri.fromParts("package", getPackageName(), null));
        }
        try {
            startActivity(i);
        } catch (Exception ignored) {
        }
    }

    // ------------------------------------------------------------------ JS bridge

    private JavaScriptReplyProxy replyProxy;

    private void installBridge() {
        docStartScript = false;
        bridgeSupported = false;
        if (!WebViewFeature.isFeatureSupported(WebViewFeature.WEB_MESSAGE_LISTENER)) {
            return; // very old System WebView: everything else still works
        }
        bridgeSupported = true;
        // Only FlowWork / FlowDrive pages get window.FlowWorkNative, and only
        // messages from the top frame are acted on. Email HTML is shown in a
        // sandboxed frame without scripts, so it cannot use the object.
        WebViewCompat.addWebMessageListener(webView, "FlowWorkNative", BRIDGE_ORIGINS,
                (view, message, sourceOrigin, isMainFrame, proxy) -> {
                    if (!isMainFrame || !BRIDGE_ORIGINS.contains(sourceOrigin.toString())) {
                        return;
                    }
                    replyProxy = proxy;
                    try {
                        onBridgeMessage(message);
                    } catch (RuntimeException e) {
                        android.util.Log.w("FlowWork", "bridge message failed", e); // a page must not crash the app
                    }
                });
        if (WebViewFeature.isFeatureSupported(WebViewFeature.DOCUMENT_START_SCRIPT) && !bridgeJs.isEmpty()) {
            WebViewCompat.addDocumentStartJavaScript(webView, bridgeJs, BRIDGE_ORIGINS);
            docStartScript = true;
        }
    }

    private void onBridgeMessage(WebMessageCompat message) {
        String raw = message.getData();
        if (raw == null || raw.length() > 40 * 1024 * 1024) {
            return;
        }
        JSONObject msg;
        try {
            msg = new JSONObject(raw);
        } catch (JSONException e) {
            return;
        }
        switch (msg.optString("type")) {
            case "download":
                saveFromPage(msg.optString("name"), msg.optString("mime"), msg.optString("data"));
                break;
            case "downloadFailed":
                toast(getString(R.string.download_failed));
                break;
            case "print":
                print(msg.optString("title"));
                break;
            case "bars":
                applyBarColours(parseColour(msg.optString("top")), parseColour(msg.optString("bottom")));
                break;
            case "status":
                sendToPage(statusMessage());
                break;
            case "askNotifications":
                if (Build.VERSION.SDK_INT >= 33 && !hasNotificationPermission()
                        && (shouldShowRequestPermissionRationale(Manifest.permission.POST_NOTIFICATIONS)
                        || !getSharedPreferences(PREFS, MODE_PRIVATE).getBoolean(KEY_ASKED_NOTIFICATIONS, false))) {
                    getSharedPreferences(PREFS, MODE_PRIVATE).edit()
                            .putBoolean(KEY_ASKED_NOTIFICATIONS, true).apply();
                    notificationPermission.launch(Manifest.permission.POST_NOTIFICATIONS);
                } else if (!notificationsEnabled()) {
                    openNotificationSettings(); // denied for good: only Settings can turn it on
                } else {
                    sendToPage(statusMessage());
                }
                break;
            case "openNotificationSettings":
                openNotificationSettings();
                break;
            default:
                break;
        }
    }

    private JSONObject statusMessage() {
        JSONObject o = new JSONObject();
        try {
            o.put("type", "status");
            o.put("app", getString(R.string.app_name));
            o.put("version", BuildConfig.VERSION_NAME);
            o.put("notifications", notificationsEnabled());
            o.put("permission", hasNotificationPermission() ? "granted"
                    : (getSharedPreferences(PREFS, MODE_PRIVATE).getBoolean(KEY_ASKED_NOTIFICATIONS, false)
                    ? "denied" : "prompt"));
            o.put("push", !PushToken.get(this).isEmpty());
        } catch (JSONException ignored) {
        }
        return o;
    }

    private void sendToPage(JSONObject msg) {
        if (replyProxy == null || webView == null || !Links.isInternal(webView.getUrl())
                || !WebViewFeature.isFeatureSupported(WebViewFeature.WEB_MESSAGE_LISTENER)) {
            return;
        }
        try {
            replyProxy.postMessage(msg.toString());
        } catch (Exception ignored) {
            // page navigated away
        }
    }

    // ------------------------------------------------------------------ bar colours

    private static final Pattern RGB = Pattern.compile(
            "rgba?\\(\\s*(\\d{1,3})[\\s,]+(\\d{1,3})[\\s,]+(\\d{1,3})(?:[\\s,/]+([\\d.]+%?))?\\s*\\)");

    /** CSS colour (#rgb, #rrggbb, rgb(), rgba()) or null. */
    static Integer parseColour(String css) {
        if (css == null) {
            return null;
        }
        String c = css.trim().toLowerCase(Locale.ROOT);
        try {
            if (c.matches("#[0-9a-f]{3}")) {
                return Color.rgb(Integer.parseInt(c.substring(1, 2), 16) * 17,
                        Integer.parseInt(c.substring(2, 3), 16) * 17, Integer.parseInt(c.substring(3, 4), 16) * 17);
            }
            if (c.matches("#[0-9a-f]{6}")) {
                return Color.parseColor(c);
            }
            Matcher m = RGB.matcher(c);
            if (m.matches()) {
                String a = m.group(4);
                if (a != null) {
                    double alpha = a.endsWith("%") ? Double.parseDouble(a.substring(0, a.length() - 1)) / 100
                            : Double.parseDouble(a);
                    if (alpha < 0.5) {
                        return null;
                    }
                }
                return Color.rgb(Math.min(255, Integer.parseInt(m.group(1))),
                        Math.min(255, Integer.parseInt(m.group(2))), Math.min(255, Integer.parseInt(m.group(3))));
            }
        } catch (NumberFormatException ignored) {
        }
        return null;
    }

    private void applyBarColours(Integer top, Integer bottom) {
        if (top == null && bottom == null) {
            return;
        }
        if (top == null) {
            top = bottom;
        }
        if (bottom == null) {
            bottom = top;
        }
        statusBg.setBackgroundColor(top);
        navBg.setBackgroundColor(bottom);
        root.setBackgroundColor(bottom);
        WindowInsetsControllerCompat bars = WindowCompat.getInsetsController(getWindow(), root);
        bars.setAppearanceLightStatusBars(isLight(top));
        if (Build.VERSION.SDK_INT >= 26) {
            bars.setAppearanceLightNavigationBars(isLight(bottom));
        }
    }

    private static boolean isLight(int c) {
        double l = (0.299 * Color.red(c) + 0.587 * Color.green(c) + 0.114 * Color.blue(c)) / 255.0;
        return l > 0.6;
    }

    // ------------------------------------------------------------------ uploads

    /** Picker (plus camera capture) for <input type="file">. */
    private boolean openFileChooser(ValueCallback<Uri[]> callback, WebChromeClient.FileChooserParams params) {
        cancelFileChooser(); // one that never returned (double-tap etc.)
        filePathCallback = callback;
        cameraOutputUri = null;

        Intent pick = new Intent(Intent.ACTION_GET_CONTENT);
        pick.addCategory(Intent.CATEGORY_OPENABLE);
        pick.setType("*/*");
        String[] accept = params.getAcceptTypes();
        if (accept != null && accept.length > 0 && accept[0] != null && !accept[0].trim().isEmpty()) {
            pick.putExtra(Intent.EXTRA_MIME_TYPES, mimeTypes(accept));
        }
        if (params.getMode() == WebChromeClient.FileChooserParams.MODE_OPEN_MULTIPLE) {
            pick.putExtra(Intent.EXTRA_ALLOW_MULTIPLE, true);
        }

        // Camera capture writes to a temp file shared via FileProvider.
        Intent camera = null;
        try {
            File dir = new File(getCacheDir(), "webview_uploads");
            if (!dir.exists() && !dir.mkdirs()) {
                throw new IOException("Cannot create upload cache dir");
            }
            File photo = File.createTempFile("capture_", ".jpg", dir);
            cameraOutputUri = FileProvider.getUriForFile(this, getPackageName() + ".fileprovider", photo);
            camera = new Intent(MediaStore.ACTION_IMAGE_CAPTURE);
            camera.putExtra(MediaStore.EXTRA_OUTPUT, cameraOutputUri);
            camera.setClipData(ClipData.newRawUri("camera", cameraOutputUri));
            camera.addFlags(Intent.FLAG_GRANT_READ_URI_PERMISSION | Intent.FLAG_GRANT_WRITE_URI_PERMISSION);
        } catch (IOException | IllegalArgumentException e) {
            cameraOutputUri = null;
        }

        Intent chooser = Intent.createChooser(pick, getString(R.string.choose_file));
        if (camera != null) {
            chooser.putExtra(Intent.EXTRA_INITIAL_INTENTS, new Intent[]{camera});
        }
        try {
            fileChooser.launch(chooser);
        } catch (ActivityNotFoundException e) {
            filePathCallback = null;
            cameraOutputUri = null;
            toast(getString(R.string.no_file_picker));
            return false;
        }
        return true;
    }

    /** accept=".pdf,image/*" → MIME types the system picker understands. */
    private static String[] mimeTypes(String[] accept) {
        Set<String> out = new HashSet<>();
        for (String group : accept) {
            if (group == null) {
                continue;
            }
            for (String a : group.split(",")) {
                a = a.trim().toLowerCase(Locale.ROOT);
                if (a.isEmpty()) {
                    continue;
                }
                if (a.startsWith(".")) {
                    String m = android.webkit.MimeTypeMap.getSingleton().getMimeTypeFromExtension(a.substring(1));
                    if (m != null) {
                        out.add(m);
                    }
                } else if (a.contains("/")) {
                    out.add(a);
                }
            }
        }
        return out.isEmpty() ? new String[]{"*/*"} : out.toArray(new String[0]);
    }

    private void onFileChosen(ActivityResult result) {
        if (filePathCallback == null) {
            return;
        }
        Uri[] results = null;
        Intent data = result.getData();
        if (result.getResultCode() == RESULT_OK) {
            if (data != null && data.getClipData() != null) {
                ClipData clip = data.getClipData();
                results = new Uri[clip.getItemCount()];
                for (int i = 0; i < clip.getItemCount(); i++) {
                    results[i] = clip.getItemAt(i).getUri();
                }
            } else if (data != null && data.getData() != null) {
                results = new Uri[]{data.getData()};
            } else if (cameraOutputUri != null) {
                results = new Uri[]{cameraOutputUri}; // camera apps return no data with EXTRA_OUTPUT
            }
        }
        filePathCallback.onReceiveValue(results);
        filePathCallback = null;
        cameraOutputUri = null;
    }

    private void cancelFileChooser() {
        if (filePathCallback != null) {
            filePathCallback.onReceiveValue(null);
            filePathCallback = null;
        }
    }

    // ------------------------------------------------------------------ downloads

    private void onDownload(String url, String disposition, String mime) {
        String scheme = Uri.parse(url).getScheme();
        if ("blob".equalsIgnoreCase(scheme) || "data".equalsIgnoreCase(scheme)) {
            // Android's downloader only takes http(s); the page has to hand the bytes over.
            if (bridgeSupported && webView != null) {
                webView.evaluateJavascript("window.__fwNative&&window.__fwNative.download("
                        + JSONObject.quote(url) + ",'')", null);
            } else {
                toast(getString(R.string.download_needs_webview));
            }
            return;
        }
        if (!"http".equalsIgnoreCase(scheme) && !"https".equalsIgnoreCase(scheme)) {
            return;
        }
        withStorage(() -> enqueueDownload(url, disposition, mime));
    }

    private void enqueueDownload(String url, String disposition, String mime) {
        String fileName = URLUtil.guessFileName(url, disposition, mime);
        try {
            DownloadManager.Request request = new DownloadManager.Request(Uri.parse(url));
            if (mime != null && !mime.isEmpty()) {
                request.setMimeType(mime);
            }
            // Pass session cookies so the download is authenticated
            String cookies = CookieManager.getInstance().getCookie(url);
            if (cookies != null) {
                request.addRequestHeader("Cookie", cookies);
            }
            request.addRequestHeader("User-Agent", webView.getSettings().getUserAgentString());
            request.setTitle(fileName);
            request.setDescription(getString(R.string.download_started, fileName));
            request.setNotificationVisibility(DownloadManager.Request.VISIBILITY_VISIBLE_NOTIFY_COMPLETED);
            request.setDestinationInExternalPublicDir(Environment.DIRECTORY_DOWNLOADS, fileName);
            DownloadManager dm = (DownloadManager) getSystemService(DOWNLOAD_SERVICE);
            if (dm == null) {
                toast(getString(R.string.download_unavailable));
                return;
            }
            dm.enqueue(request);
            toast(getString(R.string.download_started, fileName));
        } catch (Exception e) {
            toast(getString(R.string.download_failed));
        }
    }

    private void saveFromPage(String name, String mime, String base64) {
        if (base64 == null || base64.isEmpty()) {
            toast(getString(R.string.download_failed));
            return;
        }
        final Context app = getApplicationContext();
        withStorage(() -> new Thread(() -> {
            String saved = null;
            try {
                byte[] bytes = Base64.decode(base64, Base64.DEFAULT);
                saved = Downloads.save(app, name, mime, bytes);
            } catch (Exception ignored) {
            }
            final String result = saved;
            main.post(() -> toast(result != null ? getString(R.string.download_saved, result)
                    : getString(R.string.download_failed)));
        }, "flowwork-save").start());
    }

    /** Android 9 and older need the storage permission to write into Downloads. */
    private void withStorage(Runnable action) {
        if (!Downloads.needsLegacyPermission() || ContextCompat.checkSelfPermission(this,
                Manifest.permission.WRITE_EXTERNAL_STORAGE) == PackageManager.PERMISSION_GRANTED) {
            action.run();
            return;
        }
        afterStoragePermission = action;
        storagePermission.launch(Manifest.permission.WRITE_EXTERNAL_STORAGE);
    }

    // ------------------------------------------------------------------ printing

    private void print(String title) {
        PrintManager pm = (PrintManager) getSystemService(Context.PRINT_SERVICE);
        if (pm == null || webView == null) {
            toast(getString(R.string.print_unavailable));
            return;
        }
        String job = (title == null || title.trim().isEmpty()) ? getString(R.string.app_name) : title.trim();
        try {
            pm.print(job, webView.createPrintDocumentAdapter(job), new PrintAttributes.Builder().build());
        } catch (Exception e) {
            toast(getString(R.string.print_unavailable));
        }
    }

    // ------------------------------------------------------------------ network

    private boolean isOnline() {
        ConnectivityManager cm = getSystemService(ConnectivityManager.class);
        if (cm == null) {
            return true;
        }
        Network n = cm.getActiveNetwork();
        NetworkCapabilities caps = n == null ? null : cm.getNetworkCapabilities(n);
        return caps != null && caps.hasCapability(NetworkCapabilities.NET_CAPABILITY_INTERNET);
    }

    /** Reload by itself when the connection comes back. */
    private void watchNetwork() {
        ConnectivityManager cm = getSystemService(ConnectivityManager.class);
        if (cm == null) {
            return;
        }
        networkCallback = new ConnectivityManager.NetworkCallback() {
            @Override
            public void onAvailable(@NonNull Network network) {
                main.postDelayed(() -> {
                    if (offlineView != null && offlineView.getVisibility() == View.VISIBLE) {
                        retry();
                    }
                }, 600);
            }
        };
        try {
            cm.registerDefaultNetworkCallback(networkCallback);
        } catch (Exception e) {
            networkCallback = null;
        }
    }

    // ------------------------------------------------------------------ helpers

    private String readAsset(String name) {
        try (InputStream in = getAssets().open(name)) {
            byte[] buf = new byte[in.available()];
            int off = 0;
            while (off < buf.length) {
                int n = in.read(buf, off, buf.length - off);
                if (n < 0) {
                    break;
                }
                off += n;
            }
            return new String(buf, 0, off, StandardCharsets.UTF_8);
        } catch (IOException e) {
            return "";
        }
    }

    private void toast(String text) {
        Toast.makeText(this, text, Toast.LENGTH_SHORT).show();
    }

    private int dp(int v) {
        return Math.round(TypedValue.applyDimension(TypedValue.COMPLEX_UNIT_DIP, v,
                getResources().getDisplayMetrics()));
    }
}
