# Changelog

## 0.1.2 - 2026-09-24

- Fixed weekday handling for overnight schedules: the after-midnight portion now belongs to the weekday on which the schedule started.

## 0.1.1 - 2026-09-24

- Renamed the project direction to **PowerPilot** (working product name).
- Added `AGENTS.md` with project context, architecture constraints, safety boundaries, extensibility rules, and development workflow for Codex/IDE agents.
- Added explicit guidance to regenerate release ZIPs and keep CHANGELOG.md updated.


## 0.1.0 - 2026-09-24

- Initial Docker implementation for Unraid.
- Added web GUI dashboard.
- Added schedule engine with weekday and midnight-crossing schedules.
- Added Performance, Balanced, Power Save and Power Super Save profiles.
- Added host CPU, load, memory, disk I/O and network measurements.
- Added generic Docker container/process/TCP rules.
- Added Steam A2S player-count query.
- Added Minecraft RCON player-count query.
- Added priority-based workload overrides.
- Added per-rule hold time and global minimum dwell time to reduce profile flapping.
- Added manual override with optional expiry.
- Added dry-run mode.
- Added SQLite event history and rule-testing endpoint.
- Intentionally excluded dynamic SATA LPM, PCI ASPM, NVMe and HBA power-state changes from v0.1.0.
- No sleep/shutdown functionality; the design assumes the Unraid host stays online.

## Notes for 0.1.0

- Generic game support is intentionally data-driven: game names are examples, not hard-coded requirements.
- Player detection currently supports Steam A2S and Minecraft RCON.
- Other games can be handled immediately through container/process/port/load rules.
