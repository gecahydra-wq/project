@echo off
cd /d "%~dp0frontend"
npm.cmd run dev -- --host localhost --port 5173
