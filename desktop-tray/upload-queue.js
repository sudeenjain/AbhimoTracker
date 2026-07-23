'use strict';

const fs = require('fs');
const path = require('path');
const crypto = require('crypto');

/**
 * Disk-backed queue for activity events (app segments, idle/active
 * transitions). Batches them instead of sending one request per event, and
 * survives a network blip, backend restart, or agent restart without
 * losing tracked time -- queued items persist to disk and are retried on
 * the next flush.
 *
 * Each item gets a stable client_batch_id (uuid) generated at enqueue
 * time, so if a request actually succeeded on the server but the response
 * was lost (e.g. connection dropped after the write), a retry can be
 * deduped server-side instead of double-counting duration. The backend
 * needs to honor this id as an idempotency key.
 */
class UploadQueue {
  constructor(userDataDir, { flushIntervalMs = 30000, maxBatch = 25, maxAgeMs = null } = {}) {
    this.filePath = path.join(userDataDir, 'activity-queue.json');
    this.flushIntervalMs = flushIntervalMs;
    this.maxBatch = maxBatch;
    // Items older than this are dropped instead of ever being sent. Left
    // null by default (never expire) so any other future use of this
    // generic queue isn't forced to opt in. See _dropExpired() below for
    // why this exists.
    this.maxAgeMs = maxAgeMs;
    this._items = this._load();
    this._timer = null;
    this._sender = null; // async (items) => boolean
    this._flushing = false;
  }

  /** @param {(items: object[]) => Promise<boolean>} sender */
  setSender(sender) {
    this._sender = sender;
  }

  enqueue(item) {
    this._items.push({ ...item, client_batch_id: crypto.randomUUID(), queued_at: Date.now() });
    this._persist();
  }

  start() {
    if (this._timer) return;
    this._timer = setInterval(() => this.flush(), this.flushIntervalMs);
  }

  stop() {
    if (this._timer) {
      clearInterval(this._timer);
      this._timer = null;
    }
  }

  async flush() {
    if (this._flushing || !this._sender || this._items.length === 0) return;
    this._flushing = true;
    try {
      this._dropExpired();
      if (this._items.length === 0) return;

      const batch = this._items.slice(0, this.maxBatch);
      let ok = false;
      try {
        ok = await this._sender(batch);
      } catch (_) {
        ok = false;
      }
      if (ok) {
        this._items = this._items.slice(batch.length);
        this._persist();
      }
      // On failure the batch stays at the front of the queue and is
      // retried next cycle -- no data loss, just delay.
    } finally {
      this._flushing = false;
    }
  }

  /**
   * Without this, a batch the server will reject forever (its timestamps
   * fell outside the backend's accepted backdate window while the agent
   * was offline) sits at the front of the queue and is retried every
   * cycle, indefinitely blocking every entry queued behind it -- a laptop
   * left off for a long weekend could silently stop reporting *any* new
   * activity even after reconnecting, since flush() always tries the
   * batch at index 0 first.
   *
   * Runs on every flush() (not just once) since staleness is relative to
   * "now" -- an item queued when fresh can still age past maxAgeMs while
   * merely waiting its turn in a backlog, not only while offline.
   * Unparsable timestamps are kept rather than guessed away -- the server
   * is the actual authority on whether an entry is acceptable, so let a
   * real rejection response explain why instead of silently dropping
   * something this method can't confidently judge.
   */
  _dropExpired() {
    if (!this.maxAgeMs) return;
    const cutoff = Date.now() - this.maxAgeMs;
    const before = this._items.length;
    this._items = this._items.filter((item) => {
      const t = new Date(item.timestamp).getTime();
      return Number.isNaN(t) || t >= cutoff;
    });
    if (this._items.length !== before) {
      this._persist();
      console.warn(`UploadQueue: dropped ${before - this._items.length} entries older than the server's accepted window.`);
    }
  }

  _load() {
    try {
      if (fs.existsSync(this.filePath)) {
        const parsed = JSON.parse(fs.readFileSync(this.filePath, 'utf8'));
        return Array.isArray(parsed) ? parsed : [];
      }
    } catch (err) {
      console.error('Activity queue file was unreadable, starting fresh:', err.message);
    }
    return [];
  }

  _persist() {
    try {
      fs.writeFileSync(this.filePath, JSON.stringify(this._items), { mode: 0o600 });
    } catch (err) {
      console.error('Failed to persist activity queue:', err.message);
    }
  }

  get pendingCount() {
    return this._items.length;
  }
}

module.exports = { UploadQueue };
