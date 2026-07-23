# push_to_github.ps1
# Automates setting up git and pushing to GitHub (including gh-pages subdirectory deployment)

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

# 2. Add remote origin if not present
$remote = git remote get-url origin 2>$null
if ([string]::IsNullOrEmpty($remote)) {
    Write-Host "Adding remote origin..." -ForegroundColor Yellow
    git remote add origin https://github.com/sudeenjain/AbhimoTracker.git
} else {
    Write-Host "Setting remote origin..." -ForegroundColor Yellow
    git remote set-url origin https://github.com/sudeenjain/AbhimoTracker.git
}

# 3. Create .gitignore if not present
if (!(Test-Path ".gitignore")) {
    Write-Host "Creating .gitignore file..." -ForegroundColor Yellow
    @'
/publish/
/src/*/bin/
/src/*/obj/
/src/AbhimoTracker.Installer/Resources/app.zip
/src/AbhimoTracker.AdminInstaller/Resources/app.zip
*.user
*.lock
.env
credentials.dpapi
crash.log
'@ | Out-File -FilePath ".gitignore" -Encoding utf8
}

# 4. Commit files
Write-Host "Staging and committing files..." -ForegroundColor Yellow
git add .
git commit -m "Initialize Abhimo Tracker complete project with self-extracting installers and GitHub Pages links"

# 5. Push main branch
Write-Host "Pushing main branch to GitHub..." -ForegroundColor Yellow
git push -u origin main --force

# 6. Push frontend folder to gh-pages branch
Write-Host "Publishing frontend folder to GitHub Pages (gh-pages branch)..." -ForegroundColor Yellow
# Delete local gh-pages branch if it exists to avoid subtree conflict
git branch -D gh-pages 2>$null
git subtree push --prefix frontend origin gh-pages --force

Write-Host "===================================================" -ForegroundColor Green
Write-Host "Pushed successfully!" -ForegroundColor Green
Write-Host "Main project repo: https://github.com/sudeenjain/AbhimoTracker" -ForegroundColor Green
Write-Host "Live Register Link: https://sudeenjain.github.io/AbhimoTracker/register.html" -ForegroundColor Green
Write-Host "Live Onboarding Link: https://sudeenjain.github.io/AbhimoTracker/abhimo-tracker.html" -ForegroundColor Green
Write-Host "===================================================" -ForegroundColor Green
