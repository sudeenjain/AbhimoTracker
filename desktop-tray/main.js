'use strict';

const { app, Tray, Menu, BrowserWindow, ipcMain, nativeImage, dialog } = require('electron');
const path = require('path');
const { CredentialStore } = require('./credential-store');
const { IdleMonitor } = require('./idle-monitor');
const { ActivityTracker } = require('./activity-tracker');
const { UploadQueue } = require('./upload-queue');
const { DEFAULT_API_BASE, tracking: trackingConfig, endpoints } = require('./config');

const PROTOCOL = 'abhimo';

let tray = null;
let loginWindow = null;
let store = null;
/** @type {{username: string, apiBase: string} | null} */
let pairedState = null; // never holds the password in memory longer than a save/verify call

// ---- Activity/idle monitoring state ----
let idleMonitor = null;
let activityTracker = null;
let uploadQueue = null;
let monitoringAllowed = false; // only true while GET /api/attendance/today says signed-in (and 200s, not 403)
let statusPollTimer = null;
/** @type {{token: string, expiresAtMs: number} | null} */
let cachedToken = null;

// ---- Single instance + protocol registration ----
// Windows/Linux hand a second launch's argv (which includes the abhimo://
// URL) to the FIRST instance via 'second-instance', rather than actually
// opening a second app -- that's why the lock + listener below matter.
const gotLock = app.requestSingleInstanceLock();
if (!gotLock) {
  app.quit();
} else {
  app.on('second-instance', (_event, argv) => {
    const url = argv.find((a) => a.startsWith(`${PROTOCOL}://`));
    if (url) handlePairingUrl(url);
  });
}

let pendingUrlBeforeReady = null;

// macOS delivers the URL via this event instead of argv.
app.on('open-url', (event, url) => {
  event.preventDefault();
  if (!store) {
    pendingUrlBeforeReady = url;
    return;
  }
  handlePairingUrl(url);
});

app.whenReady().then(() => {
  // process.defaultApp is true only when running unpackaged via
  // `electron .` (npm start). In that case, registering the protocol with
  // no extra args makes Windows invoke it as
  // `electron.exe abhimo://pair?...`, and Electron then tries to load that
  // URL AS the app path -- "Cannot find module ...token=...". Passing
  // execPath + this script's own path explicitly is what makes Windows
  // launch `electron.exe <path-to-this-folder> abhimo://pair?...` instead,
  // which is what main.js's argv-parsing below actually expects. A built
  // installer (electron-builder) doesn't hit this branch -- there,
  // process.execPath already IS the packaged app, so no extra args needed.
  if (process.defaultApp) {
    if (process.argv.length >= 2) {
      app.setAsDefaultProtocolClient(PROTOCOL, process.execPath, [path.resolve(process.argv[1])]);
    }
  } else {
    app.setAsDefaultProtocolClient(PROTOCOL);
  }
  store = new CredentialStore(app.getPath('userData'));
  loadStoredState();
  createTray();

  if (pendingUrlBeforeReady) {
    handlePairingUrl(pendingUrlBeforeReady);
    pendingUrlBeforeReady = null;
  }

  // A pairing URL can also arrive as a launch argument on Windows/Linux
  // when the app wasn't already running.
  const launchUrl = process.argv.find((a) => a.startsWith(`${PROTOCOL}://`));
  if (launchUrl) handlePairingUrl(launchUrl);
});

app.on('window-all-closed', (e) => {
  // Tray app: stay running with no windows open. Only Quit from the tray
  // menu should actually exit.
  e.preventDefault();
});

// ---- Pairing handoff ----

function handlePairingUrl(rawUrl) {
  let parsed;
  try {
    parsed = new URL(rawUrl);
  } catch (_) {
    return;
  }
  if (parsed.protocol !== `${PROTOCOL}:`) return;

  const token = parsed.searchParams.get('token');
  const apiBase = parsed.searchParams.get('api') || DEFAULT_API_BASE;
  if (!token) return;

  exchangePairingToken(token, apiBase);
}

