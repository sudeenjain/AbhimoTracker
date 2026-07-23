using AbhimoTracker.Employee.Models;
using Microsoft.AspNetCore.SignalR.Client;

namespace AbhimoTracker.Employee.Services;

/// <summary>
/// Pushes this employee's live status (active/idle/offline/signedout) to
/// AbhimoTracker.RelayHub over SignalR, so the admin app updates instantly
/// instead of waiting out its poll interval. This is a pure add-on: if the
/// relay is unreachable, PHP's /api/admin/live-status polling still works
/// exactly as it does today (see AdminActivityController's docblock) --
/// this connection is allowed to fail silently and retry.
/// </summary>
public sealed class LiveStatusPusher : IAsyncDisposable
{
    private readonly string _hubUrl;
    private HubConnection? _connection;
    private string? _lastToken;

    public LiveStatusPusher(string hubUrl)
    {
        _hubUrl = hubUrl;
    }

    public async Task EnsureConnectedAsync(string bearerToken, CancellationToken ct = default)
    {
        if (_connection is { State: HubConnectionState.Connected } && _lastToken == bearerToken) return;

        if (_connection is not null)
        {
            await _connection.DisposeAsync();
            _connection = null;
        }

        _lastToken = bearerToken;
        _connection = new HubConnectionBuilder()
            .WithUrl(_hubUrl, options =>
            {
                options.AccessTokenProvider = () => Task.FromResult<string?>(bearerToken);
            })
            .WithAutomaticReconnect()
            .Build();

        try
        {
            await _connection.StartAsync(ct);
        }
        catch
        {
            // Relay not reachable -- non-fatal, see class docblock. Next call
            // to PushStatusAsync will try to reconnect.
        }
    }

    public async Task PushStatusAsync(string status, string? activeWindow, CancellationToken ct = default)
    {
        if (_connection is not { State: HubConnectionState.Connected }) return;
        try
        {
            await _connection.InvokeAsync("PushStatus", status, activeWindow, ct);
        }
        catch
        {
            // Best-effort -- a missed push just means the admin dashboard
            // relies on the next PHP poll for this one update.
        }
    }

    public async ValueTask DisposeAsync()
    {
        if (_connection is not null) await _connection.DisposeAsync();
    }
}
