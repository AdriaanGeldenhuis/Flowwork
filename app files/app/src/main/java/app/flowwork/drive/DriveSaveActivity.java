package app.flowwork.drive;

import android.app.Activity;
import android.content.Intent;
import android.database.Cursor;
import android.graphics.Typeface;
import android.net.Uri;
import android.os.Build;
import android.os.Bundle;
import android.provider.OpenableColumns;
import android.util.TypedValue;
import android.view.View;
import android.view.ViewGroup;
import android.widget.AdapterView;
import android.widget.ArrayAdapter;
import android.widget.Button;
import android.widget.LinearLayout;
import android.widget.ListView;
import android.widget.TextView;
import android.widget.Toast;

import java.io.File;
import java.io.FileOutputStream;
import java.io.IOException;
import java.io.InputStream;
import java.io.OutputStream;
import java.util.ArrayList;
import java.util.List;

/**
 * "Save to FlowDrive" share target: appears in every app's Share menu.
 * The user picks a destination folder (browsing the live drive) and the
 * shared file(s) are uploaded straight to the FlowDrive server.
 */
public class DriveSaveActivity extends Activity {

    private final List<Uri> sources = new ArrayList<>();
    private final List<String> folderNames = new ArrayList<>();
    private String currentPath = ""; // "" = root (the drive list)

    private ArrayAdapter<String> adapter;
    private TextView pathText;
    private TextView statusText;
    private Button upButton;
    private Button saveButton;
    private volatile boolean busy;

