# FlowWork Android app

A native shell around the Flowwork web app, with three launcher icons that
share one install and one sign-in:

| Icon | Opens | Activity |
|---|---|---|
| **FlowWork** (purple) | the whole app (home page) | `MainActivity` |
| **FlowMail** (red) | straight into the mailbox, own Recents card; also offered as an email app for `mailto:` links | `mail/MailActivity` |
| **FlowDrive** (orange) | FlowDrive in the Files app (sign-in screen on first use) | `drive/DriveSetupActivity` |

Plus "Save to FlowDrive" in every app's Share menu and FlowDrive as a location
in the Files app / file pickers (`drive/`).

## Layout

```
app/src/main/java/app/flowwork/
  WebShellActivity.java   the WebView screen shared by FlowWork and FlowMail
  MainActivity.java       FlowWork icon (starts at /)
  mail/MailActivity.java  FlowMail icon (starts at /mail/, handles mailto:)
  Links.java              which URLs stay in the app; safe server paths
  Downloads.java          saves page-generated files (CSV/JSON exports)
  FlowWorkApp.java        creates notification categories at start-up
  push/                   Firebase messaging, notifications, action buttons
  drive/                  FlowDrive documents provider, share target, sign-in
app/src/main/assets/flowwork_bridge.js   injected into FlowWork pages (downloads, print, bar colours)
app/src/main/res/        icons, strings, theme, network security config, shortcuts
tools/icons/make_icons.py                regenerates every icon from assets/logo.webp
```

## What the shell does

- **Notifications** (Firebase Cloud Messaging, data-only messages):
  categories FlowMail › New email / Reply reminders and FlowWork › Event
  reminders / Mentions / Updates, each switchable in Android settings. New
  mail shows one notification per conversation with the sender's avatar,
  subject and preview, bundled per mailbox, with **Mark as read**,
  **Archive** and **Reply** buttons. Lock screens show only "New email".
  Tapping opens the exact conversation, event or board item, in the right
  company. Opening FlowMail clears new-mail notifications, and reading a
  conversation on any other device removes it from the phone. Server side:
  `notifications/README.md`.
- **Sign-in** stays in the WebView cookie store (flushed on pause). The push
  token is handed over as a cookie and tied to the signed-in device server-side.
- **Uploads** with a camera option; **downloads** of server files through
  Android's downloader, and of files the page builds itself (CSV/JSON
  exports, which crashed the old app) straight into Downloads.
- **Print / Save as PDF**: `window.print()` (invoices, quotes, reports, mail)
  opens Android's print dialog; PDF/print pages open inside the app with the
  session instead of in Chrome without it.
- **Edge-to-edge** (required from Android 15): the page is kept clear of the
  status bar, navigation bar, notch and keyboard, and the bars take the
  page's own colours (light/dark aware).
- **Predictive Back** (Android 16): Back goes back in the page, then home.
- **Offline screen** that reconnects by itself when the network returns;
  recovers if the page renderer is killed instead of crashing.
- `mailto:` links open the FlowMail composer; `tel:`, WhatsApp, maps and other
  sites open in their apps. HTTPS only (`res/xml/network_security_config.xml`).
- Long-press shortcuts: FlowWork › Calendar, Projects; FlowMail › New email;
  FlowDrive › Settings. The icons keep their own colours even with Android's
  "themed icons" switched on (no monochrome layer on purpose).

## Build

Android Studio or the command line (JDK 17+):

```
./gradlew assembleDebug        # app/build/outputs/apk/debug/app-debug.apk
./gradlew lintDebug            # app/build/reports/lint-results-debug.html
./gradlew bundleRelease        # needs keystore.properties (see app/build.gradle)
```

`app/google-services.json` belongs to Firebase project `flowwork-f26fe`.

## Before each Play release

1. Raise `versionCode` (and `versionName`) in `app/build.gradle`.
2. `./gradlew lintDebug` shows no errors.
3. Install on a real phone and run the checklist in `notifications/README.md`
   ("Check it"), plus: FlowMail icon opens the inbox; tapping an email address
   in another app offers FlowMail; a CSV export lands in Downloads; Print on an
   invoice opens the print dialog; airplane mode shows the offline screen and
   it recovers on its own.

## Icons

`tools/icons/make_icons.py` builds the adaptive foregrounds (glyph kept inside
the 66 dp safe zone so no launcher mask clips it) in each icon's colour,
legacy icons and the white status-bar icons from `assets/logo.webp` at the
repo root. The gradient backgrounds are `res/drawable/ic_launcher*_background.xml`. It needs Python with `opencv-python-headless`, `numpy` and `pillow`:

```
python tools/icons/make_icons.py ../assets/logo.webp app/src/main/res /tmp/icon-previews
```
