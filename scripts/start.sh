#!/usr/bin/env bash
#
# nfsen-ng Startup Script with Swoole
#
# This script helps you quickly start nfsen-ng with Swoole async HTTP server
#

set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$SCRIPT_DIR/.."

# Colors
GREEN='\033[0;32m'
BLUE='\033[0;34m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
NC='\033[0m' # No Color

echo -e "${BLUE}"
echo "╔════════════════════════════════════════╗"
echo "║     nfsen-ng with Swoole              ║"
echo "║     High-Performance SSE Streaming    ║"
echo "╚════════════════════════════════════════╝"
echo -e "${NC}"

# Check for Docker Compose
if command -v docker-compose &> /dev/null || command -v docker &> /dev/null; then
    echo -e "${GREEN}✓${NC} Docker found"
    
    echo -e "\n${YELLOW}Starting with Docker Compose...${NC}"
    docker compose -f deploy/docker-compose.yml up -d
    
    echo -e "\n${GREEN}✓${NC} nfsen-ng started!"
    echo -e "\n${BLUE}Access the application:${NC}"
    echo -e "  • Frontend: ${GREEN}http://localhost:8080${NC}"
    echo -e "\n${BLUE}View logs:${NC}"
    echo -e "  docker compose -f deploy/docker-compose.yml logs -f"
    echo -e "\n${BLUE}Stop server:${NC}"
    echo -e "  docker compose -f deploy/docker-compose.yml down"
    
    exit 0
fi

# Check for Swoole extension
if php -m | grep -q swoole; then
    echo -e "${GREEN}✓${NC} Swoole extension found"
    
    echo -e "\n${YELLOW}Starting Swoole HTTP server...${NC}"
    
    php backend/server.php &
    PID=$!
    
    echo -e "\n${GREEN}✓${NC} nfsen-ng started (PID: $PID)!"
    echo -e "\n${BLUE}Access the application:${NC}"
    echo -e "  • Frontend: ${GREEN}http://localhost:8080${NC}"
    echo -e "  • SSE Streams: ${GREEN}http://localhost:8080/stream/*${NC}"
    echo -e "\n${BLUE}Stop server:${NC}"
    echo -e "  kill $PID"
    
    # Save PID
    echo $PID > .swoole.pid
    
    # Wait for Ctrl+C
    trap "kill $PID; rm -f .swoole.pid; echo -e '\n${YELLOW}Server stopped.${NC}'; exit 0" INT TERM
    wait $PID
    
    exit 0
fi

# Neither Docker nor Swoole found
echo -e "${RED}✗${NC} Neither Docker nor Swoole extension found!"
echo -e "\n${YELLOW}Please install one of the following:${NC}"
echo -e "\n${BLUE}Option 1: Docker (Recommended)${NC}"
echo -e "  Visit: https://docs.docker.com/get-docker/"
echo -e "\n${BLUE}Option 2: Swoole Extension${NC}"
echo -e "  pecl install swoole"
echo -e "  echo 'extension=swoole.so' | sudo tee /etc/php/8.4/cli/conf.d/swoole.ini"
echo -e "\n${BLUE}For development/testing only:${NC}"
echo -e "  php -S localhost:8080 -t . index.php"
echo -e "  ${YELLOW}(Note: Built-in server does NOT support SSE streaming)${NC}"

exit 1
