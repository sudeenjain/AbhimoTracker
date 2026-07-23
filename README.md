# Abhimo Tracker -- C# / .NET 10 Employee & Admin Apps

Native rewrite of the employee/admin desktop experience from the original
Electron master prompt, using **C# + .NET 10** instead, per your request --
with a new small **SignalR** relay for live admin push. Your PHP backend is
untouched and stays the system of record for everything durable.

## Why three projects, and why a relay hub at all

Your backend is PHP/Slim. SignalR is ASP.NET Core-only -- PHP can't host a
SignalR hub. So this splits into:

| Project | What it is | Talks to |
|---|---|---|
| `AbhimoTracker.RelayHub` | New, small ASP.NET Core + SignalR service | Nothing persistent -- pure in-memory relay |
| `AbhimoTracker.Employee` | WPF + tray app, replaces `desktop-tray/` | PHP REST API (durable) + RelayHub (live push) |
| `AbhimoTracker.Admin` | WPF + WebView2 shell around your existing admin HTML/JS | PHP REST API (as it already does) + RelayHub (instant refresh) |

**The relay hub only carries "employee X's status just changed" -- it never
stores anything and is never the only place a fact lives.** If it's down,
the Employee app just keeps queuing/uploading to PHP as normal, and the
Admin app falls back to its existing poll interval. Nothing breaks if you
never deploy the relay at all; you just lose the "instant" part of "instant
refresh" and get PHP's normal poll cadence instead.

Both the Employee app and any admin page validate/present the **same JWT**
your PHP backend already issues at `POST /api/login` -- the relay hub
verifies that JWT's signature locally against the same `JWT_SECRET`
(`backend/.env`) instead of asking PHP. Nobody issues a second, different
credential.

## What changed vs. the original master prompt

- Original prompt: reuse `desktop-tray/`'s JS modules unchanged inside an
  Electron `BrowserWindow`, and load `frontend/attendance.html` /
  `abhimo_admin/*.html` directly for both apps.
- This build: the **Employee** app's tracking logic (idle detection, active-
  window polling, chunking, offline queue) is a line-for-line *port* of that
  same JS into C# using Win32 interop -- same thresholds, same chunk sizes,
  same backend contract -- but the UI is native WPF (sign in/out, session
  timer) instead of embedding `attendance.html`, since a pure-C# stack was
  the point of the pivot. The **Admin** app *does* still embed your existing
  HTML/JS unmodified via WebView2, because there's no reason to rewrite a
  working multi-page admin UI in XAML just to change languages.
- New: `AbhimoTracker.RelayHub` and the SignalR wiring in both apps, which
  wasn't in the original prompt at all.

## Repo layout

```
AbhimoTracker/
  AbhimoTracker.sln
  src/
    AbhimoTracker.RelayHub/     -- ASP.NET Core + SignalR
    AbhimoTracker.Employee/     -- WPF + tray
    AbhimoTracker.Admin/        -- WPF + WebView2
  installer/
    employee.iss                -- Inno Setup script -> AbhimoTracker-Employee-Setup.exe
    admin.iss                   -- Inno Setup script -> AbhimoTracker-Admin-Setup.exe
```

Plus, outside this folder: a patched copy of `frontend/abhimo-tracker.html`
(only its `DOWNLOAD_URL` constant changed) and a patched copy of
`frontend/abhimo_admin/{attendance,employee-activity}.html` and
`frontend/js/api.js` (both additive changes -- see "Admin app" below).
Apply these back onto your real `frontend/` folder; they're also already
copied into `AbhimoTracker.Admin/wwwroot/` for the Admin app to serve
locally.

## Prerequisites

- .NET 10 SDK (Windows) -- https://dotnet.microsoft.com
- WebView2 Runtime -- preinstalled on current Windows 10/11; see
  `installer/admin.iss`'s comment if you need to support older images
- Inno Setup (free) if you want the `.exe` installers --
  https://jrsoftware.org/isinfo.php
- Your PHP backend already running and reachable

