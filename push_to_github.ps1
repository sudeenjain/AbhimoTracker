# push_to_github.ps1
# Automates setting up git and pushing to GitHub safely

$ErrorActionPreference = "Continue"

Write-Host "===================================================" -ForegroundColor Cyan
Write-Host "Configuring Git and Pushing to GitHub..." -ForegroundColor Cyan
Write-Host "===================================================" -ForegroundColor Cyan

# 1. Check if git is initialized
if (!(Test-Path ".git")) {
    Write-Host "Initializing new Git repository..." -ForegroundColor Yellow
    git init
    git branch -M main
}

# 2. Add or update remote origin
$remote = git remote get-url origin 2>$null
if ([string]::IsNullOrEmpty($remote)) {
    Write-Host "Adding remote origin..." -ForegroundColor Yellow
    git remote add origin https://github.com/sudeenjain/AbhimoTracker.git
} else {
    Write-Host "Setting remote origin..." -ForegroundColor Yellow
    git remote set-url origin https://github.com/sudeenjain/AbhimoTracker.git
}

# 3. Ensure strict .gitignore is present
Write-Host "Enforcing strict .gitignore..." -ForegroundColor Yellow
@'
# Environment & Secret files (DO NOT COMMIT)
.env
*.env
backend/.env
credentials.dpapi
crash.log

# Build Outputs, Binaries, Node Modules, Vendor, and Packages
node_modules/
[Nn]ode_modules/
vendor/
[Vv]endor/
backend/vendor/
[Bb]in/
[Oo]bj/
[Pp]ublish/
/publish/
/dist/
/desktop-tray/dist/
[Dd]ist/
/src/*/bin/
/src/*/obj/
/src/AbhimoTracker.Installer/Resources/app.zip
/src/AbhimoTracker.AdminInstaller/Resources/app.zip

# Individual Binary / Executables & Large Installers (>100MB)
*.exe
*.msi
*.dll
*.pdb
/frontend/downloads/*.exe
/frontend/downloads/AbhimoTracker-*.zip

# IDE & Editor Cache
.vs/
.vscode/
.idea/
*.user
*.suo
*.lock
*.userosscache
*.sln.docstates

# System & OS Files
[Tt]emp/
[Tt]mp/
Thumbs.db
ehthumbs.db
Desktop.ini
$RECYCLE.BIN/
'@ | Out-File -FilePath ".gitignore" -Encoding utf8

# 4. Stage and commit only clean source files
Write-Host "Staging source files..." -ForegroundColor Yellow
git add .gitignore
git add .

# Only commit if changes exist
$status = git status --porcelain
if ($status) {
    Write-Host "Committing changes..." -ForegroundColor Yellow
    git commit -m "Update AbhimoTracker clean source code"
} else {
    Write-Host "Working directory is clean, no new commit needed." -ForegroundColor Green
}

# 5. Push main branch safely
Write-Host "Pushing main branch to GitHub..." -ForegroundColor Yellow
git push -u origin main

# 6. Publish frontend folder to gh-pages branch
Write-Host "Publishing frontend folder to GitHub Pages (gh-pages branch)..." -ForegroundColor Yellow
git branch -D gh-pages 2>$null
git subtree push --prefix frontend origin gh-pages

Write-Host "===================================================" -ForegroundColor Green
Write-Host "Pushed successfully!" -ForegroundColor Green
Write-Host "Main project repo: https://github.com/sudeenjain/AbhimoTracker" -ForegroundColor Green
Write-Host "Live Register Link: https://sudeenjain.github.io/AbhimoTracker/register.html" -ForegroundColor Green
Write-Host "Live Onboarding Link: https://sudeenjain.github.io/AbhimoTracker/abhimo-tracker.html" -ForegroundColor Green
Write-Host "===================================================" -ForegroundColor Green
