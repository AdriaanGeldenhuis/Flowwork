package app.flowwork;

/**
 * FlowWork launcher icon: the whole web app, starting at the home page.
 * Everything else lives in WebShellActivity.
 */
public class MainActivity extends WebShellActivity {

    @Override
    protected String startUrl() {
        return Links.HOME;
    }
}
