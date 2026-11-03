@echo off
title PulseMark ZKTeco Hardware Bridge
color 0a
echo ======================================================
echo    PulseMark - ZKTeco Mini Hardware Bridge
echo ======================================================
echo.
echo Starting hardware bridge on http://127.0.0.1:9000 ...
python zk_bridge.py
pause
