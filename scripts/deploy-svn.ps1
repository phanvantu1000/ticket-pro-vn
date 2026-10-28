# ==============================================================================
# Script hỗ trợ đẩy plugin lên WordPress.org SVN Repository trên Windows (PowerShell)
# Cách chạy: Mở PowerShell, cd vào thư mục dự án và gõ:
#            powershell -ExecutionPolicy Bypass -File .\scripts\deploy-svn.ps1
# ==============================================================================

$ErrorActionPreference = "Stop"

$PluginSlug = "ticket-pro-vn"
$SvnUrl = "https://plugins.svn.wordpress.org/$PluginSlug"
$RootDir = Split-Path -Parent $PSScriptRoot
$PluginDir = Join-Path $RootDir "ticket-pro-vn"
$AssetsDir = Join-Path $RootDir "wp-org-assets"
$SvnDir = Join-Path $RootDir ".svn-repo"

Write-Host "=== KIEM TRA CONG CU SVN TREN WINDOWS ===" -ForegroundColor Cyan
if (-not (Get-Command svn -ErrorAction SilentlyContinue)) {
    Write-Host "LOI: Khong tim thay lenh 'svn' tren may Windows." -ForegroundColor Red
    Write-Host ""
    Write-Host "Cach khac phuc nhanh tren Windows:" -ForegroundColor Yellow
    Write-Host "1. Cai dat Subversion CLI qua Winget (chay trong PowerShell):" -ForegroundColor White
    Write-Host "   winget install Apache.Subversion" -ForegroundColor Green
    Write-Host "2. Hoac su dung cong cu giao dien TortoiseSVN (Rat khuyen nghi cho Windows):" -ForegroundColor White
    Write-Host "   https://tortoisesvn.net/downloads.html" -ForegroundColor Green
    exit 1
}

$WpUser = Read-Host "Nhap username tai khoan WordPress.org cua ban"
if ([string]::IsNullOrWhiteSpace($WpUser)) {
    Write-Host "Username khong duoc de trong!" -ForegroundColor Red
    exit 1
}

# Doc phien ban tu file ticket-pro-vn.php
$MainFile = Join-Path $PluginDir "ticket-pro-vn.php"
$VersionLine = Get-Content $MainFile | Select-String "Version:" | Select-Object -First 1
$Version = ($VersionLine.Line -split ":")[1].Trim()
Write-Host "Phien ban phat hien: $Version" -ForegroundColor Green

Write-Host "=== CHECKOUT REPOSITORY TU WORDPRESS.ORG ===" -ForegroundColor Cyan
if (-not (Test-Path (Join-Path $SvnDir ".svn"))) {
    if (Test-Path $SvnDir) { Remove-Item $SvnDir -Recurse -Force }
    svn co $SvnUrl $SvnDir --username $WpUser
} else {
    Write-Host "Dang cap nhat SVN repo..."
    svn up $SvnDir --username $WpUser
}

Write-Host "=== DONG BO CODE VAO TRUNK ===" -ForegroundColor Cyan
$TrunkDir = Join-Path $SvnDir "trunk"
if (-not (Test-Path $TrunkDir)) { New-Item -ItemType Directory -Path $TrunkDir -Force | Out-Null }
robocopy $PluginDir $TrunkDir /MIR /XD ".git" ".svn" /XF ".DS_Store" "Thumbs.db" "*.pem" | Out-Null

Write-Host "=== DONG BO ASSETS (BANNER, ICON, SCREENSHOT) ===" -ForegroundColor Cyan
if (Test-Path $AssetsDir) {
    $SvnAssetsDir = Join-Path $SvnDir "assets"
    if (-not (Test-Path $SvnAssetsDir)) { New-Item -ItemType Directory -Path $SvnAssetsDir -Force | Out-Null }
    robocopy $AssetsDir $SvnAssetsDir /MIR /XD ".git" ".svn" /XF "README.md" ".DS_Store" "Thumbs.db" | Out-Null
}

Write-Host "=== TAO RELEASE TAG $Version ===" -ForegroundColor Cyan
$TagDir = Join-Path (Join-Path $SvnDir "tags") $Version
if (-not (Test-Path $TagDir)) { New-Item -ItemType Directory -Path $TagDir -Force | Out-Null }
robocopy $PluginDir $TagDir /MIR /XD ".git" ".svn" /XF ".DS_Store" "Thumbs.db" "*.pem" | Out-Null

Push-Location $SvnDir
try {
    Write-Host "=== CAP NHAT TRANG THAI SVN ===" -ForegroundColor Cyan
    # Tu dong add cac file moi va xoa cac file bi go
    $statusLines = svn status
    foreach ($line in $statusLines) {
        $flag = $line.Substring(0, 1)
        $path = $line.Substring(8).Trim()
        if ($flag -eq "?") {
            svn add $path
        } elseif ($flag -eq "!") {
            svn rm $path
        }
    }

    Write-Host ""
    Write-Host "=== TRANG THAI SVN SAN SANG COMMIT ===" -ForegroundColor Yellow
    svn status

    Write-Host ""
    $confirm = Read-Host "Ban co muon COMMIT va phat hanh phien ban $Version len WordPress.org? (y/N)"
    if ($confirm -eq "y" -or $confirm -eq "Y") {
        svn ci -m "Release version $Version" --username $WpUser
        Write-Host "====================================================================" -ForegroundColor Green
        Write-Host "XUAT BAN THANH CONG PHIEN BAN $Version!" -ForegroundColor Green
        Write-Host "Plugin se xuat hien tai: https://wordpress.org/plugins/$PluginSlug/" -ForegroundColor White
        Write-Host "====================================================================" -ForegroundColor Green
    } else {
        Write-Host "Da huy bo thao tac commit." -ForegroundColor Yellow
    }
} finally {
    Pop-Location
}
