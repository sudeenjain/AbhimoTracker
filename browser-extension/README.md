# Abhimo Tracker -- Browser Extension (Phase 6)

Chromium-based extension (Chrome, Edge) that reports which website *domain*
an employee is actively viewing during a signed-in, consented work session
-- the browser-side counterpart to `desktop-tray/`'s app tracking.

## What it collects

- The **hostname only** of the active tab, while the browser window has OS
  focus, the tab is the focused tab, and the employee is not OS-idle.
  `https://github.com/org/private-repo/issues/42?x=1` is recorded as
  `github.com` -- the path, query string, and fragment are never read past
  extracting `.hostname` from the URL (see `domainFromUrl()` in
  `background.js`).
- OS-idle/active state (via `chrome.idle`), same threshold as the desktop
  tray app (5 minutes, configurable in `config.js`).

## What it deliberately never collects

- Page content, form data, search queries, browsing history, page titles.
- Full URLs -- enforced twice: `domainFromUrl()` only ever reads
  `.hostname`, and `WebsiteActivityController::isValidDomain()` on the
  backend independently rejects anything containing `/`, `?`, `#`, `@`, or
  whitespace, so a bug here can't silently smuggle a full URL through.
- Background tabs, or anything while the browser itself isn't the
  foreground application (another app in focus = nothing tracked, same as
  the desktop agent only tracking one foreground app at a time).
- Anything before the employee has signed in, accepted the current
  monitoring policy, and the browser extension has been connected -- the
  extension polls `GET /api/attendance/today` (same gate as the desktop
  tray) and pauses immediately when that returns "not signed in" or "signed
  out".

## Install (developer / unpacked, for testing)

1. Chrome/Edge -> Extensions -> enable **Developer mode**.
2. **Load unpacked** -> select this `browser-extension/` folder.
3. Click the extension icon, enter your backend URL (same as
   `frontend/js/api.js`'s `API_BASE`), your employee username/password, and
   **Connect**.
4. The popup shows live status: tracking on/off, current domain, idle
   state, and how many entries are queued for upload.

For a real rollout, package this with `zip` or Chrome's "Pack extension"
and distribute via your organization's Chrome/Edge admin policy
(`ExtensionInstallForcelist`) rather than the Chrome Web Store, unless you
intend to publish it there.

## How it behaves under the hood (for whoever maintains this next)

- Domain-switch detection is **event-driven**
  (`tabs.onActivated`/`onUpdated`, `windows.onFocusChanged`,
  `idle.onStateChanged`), not polled -- switching tabs is reflected
  immediately, not on the next timer tick.
- A single `chrome.alarms` tick (`config.js`'s `tickAlarmMinutes`, default
  1 minute -- the practical minimum granularity `chrome.alarms` supports)
  does three things: chunks the current segment into a queued entry (so a
  long stretch on one domain doesn't become one giant end-of-day upload),
  re-checks the sign-in/consent gate, and flushes whatever's queued to
  `POST /api/activity/website`.
- All state that needs to survive a service-worker restart (MV3 workers
  can be terminated and woken up at any time) is persisted to
  `chrome.storage.local` on every change: credentials, cached JWT, current
  segment, and the upload queue. Nothing is trusted to survive only in
  memory.
- Verified with a real test harness (`test/mock-chrome-test.mjs`) that
  mocks the `chrome.*` APIs and `fetch`, then imports and drives the actual
  `background.js` through login, tab switches, idle transitions, focus
  loss, chunked uploads, and logout -- run it with
  `node test/mock-chrome-test.mjs`. This is not a live-browser test (no
  real Chrome involved), so still do one real manual pass in an actual
  loaded extension before rolling this out.

## Known limitations (flagging honestly, same spirit as desktop-tray/README.md)

- Credentials are stored in `chrome.storage.local`, which is not encrypted
  at rest the way the desktop tray app's `credential-store.js` encrypts its
  copy (AES-256-GCM via each OS's keychain). `chrome.storage.local` is
  isolated from web pages and other extensions, but not from someone with
  full access to the OS profile. If that's not an acceptable tradeoff for
  your deployment, the fix is to have the extension exchange a short-lived
  pairing token (mirroring `TrayPairingController`) for a token instead of
  storing the raw password -- flagging this as a deliberate scope cut for
  this pass, not an oversight.
- `chrome.alarms`' ~1-minute minimum granularity means the periodic
  chunk/upload/status-check is coarser than the desktop tray's (15-90s).
  Domain-switch detection itself is unaffected (event-driven, see above);
  only the periodic housekeeping is slower.
- Manifest V3 service worker lifetime is managed entirely by the browser;
  this extension defends against that with persisted state (see above),
  but a worker being killed mid-`fetch` and never retried until the next
  tick is a real possibility on any MV3 extension, not unique to this one.
