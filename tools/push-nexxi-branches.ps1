param(
    [string]$RepoPath = (Split-Path -Parent $PSScriptRoot)
)

$ErrorActionPreference = "Stop"

$branch = (git -C $RepoPath rev-parse --abbrev-ref HEAD).Trim()
if ($branch -ne "main") {
    throw "Hay dung o nhanh 'main' truoc khi chay push-nexxi-branches.ps1 (nhanh hien tai: $branch)."
}

Write-Host "[1/4] Fetching origin (main, theme, plugin)..." -ForegroundColor Cyan
git -C $RepoPath fetch origin main theme plugin

Write-Host "[2/4] Pushing main -> origin/main..." -ForegroundColor Cyan
git -C $RepoPath push origin main:main

$headSha = (git -C $RepoPath rev-parse HEAD).Trim()
$env:GIT_AUTHOR_NAME     = (git -C $RepoPath log -1 --format="%an" $headSha)
$env:GIT_AUTHOR_EMAIL    = (git -C $RepoPath log -1 --format="%ae" $headSha)
$env:GIT_AUTHOR_DATE     = (git -C $RepoPath log -1 --format="%ad" $headSha)
$env:GIT_COMMITTER_NAME  = (git -C $RepoPath log -1 --format="%cn" $headSha)
$env:GIT_COMMITTER_EMAIL = (git -C $RepoPath log -1 --format="%ce" $headSha)
$env:GIT_COMMITTER_DATE  = (git -C $RepoPath log -1 --format="%cd" $headSha)
$commitMsg               = (git -C $RepoPath log -1 --format="%B"  $headSha)

# Sync theme branch (root = wp-content/themes/nexxi-kids)
$mainThemeTree   = (git -C $RepoPath rev-parse "HEAD:wp-content/themes/nexxi-kids").Trim()
$originThemeTree = (git -C $RepoPath rev-parse "origin/theme^{tree}").Trim()
$originThemeTip  = (git -C $RepoPath rev-parse "origin/theme").Trim()

if ($mainThemeTree -ne $originThemeTree) {
    Write-Host "[3/4] Syncing & pushing subtree wp-content/themes/nexxi-kids -> origin/theme..." -ForegroundColor Yellow
    $newThemeSha = ($commitMsg | git -C $RepoPath commit-tree $mainThemeTree -p $originThemeTip).Trim()
    git -C $RepoPath branch -f theme $newThemeSha
    git -C $RepoPath push origin theme:theme
} else {
    git -C $RepoPath branch -f theme $originThemeTip | Out-Null
    Write-Host "[3/4] Nhanh 'theme' da khop 100% voi HEAD:wp-content/themes/nexxi-kids (khong can tao commit moi)." -ForegroundColor Green
}

# Sync plugin branch (root = wp-content/plugins/nexxi-kids-commerce)
$mainPluginTree   = (git -C $RepoPath rev-parse "HEAD:wp-content/plugins/nexxi-kids-commerce").Trim()
$originPluginTree = (git -C $RepoPath rev-parse "origin/plugin^{tree}").Trim()
$originPluginTip  = (git -C $RepoPath rev-parse "origin/plugin").Trim()

if ($mainPluginTree -ne $originPluginTree) {
    Write-Host "[4/4] Syncing & pushing subtree wp-content/plugins/nexxi-kids-commerce -> origin/plugin..." -ForegroundColor Yellow
    $newPluginSha = ($commitMsg | git -C $RepoPath commit-tree $mainPluginTree -p $originPluginTip).Trim()
    git -C $RepoPath branch -f plugin $newPluginSha
    git -C $RepoPath push origin plugin:plugin
} else {
    git -C $RepoPath branch -f plugin $originPluginTip | Out-Null
    Write-Host "[4/4] Nhanh 'plugin' da khop 100% voi HEAD:wp-content/plugins/nexxi-kids-commerce (khong can tao commit moi)." -ForegroundColor Green
}

Write-Host "`n=== Trang thai dong bo 3 nhanh Git ===" -ForegroundColor Cyan
git -C $RepoPath branch -vv
