@echo off
chcp 65001 >nul
title Instalasi OLT Redaman API (HSGQ & HIOSO) - XAMPP Windows
color 0B

echo ============================================================================
echo          INSTALASI OLT REDAMAN API (HSGQ ^& HIOSO) - XAMPP WINDOWS
echo ============================================================================
echo.

cd /d "%~dp0"

:: 1. Periksa Keberadaan PHP di XAMPP
set "PHP_BIN=C:\xampp\php\php.exe"
if not exist "%PHP_BIN%" (
    where php >nul 2>nul
    if %errorlevel% equ 0 (
        set "PHP_BIN=php"
    ) else (
        color 0C
        echo [GAGAL] File C:\xampp\php\php.exe tidak ditemukan!
        echo Pastikan XAMPP sudah terpasang di C:\xampp.
        pause
        exit /b 1
    )
)

echo [1/4] Memeriksa lingkungan PHP XAMPP...
"%PHP_BIN%" -v 2>nul | findstr /i "PHP"
echo.

:: 2. Buat folder storage/cache dan storage/logs jika belum ada
echo [2/4] Menyiapkan direktori penyimpanan cache dan log...
if not exist "storage\cache" mkdir "storage\cache"
if not exist "storage\logs" mkdir "storage\logs"
echo       - storage\cache [OK]
echo       - storage\logs  [OK]
echo.

:: 3. Salin .env.example ke .env jika belum ada
echo [3/4] Memeriksa file konfigurasi .env...
if not exist ".env" (
    if exist ".env.example" (
        copy /Y ".env.example" ".env" >nul
        echo       - File .env baru berhasil dibuat dari .env.example!
        echo       - PENTING: Silakan buka file .env dan sesuaikan IP, Username, dan Password OLT Anda.
    ) else (
        echo [PERINGATAN] File .env.example tidak ditemukan.
    )
) else (
    echo       - File .env sudah ada ^(tidak ditimpa agar konfigurasi Anda tetap aman^).
)
echo.

:: 4. Jalankan Self-Test Parser & Validasi
echo [4/4] Menjalankan pengujian modul internal...
"%PHP_BIN%" cli\test-olt.php --self-test 2>nul
echo.

echo ============================================================================
echo                        INSTALASI SELESAI ^& SIAP DIGUNAKAN
echo ============================================================================
echo.
echo Langkah Selanjutnya:
echo 1. Edit file ".env" menggunakan Notepad / VS Code untuk mengisi IP ^& Password OLT.
echo 2. Pastikan modul Apache di XAMPP Control Panel berstatus "Running".
echo 3. Buka Dashboard Pengujian ^& Dokumentasi di Browser:
echo    http://localhost/olt-redaman-api/
echo.
echo Daftar Endpoint REST API:
echo - Status ^& Info API : http://localhost/olt-redaman-api/api/
echo - Data OLT HSGQ     : http://localhost/olt-redaman-api/api/hsgq.php
echo - Data OLT HIOSO    : http://localhost/olt-redaman-api/api/hioso.php
echo - Gabungan 2 OLT    : http://localhost/olt-redaman-api/api/all.php
echo - Tes Koneksi OLT   : http://localhost/olt-redaman-api/api/test.php
echo ============================================================================
echo.
pause