async function exchangePairingToken(token, apiBase) {
  updateTrayStatus('Pairing...', 'loading');
  try {
    const res = await fetch(`${apiBase}/api/tray/pair/${encodeURIComponent(token)}/exchange`, {
      method: 'POST',
    });
    const data = await res.json().catch(() => ({}));
    if (!res.ok) {
      updateTrayStatus(pairedState ? `Signed in as ${pairedState.username}` : 'Not paired', 'signedout');
      notify('Abhimo Tracker', data.error || 'Pairing failed. Try again from the onboarding page.');
      return;
    }
    persistCredentials(data.username, data.password, apiBase);
    notify('Abhimo Tracker', `Paired as ${data.username}.`);
  } catch (err) {
    updateTrayStatus(pairedState ? `Signed in as ${pairedState.username}` : 'Not paired', 'offline');
    notify('Abhimo Tracker', 'Could not reach the server to complete pairing.');
  }
}

function persistCredentials(username, password, apiBase) {
  store.save({ username, password, apiBase });
  pairedState = { username, apiBase };
  cachedToken = null; // force a fresh token under the new credentials
  updateTrayStatus(`Signed in as ${username}`, 'loading');
  startMonitoringController();
}

function loadStoredState() {
  const saved = store.load();
  if (saved) {
    pairedState = { username: saved.username, apiBase: saved.apiBase };
  }
}

// ---- Manual login fallback (used by login-window.html via preload IPC) ----

ipcMain.handle('get-default-api-base', () => (pairedState ? pairedState.apiBase : DEFAULT_API_BASE));

ipcMain.handle('manual-login', async (_event, { username, password, apiBase }) => {
  try {
    const res = await fetch(`${apiBase}/api/login`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ username, password }),
    });
    const data = await res.json().catch(() => ({}));
    if (!res.ok) {
      return { ok: false, error: data.error || 'Invalid credentials.' };
    }
    persistCredentials(username, password, apiBase);
    if (loginWindow) loginWindow.close();
    return { ok: true };
  } catch (err) {
    return { ok: false, error: 'Could not reach the server.' };
  }
});

function openLoginWindow() {
  if (loginWindow) {
    loginWindow.focus();
    return;
  }
  loginWindow = new BrowserWindow({
    width: 360,
    height: 340,
    resizable: false,
    title: 'Abhimo Tracker -- Sign in',
    webPreferences: {
      preload: path.join(__dirname, 'preload.js'),
      contextIsolation: true,
      nodeIntegration: false,
    },
  });
  loginWindow.setMenuBarVisibility(false);
  loginWindow.loadFile(path.join(__dirname, 'login-window.html'));
  loginWindow.on('closed', () => {
    loginWindow = null;
  });
}

async function testSignIn() {
  if (!pairedState) {
    notify('Abhimo Tracker', 'Not paired yet -- use "Sign in manually" or reopen the onboarding link.');
    return;
  }
  const saved = store.load();
  if (!saved) {
    notify('Abhimo Tracker', 'No saved credentials found.');
    return;
  }
  try {
    const res = await fetch(`${saved.apiBase}/api/login`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ username: saved.username, password: saved.password }),
    });
    if (res.ok) {
      notify('Abhimo Tracker', 'Saved credentials are working.');
    } else {
      const data = await res.json().catch(() => ({}));
      notify('Abhimo Tracker', `Saved credentials failed: ${data.error || 'sign-in rejected'}. Try "Sign in manually".`);
    }
  } catch (err) {
    notify('Abhimo Tracker', 'Could not reach the server to test sign-in.');
  }
}

function forgetCredentials() {
  stopMonitoringController();
  store.clear();
  pairedState = null;
  cachedToken = null;
  updateTrayStatus('Not paired', 'signedout');
  notify('Abhimo Tracker', 'Saved credentials removed from this device.');
}

