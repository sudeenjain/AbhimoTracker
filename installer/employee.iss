; Builds AbhimoTracker-Employee-Setup.exe
;
; .NET has no built-in electron-builder equivalent. Inno Setup (free,
; https://jrsoftware.org/isinfo.php) is the closest widely-used match: a
; single script produces a single unsigned NSIS-style installer .exe with
; Start Menu + optional desktop shortcut + optional run-on-startup, same as
; electron-builder's NSIS target. Code signing/auto-update are out of scope
; here too, per the master prompt.
;
; Build order:
;   1. dotnet publish ..\src\AbhimoTracker.Employee\AbhimoTracker.Employee.csproj ^
;        -c Release -r win-x64 --self-contained true ^
;        -p:PublishSingleFile=false -o publish\Employee
;   2. iscc installer\employee.iss

#define MyAppName "Abhimo Tracker"
#define MyAppVersion "1.0.0"
#define MyAppPublisher "Abhimo Technologies"
#define MyAppExeName "AbhimoTracker.Employee.exe"
#define PublishDir "..\publish\Employee"

[Setup]
AppId={{6C2C9C2A-6E1E-4B2E-9E7A-ABHIMOEMP001}
AppName={#MyAppName}
AppVersion={#MyAppVersion}
AppPublisher={#MyAppPublisher}
DefaultDirName={autopf}\AbhimoTracker\Employee
DefaultGroupName=Abhimo Tracker
DisableProgramGroupPage=yes
OutputDir=..\dist
OutputBaseFilename=AbhimoTracker-Employee-Setup
Compression=lzma
SolidCompression=yes
ArchitecturesInstallIn64BitMode=x64
; Unsigned local build for now -- see master prompt's "out of scope" section.
SignTool=

[Languages]
Name: "english"; MessagesFile: "compiler:Default.isl"

[Tasks]
Name: "desktopicon"; Description: "Create a &desktop shortcut"; GroupDescription: "Additional shortcuts:"; Flags: unchecked
Name: "startupicon"; Description: "Start Abhimo Tracker automatically when Windows starts"; GroupDescription: "Additional shortcuts:"

[Files]
Source: "{#PublishDir}\*"; DestDir: "{app}"; Flags: ignoreversion recursesubdirs createallsubdirs

[Icons]
Name: "{group}\Abhimo Tracker"; Filename: "{app}\{#MyAppExeName}"
Name: "{group}\Uninstall Abhimo Tracker"; Filename: "{uninstallexe}"
Name: "{autodesktop}\Abhimo Tracker"; Filename: "{app}\{#MyAppExeName}"; Tasks: desktopicon
Name: "{userstartup}\Abhimo Tracker"; Filename: "{app}\{#MyAppExeName}"; Tasks: startupicon

[Run]
Filename: "{app}\{#MyAppExeName}"; Description: "Launch Abhimo Tracker now"; Flags: nowait postinstall skipifsilent