I could not compile or run this in the sandbox I built it in (no .NET SDK
or Windows available there, and no way to install one -- network egress is
restricted to package registries, not arbitrary downloads). Everything here
is written carefully against your actual backend contracts (endpoint
paths/payloads pulled directly from `ActivityController`, `AuthController`,
`AdminActivityController`, `JwtService`, etc.) and against documented .NET
10/WPF/SignalR APIs, but **build it and smoke-test it before you trust it in
production** -- treat this the way you would code review from a colleague
who wrote it correctly but has never actually run it.

## Running it locally

1. **RelayHub** -- set `Jwt:Secret` in
   `src/AbhimoTracker.RelayHub/appsettings.json` to the *exact* value of
   `JWT_SECRET` in `backend/.env`, then:
   ```
   cd src/AbhimoTracker.RelayHub
   dotnet run
   ```
   It listens on `http://localhost:5080` by default (see `Kestrel:Endpoints`
   in its `appsettings.json`). `GET /health` returns `200 OK` once it's up.

2. **Employee app** -- edit `src/AbhimoTracker.Employee/appsettings.json` if
   your PHP backend isn't at the default dev URL, then:
   ```
   cd src/AbhimoTracker.Employee
   dotnet run
   ```
   First launch with no saved credentials opens the login window. After
   that it's tray-only until you double-click the icon or use "Open Abhimo
   Tracker".

3. **Admin app**:
   ```
   cd src/AbhimoTracker.Admin
   dotnet run
   ```
   Opens straight to the (unmodified) `login.html`.

## Building the installers

```
cd src\AbhimoTracker.Employee
dotnet publish -c Release -r win-x64 --self-contained true -p:PublishSingleFile=false -o ..\..\publish\Employee

cd ..\AbhimoTracker.Admin
dotnet publish -c Release -r win-x64 --self-contained true -p:PublishSingleFile=false -o ..\..\publish\Admin

cd ..\..\installer
iscc employee.iss
iscc admin.iss
```

Produces `dist\AbhimoTracker-Employee-Setup.exe` and
`dist\AbhimoTracker-Admin-Setup.exe`. Both are unsigned local builds, no
auto-update -- matches the master prompt's explicit "out of scope" list.
`--self-contained true` means the target machine doesn't need the .NET 10
runtime preinstalled (larger installer, zero extra setup for the employee).

## Building for production (not localhost)

Three places currently point at `http://localhost/...` for dev convenience:

- `src/AbhimoTracker.Employee/appsettings.json` -- `DefaultApiBase`,
  `RelayHubUrl`. A `appsettings.Production.json` placeholder is included;
  either copy it over `appsettings.json` before publishing, or add the
  couple of lines to `App.xaml.cs`'s `LoadConfig()` to pick the file based
  on a build flag.
- `src/AbhimoTracker.Admin/appsettings.json` -- `ApiBase`, `RelayHubUrl`,
  read by `MainWindow.xaml.cs` and injected into the page as
  `window.__ABHIMO_API_BASE__` / `window.__ABHIMO_HUB_URL__`.
- `src/AbhimoTracker.RelayHub/appsettings.json` -- `Kestrel:Endpoints`
  (bind address), `Cors:AllowedOrigins`, and put it behind HTTPS/a reverse
  proxy for anything beyond local testing -- Kestrel's raw HTTP binding here
  is a dev convenience only.

None of these needed a code change to retarget, only config -- matches the
master prompt's "configurable/buildable per environment, don't hardcode a
dev-only value" requirement.

## Employee app -- what it does

Direct port of `desktop-tray/`'s logic:

