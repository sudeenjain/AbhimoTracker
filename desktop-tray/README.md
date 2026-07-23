# Abhimo Tracker (desktop tray agent)

A background Windows/macOS/Linux tray app that pairs with the onboarding
page (`abhimo://` protocol), stores credentials in an encrypted local
file, tracks foreground application usage and idle time, and reports it to
the same backend the browser frontend already talks to.

## Verified against the real backend
Everything below was checked against your uploaded `backend.zip`
(`ActivityController`, `AttendanceController`, `WorkSessionController`,
`ConsentRequiredMiddleware`, `JwtAuthMiddleware`, `AdminActivityController`,
`public/index.php`, `migrations/005_create_activity_logs.sql`) -- these are
no longer guesses.

- **Login**: `POST /api/login` → `{ token, must_change_password, employee }`.
  Bearer token, `Authorization: Bearer <token>` on every subsequent call.
- **"Should I be tracking right now?"**: `GET /api/attendance/today`
  (behind `JwtAuthMiddleware` + `ConsentRequiredMiddleware`).
  - `403` → temp password not changed yet, or the current monitoring
    policy hasn't been accepted. Tray shows "signed out", tracking stays off.
  - `200` with `sign_in_time` set and `sign_out_time` null → tracking on.
  - Anything else (network error, non-200/403) → tray shows "offline",
    tracking off, retried on the next poll (`monitoringStatusPollMs`, 15s).
- **Sending activity**: `POST /api/activity/ingest`, body
  `{ "entries": [{ "timestamp": "Y-m-d H:i:s", "active_window": "Code",
  "is_idle": false, "duration_seconds": 87 }, ...] }`.
  - `active_window` is the **application/process name only** -- confirmed
    by `AdminActivityController::appUsage`'s docblock ("never a window
    title"). Window titles are never even read from `active-win`.
  - `duration_seconds` is capped at 3600 server-side; entries older than
    48h or more than 5 minutes in the future are rejected.
  - There is **no separate idle endpoint** -- an idle stretch is just an
    entry with `is_idle: true` (and `active_window: null`) in the same
    array.
- **No tray heartbeat endpoint exists.** `POST /api/work-session/heartbeat`
  is explicitly reserved for the browser tab (see that controller's
  docblock). The admin dashboard's "online" status
  (`AdminActivityController::liveStatus`) is instead derived from how
  recent the latest `activity_logs` row is (within 180s). That's why
  `activity-tracker.js` slices any long stretch in one app (or one idle
  period) into ~90s chunks (`chunkFlushMs`) instead of waiting for the
  next app switch to emit anything -- the ingest calls themselves are the
  heartbeat.

## What it does
- **Pairing**: receives an `abhimo://` link from the onboarding page,
  exchanges it for credentials, stores them AES-256-GCM-encrypted in
  Electron's per-user data directory (`credential-store.js`).
- **Manual login fallback**: tray menu → "Sign in manually".
- **Idle detection** (`idle-monitor.js`): OS-wide idle time via Electron's
  built-in `powerMonitor`, default 5-minute threshold, screen lock counts
  as idle immediately. Only a boolean ever leaves this module.
- **Active app tracking** (`activity-tracker.js`): polls the foreground
  app every 3s via `active-win`, tracks only the app/process name, chunks
  continuous stretches into ~90s entries.
- **Offline-safe upload queue** (`upload-queue.js`): batches entries to
  disk, flushes every 30s, survives network loss / app restart.
- **Status icon** in the tray reflects state using the icon set in
  `assets/`: active / idle / offline / signed-out / loading.
- **Collects only**: app/process name, idle/active flag, duration,
  timestamp. No screenshots, keystrokes, mouse coordinates, clipboard,
  window titles, or file contents.

## ⚠️ Still worth checking before rollout
- **Client clock/timezone**: `formatServerTimestamp()` in `main.js` sends
  the employee machine's local wall-clock time, formatted the way
  `ActivityController::ingest` parses by default. If a machine's clock or
  timezone drifts far from the server's, entries can get silently rejected
  (outside the 48h-past/5min-future window) or land on the wrong day in
  reports. Worth a real check across whatever machines you deploy to.
- **`active-win` on your target Windows versions**: it shells out to a
  bundled helper binary per OS; hasn't been runtime-verified here (see
  below).

**I have not runtime-tested any of this** -- no display, no real Electron
runtime, and no live database exist in the sandbox this was built in.
Every file is syntax-checked (`node --check`), and the request/response
shapes are read directly from your controllers rather than assumed, but
you should run through a real sign-in → track → ingest → admin-dashboard
loop on an actual machine before rolling this out to any employee.

## Local setup
```
cd desktop-tray/desktop
npm install
npm start
```
Requires Node.js 18+ (active-win's engine requirement) and your backend
running at the URL configured via pairing or `config.js`.

## Building an installer
```
npm run dist
```
Produces a Windows NSIS installer via `electron-builder`. Run this on
Windows for a Windows build.

## Privacy boundaries (do not remove without reconsidering the whole design)
- No screenshots, no keystroke logging, no clipboard access, no
  microphone/camera access, no window titles.
- Idle detection reports only a boolean, never raw input events.
- Tracking is gated by the backend's own consent check
  (`GET /api/attendance/today`), re-polled every 15s -- never assumed
  locally, and stops within one cycle if consent/sign-in state changes.
- Credentials are encrypted at rest; the encryption key sits on the same
  disk (not OS keychain) -- deliberate tradeoff to avoid a native module
  dependency, documented in `credential-store.js`.
