# ==============================================================================
# Script tự động đẩy code lên GitHub trên Windows (PowerShell)
# ==============================================================================

$ErrorActionPreference = "Stop"

$RootDir = Split-Path -Parent $PSScriptRoot
Set-Location $RootDir

Write-Host "=== 1. KIEM TRA GIT TREN WINDOWS ===" -ForegroundColor Cyan
if (-not (Get-Command git -ErrorAction SilentlyContinue)) {
    Write-Host "LOI: May chua cai Git!" -ForegroundColor Red
    Write-Host "Chay lenh sau trong PowerShell de cai Git nhanh:" -ForegroundColor Yellow
    Write-Host "    winget install Git.Git" -ForegroundColor Green
    exit 1
}

Write-Host "=== 2. CAU HINH THONG TIN TAC GIA ===" -ForegroundColor Cyan
git config --global user.name "Phan Van Tu"
git config --global user.email "phanvantu1000@gmail.com"
Write-Host "-> Da cau hinh: Phan Van Tu <phanvantu1000@gmail.com>" -ForegroundColor Green

Write-Host "=== 3. KHOI TAO VA DONG GOI COMMIT ===" -ForegroundColor Cyan
if (-not (Test-Path (Join-Path $RootDir ".git"))) {
    git init
}

git add .
$status = git status --porcelain
if ($status) {
    git commit -m "Initial commit: Ticket Pro VN WordPress plugin and License server"
} else {
    Write-Host "-> Khong co thay doi moi can commit." -ForegroundColor Yellow
}

git branch -M main

Write-Host ""
Write-Host "=== 4. LIEN KET GITHUB REPOSITORY ===" -ForegroundColor Cyan
$repoUrl = Read-Host "Dan link GitHub Repo cua ban vao day (vi du: https://github.com/username/ticket-pro-vn.git)"

if ([string]::IsNullOrWhiteSpace($repoUrl)) {
    Write-Host "Link khong duoc de trong!" -ForegroundColor Red
    exit 1
}

# Xoa remote cu neu da ton tai
git remote remove origin 2>$null
git remote add origin $repoUrl.Trim()

Write-Host "=== 5. DANG DAY CODE LEN GITHUB... ===" -ForegroundColor Cyan
Write-Host "(Neu trinh duyet bat len hop thoai dang nhap, ban hay bam 'Sign in with your browser' de xac thuc)" -ForegroundColor Yellow
git push -u origin main

Write-Host ""
Write-Host "====================================================================" -ForegroundColor Green
Write-Host "THANH CONG! Code da duoc day len GitHub!" -ForegroundColor Green
Write-Host "====================================================================" -ForegroundColor Green
