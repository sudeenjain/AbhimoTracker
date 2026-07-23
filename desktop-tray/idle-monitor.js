'use strict';

const { powerMonitor } = require('electron');
const EventEmitter = require('events');

/**
 * OS-wide idle detection. Uses Electron's built-in powerMonitor -- no
 * extra dependency, and it sees real system-wide mouse/keyboard activity
 * (more accurate than anything a browser tab or single app could observe).
 *
 * Only ever exposes a boolean (idle / active) via events, matching the
 * "no keystrokes, no mouse data" privacy constraint -- the actual idle
 * seconds are used internally for the threshold check and in the event
 * payload for debugging, never persisted or sent anywhere beyond that.
 *
 * Screen lock counts as idle immediately (via the 'lock-screen' event)
 * rather than waiting out the full idle threshold.
 */
class IdleMonitor extends EventEmitter {
  constructor({ idleThresholdSeconds = 300, pollIntervalMs = 10000 } = {}) {
    super();
    this.idleThresholdSeconds = idleThresholdSeconds;
    this.pollIntervalMs = pollIntervalMs;
    this._timer = null;
    this._isIdle = false;
    this._locked = false;

    this._onLock = () => {
      this._locked = true;
      this._setIdle(true);
    };
    this._onUnlock = () => {
      this._locked = false;
      // Don't force back to active on unlock -- the person may have
      // unlocked and stepped away again. Let the next poll decide.
      this._poll();
    };
    powerMonitor.on('lock-screen', this._onLock);
    powerMonitor.on('unlock-screen', this._onUnlock);
  }

  start() {
    if (this._timer) return;
    this._poll();
    this._timer = setInterval(() => this._poll(), this.pollIntervalMs);
  }

  stop() {
    if (this._timer) {
      clearInterval(this._timer);
      this._timer = null;
    }
  }

  dispose() {
    this.stop();
    powerMonitor.removeListener('lock-screen', this._onLock);
    powerMonitor.removeListener('unlock-screen', this._onUnlock);
  }

  _poll() {
    if (this._locked) return; // lock handler already forced idle=true
    let idleSeconds;
    try {
      idleSeconds = powerMonitor.getSystemIdleTime();
    } catch (err) {
      return; // unsupported platform state -- keep previous value
    }
    this._setIdle(idleSeconds >= this.idleThresholdSeconds);
  }

  _setIdle(nowIdle) {
    if (nowIdle === this._isIdle) return;
    this._isIdle = nowIdle;
    this.emit(nowIdle ? 'idle' : 'active', { at: Date.now() });
  }

  get isIdle() {
    return this._isIdle;
  }
}

module.exports = { IdleMonitor };
