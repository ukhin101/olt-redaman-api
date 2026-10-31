@echo off
chcp 65001 >nul
title Pengujian Koneksi & Validasi Redaman OLT (HSGQ & HIOSO)
color 0A

cd /d "%~dp0"

set "PHP_BIN=C:\xampp\php\php.exe"
if not exist "%PHP_BIN%" set "PHP_BIN=php"

"%PHP_BIN%" cli\test-olt.php all 2>nul

echo.
pause

