#!/bin/bash
# Quick setup script for VictoriaMetrics datasource

set -e

# Always run from the repository root regardless of where the script is called from
cd "$(cd "$(dirname "$0")" && pwd)/.."

echo "================================"
echo "nfsen-ng VictoriaMetrics Setup"
echo "================================"
echo ""

# Check if Docker is running
if ! docker info > /dev/null 2>&1; then
    echo "Error: Docker is not running"
    exit 1
fi

# Check if docker compose is available
if ! docker compose version > /dev/null 2>&1; then
    echo "Error: docker compose is not available"
    exit 1
fi

# Step 1: Copy settings file
echo "Step 1: Configuring settings..."
if [ ! -f backend/settings/settings.php ]; then
    cp backend/settings/settings.victoriametrics.dist backend/settings/settings.php
    echo "✓ Created settings.php from VictoriaMetrics template"
else
    echo "⚠ settings.php already exists - please manually update 'db' => 'VictoriaMetrics'"
fi

# Step 2: Start VictoriaMetrics
echo ""
echo "Step 2: Starting VictoriaMetrics..."
docker compose -f deploy/docker-compose.victoriametrics.yml up -d victoriametrics

# Wait for VictoriaMetrics to be ready
echo "Waiting for VictoriaMetrics to be ready..."
for i in {1..30}; do
    if curl -s http://localhost:8428/health > /dev/null 2>&1; then
        echo "✓ VictoriaMetrics is ready!"
        break
    fi
    if [ $i -eq 30 ]; then
        echo "Error: VictoriaMetrics failed to start"
        exit 1
    fi
    sleep 1
done

# Step 3: Start nfsen-ng
echo ""
echo "Step 3: Starting nfsen-ng..."
docker compose -f deploy/docker-compose.victoriametrics.yml up -d nfsen-ng

echo ""
echo "================================"
echo "Setup Complete!"
echo "================================"
echo ""
echo "VictoriaMetrics UI:  http://localhost:8428/vmui"
echo "nfsen-ng:            http://localhost:8080"
echo ""
echo "To import data:"
echo "  docker exec nfsen-ng php backend/cli.php -p import"
echo ""
echo "To view logs:"
echo "  docker logs -f victoriametrics"
echo "  docker logs -f nfsen-ng"
echo ""
