#!/bin/bash
echo '=== LATEST OTP CODES FROM LOGS ==='
tail -20 storage/logs/laravel.log | grep 'OTP:' | tail -5
echo ''
echo '=== CURRENT DEV OTP CODE ==='
grep OTP_DEV_CODE .env.local
echo ''
echo 'Note: In development mode, all OTP requests return the dev code above'
