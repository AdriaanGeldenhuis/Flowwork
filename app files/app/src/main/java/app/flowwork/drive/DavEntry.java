package app.flowwork.drive;

/**
 * One resource returned by the FlowDrive WebDAV server (dav.php).
 * {@code path} is the decoded path relative to the DAV root, without leading
 * or trailing slashes (empty string = the root itself).
 */
public class DavEntry {
    public String path = "";
    public String name = "";
    public boolean directory;
    public long size;
    public long lastModified;
    public String contentType = "";
}
