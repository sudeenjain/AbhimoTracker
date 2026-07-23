'use strict';

import { DEFAULT_API_BASE, tracking } from './config.js';
import { encryptSecret, decryptSecret } from './crypto-store.js';

/**
 * Abhimo Tracker -- website activity (Phase 6).
 *
 * Same privacy boundary as desktop-tray/activity-tracker.js, applied to
 * browser tabs instead of desktop apps: collects only the ACTIVE tab's
 * hostname and how long it was actively displayed. Never reads page
 * content, form data, search queries, full URLs (path/query/hash are
 * stripped before anything leaves this file), or browsing history --
 * WebsiteActivityController::isValidDomain() enforces the same boundary
 * again server-side, so a bug here can't silently smuggle more than a
 * hostname through.
 *
 * "Active" specifically means: the browser window has OS focus (not just
 * "a tab exists"), that tab is the focused tab in that window, the
 * employee is not OS-idle, and the backend confirms a signed-in +
 * consented work session is in progress. Any one of those being false
 * means no domain is being tracked at that moment -- background tabs are
 * never counted (see recomputeSegment()).
 *
 * MV3 service workers can be terminated and woken up at any time, so:
 *   - Domain-switch detection is event-driven (tabs.onActivated/onUpdated,
 *     windows.onFocusChanged, idle.onStateChanged) rather than polled --
 *     these wake the worker reliably and fire with no timing drift.
 *   - Anything that must survive a restart (the in-progress segment, the
 *     upload queue, cached credentials/token) is persisted to
 *     chrome.storage.local on every change and re-read at the top of every
 *     wake-up, instead of trusted to live only in memory.
 *   - chrome.alarms (not setInterval) drives the one periodic tick --
 *     setInterval timers do not survive worker suspension, alarms do.
 */

const STORAGE_KEY = 'abhimoState';
const ALARM_NAME = 'abhimo-tick';

/** @typedef {{ username: string, apiBase: string }} Credentials */
/** @typedef {{ iv: string, data: string }} EncryptedSecret */
/** @typedef {{ domain: string|null, isIdle: boolean, startedAtMs: number }} Segment */

/**
 * @typedef {{
 *   credentials: Credentials|null,
 *   credentialsEnc: EncryptedSecret|null,
 *   cachedToken: { token: string, expiresAtMs: number }|null,
 *   monitoringAllowed: boolean,
 *   isIdle: boolean,
 *   currentSegment: Segment|null,
 *   queue: Array<object>,
 *   lastError: string|null,
 * }} State
 */

/** @returns {State} */
function defaultState() {
  return {
    credentials: null,
    credentialsEnc: null,
    cachedToken: null,
    monitoringAllowed: false,
    isIdle: false,
    currentSegment: null,
    queue: [],
    lastError: null,
  };
}

/** @returns {Promise<State>} */
async function loadState() {
  const stored = await chrome.storage.local.get(STORAGE_KEY);
  return { ...defaultState(), ...(stored[STORAGE_KEY] || {}) };
}

/** @param {State} state */
async function saveState(state) {
  await chrome.storage.local.set({ [STORAGE_KEY]: state });
}

// ---- Domain extraction -- the one place a URL is ever touched ----

/**
 * Returns a bare hostname for a trackable http(s) page, or null for
 * anything else (chrome://, extension pages, file://, new-tab page, a tab
 * with no URL yet, etc.) -- null means "don't track this", not "track a
 * blank domain". Only `.hostname` is ever read off the URL object; the
 * path, query string, and hash are never touched, matching
 * WebsiteActivityController's server-side enforcement of the same rule.
 */
function domainFromUrl(rawUrl) {
  if (!rawUrl) return null;
  let parsed;
  try {
    parsed = new URL(rawUrl);
  } catch (_) {
    return null;
  }
  if (parsed.protocol !== 'http:' && parsed.protocol !== 'https:') {
    return null;
  }
  return parsed.hostname || null;
}

/** Resolves the domain of whatever tab is currently both focused-window
 *  and the active tab in it -- or null if the browser itself doesn't have
 *  OS focus right now (background tabs / other apps in foreground are
 *  never counted, per the Phase 6 privacy requirement). */
async function getCurrentTrackableDomain() {
  const win = await chrome.windows.getLastFocused({ populate: false }).catch(() => null);
  if (!win || win.focused !== true) {
    return null; // browser is not the foreground app right now
  }
  const tabs = await chrome.tabs.query({ active: true, windowId: win.id }).catch(() => []);
  const tab = tabs[0];
  return tab ? domainFromUrl(tab.url) : null;
}

// ---- Segment tracking (mirrors desktop-tray/activity-tracker.js) ----

