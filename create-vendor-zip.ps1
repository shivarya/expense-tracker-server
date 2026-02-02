#!/usr/bin/env pwsh
# Script to create vendor.zip for upload to production server

Write-Host "`n📦 Creating vendor.zip for production deployment`n" -ForegroundColor Cyan

$vendorPath = Join-Path $PSScriptRoot "vendor"
$zipPath = Join-Path $PSScriptRoot "vendor.zip"

# Check if vendor folder exists
if (-not (Test-Path $vendorPath)) {
    Write-Host "❌ Error: vendor folder not found" -ForegroundColor Red
    Write-Host "   Run 'composer install' first to generate vendor folder`n" -ForegroundColor Yellow
    exit 1
}

# Remove existing zip if present
if (Test-Path $zipPath) {
    Write-Host "🗑️  Removing existing vendor.zip..." -ForegroundColor Yellow
    Remove-Item $zipPath -Force
}

# Create zip archive
Write-Host "📦 Compressing vendor folder..." -ForegroundColor Green
try {
    Compress-Archive -Path $vendorPath -DestinationPath $zipPath -CompressionLevel Optimal
    
    $zipSize = (Get-Item $zipPath).Length / 1MB
    Write-Host "✅ Created vendor.zip successfully!" -ForegroundColor Green
    Write-Host "   Size: $([math]::Round($zipSize, 2)) MB" -ForegroundColor Cyan
    Write-Host "   Location: $zipPath`n" -ForegroundColor Cyan
    
    Write-Host "📤 Next steps:" -ForegroundColor Yellow
    Write-Host "   1. Upload vendor.zip to your server via cPanel File Manager" -ForegroundColor White
    Write-Host "   2. In cPanel File Manager, extract vendor.zip" -ForegroundColor White
    Write-Host "   3. Delete vendor.zip after extraction" -ForegroundColor White
    Write-Host "   4. Test the API: https://shivarya.dev/expense_tracker/health`n" -ForegroundColor White
    
} catch {
    Write-Host "❌ Error creating zip: $_" -ForegroundColor Red
    exit 1
}
