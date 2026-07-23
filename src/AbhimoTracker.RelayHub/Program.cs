using System.Text;
using AbhimoTracker.RelayHub.Hubs;
using Microsoft.AspNetCore.Authentication.JwtBearer;
using Microsoft.IdentityModel.Tokens;
using Microsoft.AspNetCore.SignalR;

var builder = WebApplication.CreateBuilder(args);

builder.Services.AddSignalR();

var jwtSecret = builder.Configuration["Jwt:Secret"]
    ?? throw new InvalidOperationException("Jwt:Secret is not configured.");

if (jwtSecret == "change-this-to-a-long-random-string" && !builder.Environment.IsDevelopment())
{
    // Same fail-loud posture as backend/src/Support/JwtService.php -- refuse to run
    // outside dev with the placeholder secret rather than silently accepting tokens
    // signed with a value anyone can read out of this repo.
    throw new InvalidOperationException("Jwt:Secret must be set to the real, shared JWT_SECRET outside local dev.");
}

builder.Services
    .AddAuthentication(JwtBearerDefaults.AuthenticationScheme)
    .AddJwtBearer(options =>
    {
        // Keep claim type names exactly as PHP put them ("sub", "username", "role", "tv")
        // instead of .NET's default inbound mapping to long XML-namespace claim URIs.
        options.MapInboundClaims = false;
        options.TokenValidationParameters = new TokenValidationParameters
        {
            ValidateIssuer = false,   // PHP's JwtService never sets iss
            ValidateAudience = false, // ...or aud
            ValidateLifetime = true,
            ValidateIssuerSigningKey = true,
            IssuerSigningKey = new SymmetricSecurityKey(Encoding.UTF8.GetBytes(jwtSecret)),
            ClockSkew = TimeSpan.FromSeconds(30),
        };

        // SignalR sends the JWT via a query string on the websocket handshake
        // (browsers/native clients can't set an Authorization header on that
        // upgrade request) -- lift it into the header the handler expects.
        options.Events = new JwtBearerEvents
        {
            OnMessageReceived = context =>
            {
                var accessToken = context.Request.Query["access_token"];
                var path = context.HttpContext.Request.Path;
                if (!string.IsNullOrEmpty(accessToken) && path.StartsWithSegments("/hubs/live-status"))
                {
                    context.Token = accessToken;
                }
                return Task.CompletedTask;
            },
        };
    });

builder.Services.AddAuthorization();

var allowedOrigins = builder.Configuration.GetSection("Cors:AllowedOrigins").Get<string[]>() ?? [];
builder.Services.AddCors(options =>
{
    options.AddDefaultPolicy(policy =>
    {
        policy.WithOrigins(allowedOrigins)
              .AllowAnyHeader()
              .AllowAnyMethod()
              .AllowCredentials();
    });
});

var app = builder.Build();

app.UseCors();
app.UseAuthentication();
app.UseAuthorization();

// Liveness check -- handy for the Employee/Admin apps to show "relay reachable"
// in their own status UI without needing a real hub connection just to probe.
app.MapGet("/health", () => Results.Ok(new { status = "ok" }));

app.MapHub<LiveStatusHub>("/hubs/live-status");

app.MapPost("/api/relay/new-registration", async (NewRegistrationInfo info, Microsoft.AspNetCore.SignalR.IHubContext<LiveStatusHub> hubContext, IConfiguration config, HttpContext httpContext) =>
{
    var secret = config["Jwt:Secret"];
    if (httpContext.Request.Headers["X-Relay-Secret"] != secret)
    {
        return Results.Unauthorized();
    }

    await hubContext.Clients.Group("admins").SendAsync("NewEmployeeRegistered", info);
    return Results.Ok(new { success = true });
});

app.Run();

public record NewRegistrationInfo(int Id, string Name, string Email, string Phone);
