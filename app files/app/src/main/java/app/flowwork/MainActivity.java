package app.flowwork;

import android.Manifest;
import android.app.DownloadManager;
import android.content.ActivityNotFoundException;
import android.content.ClipData;
import android.content.Intent;
import android.content.pm.PackageManager;
import android.net.Uri;
import android.os.Build;
import android.os.Bundle;
import android.os.Environment;
import android.os.Message;
import android.provider.MediaStore;
import android.webkit.CookieManager;
import android.webkit.DownloadListener;
import android.webkit.URLUtil;
import android.webkit.ValueCallback;
import android.webkit.WebChromeClient;
import android.webkit.WebResourceRequest;
import android.webkit.WebSettings;
import android.webkit.WebView;
import android.webkit.WebViewClient;
import android.widget.Toast;
import androidx.appcompat.app.AppCompatActivity;
import androidx.core.app.ActivityCompat;
import androidx.core.content.ContextCompat;
import androidx.core.content.FileProvider;

import java.io.File;
import java.io.IOException;

public class MainActivity extends AppCompatActivity {

    private WebView webView;
    private static final int STORAGE_PERMISSION_CODE = 1;
    private static final int FILE_CHOOSER_CODE = 2;

    // Hosts that stay inside the app's WebView. FlowDrive is part of the app
    // experience (open/save/upload files); anything else opens in the browser.
    private static final String[] INTERNAL_HOSTS = {
            "flowwork.app", "www.flowwork.app",
            "flowdrive.co.za", "www.flowdrive.co.za",
    };

    private String pendingDownloadUrl;
    private String pendingContentDisposition;
    private String pendingMimeType;

    // <input type="file"> support (FlowDrive uploads)
    private ValueCallback<Uri[]> filePathCallback;
    private Uri cameraOutputUri;

