@echo off
chcp 65001 >nul
title Push Repository ke GitHub - olt-redaman-api
color 0E

cd /d "%~dp0"

echo ============================================================================
echo            PUSH STANDALONE REPOSITORY KE GITHUB (olt-redaman-api)
echo ============================================================================
echo.

git status -s
echo.

git remote get-url origin >nul 2>nul
if %errorlevel% neq 0 (
    echo Remote "origin" belum diatur.
    set /p REPO_URL="Masukkan URL Repository GitHub (contoh: https://github.com/ukhin101/olt-redaman-api.git): "
    if not "%REPO_URL%"=="" (
        git remote add origin "%REPO_URL%"
    ) else (
        echo URL kosong. Dibatalkan.
        pause
        exit /b 1
    )
)

echo Remote aktif saat ini:
git remote -v
echo.

echo Mengirim branch main ke GitHub...
git branch -M main
git push -u origin main

echo.
pause

