<?php

/**
 * All timing knobs for the browser work-session tracker in one place, per
 * the "easy to configure from one place" requirement. Returned as a plain
 * array; consumed by WorkSessionController / AdminWorkSessionController.
 * Frontend copies of the interval/idle values live in
 * frontend/js/work-session.js -- keep the two in sync if you change these.
 */
return [
    // How often the browser is expected to send a heartbeat (seconds).
    // Frontend timer in work-session.js should match this.
    'heartbeat_interval_seconds' => 25,

    // If no heartbeat has arrived in this long, the admin dashboard treats
    // the employee as OFFLINE even if the last stored status was ACTIVE/IDLE.
    // Covers browser crash, laptop sleep, network loss (Phase 10).
    'offline_after_seconds' => 90,

    // Server-side idle detection is client-reported (the browser knows
    // about real interaction events; the server doesn't). This value bounds
    // how much active/idle time a single heartbeat gap can contribute, so a
    // laptop that was asleep for 6 hours doesn't get counted as 6 hours of
    // idle time the moment it reconnects.
    'max_heartbeat_gap_seconds' => 90,

    // Client-side: how long with no mouse/keyboard/scroll/touch interaction
    // before the browser reports is_idle = true on its next heartbeat.
    'idle_after_seconds' => 300,
];
