// Fallback API base if the employee hasn't logged in yet. Matches
// frontend/js/api.js and desktop-tray/config.js -- keep all three in sync,
// or point them all at your real deployment URL.
export const DEFAULT_API_BASE = 'http://localhost/attendance-system/backend/public';

export const tracking = {
  // OS-wide idle threshold, same default as desktop-tray/idle-monitor.js
  // and config/tracking.php's browser-tab idle_after_seconds -- kept in
  // sync across all three tracking surfaces deliberately.
  idleThresholdSeconds: 300,

  // chrome.alarms enforces a practical minimum granularity of ~1 minute
  // for a packaged/published extension, so a single alarm drives three
  // things every tick instead of separate faster timers like the desktop
  // tray uses (3s app poll / 90s chunk / 30s upload / 15s status poll):
  //   1. flush the current domain segment into a queued entry
  //   2. re-check GET /api/attendance/today (sign-in + consent gate)
  //   3. flush whatever's queued to POST /api/activity/website
  // Domain-switch detection itself is NOT on this timer -- it's event-driven
  // (chrome.tabs.onActivated/onUpdated, chrome.windows.onFocusChanged,
  // chrome.idle.onStateChanged all fire immediately), so switching tabs is
  // never delayed by up to a minute, only the periodic flush/poll is.
  tickAlarmMinutes: 1,

  maxBatchSize: 25,

  // 1 hour under WebsiteActivityController::MAX_BACKDATE_HOURS (48h) --
  // same margin and same reasoning as desktop-tray/config.js's
  // maxQueueAgeHours: entries this old are dropped locally in
  // flushQueue() so a batch the server would reject forever can't get
  // permanently stuck at the front of the queue after a long offline
  // stretch, blocking everything queued behind it.
  maxQueueAgeHours: 47,

  // Confirmed against backend/public/index.php and the real controllers --
  // same endpoints the desktop tray app uses for login/status, plus the
  // Phase 6 website-specific ingest route.
  endpoints: {
    login: '/api/login',
    monitoringStatus: '/api/attendance/today',
    websiteIngest: '/api/activity/website',
  },
};
