#!/bin/bash

# Load environment variables from .env file
if [ -f .env ]; then
    export $(cat .env | xargs)
else
    echo "Error: .env file not found"
    echo "Create .env with ADMIN_USER, ADMIN_PASS, PRO_USER, PRO_PASS, FREE_USER, FREE_PASS"
    exit 1
fi

# Check if Selenium is running
PROCESS_INFO=$(ps aux | grep '[s]elenium-server')
if [ -z "$PROCESS_INFO" ]; then
    echo "Selenium server is not running."
    echo "Start it with:"
    echo "  xvfb-run java -Dwebdriver.chrome.driver=/usr/bin/chromedriver -jar /usr/local/bin/selenium-server-*.jar standalone"
    exit 1
fi

# Run tests
vendor/bin/codecept run Webdriver "$@"