    @Override
    protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);

        collectSharedFiles();
        if (sources.isEmpty()) {
            Toast.makeText(this, "Nothing to save to FlowDrive.", Toast.LENGTH_LONG).show();
            finish();
            return;
        }
        if (!DriveStore.isConfigured(this)) {
            Toast.makeText(this,
                    "Sign in to FlowDrive first, then share again.", Toast.LENGTH_LONG).show();
            Intent setup = new Intent(this, DriveSetupActivity.class);
            setup.putExtra(DriveSetupActivity.EXTRA_SETTINGS, true);
            startActivity(setup);
            finish();
            return;
        }
        buildUi();
        loadFolders("");
    }

    private void collectSharedFiles() {
        Intent intent = getIntent();
        if (intent == null) {
            return;
        }
        String action = intent.getAction();
        if (Intent.ACTION_SEND.equals(action)) {
            Uri uri = getUriExtra(intent);
            if (uri != null) {
                sources.add(uri);
            }
        } else if (Intent.ACTION_SEND_MULTIPLE.equals(action)) {
            List<Uri> uris = getUriListExtra(intent);
            if (uris != null) {
                for (Uri uri : uris) {
                    if (uri != null) {
                        sources.add(uri);
                    }
                }
            }
        }
    }

    @SuppressWarnings("deprecation")
    private Uri getUriExtra(Intent intent) {
        if (Build.VERSION.SDK_INT >= 33) {
            return intent.getParcelableExtra(Intent.EXTRA_STREAM, Uri.class);
        }
        return (Uri) intent.getParcelableExtra(Intent.EXTRA_STREAM);
    }

    @SuppressWarnings("deprecation")
    private List<Uri> getUriListExtra(Intent intent) {
        if (Build.VERSION.SDK_INT >= 33) {
            return intent.getParcelableArrayListExtra(Intent.EXTRA_STREAM, Uri.class);
        }
        return intent.getParcelableArrayListExtra(Intent.EXTRA_STREAM);
    }

    private void buildUi() {
        int pad = dp(16);
        LinearLayout column = new LinearLayout(this);
        column.setOrientation(LinearLayout.VERTICAL);
        column.setPadding(pad, pad, pad, pad);

        TextView title = new TextView(this);
        title.setText(sources.size() == 1
                ? "Save 1 file to FlowDrive"
                : "Save " + sources.size() + " files to FlowDrive");
        title.setTextSize(TypedValue.COMPLEX_UNIT_SP, 20);
        title.setTypeface(null, Typeface.BOLD);
        column.addView(title);

        pathText = new TextView(this);
        pathText.setPadding(0, dp(8), 0, dp(8));
        column.addView(pathText);

        upButton = new Button(this);
        upButton.setText("Up one folder");
        upButton.setOnClickListener(new View.OnClickListener() {
            @Override
            public void onClick(View v) {
                if (busy || currentPath.isEmpty()) {
                    return;
                }
                int slash = currentPath.lastIndexOf('/');
                loadFolders(slash < 0 ? "" : currentPath.substring(0, slash));
            }
        });
        column.addView(upButton);

        ListView list = new ListView(this);
        adapter = new ArrayAdapter<>(this, android.R.layout.simple_list_item_1, folderNames);
        list.setAdapter(adapter);
        list.setOnItemClickListener(new AdapterView.OnItemClickListener() {
            @Override
            public void onItemClick(AdapterView<?> parent, View view, int position, long id) {
                if (busy || position >= folderNames.size()) {
                    return;
                }
                String name = folderNames.get(position);
                loadFolders(currentPath.isEmpty() ? name : currentPath + "/" + name);
            }
        });
        LinearLayout.LayoutParams listParams = new LinearLayout.LayoutParams(
                ViewGroup.LayoutParams.MATCH_PARENT, 0, 1f);
        column.addView(list, listParams);

        saveButton = new Button(this);
        saveButton.setText("Save here");
        saveButton.setOnClickListener(new View.OnClickListener() {
            @Override
            public void onClick(View v) {
                if (!busy) {
                    uploadAll();
                }
            }
        });
        column.addView(saveButton);

        statusText = new TextView(this);
        statusText.setPadding(0, dp(8), 0, 0);
        column.addView(statusText);

        setContentView(column);
    }

    private void loadFolders(final String path) {
        busy = true;
        statusText.setText("Loading...");
        new Thread(new Runnable() {
            @Override
            public void run() {
                final List<String> names = new ArrayList<>();
                String error = null;
                try {
                    List<DavEntry> entries = DriveStore.client(DriveSaveActivity.this).list(path);
                    for (DavEntry entry : entries) {
                        if (!entry.directory || entry.path.equals(path)) {
                            continue;
                        }
                        String name = entry.name.isEmpty()
                                ? entry.path.substring(entry.path.lastIndexOf('/') + 1)
                                : entry.name;
                        // The server refuses writes into FlowWork Drive.
                        if (path.isEmpty() && name.equalsIgnoreCase("FlowWork Drive")) {
                            continue;
                        }
                        names.add(name);
                    }
                } catch (Exception e) {
                    error = e.getMessage();
                }
                final String finalError = error;
                runOnUiThread(new Runnable() {
                    @Override
                    public void run() {
                        busy = false;
                        if (finalError != null) {
                            statusText.setText("Could not load folders: " + finalError);
                            return;
                        }
                        currentPath = path;
                        folderNames.clear();
                        folderNames.addAll(names);
                        adapter.notifyDataSetChanged();
                        pathText.setText(currentPath.isEmpty()
                                ? "Choose a drive:" : "FlowDrive / " + currentPath);
                        upButton.setEnabled(!currentPath.isEmpty());
                        boolean canSave = !currentPath.isEmpty();
                        saveButton.setEnabled(canSave);
                        statusText.setText(canSave
                                ? "" : "Open a drive or folder, then tap Save here.");
                    }
                });
            }
        }).start();
    }

    private void uploadAll() {
        busy = true;
        saveButton.setEnabled(false);
        final String targetDir = currentPath;
        new Thread(new Runnable() {
            @Override
            public void run() {
                int done = 0;
                final List<String> failures = new ArrayList<>();
                for (Uri uri : sources) {
                    done++;
                    final String progress = "Uploading " + done + " of " + sources.size() + "...";
                    runOnUiThread(new Runnable() {
                        @Override
                        public void run() {
                            statusText.setText(progress);
                        }
                    });
                    try {
                        uploadOne(uri, targetDir);
                    } catch (Exception e) {
                        failures.add(displayName(uri) + " (" + e.getMessage() + ")");
                    }
                }
                runOnUiThread(new Runnable() {
                    @Override
                    public void run() {
                        busy = false;
                        if (failures.isEmpty()) {
                            Toast.makeText(DriveSaveActivity.this,
                                    "Saved to FlowDrive / " + targetDir, Toast.LENGTH_LONG).show();
                            finish();
                        } else {
                            saveButton.setEnabled(true);
                            statusText.setText("Some files failed: "
                                    + failures.toString() + " - tap Save here to retry.");
                        }
                    }
                });
            }
        }).start();
    }

    private void uploadOne(Uri uri, String targetDir) throws IOException {
        DavClient client = DriveStore.client(this);
        if (client == null) {
            throw new IOException("Not signed in to FlowDrive.");
        }
        String name = sanitizeName(displayName(uri));

        // Copy the shared stream to a temp file so the upload has a known size.
        File tmp = File.createTempFile("share_", ".bin", getCacheDir());
        try {
            InputStream in = getContentResolver().openInputStream(uri);
            if (in == null) {
                throw new IOException("Cannot read the shared file.");
            }
            try {
                OutputStream out = new FileOutputStream(tmp);
                try {
                    byte[] buf = new byte[65536];
                    int n;
                    while ((n = in.read(buf)) != -1) {
                        out.write(buf, 0, n);
                    }
                } finally {
                    out.close();
                }
            } finally {
                in.close();
            }

            // Avoid silently overwriting an existing file with the same name.
            String candidate = name;
            for (int i = 1; i <= 32; i++) {
                if (client.stat(targetDir + "/" + candidate) == null) {
                    break;
                }
                int dot = name.lastIndexOf('.');
                candidate = dot > 0
                        ? name.substring(0, dot) + " (" + i + ")" + name.substring(dot)
                        : name + " (" + i + ")";
            }
            client.put(targetDir + "/" + candidate, tmp, getContentResolver().getType(uri));
        } finally {
            //noinspection ResultOfMethodCallIgnored
            tmp.delete();
        }
    }

    private String displayName(Uri uri) {
        try {
            Cursor c = getContentResolver().query(uri,
                    new String[]{OpenableColumns.DISPLAY_NAME}, null, null, null);
            if (c != null) {
                try {
                    if (c.moveToFirst()) {
                        String name = c.getString(0);
                        if (name != null && !name.trim().isEmpty()) {
                            return name;
                        }
                    }
                } finally {
                    c.close();
                }
            }
        } catch (Exception ignored) {
        }
        String last = uri.getLastPathSegment();
        return last == null || last.trim().isEmpty() ? "shared-file" : last;
    }

    private static String sanitizeName(String displayName) {
        String name = displayName == null ? "" : displayName;
        StringBuilder sb = new StringBuilder(name.length());
        for (int i = 0; i < name.length(); i++) {
            char c = name.charAt(i);
            if (c < 0x20 || "/\\:*?\"<>|".indexOf(c) >= 0) {
                sb.append('_');
            } else {
                sb.append(c);
            }
        }
        String out = sb.toString().trim();
        if (out.length() > 200) {
            out = out.substring(0, 200).trim();
        }
        if (out.isEmpty() || out.equals(".") || out.equals("..")) {
            out = "shared-file";
        }
        return out;
    }

    private int dp(int value) {
        return Math.round(TypedValue.applyDimension(TypedValue.COMPLEX_UNIT_DIP,
                value, getResources().getDisplayMetrics()));
    }
}
