#!/bin/bash
set -euo pipefail
cd /var/www/moodle/public/local/wamakademi
sudo cp -a calendar.php calendar.php.bak-faz2
sudo cp -a js/calendar.php.faz2 calendar.php
sudo chown root:root calendar.php
sudo chmod 644 calendar.php
ls -la calendar.php calendar.php.bak-faz2
echo "OK: calendar.php updated"
