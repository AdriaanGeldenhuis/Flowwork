package app.flowwork.drive;

import android.util.Xml;

import org.xmlpull.v1.XmlPullParser;

import java.io.File;
import java.io.FileInputStream;
import java.io.IOException;
import java.io.InputStream;
import java.io.OutputStream;
import java.net.URLDecoder;
import java.text.ParseException;
import java.text.SimpleDateFormat;
import java.util.ArrayList;
import java.util.List;
import java.util.Locale;
import java.util.concurrent.TimeUnit;

import okhttp3.Credentials;
import okhttp3.HttpUrl;
import okhttp3.MediaType;
import okhttp3.OkHttpClient;
import okhttp3.Request;
import okhttp3.RequestBody;
import okhttp3.Response;
import okio.BufferedSink;

/**
 * Minimal WebDAV client for the FlowDrive server (dav.php).
 *
 * All paths are decoded, relative to the DAV root, with no leading or
 * trailing slashes ("" = root, "My Drive/Reports/q1.pdf" = a file).
 * Methods are blocking and must not be called on the main thread; the
 * DocumentsProvider entry points run on binder threads, which is fine.
 */
public class DavClient {

    /** HTTP status the caller may want to special-case. */
    public static class DavException extends IOException {
        public final int status;

        public DavException(int status, String message) {
            super(message);
            this.status = status;
        }
    }

    private final HttpUrl base;      // e.g. https://flowdrive.co.za/dav.php/
    private final String basePath;   // decoded path of base, e.g. "/dav.php/"
    private final String authHeader;
    private final OkHttpClient http;

    public DavClient(String baseUrl, String email, String password) {
        HttpUrl parsed = HttpUrl.parse(baseUrl.endsWith("/") ? baseUrl : baseUrl + "/");
        if (parsed == null) {
            throw new IllegalArgumentException("Invalid FlowDrive address: " + baseUrl);
        }
        // Basic auth over plain http would expose the password to anyone on
        // the network path; allow it only for local development hosts.
        if (!parsed.isHttps() && !isPrivateHost(parsed.host())) {
            throw new IllegalArgumentException(
                    "The FlowDrive address must start with https:// so your password stays private.");
        }
        this.base = parsed;
        String p = parsed.encodedPath();
        this.basePath = urlDecode(p.endsWith("/") ? p : p + "/");
        // UTF-8, not OkHttp's ISO-8859-1 default: the server verifies the
        // password against a hash of the UTF-8 bytes the web login sends,
        // so a non-ASCII password would otherwise never authenticate.
        this.authHeader = Credentials.basic(email, password,
                java.nio.charset.StandardCharsets.UTF_8);
        this.http = new OkHttpClient.Builder()
                .connectTimeout(20, TimeUnit.SECONDS)
                .readTimeout(60, TimeUnit.SECONDS)
                .writeTimeout(60, TimeUnit.SECONDS)
                .build();
    }

    private HttpUrl url(String relPath, boolean directory) {
        HttpUrl.Builder b = base.newBuilder();
        if (!relPath.isEmpty()) {
            for (String seg : relPath.split("/")) {
                if (!seg.isEmpty()) {
                    b.addPathSegment(seg);
                }
            }
            if (directory) {
                b.addPathSegment(""); // trailing slash for collections
            }
        }
        return b.build();
    }

    private Request.Builder request(String relPath, boolean directory) {
        return new Request.Builder()
                .url(url(relPath, directory))
                .header("Authorization", authHeader);
    }

    private static void ensureSuccess(Response resp) throws IOException {
        if (!resp.isSuccessful()) {
            String msg;
            int code = resp.code();
            if (code == 401) {
                msg = "FlowDrive sign-in failed - check your email and password in the FlowDrive app.";
            } else if (code == 403) {
                msg = "FlowDrive refused this action (this drive or file type may be read-only).";
            } else if (code == 404) {
                msg = "Not found on FlowDrive.";
            } else if (code == 507) {
                msg = "FlowDrive storage is full.";
            } else {
                msg = "FlowDrive server error (HTTP " + code + ").";
            }
            resp.close();
            throw new DavException(code, msg);
        }
    }

    /** PROPFIND Depth: 1 - the entry itself first (if present), then children. */
    public List<DavEntry> list(String relPath) throws IOException {
        return propfind(relPath, "1");
    }

    /** PROPFIND Depth: 0, or null when the resource does not exist. */
    public DavEntry stat(String relPath) throws IOException {
        try {
            List<DavEntry> entries = propfind(relPath, "0");
            return entries.isEmpty() ? null : entries.get(0);
        } catch (DavException e) {
            if (e.status == 404) {
                return null;
            }
            throw e;
        }
    }