/**
 * Closes out the current segment (if any) into a queued entry for the
 * elapsed time, then either clears it (newSegment === null, e.g.
 * monitoring stopped) or opens a fresh one. Called on every domain switch,
 * idle-state change, and monitoring-status change, plus once per alarm
 * tick to chunk a long-running segment (see MAX segment duration note in
 * flushChunk()).
 * @param {State} state
 * @param {{ domain: string|null, isIdle: boolean }|null} newSegment
 */
function switchSegment(state, newSegment) {
  flushChunk(state, /* reopen */ false);
  state.currentSegment = newSegment
    ? { domain: newSegment.domain, isIdle: newSegment.isIdle, startedAtMs: Date.now() }
    : null;
}

/**
 * Emits a queued entry for whatever time has elapsed in the current
 * segment. With reopen=true (the periodic alarm tick) the segment stays
 * open under the same domain/idle state starting from now -- this is what
 * turns one long stretch of the same domain into periodic chunks, so
 * AdminActivityController-style "online in the last N seconds" checks and
 * the server's 3600s per-entry cap are both non-issues, exactly like
 * activity-tracker.js's _flushChunk(). With reopen=false (a real switch)
 * the segment is simply closed.
 * @param {State} state
 * @param {boolean} reopen
 */
function flushChunk(state, reopen) {
  const seg = state.currentSegment;
  if (!seg) return;

  const now = Date.now();
  const elapsedSeconds = Math.round((now - seg.startedAtMs) / 1000);

  if (elapsedSeconds > 0 && seg.domain && !seg.isIdle) {
    // Idle stretches are intentionally not queued from this extension at
    // all -- the desktop tray's activity_logs is the authoritative idle
    // signal (see AdminEmployeeActivityController::collapseSegments on the
    // backend, which already treats the app-stream idle entries as
    // authoritative for the same reason). A domain of null (browser not
    // focused / non-http page) is never queued either -- nothing was
    // "actively used" during that stretch.
    state.queue.push({
      timestamp: formatServerTimestamp(new Date(seg.startedAtMs)),
      domain: seg.domain,
      is_idle: false,
      duration_seconds: Math.min(elapsedSeconds, 3600),
      client_batch_id: crypto.randomUUID(),
    });
  }

  state.currentSegment = reopen ? { ...seg, startedAtMs: now } : null;
}

/** Formats a JS Date as 'Y-m-d H:i:s' in local wall-clock time, matching
 *  WebsiteActivityController::ingest's parse format -- identical helper to
 *  desktop-tray/main.js's formatServerTimestamp() for the same reason
 *  (server rejects entries whose timestamp looks skewed vs. its own clock). */
