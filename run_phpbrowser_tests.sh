#!/bin/bash

# Load environment variables from .env file
if [ -f .env ]; then
    set -a; source .env; set +a
else
    echo "Error: .env file not found"
    exit 1
fi

# Run the PhpBrowser suite (HTTP-only — no Selenium needed)
vendor/bin/codecept run ProjectManagementPhpbrowser "$@"
