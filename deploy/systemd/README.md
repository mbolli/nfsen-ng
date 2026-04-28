# Systemd Service Files

Pre-built service files for running nfsen-ng and its NetFlow capture stack.

| File | Purpose |
|---|---|
| `nfsen-ng-docker.service` | Run nfsen-ng via Docker Compose (recommended) |
| `nfsen-ng.service` | Run nfsen-ng directly on bare metal |
| `nfcapd.service` | NetFlow capture daemon |
| `softflowd.service` | Software NetFlow exporter (testing / dev) |

---

## Option A: Docker deployment

### 1. Edit the install path if needed

The unit file assumes the repo lives at `/var/www/nfsen-ng`. Adjust if yours differs:

```bash
sed -i 's|/var/www/nfsen-ng|/your/path|g' deploy/systemd/nfsen-ng-docker.service
```

### 2. Install and enable

```bash
sudo cp deploy/systemd/nfsen-ng-docker.service /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now nfsen-ng-docker.service
```

### 3. Check status

```bash
sudo systemctl status nfsen-ng-docker
docker compose -f deploy/docker-compose.yml logs -f nfsen
```

---

## Option B: Bare-metal deployment

### 1. Edit the install path if needed

The unit file assumes the repo lives at `/var/www/nfsen-ng`. Adjust if yours differs:

```bash
sed -i 's|/var/www/nfsen-ng|/your/path|g' deploy/systemd/nfsen-ng.service
```

### 2. Install and enable

```bash
sudo cp deploy/systemd/nfsen-ng.service /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now nfsen-ng.service
```

### 3. Check status

```bash
sudo systemctl status nfsen-ng
journalctl -t nfsen-ng -f
```

---

## NetFlow capture (nfcapd + softflowd)

Use these if you need nfsen-ng to capture flows on the same host.

### 1. Find your network interface

```bash
ip link show
```

### 2. Edit softflowd.service

Replace `eth0` with your actual interface name:

```bash
sed -i 's/eth0/YOUR_INTERFACE/g' deploy/systemd/softflowd.service
```

### 3. Install and enable

```bash
sudo cp deploy/systemd/nfcapd.service /etc/systemd/system/
sudo cp deploy/systemd/softflowd.service /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now nfcapd.service softflowd.service
```

### 4. Verify capture

```bash
# After ~5 minutes nfcapd rotates a file:
ls -lh /var/nfdump/profiles-data/live/all/
journalctl -u nfcapd -f
```

## Stopping the Services

```bash
# Stop services
sudo systemctl stop softflowd
sudo systemctl stop nfcapd

# Disable from starting on boot
sudo systemctl disable softflowd
sudo systemctl disable nfcapd
```
