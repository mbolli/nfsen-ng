# Systemd Service Setup for NetFlow Capture

This directory contains systemd service files for capturing NetFlow data.

## Installation

### 1. Find your network interface

```bash
ip link show
# Look for your main interface (eth0, wlan0, enp0s3, etc.)
```

### 2. Edit softflowd.service

Edit `softflowd.service` and replace `eth0` with your actual interface name:

```bash
# Example: if your interface is enp0s3
sed -i 's/eth0/enp0s3/g' systemd/softflowd.service
```

### 3. Install the services

```bash
# Copy service files to systemd directory
sudo cp systemd/nfcapd.service /etc/systemd/system/
sudo cp systemd/softflowd.service /etc/systemd/system/

# Reload systemd
sudo systemctl daemon-reload

# Enable services to start on boot
sudo systemctl enable nfcapd.service
sudo systemctl enable softflowd.service

# Start the services
sudo systemctl start nfcapd.service
sudo systemctl start softflowd.service
```

### 4. Check status

```bash
# Check nfcapd status
sudo systemctl status nfcapd

# Check softflowd status
sudo systemctl status softflowd

# View logs
sudo journalctl -u nfcapd -f
sudo journalctl -u softflowd -f
```

### 5. Verify data capture

```bash
# Generate some traffic
curl https://www.google.com
ping -c 10 8.8.8.8

# Check for nfcapd files (wait ~5 minutes for file rotation)
ls -lh /var/nfdump/capture/
```

### 6. Copy data to Docker volume

```bash
# Copy captured flows to nfsen-ng
docker cp /var/nfdump/capture/. nfsen-ng:/var/nfdump/profiles-data/live/
```

## Periodic Data Sync (Optional)

To automatically sync captured data to the Docker container, create a cron job:

```bash
# Edit crontab
crontab -e

# Add this line to sync every 5 minutes
*/5 * * * * docker cp /var/nfdump/capture/. nfsen-ng:/var/nfdump/profiles-data/live/ 2>/dev/null
```

## Troubleshooting

### Service won't start

```bash
# Check logs
sudo journalctl -xe

# Verify binary paths
which nfcapd
which softflowd
```

### No data being captured

```bash
# Verify softflowd is sending to correct port
sudo netstat -tuln | grep 9995

# Check if interface is correct
ip link show

# Test manually
sudo softflowd -i eth0 -n 127.0.0.1:9995 -v 9 -d
```

### Permission issues

```bash
# Ensure directory exists and has correct permissions
sudo mkdir -p /var/nfdump/capture
sudo chown root:root /var/nfdump/capture
sudo chmod 755 /var/nfdump/capture
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