/**
 * Phase 13: lets the employee withdraw monitoring consent from the tray
 * itself, rather than only ever being able to accept it once during
 * onboarding (see abhimo-tracker.html). Confirms first since this is a
 * one-click-away destructive-feeling action; on success, stops tracking
 * on this device immediately (setMonitoringAllowed(false)) instead of
 * waiting up to monitoringStatusPollMs for the next poll to notice the
 * backend now returns 403 { reason: "consent_required" } for every
 * consent-gated route.
 */
async function withdrawConsent() {
  if (!pairedState) {
    notify('Abhimo Tracker', 'Not signed in yet.');
    return;
  }
  const choice = await dialog.showMessageBox({
    type: 'warning',
    buttons: ['Cancel', 'Withdraw consent'],
    defaultId: 0,
    cancelId: 0,
    title: 'Withdraw monitoring consent',
    message: 'Stop Abhimo Tracker from recording new activity?',
    detail: 'This stops new application, idle-time, and website tracking immediately. Activity already recorded is not deleted. You can accept the monitoring policy again at any time to resume.',
  });
  if (choice.response !== 1) return;

  const saved = store.load();
  const token = await getAuthToken();
  if (!saved || !token) {
    notify('Abhimo Tracker', 'Could not withdraw consent -- not signed in.');
    return;
  }
  try {
    const res = await fetch(`${saved.apiBase}${endpoints.consentWithdraw}`, {
      method: 'POST',
      headers: { Authorization: `Bearer ${token}` },
    });
    if (res.ok) {
      setMonitoringAllowed(false);
      updateTrayStatus(`Signed in as ${pairedState.username}`, 'signedout');
      notify('Abhimo Tracker', 'Monitoring consent withdrawn. Tracking has stopped.');
    } else {
      notify('Abhimo Tracker', 'Could not withdraw consent -- please try again.');
    }
  } catch (err) {
    notify('Abhimo Tracker', 'Could not reach the server to withdraw consent.');
  }
}

// ---- Activity/idle monitoring ----
//
// Tracking only ever runs while monitoringAllowed is true, which is driven
// entirely by pollMonitoringStatus() asking the backend (GET
// /api/attendance/today) whether this employee is currently signed in.
// That route sits behind ConsentRequiredMiddleware + JwtAuthMiddleware, so
// a 403 from it already means "password not changed yet" or "monitoring
// policy not accepted" -- we don't need a separate consent check. Losing
// pairing, consent, or the work session all stop tracking within one poll
// cycle (monitoringStatusPollMs).
//
// There is no dedicated tray heartbeat endpoint -- POST /api/work-session/
// heartbeat is explicitly reserved for the browser tab (see WorkSessionController's
// docblock). AdminActivityController.liveStatus instead derives "online"
// purely from how recent the latest activity_logs row is (within 180s), so
// ActivityTracker's chunking (chunkFlushMs, see config.js) is what keeps
// this agent looking alive on the admin dashboard -- the ingest calls
// themselves are the heartbeat.

function decodeJwtExpiryMs(token) {
  try {
    const payload = JSON.parse(Buffer.from(token.split('.')[1], 'base64url').toString('utf8'));
    return typeof payload.exp === 'number' ? payload.exp * 1000 : null;
  } catch (_) {
    return null;
  }
}

/** Returns a bearer token for API calls, reusing a cached one until it's
 *  close to expiry, otherwise re-authenticating with the stored credentials
 *  (same pattern already used by testSignIn). */
async function getAuthToken() {
  if (cachedToken && Date.now() < cachedToken.expiresAtMs - 15000) {
    return cachedToken.token;
  }
  const saved = store.load();
  if (!saved) return null;
  try {
    const res = await fetch(`${saved.apiBase}${endpoints.login}`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ username: saved.username, password: saved.password }),
    });
    if (!res.ok) return null;
    const data = await res.json().catch(() => ({}));
    if (!data.token) return null;
    cachedToken = { token: data.token, expiresAtMs: decodeJwtExpiryMs(data.token) || Date.now() + 5 * 60000 };
    return cachedToken.token;
  } catch (_) {
    return null;
  }
}

