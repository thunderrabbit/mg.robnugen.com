#!/bin/bash
# Deploy current branch to DreamHost via git push + ssh pull
set -e

cd "$(dirname "$0")"
BRANCH=$(git rev-parse --abbrev-ref HEAD)

echo "Pushing $BRANCH to origin..."
git push -u origin "$BRANCH"

echo "Pulling $BRANCH on DreamHost..."
ssh mg "cd mg.robnugen.com; git restore .; git pull origin $BRANCH"

echo "Deployed $BRANCH to DreamHost."