function formatServerTimestamp(date) {
  const pad = (n) => String(n).padStart(2, '0');
  return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())} ` +
    `${pad(date.getHours())}:${pad(date.getMinutes())}:${pad(date.getSeconds())}`;
}

/** Re-evaluates what should currently be tracked and switches the segment
 *  if it changed. Called from every event listener below -- this is the
 *  single source of truth for "what domain, if any, counts as active
 *  right now", so idle/focus/monitoring/tab-switch logic never has to be
 *  duplicated across listeners. */
async function recomputeSegment(state) {
  if (!state.monitoringAllowed) {
    switchSegment(state, null);
    return;
  }
  if (state.isIdle) {
    const current = state.currentSegment;
    if (!current || !current.isIdle) {
      switchSegment(state, { domain: null, isIdle: true });
    }
    return;
  }
  const domain = await getCurrentTrackableDomain();
  const current = state.currentSegment;
  if (!current || current.isIdle || current.domain !== domain) {
    switchSegment(state, { domain, isIdle: false });
  }
}

// ---- Auth ----

function decodeJwtExpiryMs(token) {
  try {
    const payload = JSON.parse(atob(token.split('.')[1].replace(/-/g, '+').replace(/_/g, '/')));
    return typeof payload.exp === 'number' ? payload.exp * 1000 : null;
  } catch (_) {
    return null;
  }
}

/** @param {State} state @returns {Promise<string|null>} */
async function getAuthToken(state) {
  if (state.cachedToken && Date.now() < state.cachedToken.expiresAtMs - 15000) {
    return state.cachedToken.token;
  }
  if (!state.credentials) return null;
  const password = await decryptSecret(state.credentialsEnc);
  if (!password) return null; // no encrypted password saved, or storage was corrupted/tampered
  try {
    const res = await fetch(`${state.credentials.apiBase}${tracking.endpoints.login}`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ username: state.credentials.username, password }),
    });
    if (!res.ok) return null;
    const data = await res.json().catch(() => ({}));
    if (!data.token) return null;
    state.cachedToken = { token: data.token, expiresAtMs: decodeJwtExpiryMs(data.token) || Date.now() + 5 * 60000 };
    return state.cachedToken.token;
  } catch (_) {
    return null;
  }
}

/** Checks GET /api/attendance/today the same way desktop-tray/main.js's
 *  pollMonitoringStatus() does, and updates state.monitoringAllowed to
 *  match -- a 403 here (password/consent gate not cleared) is expected,
 *  not an error condition to surface to the employee. */
async function pollMonitoringStatus(state) {
  if (!state.credentials) {
    state.monitoringAllowed = false;
    return;
  }
  const token = await getAuthToken(state);
  if (!token) {
    state.monitoringAllowed = false;
    state.lastError = 'Could not sign in with the saved credentials.';
    return;
  }
  try {
    const res = await fetch(`${state.credentials.apiBase}${tracking.endpoints.monitoringStatus}`, {
      headers: { Authorization: `Bearer ${token}` },
    });
    if (res.status === 403) {
      state.monitoringAllowed = false;
      state.lastError = null;
      return;
    }
    if (!res.ok) {
      state.monitoringAllowed = false;
      state.lastError = `Server returned ${res.status}.`;
      return;
    }
    const data = await res.json().catch(() => ({}));
    state.monitoringAllowed = !!(data.sign_in_time && !data.sign_out_time);
    state.lastError = null;
  } catch (_) {
    state.monitoringAllowed = false;
    state.lastError = 'Could not reach the server.';
  }
}

/**
 * Without this, a batch the server will reject forever (timestamps fell
 * outside WebsiteActivityController's accepted backdate window while the
 * browser was closed/offline) sits at the front of state.queue and is
 * retried every tick, indefinitely blocking every entry queued behind it --
 * see desktop-tray/upload-queue.js's _dropExpired() for the identical
 * reasoning on the tray side. Run on every flushQueue() call, not just
 * once, since staleness is relative to "now" -- an entry queued while
 * fresh can still age past the cutoff purely while waiting its turn in a
 * backlog. Unparsable timestamps are kept -- the server is the actual
 * authority on whether an entry is acceptable, not this best-effort check.
 * @param {State} state
 */
function dropExpiredQueueEntries(state) {
  const cutoff = Date.now() - tracking.maxQueueAgeHours * 60 * 60 * 1000;
  const before = state.queue.length;
  state.queue = state.queue.filter((item) => {
    const t = new Date(item.timestamp).getTime();
    return Number.isNaN(t) || t >= cutoff;
  });
  if (state.queue.length !== before) {
    console.warn(`Abhimo Tracker: dropped ${before - state.queue.length} queued entries older than the server's accepted window.`);
  }
}

/** Sends up to tracking.maxBatchSize queued entries. On success those
 *  entries are removed from the queue; on failure the queue is left
 *  untouched and retried on the next tick -- same offline-safe pattern as
 *  desktop-tray/upload-queue.js, with client_batch_id giving the backend
 *  its idempotency key for a retry that actually succeeded server-side but
 *  whose response was lost. */
async function flushQueue(state) {
  dropExpiredQueueEntries(state);
  if (state.queue.length === 0 || !state.credentials) return;
  const token = await getAuthToken(state);
  if (!token) return;

  const batch = state.queue.slice(0, tracking.maxBatchSize);
  try {
    const res = await fetch(`${state.credentials.apiBase}${tracking.endpoints.websiteIngest}`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', Authorization: `Bearer ${token}` },
      body: JSON.stringify({ entries: batch }),
    });
    if (res.ok) {
      state.queue = state.queue.slice(batch.length);
      state.lastError = null;
    } else {
      const data = await res.json().catch(() => ({}));
      state.lastError = data.error || `Upload failed (${res.status}).`;
    }
  } catch (_) {
    state.lastError = 'Could not reach the server to upload queued activity.';
  }
}

// ---- Alarm tick: chunk-flush + status poll + queue upload, in one shot ----

async function ensureAlarm() {
  const existing = await chrome.alarms.get(ALARM_NAME);
  if (!existing) {
    chrome.alarms.create(ALARM_NAME, { periodInMinutes: tracking.tickAlarmMinutes });
  }
}

async function onTick() {
  const state = await loadState();
  const wasAllowed = state.monitoringAllowed;

  await pollMonitoringStatus(state);

  if (wasAllowed && !state.monitoringAllowed) {
    switchSegment(state, null); // monitoring just stopped -- close out immediately
  } else if (!wasAllowed && state.monitoringAllowed) {
    await recomputeSegment(state); // monitoring just started -- begin a fresh segment
  } else if (state.monitoringAllowed && state.currentSegment) {
    flushChunk(state, /* reopen */ true); // still tracking -- periodic chunk
  }

  await flushQueue(state);
  await saveState(state);
}

chrome.alarms.onAlarm.addListener((alarm) => {
  if (alarm.name === ALARM_NAME) onTick();
});

// ---- Event-driven domain-switch detection ----

