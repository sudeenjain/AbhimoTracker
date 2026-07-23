// Browser-based work-session tracking (heartbeat, active/idle detection,
// Page Visibility, multi-tab dedupe, progress updates).
//
// Only detects THAT an interaction happened (mouse/keyboard/scroll/touch)
// and WHETHER the tab is visible. It never reads what was typed, what was
// clicked on, page contents, or anything else -- see WorkSessionController
// on the backend for the matching whitelist.
//
// Public API (used by attendance.html):
//   WorkSession.start(onUpdate)  -- begin tracking; onUpdate(state) is
//                                   called after every heartbeat/status change
//   WorkSession.stop()           -- stop tracking (e.g. after sign-out)
//   WorkSession.submitProgress(payload) -> Promise<{ok, status, data}>
//   WorkSession.fetchToday()     -> Promise<{ok, status, data}>

const WorkSession = (() => {
  // Keep in sync with backend/config/tracking.php
  const HEARTBEAT_INTERVAL_MS = 25000;
  const IDLE_AFTER_MS = 5 * 60 * 1000;
  const LEADER_LOCK_KEY = 'work_session_leader';
  const LEADER_LOCK_TTL_MS = 15000; // a leader must renew within this window or another tab takes over

  let heartbeatTimer = null;
  let leaderRenewTimer = null;
  let lastInteractionAt = Date.now();
  let onUpdateCallback = null;
  let tabId = null;
  let listenersAttached = false;

  function nowMs() {
    return Date.now();
  }

  function markInteraction() {
    lastInteractionAt = nowMs();
  }

  function attachInteractionListeners() {
    if (listenersAttached) return;
    ['mousemove', 'mousedown', 'keydown', 'scroll', 'touchstart', 'click'].forEach((evt) => {
      window.addEventListener(evt, markInteraction, { passive: true });
    });
    document.addEventListener('visibilitychange', markInteraction);
    listenersAttached = true;
  }

  // --- Multi-tab leader election -----------------------------------------
  // Only the "leader" tab sends heartbeats, so opening the app in 3 tabs
  // doesn't triple the heartbeat rate or create inconsistent active/idle
  // accounting. A lock in localStorage (visible to all tabs of the same
  // origin) records which tab is currently leading and when it last
  // renewed; if a leader disappears (tab closed, crashed) without releasing
  // the lock, it goes stale after LEADER_LOCK_TTL_MS and another tab claims it.
  function tryBecomeLeader() {
    const raw = localStorage.getItem(LEADER_LOCK_KEY);
    const lock = raw ? JSON.parse(raw) : null;
    const isStale = !lock || (nowMs() - lock.ts) > LEADER_LOCK_TTL_MS;

    if (isStale || lock.tabId === tabId) {
      localStorage.setItem(LEADER_LOCK_KEY, JSON.stringify({ tabId, ts: nowMs() }));
      return true;
    }
    return false;
  }

  function releaseLeaderIfSelf() {
    const raw = localStorage.getItem(LEADER_LOCK_KEY);
    const lock = raw ? JSON.parse(raw) : null;
    if (lock && lock.tabId === tabId) {
      localStorage.removeItem(LEADER_LOCK_KEY);
    }
  }

  // --- Heartbeat -----------------------------------------------------------
  async function sendHeartbeat() {
    if (!tryBecomeLeader()) {
      return; // another tab is already reporting for this employee
    }

    const isIdle = (nowMs() - lastInteractionAt) > IDLE_AFTER_MS;
    const isTabVisible = document.visibilityState === 'visible';

    try {
      const { ok, status, data } = await apiFetch('/api/work-session/heartbeat', {
        method: 'POST',
        body: JSON.stringify({ is_idle: isIdle, is_tab_visible: isTabVisible }),
      });
      if (ok && onUpdateCallback) {
        onUpdateCallback({
          status: data.status,
          activeSeconds: data.active_seconds,
          idleSeconds: data.idle_seconds,
          lastSyncedAt: nowMs(),
        });
      } else if (!ok && status === 409) {
        // No open session (e.g. already signed out in another tab) -- stop quietly.
        stop();
      }
    } catch (e) {
      // Network hiccup: skip this tick, try again on the next interval.
      // Do not surface as a hard error -- Phase 17, failures here must not
      // break sign-in/out or the rest of the app.
    }
  }

  function start(onUpdate) {
    if (heartbeatTimer) return; // already running
    tabId = tabId || `${Date.now()}-${Math.random().toString(36).slice(2)}`;
    onUpdateCallback = onUpdate || null;

    attachInteractionListeners();
    markInteraction();

    sendHeartbeat();
    heartbeatTimer = setInterval(sendHeartbeat, HEARTBEAT_INTERVAL_MS);
    leaderRenewTimer = setInterval(() => { tryBecomeLeader(); }, LEADER_LOCK_TTL_MS / 2);

    window.addEventListener('beforeunload', releaseLeaderIfSelf);
  }

  function stop() {
    if (heartbeatTimer) clearInterval(heartbeatTimer);
    if (leaderRenewTimer) clearInterval(leaderRenewTimer);
    heartbeatTimer = null;
    leaderRenewTimer = null;
    releaseLeaderIfSelf();
  }

  async function submitProgress(payload) {
    return apiFetch('/api/work-session/progress', {
      method: 'POST',
      body: JSON.stringify(payload),
    });
  }

  async function fetchToday() {
    return apiFetch('/api/work-session/today');
  }

  return { start, stop, submitProgress, fetchToday };
})();
