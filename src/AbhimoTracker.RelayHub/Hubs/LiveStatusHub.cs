using AbhimoTracker.RelayHub.Models;
using Microsoft.AspNetCore.Authorization;
using Microsoft.AspNetCore.SignalR;

namespace AbhimoTracker.RelayHub.Hubs;

/// <summary>
/// Live-status relay only. Every connection carries the same JWT the PHP
/// backend already issued at /api/login -- this hub trusts its "role" and
/// "sub"/"username" claims exactly as JwtAuthMiddleware does, it just
/// verifies the signature locally instead of asking PHP.
///
/// Two kinds of caller:
///   - Employee apps: call PushStatus() whenever their local idle/active
///     state changes (or on a slow heartbeat interval). They never receive
///     broadcasts.
///   - Admin apps: join the "admins" group on connect and receive
///     StatusUpdated pushes. They never call PushStatus().
/// </summary>
[Authorize]
public class LiveStatusHub : Hub
{
    private const string AdminsGroup = "admins";

    public override async Task OnConnectedAsync()
    {
        if (IsAdmin())
        {
            await Groups.AddToGroupAsync(Context.ConnectionId, AdminsGroup);
        }
        await base.OnConnectedAsync();
    }

    /// <summary>
    /// Called by the Employee app. Employee identity comes from the
    /// validated JWT's claims, never from anything the client passes in --
    /// an employee physically cannot spoof another employee's status.
    /// </summary>
    public async Task PushStatus(string status, string? activeWindow)
    {
        var employeeIdClaim = Context.User?.FindFirst("sub")?.Value;
        var usernameClaim = Context.User?.FindFirst("username")?.Value;
        if (employeeIdClaim is null || usernameClaim is null) return;
        if (!int.TryParse(employeeIdClaim, out var employeeId)) return;

        var allowedStatuses = new[] { "active", "idle", "offline", "signedout" };
        if (Array.IndexOf(allowedStatuses, status) < 0) return;

        var update = new StatusUpdate(employeeId, usernameClaim, status, activeWindow, DateTimeOffset.UtcNow);
        await Clients.Group(AdminsGroup).SendAsync("StatusUpdated", update);
    }

    private bool IsAdmin() => Context.User?.FindFirst("role")?.Value == "admin";
}
