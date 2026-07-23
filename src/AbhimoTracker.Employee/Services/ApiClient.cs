using System.Net.Http;
using System.Net.Http.Headers;
using System.Net.Http.Json;
using System.Text;
using System.Text.Json;
using AbhimoTracker.Employee.Models;

namespace AbhimoTracker.Employee.Services;

public sealed class LoginResult
{
    public bool Ok { get; init; }
    public string? Error { get; init; }
    public string? Token { get; init; }
}

/// <summary>
/// Talks to the same PHP endpoints desktop-tray/main.js already calls.
/// Caches the JWT until ~15s before its decoded `exp`, then re-authenticates
/// with the stored credentials -- identical pattern to getAuthToken() in
/// main.js.
/// </summary>
public sealed class ApiClient
{
    private readonly AppConfig _config;
    private readonly HttpClient _http;
    private (string Token, DateTimeOffset ExpiresAt)? _cachedToken;

    public ApiClient(AppConfig config)
    {
        _config = config;
        _http = new HttpClient { Timeout = TimeSpan.FromSeconds(20) };
    }

    public async Task<LoginResult> LoginAsync(string apiBase, string username, string password, CancellationToken ct = default)
    {
        try
        {
            using var res = await _http.PostAsJsonAsync($"{apiBase}{_config.Endpoints.Login}",
                new { username, password }, ct);
            var body = await res.Content.ReadFromJsonAsync<JsonElement>(cancellationToken: ct);
            if (!res.IsSuccessStatusCode)
            {
                var error = body.TryGetProperty("error", out var e) ? e.GetString() : "Invalid credentials.";
                return new LoginResult { Ok = false, Error = error };
            }
            var token = body.GetProperty("token").GetString();
            return new LoginResult { Ok = true, Token = token };
        }
        catch (Exception)
        {
            return new LoginResult { Ok = false, Error = "Could not reach the server." };
        }
    }

    /// <summary>Bearer token for API calls, re-authenticating with stored credentials when needed.</summary>
    public async Task<string?> GetAuthTokenAsync(StoredCredentials creds, CancellationToken ct = default)
    {
        if (_cachedToken is { } cached && DateTimeOffset.UtcNow < cached.ExpiresAt - TimeSpan.FromSeconds(15))
        {
            return cached.Token;
        }

        var result = await LoginAsync(creds.ApiBase, creds.Username, creds.Password, ct);
        if (!result.Ok || result.Token is null) return null;

        var expiresAt = DecodeJwtExpiry(result.Token) ?? DateTimeOffset.UtcNow.AddMinutes(5);
        _cachedToken = (result.Token, expiresAt);
        return result.Token;
    }

    public void InvalidateCachedToken() => _cachedToken = null;

    public async Task<(bool Ok, DateTime? SignInTime, DateTime? SignOutTime, int Status)> GetMonitoringStatusAsync(
        StoredCredentials creds, CancellationToken ct = default)
    {
        var token = await GetAuthTokenAsync(creds, ct);
        if (token is null) return (false, null, null, 0);

        try
        {
            using var req = new HttpRequestMessage(HttpMethod.Get, $"{creds.ApiBase}{_config.Endpoints.MonitoringStatus}");
            req.Headers.Authorization = new AuthenticationHeaderValue("Bearer", token);
            using var res = await _http.SendAsync(req, ct);
            if (res.StatusCode == System.Net.HttpStatusCode.Forbidden)
            {
                return (false, null, null, 403); // consent/password gate not cleared -- not an error state
            }
            if (!res.IsSuccessStatusCode) return (false, null, null, (int)res.StatusCode);

            var body = await res.Content.ReadFromJsonAsync<JsonElement>(cancellationToken: ct);
            DateTime? signIn = body.TryGetProperty("sign_in_time", out var si) && si.ValueKind == JsonValueKind.String
                ? DateTime.Parse(si.GetString()!) : null;
            DateTime? signOut = body.TryGetProperty("sign_out_time", out var so) && so.ValueKind == JsonValueKind.String
                ? DateTime.Parse(so.GetString()!) : null;
            return (true, signIn, signOut, (int)res.StatusCode);
        }
        catch
        {
            return (false, null, null, 0);
        }
    }

    public async Task<(bool Ok, string? Error)> SignInAsync(StoredCredentials creds, CancellationToken ct = default)
        => await PostAuthedAsync(creds, _config.Endpoints.SignIn, ct);

    public async Task<(bool Ok, string? Error)> SignOutAsync(StoredCredentials creds, CancellationToken ct = default)
        => await PostAuthedAsync(creds, _config.Endpoints.SignOut, ct);

