'use strict';

const EventEmitter = require('events');

/** Hard server-side cap in ActivityController::ingest -- entries longer
 *  than this are rejected outright, so a chunk can never exceed it. */
const MAX_ENTRY_DURATION_SECONDS = 3600;

/**
 * Tracks foreground application usage and emits entries matching the
 * backend's exact contract (see ActivityController::ingest):
 *   { timestamp, active_window, is_idle, duration_seconds }
 *
 * Two things this deliberately does that a naive "one row per app switch"
 * design wouldn't:
 *
 * 1. active_window is the app/process name ONLY, never a window title --
 *    confirmed by AdminActivityController::appUsage's docblock ("never a
 *    window title"). We never even read the window title from active-win.
 *
 * 2. A single continuous stretch in one app (or one idle stretch) is
 *    chunked into ~90s slices (chunkFlushMs) instead of one entry that
 *    only closes on the next switch. Two reasons:
 *      - AdminActivityController.liveStatus treats an employee as
 *        "offline" if their most recent activity_logs row is more than
 *        180s old -- sitting in the same app for an hour would otherwise
 *        make them look offline to the admin dashboard.
 *      - duration_seconds is capped at 3600 server-side; chunking makes
 *        that cap a non-issue instead of something that could silently
 *        truncate/reject a long stretch.
 *
 * Idle and active-app tracking are unified into one current-segment
 * concept because the backend's entries table treats them as one stream
 * (is_idle flag on the same row shape), not two separate feeds.
 *
 * Collects only: app/process name and duration. Never window titles,
 * keystrokes, mouse position, clipboard, or screenshots.
 */
class ActivityTracker extends EventEmitter {
  constructor({ pollIntervalMs = 3000, chunkFlushMs = 90000 } = {}) {
    super();
    this.pollIntervalMs = pollIntervalMs;
    this.chunkFlushMs = chunkFlushMs;
    this._pollTimer = null;
    this._chunkTimer = null;
    this._activeWinFn = null;
    this._isIdle = false;
    /** @type {{appName: string|null, isIdle: boolean, segmentStart: number} | null} */
    this._current = null;
  }

  async _loadActiveWin() {
    if (!this._activeWinFn) {
      // active-win has no default export -- it only exports named functions
      // (activeWindow, activeWindowSync, openWindows, openWindowsSync). The
      // previous `mod.default || mod` fallback silently resolved to the
      // whole module namespace object (not callable) since mod.default is
      // always undefined, which is why every poll failed with
      // "activeWin is not a function" and active_window stayed null forever.
      const mod = await import('active-win');
      this._activeWinFn = mod.activeWindow;
    }
    return this._activeWinFn;
  }

  start() {
    if (this._pollTimer) return;
    this._current = { appName: null, isIdle: this._isIdle, segmentStart: Date.now() };
    this._pollTimer = setInterval(() => this._pollApp(), this.pollIntervalMs);
    this._chunkTimer = setInterval(() => this._flushChunk(), this.chunkFlushMs);
    if (!this._isIdle) this._pollApp();
  }

  stop() {
    if (this._pollTimer) {
      clearInterval(this._pollTimer);
      this._pollTimer = null;
    }
    if (this._chunkTimer) {
      clearInterval(this._chunkTimer);
      this._chunkTimer = null;
    }
    this._flushChunk();
    this._current = null;
  }

  /** Called by main.js when the IdleMonitor's state changes. Closes out
   *  whatever segment was running and starts a fresh one under the new
   *  idle/active state, so an idle stretch and the app usage before it are
   *  never merged into a single misleading entry. */
  setIdle(isIdle) {
    this._isIdle = isIdle;
    if (!this._current || isIdle === this._current.isIdle) return;
    this._flushChunk();
    this._current = { appName: null, isIdle, segmentStart: Date.now() };
  }

  async _pollApp() {
    if (!this._current || this._current.isIdle) return; // no app polling while idle
    let win;
    try {
      const activeWin = await this._loadActiveWin();
      win = await activeWin();
    } catch (err) {
      console.error('[ActivityTracker] active-win failed:', err.message);
      return; // e.g. transient OS permission hiccup -- try again next tick
    }
    const appName = win && win.owner && win.owner.name ? win.owner.name : null;
    if (appName !== this._current.appName) {
      this._flushChunk();
      this._current = { appName, isIdle: false, segmentStart: Date.now() };
    }
  }

  /** Emits an entry for elapsed time in the current segment, then keeps
   *  the segment open (same app/idle state) starting from now -- this is
   *  what turns one long stretch into periodic chunks instead of closing
   *  the segment. */
  _flushChunk() {
    if (!this._current) return;
    const now = Date.now();
    const elapsedSeconds = Math.round((now - this._current.segmentStart) / 1000);
    this._current.segmentStart = now;
    if (elapsedSeconds <= 0) return;

    this.emit('entry', {
      timestamp: new Date(now - elapsedSeconds * 1000),
      active_window: this._current.appName, // app/process name only, see class docblock
      is_idle: this._current.isIdle,
      duration_seconds: Math.min(elapsedSeconds, MAX_ENTRY_DURATION_SECONDS),
    });
  }
}

/** Convenience wrapper matching the function name AdminActivityController's
 *  docblock references ("see main.js's getForegroundAppName") -- kept here
 *  so a search for that name in this codebase actually finds the logic. */
async function getForegroundAppName(activeWinFn) {
  const win = await activeWinFn();
  return win && win.owner && win.owner.name ? win.owner.name : null;
}

module.exports = { ActivityTracker, MAX_ENTRY_DURATION_SECONDS, getForegroundAppName };