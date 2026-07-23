using System.IO;
using System.Text.Json;
using System.Windows;

namespace AbhimoTracker.Admin;

public sealed class AdminConfig
{
    public string ApiBase { get; init; } = "http://localhost:8080";
    public string RelayHubUrl { get; init; } = "http://localhost:5080/hubs/live-status";
}

public partial class App : Application
{
    public static AdminConfig Config { get; private set; } = new();

    protected override void OnStartup(StartupEventArgs e)
    {
        base.OnStartup(e);
        try
        {
            var path = Path.Combine(AppContext.BaseDirectory, "appsettings.json");
            if (File.Exists(path))
            {
                var loaded = JsonSerializer.Deserialize<AdminConfig>(File.ReadAllText(path),
                    new JsonSerializerOptions { PropertyNameCaseInsensitive = true });
                if (loaded is not null) Config = loaded;
            }
        }
        catch
        {
            // Fall back to AdminConfig's built-in defaults.
        }
    }
}
