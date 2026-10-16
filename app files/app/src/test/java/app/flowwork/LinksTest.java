package app.flowwork;

import static org.junit.Assert.assertEquals;
import static org.junit.Assert.assertFalse;
import static org.junit.Assert.assertNull;
import static org.junit.Assert.assertTrue;

import android.graphics.Color;

import org.junit.Test;
import org.junit.runner.RunWith;
import org.robolectric.RobolectricTestRunner;
import org.robolectric.annotation.Config;

/** Which URLs may open inside the app, and the small parsers in the shell. */
@RunWith(RobolectricTestRunner.class)
@Config(sdk = 35)
public class LinksTest {

    @Test
    public void serverPathsResolveOnFlowWorkOnly() {
        assertEquals("https://www.flowwork.app/mail/?open=t5", Links.resolve("/mail/?open=t5"));
        assertEquals("https://www.flowwork.app/x", Links.resolve("https://www.flowwork.app/x"));
        assertEquals("https://flowdrive.co.za/drive/", Links.resolve("https://flowdrive.co.za/drive/"));
        assertNull("protocol-relative", Links.resolve("//evil.example/x"));
        assertNull("other site", Links.resolve("https://evil.example/x"));
        assertNull("look-alike host", Links.resolve("https://www.flowwork.app.evil.example/x"));
        assertNull("plain http", Links.resolve("http://www.flowwork.app/x"));
        assertNull("javascript", Links.resolve("javascript:alert(1)"));
        assertNull("backslash trick", Links.resolve("/\\evil.example"));
        assertNull("control characters", Links.resolve("/ma\nil/"));
        assertNull("NUL", Links.resolve("/mail/\u0000x"));
        assertNull(Links.resolve(""));
        assertNull(Links.resolve(null));
    }

    @Test
    public void mailAndAuthPages() {
        assertTrue(Links.isMail("https://www.flowwork.app/mail/#inbox?open=t1"));
        assertTrue(Links.isMail("https://flowwork.app/mail"));
        assertFalse(Links.isMail("https://www.flowwork.app/mailbox/"));
        assertFalse(Links.isMail("https://flowdrive.co.za/mail/"));
        assertTrue(Links.isAuthPage("https://www.flowwork.app/login.php?redirect=%2F"));
        assertTrue(Links.isAuthPage("https://www.flowwork.app/"));
        assertFalse(Links.isAuthPage("https://www.flowwork.app/home.php"));
    }

    @Test
    public void paramsGoBeforeTheFragment() {
        assertEquals("https://x/a?b=1&fw_nid=7#h", Links.withParam("https://x/a?b=1#h", "fw_nid", "7"));
        assertEquals("https://x/a?fw_nid=7", Links.withParam("https://x/a", "fw_nid", "7"));
    }

    @Test
    public void mailtoBecomesAFlowMailDraft() {
        assertEquals("https://www.flowwork.app/mail/compose.php?mode=new&to=ann%40example.com&subject=Hi%20there",
                WebShellActivity.composeUrl("mailto:ann@example.com?subject=Hi%20there"));
        assertEquals("https://www.flowwork.app/mail/compose.php?mode=new",
                WebShellActivity.composeUrl("mailto:"));
    }

    @Test
    public void cssColoursFromThePage() {
        assertEquals(Integer.valueOf(Color.rgb(13, 10, 9)), WebShellActivity.parseColour("#0d0a09"));
        assertEquals(Integer.valueOf(Color.WHITE), WebShellActivity.parseColour("#fff"));
        assertEquals(Integer.valueOf(Color.rgb(17, 16, 21)), WebShellActivity.parseColour("rgb(17, 16, 21)"));
        assertEquals(Integer.valueOf(Color.rgb(1, 2, 3)), WebShellActivity.parseColour("rgba(1, 2, 3, 0.9)"));
        assertNull("transparent", WebShellActivity.parseColour("rgba(0, 0, 0, 0)"));
        assertNull(WebShellActivity.parseColour("var(--bg)"));
        assertNull(WebShellActivity.parseColour(null));
    }
}
