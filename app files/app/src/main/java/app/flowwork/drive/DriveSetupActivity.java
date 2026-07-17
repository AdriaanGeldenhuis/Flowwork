package app.flowwork.drive;

import android.app.Activity;
import android.content.Intent;
import android.content.pm.PackageManager;
import android.graphics.Typeface;
import android.net.Uri;
import android.os.Build;
import android.os.Bundle;
import android.provider.DocumentsContract;
import android.text.InputType;
import android.util.TypedValue;
import android.view.View;
import android.view.ViewGroup;
import android.widget.Button;
import android.widget.EditText;
import android.widget.LinearLayout;
import android.widget.ScrollView;
import android.widget.TextView;
import android.widget.Toast;

import java.io.IOException;

/**
 * One-time FlowDrive sign-in. After connecting, FlowDrive appears as a
 * location in the Android Files app (and every app's file picker), showing
 * My Drive, Company Drive and FlowWork Drive like normal folders.
 */
public class DriveSetupActivity extends Activity {

    private EditText urlInput;
    private EditText emailInput;
    private EditText passwordInput;
    private Button connectButton;
    private Button openFilesButton;
    private Button disconnectButton;
    private TextView statusText;

    @Override
    protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);
        buildUi();
        refreshState();
    }

    private void buildUi() {
        int pad = dp(20);
        LinearLayout column = new LinearLayout(this);
        column.setOrientation(LinearLayout.VERTICAL);
        column.setPadding(pad, pad, pad, pad);

        TextView title = new TextView(this);
        title.setText("FlowDrive on this phone");
        title.setTextSize(TypedValue.COMPLEX_UNIT_SP, 22);
        title.setTypeface(null, Typeface.BOLD);
        column.addView(title);

        TextView blurb = new TextView(this);
        blurb.setText("Sign in once and FlowDrive appears as a folder in your "
                + "Files app - with My Drive, Company Drive and FlowWork Drive. "
                + "Use the same email and password as the FlowDrive website.");
        blurb.setPadding(0, dp(8), 0, dp(16));
        column.addView(blurb);

        urlInput = addField(column, "Server address", InputType.TYPE_TEXT_VARIATION_URI);
        emailInput = addField(column, "Email",
                InputType.TYPE_CLASS_TEXT | InputType.TYPE_TEXT_VARIATION_EMAIL_ADDRESS);
        passwordInput = addField(column, "Password",
                InputType.TYPE_CLASS_TEXT | InputType.TYPE_TEXT_VARIATION_PASSWORD);

        connectButton = new Button(this);
        connectButton.setText("Connect");
        connectButton.setOnClickListener(new View.OnClickListener() {
            @Override
            public void onClick(View v) {
                connect();
            }
        });
        column.addView(connectButton);

        openFilesButton = new Button(this);
        openFilesButton.setText("Open in Files app");
        openFilesButton.setOnClickListener(new View.OnClickListener() {
            @Override
            public void onClick(View v) {
                openFilesApp();
            }
        });
        column.addView(openFilesButton);

        disconnectButton = new Button(this);
        disconnectButton.setText("Disconnect FlowDrive");
        disconnectButton.setOnClickListener(new View.OnClickListener() {
            @Override
            public void onClick(View v) {
                DriveStore.clear(DriveSetupActivity.this);
                notifyRootsChanged();
                statusText.setText("Disconnected. Sign in again to bring FlowDrive back.");
                refreshState();
            }
        });
        column.addView(disconnectButton);

        statusText = new TextView(this);
        statusText.setPadding(0, dp(12), 0, 0);
        column.addView(statusText);

        ScrollView scroll = new ScrollView(this);
        scroll.addView(column, new ViewGroup.LayoutParams(
                ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.WRAP_CONTENT));
        setContentView(scroll);
    }

    private EditText addField(LinearLayout parent, String hint, int inputType) {
        EditText field = new EditText(this);
        field.setHint(hint);
        field.setInputType(inputType);
        field.setSingleLine(true);
        parent.addView(field, new LinearLayout.LayoutParams(
                ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.WRAP_CONTENT));
        return field;
    }

    private void refreshState() {
        urlInput.setText(DriveStore.getUrl(this));
        emailInput.setText(DriveStore.getEmail(this));
        passwordInput.setText("");
        boolean configured = DriveStore.isConfigured(this);
        openFilesButton.setVisibility(configured ? View.VISIBLE : View.GONE);
        disconnectButton.setVisibility(configured ? View.VISIBLE : View.GONE);
        if (configured) {
            statusText.setText("Connected as " + DriveStore.getEmail(this)
                    + ". FlowDrive is available in your Files app under Browse.");
        }
    }

    private void connect() {
        final String url = urlInput.getText().toString().trim();
        final String email = emailInput.getText().toString().trim();
        final String password = passwordInput.getText().toString();
        if (url.isEmpty() || email.isEmpty() || password.isEmpty()) {
            statusText.setText("Please fill in the server address, email and password.");
            return;
        }
        connectButton.setEnabled(false);
        statusText.setText("Checking your sign-in...");
        new Thread(new Runnable() {
            @Override
            public void run() {
                String error = null;
                try {
                    new DavClient(url, email, password).list("");
                } catch (IllegalArgumentException e) {
                    error = e.getMessage();
                } catch (IOException e) {
                    error = e.getMessage();
                }
                final String finalError = error;
                runOnUiThread(new Runnable() {
                    @Override
                    public void run() {
                        connectButton.setEnabled(true);
                        if (finalError == null) {
                            DriveStore.save(DriveSetupActivity.this, url, email, password);
                            notifyRootsChanged();
                            refreshState();
                            statusText.setText("Connected! Open your Files app - FlowDrive "
                                    + "now appears under Browse, next to Downloads and "
                                    + "your other storage.");
                            Toast.makeText(DriveSetupActivity.this,
                                    "FlowDrive connected", Toast.LENGTH_SHORT).show();
                            // Android 13+ drops notifications (used for
                            // upload status) unless the user grants this.
                            if (Build.VERSION.SDK_INT >= 33 && checkSelfPermission(
                                    "android.permission.POST_NOTIFICATIONS")
                                    != PackageManager.PERMISSION_GRANTED) {
                                requestPermissions(new String[]{
                                        "android.permission.POST_NOTIFICATIONS"}, 100);
                            }
                        } else {
                            statusText.setText("Could not connect: " + finalError);
                        }
                    }
                });
            }
        }).start();
    }

    private void notifyRootsChanged() {
        getContentResolver().notifyChange(
                DocumentsContract.buildRootsUri(FlowDriveProvider.AUTHORITY), null);
    }

    private void openFilesApp() {
        // Ask the system documents UI to show our root directly.
        try {
            Uri uri = DocumentsContract.buildRootUri(
                    FlowDriveProvider.AUTHORITY, FlowDriveProvider.ROOT_ID);
            Intent intent = new Intent(Intent.ACTION_VIEW);
            intent.setDataAndType(uri, "vnd.android.document/root");
            intent.addFlags(Intent.FLAG_ACTIVITY_NEW_TASK);
            startActivity(intent);
            return;
        } catch (Exception ignored) {
        }
        // Fall back to a generic picker rooted at FlowDrive.
        try {
            Intent intent = new Intent(Intent.ACTION_OPEN_DOCUMENT);
            intent.addCategory(Intent.CATEGORY_OPENABLE);
            intent.setType("*/*");
            startActivity(intent);
        } catch (Exception e) {
            Toast.makeText(this, "Open your Files app and look for FlowDrive under Browse.",
                    Toast.LENGTH_LONG).show();
        }
    }

    private int dp(int value) {
        return Math.round(TypedValue.applyDimension(TypedValue.COMPLEX_UNIT_DIP,
                value, getResources().getDisplayMetrics()));
    }
}
