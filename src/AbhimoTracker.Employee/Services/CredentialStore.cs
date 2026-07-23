using System.IO;
using System.Security.Cryptography;
using System.Text;
using System.Text.Json;
using AbhimoTracker.Employee.Models;

namespace AbhimoTracker.Employee.Services;

/// <summary>
/// Local encrypted credential store, same job as desktop-tray/credential-store.js
/// but backed by Windows DPAPI (ProtectedData) instead of a self-managed
/// AES-256-GCM file + key. DPAPI ties the ciphertext to the current Windows
/// user account -- no key file sits next to the data at all, and the OS
/// handles key management, which is a strictly stronger guarantee than the
/// Electron app's "AES key stored as a sibling file" approach.
/// </summary>
public sealed class CredentialStore
{
    private readonly string _credsPath;

    // Ties the ciphertext to this specific install of the app in addition to
    // the Windows user account -- an unrelated app running as the same user
    // can't casually call ProtectedData.Unprotect on this blob without also
    // knowing this entropy value.
    private static readonly byte[] Entropy = Encoding.UTF8.GetBytes("AbhimoTracker.Employee.v1");

    public CredentialStore(string userDataDir)
    {
        Directory.CreateDirectory(userDataDir);
        _credsPath = Path.Combine(userDataDir, "credentials.dpapi");
    }

    public void Save(StoredCredentials creds)
    {
        var plaintext = JsonSerializer.SerializeToUtf8Bytes(creds);
        var encrypted = ProtectedData.Protect(plaintext, Entropy, DataProtectionScope.CurrentUser);
        File.WriteAllBytes(_credsPath, encrypted);
    }

    public StoredCredentials? Load()
    {
        if (!File.Exists(_credsPath)) return null;
        try
        {
            var encrypted = File.ReadAllBytes(_credsPath);
            var plaintext = ProtectedData.Unprotect(encrypted, Entropy, DataProtectionScope.CurrentUser);
            return JsonSerializer.Deserialize<StoredCredentials>(plaintext);
        }
        catch
        {
            // Corrupt, tampered, or from a different Windows user account --
            // treat as unpaired rather than crashing, same posture as the
            // Electron version's catch block.
            return null;
        }
    }

    public void Clear()
    {
        if (File.Exists(_credsPath)) File.Delete(_credsPath);
    }
}
