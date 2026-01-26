#!/usr/bin/env pwsh
# Start PHP Development Server for Expense Tracker API

Write-Host "Starting Expense Tracker API Server..." -ForegroundColor Green
Write-Host "Server will be available at: http://localhost:8000" -ForegroundColor Cyan
Write-Host "Press Ctrl+C to stop the server" -ForegroundColor Yellow
Write-Host ""

# Navigate to server directory
$serverDir = Split-Path -Parent $MyInvocation.MyCommand.Path
Set-Location $serverDir

# Start PHP server
php -S 0.0.0.0:8000