/** Formats a JS Date as 'Y-m-d H:i:s' in local wall-clock time, matching
 *  ActivityController::ingest's primary parse format. NOTE: this assumes
 *  the employee's machine clock/timezone is reasonably close to the
 *  server's -- the endpoint rejects anything more than 5 minutes in the
 *  future or more than 48 hours in the past, so a badly-skewed client
 *  clock will show up as rejected batches rather than silently wrong data,
 *  but it's worth checking on real hardware before rollout. */
function formatServerTimestamp(date) {
  const pad = (n) => String(n).padStart(2, '0');
  return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())} ` +
    `${pad(date.getHours())}:${pad(date.getMinutes())}:${pad(date.getSeconds())}`;
}

function initMonitoring() {
  if (idleMonitor) return;
  idleMonitor = new IdleMonitor({
    idleThresholdSeconds: trackingConfig.idleThresholdSeconds,
    pollIntervalMs: trackingConfig.idlePollIntervalMs,
  });
  activityTracker = new ActivityTracker({
    pollIntervalMs: trackingConfig.activePollIntervalMs,
    chunkFlushMs: trackingConfig.chunkFlushMs,
  });
  uploadQueue = new UploadQueue(app.getPath('userData'), {
    flushIntervalMs: trackingConfig.uploadFlushIntervalMs,
    maxBatch: trackingConfig.maxBatchSize,
    // 1 hour under ActivityController::MAX_BACKDATE_HOURS (48h) -- entries
    // are dropped locally with this margin so we never send something the
    // server would reject anyway just because a request took a while, and
    // never leave a permanently-rejected batch blocking the queue after a
    // long offline stretch. See upload-queue.js's _dropExpired() docblock.
    maxAgeMs: trackingConfig.maxQueueAgeHours * 60 * 60 * 1000,
  });
  uploadQueue.setSender(sendActivityBatch);

  idleMonitor.on('idle', () => {
    activityTracker.setIdle(true);
    if (monitoringAllowed) updateTrayStatus(`Signed in as ${pairedState.username}`, 'idle');
  });
  idleMonitor.on('active', () => {
    activityTracker.setIdle(false);
    if (monitoringAllowed) updateTrayStatus(`Signed in as ${pairedState.username}`, 'active');
  });
  // Matches ActivityController::ingest's entry shape exactly -- see
  // activity-tracker.js's docblock for why entries are pre-chunked here.
  activityTracker.on('entry', (entry) => {
    uploadQueue.enqueue(entry);
  });
}

async function sendActivityBatch(items) {
  const saved = store.load();
  if (!saved) return false;
  const token = await getAuthToken();
  if (!token) return false;
  try {
    const res = await fetch(`${saved.apiBase}${endpoints.activityIngest}`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', Authorization: `Bearer ${token}` },
      body: JSON.stringify({
        entries: items.map((item) => ({
          timestamp: formatServerTimestamp(new Date(item.timestamp)),
          active_window: item.active_window,
          is_idle: !!item.is_idle,
          duration_seconds: item.duration_seconds,
          client_batch_id: item.client_batch_id,
        })),
      }),
    });
    return res.ok;
  } catch (_) {
    return false;
  }
}

async function pollMonitoringStatus() {
  if (!pairedState) {
    setMonitoringAllowed(false);
    return;
  }
  const saved = store.load();
  const token = await getAuthToken();
  if (!saved || !token) {
    setMonitoringAllowed(false);
    updateTrayStatus(`Signed in as ${pairedState.username}`, 'offline');
    return;
  }
  try {
    const res = await fetch(`${saved.apiBase}${endpoints.monitoringStatus}`, {
      headers: { Authorization: `Bearer ${token}` },
    });
    if (res.status === 403) {
      // ConsentRequiredMiddleware: temp password not changed yet, or the
      // current monitoring policy hasn't been accepted. Either way, not an
      // error state to alarm the employee about -- just "not tracking yet".
      setMonitoringAllowed(false);
      updateTrayStatus(`Signed in as ${pairedState.username}`, 'signedout');
      return;
    }
    if (!res.ok) {
      setMonitoringAllowed(false);
      updateTrayStatus(`Signed in as ${pairedState.username}`, 'offline');
      return;
    }
    const data = await res.json().catch(() => ({}));
    const allowed = !!(data.sign_in_time && !data.sign_out_time);
    setMonitoringAllowed(allowed);
    updateTrayStatus(
      `Signed in as ${pairedState.username}`,
      allowed ? (idleMonitor.isIdle ? 'idle' : 'active') : 'signedout'
    );
  } catch (_) {
    setMonitoringAllowed(false);
    updateTrayStatus(`Signed in as ${pairedState.username}`, 'offline');
  }
}

function setMonitoringAllowed(allowed) {
  if (allowed === monitoringAllowed) return;
  monitoringAllowed = allowed;
  if (allowed) {
    idleMonitor.start();
    activityTracker.start();
    uploadQueue.start();
  } else {
    idleMonitor.stop();
    activityTracker.stop();
    uploadQueue.stop();
  }
}

function startMonitoringController() {
  initMonitoring();
  if (statusPollTimer) return;
  pollMonitoringStatus();
  statusPollTimer = setInterval(pollMonitoringStatus, trackingConfig.monitoringStatusPollMs);
}

function stopMonitoringController() {
  if (statusPollTimer) {
    clearInterval(statusPollTimer);
    statusPollTimer = null;
  }
  setMonitoringAllowed(false);
}

// ---- Tray UI ----

// statusKey selects which of the pre-supplied tray icons to show:
// active (working) / idle / offline (status check to the backend is
// failing) / signedout (paired, reachable, but not currently signed in to
// a work session -- or consent/password gate not cleared) / loading.
const STATUS_ICON_FILE = {
  active: 'icon-active-16.png',
  idle: 'icon-idle-16.png',
  offline: 'icon-offline-16.png',
  signedout: 'icon-signedout-16.png',
  loading: 'icon-loading-16.png',
};

function createTray() {
  const icon = nativeImage.createFromPath(path.join(__dirname, 'assets', 'tray-icon.png'));
  tray = new Tray(icon);
  tray.setToolTip('Abhimo Tracker');
  updateTrayStatus(pairedState ? `Signed in as ${pairedState.username}` : 'Not paired', 'loading');
  if (pairedState) startMonitoringController();
}

function updateTrayStatus(statusLine, statusKey = 'signedout') {
  if (!tray) return;
  const iconFile = STATUS_ICON_FILE[statusKey] || 'tray-icon.png';
  try {
    tray.setImage(nativeImage.createFromPath(path.join(__dirname, 'assets', iconFile)));
  } catch (_) {
    /* fall back silently to whatever icon is already showing */
  }
  tray.setToolTip(`Abhimo Tracker -- ${statusLine}`);
  const pendingLabel = uploadQueue && uploadQueue.pendingCount > 0
    ? `${uploadQueue.pendingCount} activity update(s) queued`
    : null;
  const menu = Menu.buildFromTemplate([
    { label: statusLine, enabled: false },
    ...(pendingLabel ? [{ label: pendingLabel, enabled: false }] : []),
    { type: 'separator' },
    { label: 'Test saved sign-in', click: testSignIn, enabled: !!pairedState },
    { label: 'Sign in manually', click: openLoginWindow },
    { label: 'Forget saved credentials', click: forgetCredentials, enabled: !!pairedState },
    { label: 'Withdraw monitoring consent...', click: withdrawConsent, enabled: !!pairedState },
    { type: 'separator' },
    { label: 'Quit Abhimo Tracker', click: () => app.quit() },
  ]);
  tray.setContextMenu(menu);
}

function notify(title, body) {
  // Lightweight status surface -- native Notification where supported,
  // otherwise the tray tooltip already set by updateTrayStatus is the
  // fallback (no extra dependency for a one-line message).
  try {
    const { Notification } = require('electron');
    if (Notification.isSupported()) {
      new Notification({ title, body }).show();
    }
  } catch (_) {
    /* tray tooltip already reflects current state */
  }
}