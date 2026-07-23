using System.Text.Json.Serialization;

namespace AbhimoTracker.Employee.Models;

/// <summary>
/// Matches ActivityController::ingest's exact entry contract:
/// { timestamp, active_window, is_idle, duration_seconds, client_batch_id }.
/// active_window is the app/process name ONLY, never a window title -- see
/// ActivityTracker.cs's docblock.
/// </summary>
public sealed class ActivityEntry
{
    [JsonPropertyName("timestamp")]
    public DateTime Timestamp { get; set; }

    [JsonPropertyName("active_window")]
    public string? ActiveWindow { get; set; }

    [JsonPropertyName("is_idle")]
    public bool IsIdle { get; set; }

    [JsonPropertyName("duration_seconds")]
    public int DurationSeconds { get; set; }

    [JsonPropertyName("client_batch_id")]
    public string ClientBatchId { get; set; } = Guid.NewGuid().ToString();

    [JsonIgnore]
    public long QueuedAtUnixMs { get; set; }
}

public sealed class StoredCredentials
{
    public string Username { get; set; } = "";
    public string Password { get; set; } = "";
    public string ApiBase { get; set; } = "";
}