async function handleActivityEvent() {
  const state = await loadState();
  if (!state.monitoringAllowed) return; // nothing to recompute if we're not tracking
  await recomputeSegment(state);
  await saveState(state);
}

chrome.tabs.onActivated.addListener(handleActivityEvent);
chrome.tabs.onUpdated.addListener((tabId, changeInfo, tab) => {
  if (changeInfo.url && tab.active) handleActivityEvent();
});
chrome.windows.onFocusChanged.addListener(handleActivityEvent);

chrome.idle.onStateChanged.addListener(async (newState) => {
  const state = await loadState();
  state.isIdle = newState !== 'active';
  if (state.monitoringAllowed) {
    await recomputeSegment(state);
  }
  await saveState(state);
});

// ---- Extension lifecycle ----

async function init() {
  chrome.idle.setDetectionInterval(tracking.idleThresholdSeconds);
  await ensureAlarm();
  // Re-evaluate immediately on every wake so a service-worker restart
  // (browser relaunch, worker eviction) doesn't leave stale tracking state
  // active until the next alarm tick.
  const state = await loadState();
  if (state.monitoringAllowed) {
    await recomputeSegment(state);
    await saveState(state);
  }
}

chrome.runtime.onInstalled.addListener(init);
chrome.runtime.onStartup.addListener(init);
init();

// ---- Messages from the onboarding page (zero-typing pairing) ----
// Mirrors desktop-tray/main.js's abhimo://pair flow exactly, just delivered
// over chrome.runtime.sendMessage (see manifest.json's externally_connectable)
// instead of a custom protocol handler. The onboarding page never sees the
// real password -- it only ever holds a single-use token; this background
// worker is what exchanges that token for real credentials and logs in with
// them, identically to a manual login via popup.js.
chrome.runtime.onMessageExternal.addListener((message, _sender, sendResponse) => {
  if (message && message.type === 'PING') {
    sendResponse({ ok: true, installed: true });
    return true;
  }
  if (message && message.type === 'PAIR') {
    handlePairMessage(message).then(sendResponse);
    return true; // keep the message channel open for the async response
  }
  return false;
});

async function handlePairMessage(message) {
  const apiBase = message.apiBase || DEFAULT_API_BASE;
  try {
    const res = await fetch(`${apiBase}/api/extension/pair/${encodeURIComponent(message.token)}/exchange`, {
      method: 'POST',
    });
    const data = await res.json().catch(() => ({}));
    if (!res.ok) {
      return { ok: false, error: data.error || 'Pairing failed. Reopen the onboarding page and try again.' };
    }
    // Same path as a manual login (message.type 'login' below) -- saves
    // credentials, fetches a token, and checks monitoring status.
    return handleMessage({ type: 'login', username: data.username, password: data.password, apiBase });
  } catch (_) {
    return { ok: false, error: 'Could not reach the server to complete pairing.' };
  }
}

// ---- Messages from popup.js ----

chrome.runtime.onMessage.addListener((message, _sender, sendResponse) => {
  handleMessage(message).then(sendResponse);
  return true; // keep the message channel open for the async response
});

async function handleMessage(message) {
  const state = await loadState();

  if (message.type === 'login') {
    const apiBase = message.apiBase || DEFAULT_API_BASE;
    try {
      const res = await fetch(`${apiBase}${tracking.endpoints.login}`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ username: message.username, password: message.password }),
      });
      const data = await res.json().catch(() => ({}));
      if (!res.ok) {
        return { ok: false, error: data.error || 'Invalid credentials.' };
      }
      state.credentials = { username: message.username, apiBase };
      state.credentialsEnc = await encryptSecret(message.password);
      state.cachedToken = { token: data.token, expiresAtMs: decodeJwtExpiryMs(data.token) || Date.now() + 5 * 60000 };
      state.lastError = null;
      await pollMonitoringStatus(state);
      await saveState(state);
      return { ok: true };
    } catch (_) {
      return { ok: false, error: 'Could not reach the server.' };
    }
  }

  if (message.type === 'logout') {
    switchSegment(state, null);
    state.credentials = null;
    state.credentialsEnc = null;
    state.cachedToken = null;
    state.monitoringAllowed = false;
    state.queue = [];
    state.lastError = null;
    await saveState(state);
    return { ok: true };
  }

  if (message.type === 'getStatus') {
    return {
      ok: true,
      pairedAs: state.credentials ? state.credentials.username : null,
      monitoringAllowed: state.monitoringAllowed,
      isIdle: state.isIdle,
      currentDomain: state.currentSegment && !state.currentSegment.isIdle ? state.currentSegment.domain : null,
      pendingCount: state.queue.length,
      lastError: state.lastError,
    };
  }

  return { ok: false, error: 'Unknown message type.' };
}