    public async Task<bool> WithdrawConsentAsync(StoredCredentials creds, CancellationToken ct = default)
    {
        var (ok, _) = await PostAuthedAsync(creds, _config.Endpoints.ConsentWithdraw, ct);
        return ok;
    }

    private async Task<(bool Ok, string? Error)> PostAuthedAsync(StoredCredentials creds, string path, CancellationToken ct)
    {
        var token = await GetAuthTokenAsync(creds, ct);
        if (token is null) return (false, "Not signed in.");
        try
        {
            using var req = new HttpRequestMessage(HttpMethod.Post, $"{creds.ApiBase}{path}");
            req.Headers.Authorization = new AuthenticationHeaderValue("Bearer", token);
            using var res = await _http.SendAsync(req, ct);
            if (res.IsSuccessStatusCode) return (true, null);
            var body = await res.Content.ReadFromJsonAsync<JsonElement>(cancellationToken: ct);
            var error = body.TryGetProperty("error", out var e) ? e.GetString() : $"Request failed ({(int)res.StatusCode}).";
            return (false, error);
        }
        catch
        {
            return (false, "Could not reach the server.");
        }
    }

    /// <summary>
    /// Sends one batch to /api/activity/ingest. Timestamps are formatted as
    /// local wall-clock 'Y-m-d H:i:s', matching ActivityController::ingest's
    /// primary parse format -- see formatServerTimestamp's docblock in the
    /// original main.js for why this assumes client/server clocks are close.
    /// </summary>
    public async Task<bool> SendActivityBatchAsync(StoredCredentials creds, IReadOnlyList<ActivityEntry> items, CancellationToken ct = default)
    {
        var token = await GetAuthTokenAsync(creds, ct);
        if (token is null) return false;

        var payload = new
        {
            entries = items.Select(i => new
            {
                timestamp = i.Timestamp.ToString("yyyy-MM-dd HH:mm:ss"),
                active_window = i.ActiveWindow,
                is_idle = i.IsIdle,
                duration_seconds = i.DurationSeconds,
                client_batch_id = i.ClientBatchId,
            }),
        };

        try
        {
            using var req = new HttpRequestMessage(HttpMethod.Post, $"{creds.ApiBase}{_config.Endpoints.ActivityIngest}");
            req.Headers.Authorization = new AuthenticationHeaderValue("Bearer", token);
            req.Content = new StringContent(JsonSerializer.Serialize(payload), Encoding.UTF8, "application/json");
            using var res = await _http.SendAsync(req, ct);
            return res.IsSuccessStatusCode;
        }
        catch
        {
            return false;
        }
    }

    /// <summary>Exchanges a one-time pairing token (from the onboarding link) for real credentials.</summary>
    public async Task<(bool Ok, string? Username, string? Password, string? Error)> ExchangePairingTokenAsync(
        string apiBase, string token, CancellationToken ct = default)
    {
        var path = _config.Endpoints.TrayPairExchangeTemplate.Replace("{token}", Uri.EscapeDataString(token));
        try
        {
            using var res = await _http.PostAsync($"{apiBase}{path}", null, ct);
            var body = await res.Content.ReadFromJsonAsync<JsonElement>(cancellationToken: ct);
            if (!res.IsSuccessStatusCode)
            {
                var error = body.TryGetProperty("error", out var e) ? e.GetString() : "Pairing failed.";
                return (false, null, null, error);
            }
            return (true, body.GetProperty("username").GetString(), body.GetProperty("password").GetString(), null);
        }
        catch
        {
            return (false, null, null, "Could not reach the server to complete pairing.");
        }
    }

    /// <summary>Decodes a JWT's `exp` claim without validating the signature -- we only need it
    /// for local cache-expiry bookkeeping; the server is the actual authority on validity.</summary>
    private static DateTimeOffset? DecodeJwtExpiry(string jwt)
    {
        try
        {
            var parts = jwt.Split('.');
            if (parts.Length < 2) return null;
            var payloadJson = Base64UrlDecode(parts[1]);
            using var doc = JsonDocument.Parse(payloadJson);
            if (doc.RootElement.TryGetProperty("exp", out var expEl) && expEl.TryGetInt64(out var expUnix))
            {
                return DateTimeOffset.FromUnixTimeSeconds(expUnix);
            }
            return null;
        }
        catch
        {
            return null;
        }
    }

    private static string Base64UrlDecode(string input)
    {
        var s = input.Replace('-', '+').Replace('_', '/');
        switch (s.Length % 4)
        {
            case 2: s += "=="; break;
            case 3: s += "="; break;
        }
        return Encoding.UTF8.GetString(Convert.FromBase64String(s));
    }
}
