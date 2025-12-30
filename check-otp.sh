#!/bin/bash
echo '📱 Monitoring OTP codes in real-time...'
echo 'Press Ctrl+C to stop'
echo ''
tail -f storage/logs/laravel.log | grep --line-buffered 'OTP'

