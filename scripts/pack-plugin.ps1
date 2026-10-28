# ==============================================================================
# Script đóng gói plugin WordPress Ticket Pro VN trên Windows (PowerShell)
# Cách chạy: Mở PowerShell, cd vào thư mục dự án và gõ:
#            powershell -ExecutionPolicy Bypass -File .\scripts\pack-plugin.ps1
# ==============================================================================

$ErrorActionPreference = "Stop"

$RootDir = Split-Path -Parent $PSScriptRoot
$PluginDir = Join-Path $RootDir "ticket-pro-vn"
$DistDir = Join-Path $RootDir "dist-wp-org"
$ZipPath = Join-Path $DistDir "ticket-pro-vn.zip"

Write-Host "=== 1. KIEM TRA CAC FILE COT LOI CUA PLUGIN ===" -ForegroundColor Cyan
if (-not (Test-Path (Join-Path $PluginDir "readme.txt"))) {
    Write-Host "LOI: Khong tim thay file readme.txt trong thu muc ticket-pro-vn!" -ForegroundColor Red
    exit 1
}
if (-not (Test-Path (Join-Path $PluginDir "ticket-pro-vn.php"))) {
    Write-Host "LOI: Khong tim thay file ticket-pro-vn.php trong thu muc ticket-pro-vn!" -ForegroundColor Red
    exit 1
}
Write-Host "-> Da tim thay day du file readme.txt va ticket-pro-vn.php." -ForegroundColor Green

# Kiem tra cu phap PHP neu may co cai san PHP
if (Get-Command php -ErrorAction SilentlyContinue) {
    Write-Host "=== 2. KIEM TRA CU PHAP PHP ===" -ForegroundColor Cyan
    Get-ChildItem -Path $PluginDir -Filter *.php -Recurse | ForEach-Object {
        $result = php -l $_.FullName 2>&1
        if ($LASTEXITCODE -ne 0) {
            Write-Host "LOI cu phap PHP tai: $($_.FullName)" -ForegroundColor Red
            exit 1
        }
    }
    Write-Host "-> Tat ca cac file PHP deu hop le!" -ForegroundColor Green
} else {
    Write-Host "-> (Bo qua kiem tra PHP vi may Windows chua them php vao PATH)" -ForegroundColor Yellow
}

Write-Host "=== 3. DON DEP FILE RAC HE THONG ===" -ForegroundColor Cyan
Get-ChildItem -Path $PluginDir -Include ".DS_Store", "Thumbs.db", "*~" -Recurse -Force | Remove-Item -Force -ErrorAction SilentlyContinue

Write-Host "=== 4. DONG GOI FILE ZIP CHO WORDPRESS.ORG ===" -ForegroundColor Cyan
if (-not (Test-Path $DistDir)) {
    New-Item -ItemType Directory -Path $DistDir -Force | Out-Null
}

if (Test-Path $ZipPath) {
    Remove-Item $ZipPath -Force
}

# Su dung lenh nen cua PowerShell
Compress-Archive -Path $PluginDir -DestinationPath $ZipPath -Force

$ZipSize = (Get-Item $ZipPath).Length / 1KB
Write-Host ""
Write-Host "==============================================================================" -ForegroundColor Green
Write-Host "DONG GOI THANH CONG!" -ForegroundColor Green
Write-Host "File nop WordPress.org: $ZipPath" -ForegroundColor White
Write-Host "Dung luong: $([math]::Round($ZipSize, 2)) KB" -ForegroundColor White
Write-Host "Ban co the tai file nay len: https://wordpress.org/plugins/developers/add/" -ForegroundColor Yellow
Write-Host "==============================================================================" -ForegroundColor Green