    private List<DavEntry> propfind(String relPath, String depth) throws IOException {
        RequestBody body = RequestBody.create(
                "<?xml version=\"1.0\" encoding=\"utf-8\"?>"
                        + "<D:propfind xmlns:D=\"DAV:\"><D:allprop/></D:propfind>",
                MediaType.parse("application/xml; charset=utf-8"));
        Request req = request(relPath, true)
                .method("PROPFIND", body)
                .header("Depth", depth)
                .build();
        Response resp = http.newCall(req).execute();
        ensureSuccess(resp);
        try {
            InputStream in = resp.body() != null ? resp.body().byteStream() : null;
            if (in == null) {
                return new ArrayList<>();
            }
            return parseMultiStatus(in);
        } finally {
            resp.close();
        }
    }

    /** Streams a file's content; the caller must close the stream. */
    public InputStream get(String relPath) throws IOException {
        Request req = request(relPath, false).get().build();
        Response resp = http.newCall(req).execute();
        ensureSuccess(resp);
        if (resp.body() == null) {
            resp.close();
            throw new IOException("Empty response from FlowDrive.");
        }
        return resp.body().byteStream();
    }

    /** Uploads a local file with PUT. */
    public void put(String relPath, final File source, String contentType) throws IOException {
        MediaType mt = contentType != null && !contentType.isEmpty()
                ? MediaType.parse(contentType) : MediaType.parse("application/octet-stream");
        final MediaType finalMt = mt;
        RequestBody body = new RequestBody() {
            @Override
            public MediaType contentType() {
                return finalMt;
            }

            @Override
            public long contentLength() {
                return source.length();
            }

            @Override
            public void writeTo(BufferedSink sink) throws IOException {
                InputStream in = new FileInputStream(source);
                try {
                    byte[] buf = new byte[65536];
                    int n;
                    while ((n = in.read(buf)) != -1) {
                        sink.write(buf, 0, n);
                    }
                } finally {
                    in.close();
                }
            }
        };
        Response resp = http.newCall(request(relPath, false).put(body).build()).execute();
        ensureSuccess(resp);
        resp.close();
    }

    /** Creates an empty file. */
    public void putEmpty(String relPath) throws IOException {
        RequestBody body = RequestBody.create(new byte[0],
                MediaType.parse("application/octet-stream"));
        Response resp = http.newCall(request(relPath, false).put(body).build()).execute();
        ensureSuccess(resp);
        resp.close();
    }

    public void mkcol(String relPath) throws IOException {
        Response resp = http.newCall(
                request(relPath, true).method("MKCOL", null).build()).execute();
        ensureSuccess(resp);
        resp.close();
    }

    public void delete(String relPath, boolean directory) throws IOException {
        Response resp = http.newCall(
                request(relPath, directory).delete().build()).execute();
        ensureSuccess(resp);
        resp.close();
    }

    public void move(String fromPath, String toPath, boolean directory) throws IOException {
        Response resp = http.newCall(request(fromPath, directory)
                .method("MOVE", null)
                .header("Destination", url(toPath, directory).toString())
                .header("Overwrite", "F")
                .build()).execute();
        ensureSuccess(resp);
        resp.close();
    }

    public void copy(String fromPath, String toPath, boolean directory) throws IOException {
        Response resp = http.newCall(request(fromPath, directory)
                .method("COPY", null)
                .header("Destination", url(toPath, directory).toString())
                .header("Overwrite", "F")
                .build()).execute();
        ensureSuccess(resp);
        resp.close();
    }

    /** Copies a remote file to a local file. */
    public void download(String relPath, File target, OutputStream progressSink) throws IOException {
        InputStream in = get(relPath);
        try {
            OutputStream out = new java.io.FileOutputStream(target);
            try {
                byte[] buf = new byte[65536];
                int n;
                while ((n = in.read(buf)) != -1) {
                    out.write(buf, 0, n);
                    if (progressSink != null) {
                        progressSink.write(buf, 0, n);
                    }
                }
            } finally {
                out.close();
            }
        } finally {
            in.close();
        }
    }

    // ------------------------------------------------------------------
    // multistatus parsing
    // ------------------------------------------------------------------

    private static final String NS_DAV = "DAV:";

