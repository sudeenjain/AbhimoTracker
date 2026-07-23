namespace AbhimoTracker.RelayHub.Models;

/// <summary>
/// Ephemeral, in-memory only -- never persisted here. The PHP backend's
/// activity_logs table (via /api/activity/ingest) remains the durable
/// record; this is purely "what's the fastest way to tell the admin app
/// this changed", matching AdminActivityController::liveStatus's docblock
/// (polling is the durable/authoritative fallback, this hub is the
/// low-latency addition on top of it).
/// </summary>
public sealed record StatusUpdate(
    int EmployeeId,
    string Username,
    string Status,       // "active" | "idle" | "offline" | "signedout"
    string? ActiveWindow, // app/process name only -- never a window title, same constraint as ActivityController
    DateTimeOffset At
);