- `Services/IdleMonitor.cs` -- Win32 `GetLastInputInfo` (system-wide idle
  time, same signal Electron's `powerMonitor.getSystemIdleTime()` wraps),
  plus immediate idle-on-lock via `SystemEvents.SessionSwitch`
- `Services/ActivityTracker.cs` -- Win32 `GetForegroundWindow` +
  `GetWindowThreadProcessId` → `Process.ProcessName`. **Process name only,
  never a window title** -- same constraint as the original tray app and as
  `AdminActivityController::appUsage`'s docblock
- `Services/UploadQueue.cs` -- disk-backed (`%LocalAppData%\AbhimoTracker\
  Employee\activity-queue.json`), batches every `UploadFlushIntervalMs`
  (default 30s, configurable up to 60s), survives offline periods, drops
  entries once they'd fall outside the backend's accepted backdate window
  so one stuck batch can't block everything queued behind it forever
- `Services/CredentialStore.cs` -- Windows DPAPI
  (`ProtectedData.Protect/Unprotect`, tied to the Windows user account) --
  a step up from the Electron app's self-managed AES key file, since there's
  no key material sitting on disk at all
- `Services/MonitoringController.cs` -- the orchestrator: tracking only ever
  runs while `GET /api/attendance/today` says this employee is currently
  signed in, polled every 15s, exactly like `pollMonitoringStatus()` in the
  original `main.js`
- Tray menu is unchanged: Open Abhimo Tracker / Test saved sign-in / Sign in
  manually / Forget saved credentials / Withdraw monitoring consent / Quit
- `Views/DashboardWindow` -- native sign-in/out + session-timer window
  (no `attendance.html` embedding, since this app is meant to be pure C#/
  WPF; if you'd rather it also embed the real `attendance.html` via
  WebView2 the way `AbhimoTracker.Admin` does, that's a straightforward
  follow-up -- say the word and I'll add it)

**Not yet ported:** the zero-typing pairing token exchange
(`TrayPairingController`'s flow) is stubbed as `ApiClient.
ExchangePairingTokenAsync` but nothing calls it yet -- `LoginWindow`
currently only supports typing a username/password. If you still want the
onboarding page to hand off a pairing token into this app (e.g. via a
custom URL scheme `abhimotracker://pair/<token>`, WPF's equivalent of
Electron's `abhimo://`), that's a small addition on top of what's here --
flag it and I'll wire it in.

## Admin app -- what it does

- `MainWindow.xaml.cs` maps `wwwroot/` to a virtual host
  (`https://abhimo-admin.local/...`) via WebView2's
  `SetVirtualHostNameToFolderMapping` and navigates to `login.html` --
  your existing multi-page admin UI, byte-for-byte unmodified except:
  - `js/api.js`: one line changed, `API_BASE` now checks
    `window.__ABHIMO_API_BASE__` first (additive override hook, falls back
    to the original hardcoded default if unset)
  - `attendance.html` / `employee-activity.html`: two `<script>` tags added
    before `</body>` (SignalR JS client from CDN + `live-status-client.js`)
- `wwwroot/js/live-status-client.js` connects to the relay hub using the
  admin's own JWT (already sitting in `localStorage.auth_token` from
  `login.html`'s existing code) and, on a push, calls whatever refresh
  function(s) that page already defines (`loadLiveStatus`,
  `loadAppUsageAndAlerts`) -- **it never touches the DOM directly and never
  duplicates your rendering logic**, it just makes the existing poll-driven
  refresh fire immediately instead of waiting out the interval
- `NewWindowRequested` is intercepted so `target="_blank"` links or
  `window.open()` calls stay inside this window instead of spawning a real
  browser

`pending.html` and `login.html` were left completely untouched -- there was
nothing on them that benefits from a live push.

## RelayHub -- what it does

- `Hubs/LiveStatusHub.cs`: `[Authorize]`-gated SignalR hub at
  `/hubs/live-status`. Connections with `role: "admin"` in their JWT join an
  `admins` group; everyone else (employees) can call `PushStatus(status,
  activeWindow)`, which is server-side attributed to *that connection's own*
  JWT claims -- an employee cannot spoof another employee's status update,
  there's no client-supplied employee ID anywhere in the message
- No database, no persistence, no `Task<T>` that survives a restart --
  entirely in-memory, by design (see "Why three projects" above)

## Acceptance checklist, mapped to this build

- [x] Employee installs, opens, sees a native window with sign in/out -- no
      browser opened
- [x] Sign in/out produces the same backend effects (same endpoints, same
      payloads as the original tray app)
- [x] App-usage tracking (`activity_logs`) works the same way, ported
      1:1 from `activity-tracker.js`
- [x] Admin installs, opens, logs in, views attendance/live status/employee
      activity -- no browser opened (WebView2 stays fully in-app)
- [x] Chrome extension pairing/tracking untouched -- nothing here reads or
      writes anything extension-related
- [ ] **Not yet verified by an actual build/run** -- see "Prerequisites"
      above. Please compile and smoke-test before relying on this.
