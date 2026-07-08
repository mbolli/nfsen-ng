# nfdump Profiles

nfsen-ng supports multiple nfdump profiles (e.g. `live`, `test`, `backup`). Each
profile is an independent tree of `nfcapd` capture files under
`nfdump.profiles-data`, with its own separate RRD (or VictoriaMetrics) dataset.
The conceptual model is covered in
[Data Sources → Profiles](../architecture/data-sources.md#profiles); this page is
the operational side.

## Directory layout

```
<profiles-data>/
├── live/
│   └── <source>/
│       └── YYYY/MM/DD/nfcapd.YYYYMMDDHHMM
└── test/
    └── <source>/
        └── YYYY/MM/DD/nfcapd.YYYYMMDDHHMM
```

Profiles are detected automatically: `Config::detectProfiles()` scans
`<profiles-data>` and treats each top-level directory that contains a
source → `YYYY/` tree as a profile. A directory that instead holds *sub*-groups
of such trees becomes a nested `group/child` profile. The `nfdump.profile`
setting (`NFSEN_NFDUMP_PROFILE`, default `live`) only picks the *default* profile
shown on load. If the data root is missing or empty, nfsen-ng falls back to a
single profile named after `nfdump.profile`.

## Configuration

No extra configuration is required — detection is automatic on startup. To run a
second collector writing into another profile, just point its `-w` at a different
top-level directory:

```bash
# Live collector — port 9995 → profiles-data/live/all/
nfcapd -w /var/nfdump/profiles-data/live/all -z=lz4 -S 1 -T all -p 9995 -D

# Test/secondary collector — port 9996 → profiles-data/test/all/
nfcapd -w /var/nfdump/profiles-data/test/all -z=lz4 -S 1 -T all -p 9996 -D
```

### Docker Compose example

```yaml
nfcapd-test:
  image: ghcr.io/mbolli/nfsen-ng:latest
  entrypoint: ["/usr/local/nfdump/bin/nfcapd"]
  command: ["-w", "/data/nfsen-ng/test/all", "-z=lz4", "-S", "1", "-T", "all", "-p", "9996"]
  volumes:
    - /var/nfdump/profiles-data:/data/nfsen-ng
  ports:
    - "9996:9996/udp"
```

## Profile selector

When more than one profile is detected, a profile selector appears in the page
header. Switching profiles re-scopes the whole UI to that profile's data range
and slides the visible window to the end of its available data; the choice is
persisted to `preferences.json` so it survives a reload.

## Import daemon

The embedded import daemon watches each detected profile independently — the
Settings → Import panel shows a per-profile row, and the footer daemon indicator
reports how many profiles are being watched. Each profile:

- watches its own `<profiles-data>/<profile>/` subtree via inotify;
- writes into profile-namespaced storage (`data/<profile>/<source>.rrd`, or a
  `profile=` label for VictoriaMetrics);
- decides on startup whether to gap-fill based on its own last-update timestamp.
  A profile with no data yet is skipped — trigger its first import manually with
  the per-row **Trigger** button.

## Storage layout

RRD files are stored per profile:

```
backend/datasources/data/<profile>/<source>.rrd
backend/datasources/data/<profile>/<source>_<port>.rrd
backend/datasources/data/<profile>/<port>.rrd        # port-only aggregate
```

A `.rrd.first` sidecar (e.g. `all.rrd.first`) records the Unix timestamp of the
first real data point, so the date-range slider starts at the true beginning of
available data rather than the RRD creation time.

**Force Rescan is per profile.** The Rescan action reads the targeted profile
(`admin_target_profile`) and resets only that profile's datasource, leaving the
others untouched.

For VictoriaMetrics the profile is a Prometheus label (`profile="live"`).
Historical data written before profiles existed has no label and is still matched
via a regex selector (`profile=~"live|"`) for backward compatibility.

## Health checks

The health panel reports per-profile status: `nfcapd` path existence and capture
freshness, daemon status and last auto-import, and datasource freshness. With a
single profile the check ids and labels stay flat (`rrd_data_<source>`); with
more than one they're profile-suffixed (`rrd_data_<profile>_<source>`) so the two
don't collide. See [Health & Admin](../features/health-admin.md).