    @Override
    protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);

        // Create WebView programmatically
        webView = new WebView(this);
        setContentView(webView);

        // WebView settings
        WebSettings settings = webView.getSettings();
        settings.setJavaScriptEnabled(true);
        settings.setDomStorageEnabled(true);
        settings.setDatabaseEnabled(true);
        settings.setCacheMode(WebSettings.LOAD_DEFAULT);
        settings.setMixedContentMode(WebSettings.MIXED_CONTENT_ALWAYS_ALLOW);
        // Required for window.open() (e.g. the "Print" button on invoice/quote
        // pages). Without these, window.open silently returns null and the
        // user sees nothing happen.
        settings.setSupportMultipleWindows(true);
        settings.setJavaScriptCanOpenWindowsAutomatically(true);
        // Let the web apps detect they are running inside the Android app.
        settings.setUserAgentString(settings.getUserAgentString() + " FlowWorkApp/1.0");

        // WebViewClient — keep FlowWork and FlowDrive inside the app, hand
        // everything else (external links, mailto:, tel:) to the system.
        webView.setWebViewClient(new WebViewClient() {
            @Override
            public boolean shouldOverrideUrlLoading(WebView view, WebResourceRequest request) {
                if (!request.isForMainFrame()) {
                    return false; // iframes (e.g. Office viewer) load in place
                }
                Uri url = request.getUrl();
                String scheme = url.getScheme();
                if (!"http".equals(scheme) && !"https".equals(scheme)) {
                    try {
                        startActivity(new Intent(Intent.ACTION_VIEW, url));
                    } catch (Exception ignored) {
                    }
                    return true;
                }
                String host = url.getHost();
                if (host != null) {
                    for (String internal : INTERNAL_HOSTS) {
                        if (host.equalsIgnoreCase(internal)) {
                            return false;
                        }
                    }
                }
                try {
                    startActivity(new Intent(Intent.ACTION_VIEW, url));
                } catch (Exception ignored) {
                }
                return true;
            }
        });

        // WebChromeClient — handle window.open by forwarding the target URL to
        // the system browser, and <input type="file"> by showing a picker with
        // a camera option (photograph a document straight into FlowDrive).
        webView.setWebChromeClient(new WebChromeClient() {
            @Override
            public boolean onCreateWindow(WebView view, boolean isDialog,
                    boolean isUserGesture, Message resultMsg) {
                WebView popup = new WebView(view.getContext());
                popup.setWebViewClient(new WebViewClient() {
                    @Override
                    public boolean shouldOverrideUrlLoading(WebView v,
                            WebResourceRequest request) {
                        try {
                            startActivity(new Intent(Intent.ACTION_VIEW, request.getUrl()));
                        } catch (Exception ignored) {
                        }
                        return true;
                    }
                });
                WebView.WebViewTransport transport =
                        (WebView.WebViewTransport) resultMsg.obj;
                transport.setWebView(popup);
                resultMsg.sendToTarget();
                return true;
            }

            @Override
            public boolean onShowFileChooser(WebView view, ValueCallback<Uri[]> callback,
                    FileChooserParams params) {
                return openFileChooser(callback, params);
            }
        });

        // Handle file downloads (PDF, CSV, etc.)
        webView.setDownloadListener(new DownloadListener() {
            @Override
            public void onDownloadStart(String url, String userAgent,
                    String contentDisposition, String mimeType, long contentLength) {
                downloadFile(url, contentDisposition, mimeType);
            }
        });

        // Load URL
        webView.loadUrl("https://www.flowwork.app");
    }

    /**
     * Show a file picker (plus camera capture) for <input type="file">.
     * Without this, upload buttons in the WebView silently do nothing.
     */
    private boolean openFileChooser(ValueCallback<Uri[]> callback,
            WebChromeClient.FileChooserParams params) {
        // Cancel a chooser that never returned (double-tap etc.)
        if (filePathCallback != null) {
            filePathCallback.onReceiveValue(null);
        }
        filePathCallback = callback;
        cameraOutputUri = null;

        // Content picker honouring the page's accept types
        Intent pick = new Intent(Intent.ACTION_GET_CONTENT);
        pick.addCategory(Intent.CATEGORY_OPENABLE);
        pick.setType("*/*");
        String[] acceptTypes = params.getAcceptTypes();
        if (acceptTypes != null && acceptTypes.length > 0
                && acceptTypes[0] != null && !acceptTypes[0].trim().isEmpty()) {
            pick.putExtra(Intent.EXTRA_MIME_TYPES, acceptTypes);
        }
        if (params.getMode() == WebChromeClient.FileChooserParams.MODE_OPEN_MULTIPLE) {
            pick.putExtra(Intent.EXTRA_ALLOW_MULTIPLE, true);
        }

        // Camera capture writes to a temp file shared via FileProvider
        Intent camera = null;
        try {
            File dir = new File(getCacheDir(), "webview_uploads");
            if (!dir.exists() && !dir.mkdirs()) {
                throw new IOException("Cannot create upload cache dir");
            }
            File photo = File.createTempFile("capture_", ".jpg", dir);
            cameraOutputUri = FileProvider.getUriForFile(
                    this, getPackageName() + ".fileprovider", photo);
            camera = new Intent(MediaStore.ACTION_IMAGE_CAPTURE);
            camera.putExtra(MediaStore.EXTRA_OUTPUT, cameraOutputUri);
            camera.setClipData(ClipData.newRawUri("camera", cameraOutputUri));
            camera.addFlags(Intent.FLAG_GRANT_READ_URI_PERMISSION
                    | Intent.FLAG_GRANT_WRITE_URI_PERMISSION);
        } catch (IOException e) {
            cameraOutputUri = null;
        }

        Intent chooser = Intent.createChooser(pick, "Choose file");
        if (camera != null) {
            chooser.putExtra(Intent.EXTRA_INITIAL_INTENTS, new Intent[]{camera});
        }
        try {
            startActivityForResult(chooser, FILE_CHOOSER_CODE);
        } catch (ActivityNotFoundException e) {
            filePathCallback = null;
            cameraOutputUri = null;
            Toast.makeText(this, "No file picker available", Toast.LENGTH_LONG).show();
            return false;
        }
        return true;
    }

    @Override
    protected void onActivityResult(int requestCode, int resultCode, Intent data) {
        super.onActivityResult(requestCode, resultCode, data);
        if (requestCode != FILE_CHOOSER_CODE || filePathCallback == null) {
            return;
        }

        Uri[] results = null;
        if (resultCode == RESULT_OK) {
            if (data != null && data.getClipData() != null) {
                // Multiple files selected
                ClipData clip = data.getClipData();
                results = new Uri[clip.getItemCount()];
                for (int i = 0; i < clip.getItemCount(); i++) {
                    results[i] = clip.getItemAt(i).getUri();
                }
            } else if (data != null && data.getData() != null) {
                results = new Uri[]{data.getData()};
            } else if (cameraOutputUri != null) {
                // Camera apps return no data when EXTRA_OUTPUT is used
                results = new Uri[]{cameraOutputUri};
            }
        }
        filePathCallback.onReceiveValue(results);
        filePathCallback = null;
        cameraOutputUri = null;
    }

    private void downloadFile(String url, String contentDisposition, String mimeType) {
        // On Android 9 and below, request storage permission first.
        // Android 10+ uses scoped storage and doesn't need WRITE_EXTERNAL_STORAGE
        // for DownloadManager writing into the public Downloads directory.
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.Q &&
                ContextCompat.checkSelfPermission(this, Manifest.permission.WRITE_EXTERNAL_STORAGE)
                        != PackageManager.PERMISSION_GRANTED) {
            pendingDownloadUrl = url;
            pendingContentDisposition = contentDisposition;
            pendingMimeType = mimeType;
            ActivityCompat.requestPermissions(this,
                    new String[]{Manifest.permission.WRITE_EXTERNAL_STORAGE},
                    STORAGE_PERMISSION_CODE);
            return;
        }

        String fileName = URLUtil.guessFileName(url, contentDisposition, mimeType);

        DownloadManager.Request request = new DownloadManager.Request(Uri.parse(url));
        if (mimeType != null && !mimeType.isEmpty()) {
            request.setMimeType(mimeType);
        }

        // Pass session cookies so the download is authenticated
        String cookies = CookieManager.getInstance().getCookie(url);
        if (cookies != null) {
            request.addRequestHeader("Cookie", cookies);
        }
        request.addRequestHeader("User-Agent", webView.getSettings().getUserAgentString());

        request.setTitle(fileName);
        request.setDescription("Downloading " + fileName);
        request.setNotificationVisibility(
                DownloadManager.Request.VISIBILITY_VISIBLE_NOTIFY_COMPLETED);
        request.setDestinationInExternalPublicDir(Environment.DIRECTORY_DOWNLOADS, fileName);
        // Make the file visible in the Downloads app / file pickers.
        request.allowScanningByMediaScanner();

        DownloadManager dm = (DownloadManager) getSystemService(DOWNLOAD_SERVICE);
        if (dm != null) {
            dm.enqueue(request);
            Toast.makeText(this, "Downloading " + fileName, Toast.LENGTH_SHORT).show();
        } else {
            Toast.makeText(this, "Download service unavailable", Toast.LENGTH_LONG).show();
        }
    }

    @Override
    public void onRequestPermissionsResult(int requestCode, String[] permissions,
            int[] grantResults) {
        super.onRequestPermissionsResult(requestCode, permissions, grantResults);
        if (requestCode == STORAGE_PERMISSION_CODE) {
            if (grantResults.length > 0 && grantResults[0] == PackageManager.PERMISSION_GRANTED) {
                if (pendingDownloadUrl != null) {
                    downloadFile(pendingDownloadUrl, pendingContentDisposition, pendingMimeType);
                    pendingDownloadUrl = null;
                }
            } else {
                Toast.makeText(this, "Storage permission needed to download files",
                        Toast.LENGTH_LONG).show();
            }
        }
    }

    @Override
    public void onBackPressed() {
        if (webView.canGoBack()) {
            webView.goBack();
        } else {
            super.onBackPressed();
        }
    }
}
