'use strict';

// Fallback API base if a pairing link doesn't carry one (e.g. someone opens
// the tray app before ever pairing). Matches frontend/js/api.js's default --
// keep the two in sync, or point both at your real deployment URL.
module.exports = {
  DEFAULT_API_BASE: 'http://localhost/attendance-system/backend/public',

  tracking: {
    idleThresholdSeconds: 300, // 5 min of no OS-wide input -> idle
    idlePollIntervalMs: 10000, // check OS idle time every 10s
    activePollIntervalMs: 3000, // check foreground app every 3s
    chunkFlushMs: 90000, // slice a long stretch into entries this often --
    // must stay under the backend's 180s "online" window
    // (AdminActivityController::ONLINE_WINDOW_SECONDS) and its 3600s
    // per-entry cap (ActivityController::MAX...), so this is a heartbeat
    // and a duration-cap safeguard in one.
    uploadFlushIntervalMs: 30000, // send whatever's queued every 30s
    maxBatchSize: 25,
    maxQueueAgeHours: 47, // see main.js's UploadQueue construction
    monitoringStatusPollMs: 15000, // re-check signed-in/consent state
  },

  // Confirmed against backend/public/index.php and the real controllers.
  endpoints: {
    login: '/api/login', // returns { token, must_change_password, employee }
    monitoringStatus: '/api/attendance/today', // returns { sign_in_time, sign_out_time }; 403 = consent/password gate not cleared
    activityIngest: '/api/activity/ingest', // expects { entries: [{ timestamp, active_window, is_idle, duration_seconds }] }
    consentWithdraw: '/api/consent/withdraw', // Phase 13: employee-initiated -- stops new monitoring immediately
  },
};
