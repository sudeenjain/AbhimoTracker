using System.IO;
using System.Text.Json;
using System.Timers;
using AbhimoTracker.Employee.Models;
using Timer = System.Timers.Timer;

namespace AbhimoTracker.Employee.Services;

/// <summary>
/// Disk-backed queue for activity events. Port of desktop-tray/upload-queue.js.
/// Batches entries instead of sending one request per event, and survives a
/// network blip, backend restart, or agent restart without losing tracked
/// time -- queued items persist to a local JSON file and are retried on the
/// next flush ("stores data locally temporarily if internet disconnects").
///
/// Each item carries a stable client_batch_id (GUID) generated at enqueue
/// time, so a retry after a lost response can be deduped server-side
/// (ActivityController::ingest's `ON DUPLICATE KEY UPDATE id = id`) instead
/// of double-counting duration.
/// </summary>
public sealed class UploadQueue : IDisposable
{
    private readonly string _filePath;
    private readonly int _maxBatch;
    private readonly TimeSpan? _maxAge;
    private readonly Timer _timer;
    private readonly object _lock = new();
    private List<ActivityEntry> _items;
    private Func<IReadOnlyList<ActivityEntry>, CancellationToken, Task<bool>>? _sender;
    private bool _flushing;

    public int PendingCount { get { lock (_lock) return _items.Count; } }

    public UploadQueue(string userDataDir, int flushIntervalMs, int maxBatch, TimeSpan? maxAge)
    {
        Directory.CreateDirectory(userDataDir);
        _filePath = Path.Combine(userDataDir, "activity-queue.json");
        _maxBatch = maxBatch;
        _maxAge = maxAge;
        _items = Load();
        _timer = new Timer(flushIntervalMs) { AutoReset = true };
        _timer.Elapsed += async (_, _) => await FlushAsync();
    }

    public void SetSender(Func<IReadOnlyList<ActivityEntry>, CancellationToken, Task<bool>> sender) => _sender = sender;

    public void Enqueue(ActivityEntry item)
    {
        item.QueuedAtUnixMs = DateTimeOffset.UtcNow.ToUnixTimeMilliseconds();
        lock (_lock) _items.Add(item);
        Persist();
    }

    public void Start() => _timer.Start();
    public void Stop() => _timer.Stop();

    public async Task FlushAsync(CancellationToken ct = default)
    {
        if (_flushing || _sender is null) return;
        List<ActivityEntry> batch;
        lock (_lock)
        {
            if (_flushing) return;
            _flushing = true;
        }
        try
        {
            DropExpired();
            lock (_lock)
            {
                if (_items.Count == 0) return;
                batch = _items.Take(_maxBatch).ToList();
            }

            bool ok;
            try
            {
                ok = await _sender(batch, ct);
            }
            catch
            {
                ok = false;
            }

            if (ok)
            {
                lock (_lock) _items = _items.Skip(batch.Count).ToList();
                Persist();
            }
            // On failure the batch stays at the front of the queue and is
            // retried next cycle -- no data loss, just delay.
        }
        finally
        {
            lock (_lock) _flushing = false;
        }
    }

    /// <summary>
    /// Without this, a batch the server will reject forever (timestamps fell
    /// outside the accepted backdate window while offline) sits at the front
    /// of the queue and blocks every entry queued behind it indefinitely --
    /// same reasoning as upload-queue.js's _dropExpired().
    /// </summary>
    private void DropExpired()
    {
        if (_maxAge is not { } maxAge) return;
        var cutoff = DateTimeOffset.UtcNow - maxAge;
        lock (_lock)
        {
            var before = _items.Count;
            _items = _items.Where(i => i.Timestamp.ToUniversalTime() >= cutoff.UtcDateTime).ToList();
            if (_items.Count != before) Persist();
        }
    }

    private List<ActivityEntry> Load()
    {
        try
        {
            if (File.Exists(_filePath))
            {
                var json = File.ReadAllText(_filePath);
                return JsonSerializer.Deserialize<List<ActivityEntry>>(json) ?? [];
            }
        }
        catch
        {
            // Corrupt queue file -- start fresh rather than crashing.
        }
        return [];
    }

    private void Persist()
    {
        try
        {
            List<ActivityEntry> snapshot;
            lock (_lock) snapshot = [.. _items];
            File.WriteAllText(_filePath, JsonSerializer.Serialize(snapshot));
        }
        catch
        {
            // Best-effort persistence -- next successful flush will retry writing anyway.
        }
    }

    public void Dispose()
    {
        _timer.Stop();
        _timer.Dispose();
    }
}
