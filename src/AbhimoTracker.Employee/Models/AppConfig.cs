namespace AbhimoTracker.Employee.Models;

/// <summary>
/// Mirrors desktop-tray/config.js -- kept as one place so the numbers here
/// can be diffed against that file if the two ever need to be kept in sync.
/// </summary>
public sealed class TrackingConfig
{
    public int IdleThresholdSeconds { get; init; } = 300;      // 5 min of no OS-wide input -> idle
    public int IdlePollIntervalMs { get; init; } = 10_000;      // check OS idle time every 10s
    public int ActivePollIntervalMs { get; init; } = 3_000;     // check foreground app every 3s

    // Slice a long stretch into entries this often -- must stay under the
    // backend's 180s "online" window (AdminActivityController::ONLINE_WINDOW_SECONDS)
    // and its 3600s per-entry cap (ActivityController::MAX_ENTRY_DURATION_SECONDS).
    public int ChunkFlushMs { get; init; } = 90_000;

    // "Sends activity in batches every 30-60 seconds" -- default sits at the
    // low end of that range; raise to up to 60000 if you want fewer, larger
    // batches instead.
    public int UploadFlushIntervalMs { get; init; } = 30_000;
    public int MaxBatchSize { get; init; } = 25;
    public int MaxQueueAgeHours { get; init; } = 47; // 1h under ActivityController::MAX_BACKDATE_HOURS (48h)
    public int MonitoringStatusPollMs { get; init; } = 15_000;
}

public sealed class Endpoints
{
    public string Login { get; init; } = "/api/login";
    public string MonitoringStatus { get; init; } = "/api/attendance/today";
    public string SignIn { get; init; } = "/api/attendance/sign-in";
    public string SignOut { get; init; } = "/api/attendance/sign-out";
    public string ActivityIngest { get; init; } = "/api/activity/ingest";
    public string ConsentWithdraw { get; init; } = "/api/consent/withdraw";
    public string TrayPairExchangeTemplate { get; init; } = "/api/tray/pair/{token}/exchange";
}

public sealed class AppConfig
{
    // Kept configurable/buildable per environment rather than hardcoded, per
    // the master prompt's "shared requirements" section -- override via
    // appsettings.json or appsettings.Production.json for a real deployment.
    public string DefaultApiBase { get; init; } = "http://localhost:8080";

    public string RelayHubUrl { get; init; } = "http://localhost:5080/hubs/live-status";

    public TrackingConfig Tracking { get; init; } = new();
    public Endpoints Endpoints { get; init; } = new();
}
