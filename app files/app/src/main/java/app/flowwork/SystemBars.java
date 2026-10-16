package app.flowwork;

import android.app.Activity;
import android.content.res.Configuration;
import android.os.Build;
import android.view.View;

import androidx.core.graphics.Insets;
import androidx.core.view.ViewCompat;
import androidx.core.view.WindowCompat;
import androidx.core.view.WindowInsetsCompat;
import androidx.core.view.WindowInsetsControllerCompat;

/**
 * For the plain (non-WebView) screens such as the FlowDrive sign-in and
 * "Save to FlowDrive". From Android 15 every app draws edge-to-edge, so
 * without this their content would sit under the status bar, the notch, the
 * navigation buttons and the keyboard. Earlier versions are left as they were.
 */
public final class SystemBars {

    private SystemBars() {
    }

    /** Pad {@code root} (on top of its own padding) clear of the bars, cutout and keyboard. */
    public static void fit(Activity activity, View root) {
        if (Build.VERSION.SDK_INT < 35) {
            return;
        }
        final int left = root.getPaddingLeft();
        final int top = root.getPaddingTop();
        final int right = root.getPaddingRight();
        final int bottom = root.getPaddingBottom();
        ViewCompat.setOnApplyWindowInsetsListener(root, (v, insets) -> {
            Insets bars = insets.getInsets(WindowInsetsCompat.Type.systemBars()
                    | WindowInsetsCompat.Type.displayCutout());
            Insets ime = insets.getInsets(WindowInsetsCompat.Type.ime());
            v.setPadding(left + bars.left, top + bars.top, right + bars.right,
                    bottom + Math.max(bars.bottom, ime.bottom));
            return WindowInsetsCompat.CONSUMED;
        });
        // The bars are transparent now: dark icons on the light theme, light on the dark one.
        boolean night = (activity.getResources().getConfiguration().uiMode
                & Configuration.UI_MODE_NIGHT_MASK) == Configuration.UI_MODE_NIGHT_YES;
        WindowInsetsControllerCompat c = WindowCompat.getInsetsController(activity.getWindow(), root);
        c.setAppearanceLightStatusBars(!night);
        c.setAppearanceLightNavigationBars(!night);
    }
}