    private List<DavEntry> parseMultiStatus(InputStream in) throws IOException {
        List<DavEntry> out = new ArrayList<>();
        try {
            XmlPullParser p = Xml.newPullParser();
            p.setFeature(XmlPullParser.FEATURE_PROCESS_NAMESPACES, true);
            p.setInput(in, null);

            DavEntry cur = null;
            String text = null;
            int depthInsideResourceType = 0;

            for (int ev = p.getEventType(); ev != XmlPullParser.END_DOCUMENT; ev = p.next()) {
                if (ev == XmlPullParser.START_TAG && NS_DAV.equals(p.getNamespace())) {
                    String tag = p.getName();
                    if ("response".equals(tag)) {
                        cur = new DavEntry();
                    } else if ("resourcetype".equals(tag)) {
                        depthInsideResourceType = 1;
                    } else if (depthInsideResourceType > 0 && "collection".equals(tag)) {
                        if (cur != null) {
                            cur.directory = true;
                        }
                    }
                    text = null;
                } else if (ev == XmlPullParser.TEXT) {
                    text = p.getText();
                } else if (ev == XmlPullParser.END_TAG && NS_DAV.equals(p.getNamespace())) {
                    String tag = p.getName();
                    if (cur != null) {
                        if ("href".equals(tag) && text != null) {
                            cur.path = hrefToRelPath(text.trim());
                        } else if ("displayname".equals(tag) && text != null) {
                            cur.name = text.trim();
                        } else if ("getcontentlength".equals(tag) && text != null) {
                            try {
                                cur.size = Long.parseLong(text.trim());
                            } catch (NumberFormatException ignored) {
                            }
                        } else if ("getcontenttype".equals(tag) && text != null) {
                            cur.contentType = text.trim();
                        } else if ("getlastmodified".equals(tag) && text != null) {
                            cur.lastModified = parseHttpDate(text.trim());
                        } else if ("resourcetype".equals(tag)) {
                            depthInsideResourceType = 0;
                        } else if ("response".equals(tag)) {
                            if (cur.name.isEmpty()) {
                                int slash = cur.path.lastIndexOf('/');
                                cur.name = slash >= 0 ? cur.path.substring(slash + 1) : cur.path;
                            }
                            out.add(cur);
                            cur = null;
                        }
                    }
                    text = null;
                }
            }
        } catch (Exception e) {
            throw new IOException("Could not read the FlowDrive folder listing: " + e.getMessage(), e);
        }
        return out;
    }

    /** Converts a multistatus href (encoded, absolute path) to a relative decoded path. */
    private String hrefToRelPath(String href) {
        String path = href;
        int scheme = path.indexOf("://");
        if (scheme >= 0) {
            int slash = path.indexOf('/', scheme + 3);
            path = slash >= 0 ? path.substring(slash) : "/";
        }
        int query = path.indexOf('?');
        if (query >= 0) {
            path = path.substring(0, query);
        }
        path = urlDecode(path);
        if (path.startsWith(basePath)) {
            path = path.substring(basePath.length());
        } else {
            // Tolerate a base path without trailing slash in the href.
            String noSlash = basePath.endsWith("/")
                    ? basePath.substring(0, basePath.length() - 1) : basePath;
            if (path.startsWith(noSlash)) {
                path = path.substring(noSlash.length());
            }
        }
        while (path.startsWith("/")) {
            path = path.substring(1);
        }
        while (path.endsWith("/")) {
            path = path.substring(0, path.length() - 1);
        }
        return path;
    }

    /** Loopback / RFC1918 / .local hosts where cleartext http is tolerable for dev. */
    private static boolean isPrivateHost(String host) {
        String h = host.toLowerCase(Locale.US);
        if (h.equals("localhost") || h.equals("::1") || h.startsWith("127.")
                || h.endsWith(".local")) {
            return true;
        }
        if (h.startsWith("10.") || h.startsWith("192.168.")) {
            return true;
        }
        if (h.startsWith("172.")) {
            String[] parts = h.split("\\.");
            if (parts.length == 4) {
                try {
                    int second = Integer.parseInt(parts[1]);
                    return second >= 16 && second <= 31;
                } catch (NumberFormatException ignored) {
                }
            }
        }
        return false;
    }

    private static String urlDecode(String s) {
        try {
            return URLDecoder.decode(s, "UTF-8");
        } catch (Exception e) {
            return s;
        }
    }

    private static long parseHttpDate(String value) {
        try {
            SimpleDateFormat fmt =
                    new SimpleDateFormat("EEE, dd MMM yyyy HH:mm:ss zzz", Locale.US);
            return fmt.parse(value).getTime();
        } catch (ParseException e) {
            return 0L;
        }
    }
}
