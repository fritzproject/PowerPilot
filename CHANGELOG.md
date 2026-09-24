# Changelog

## 0.2.0 - 2026-09-24

- Replaced the Docker/FastAPI deployment with a native Unraid plugin package and WebGUI page.
- Added a PHP host controller for schedules, generic workload rules, Docker/process/TCP signals, Steam A2S and Minecraft RCON queries.
- Added host metrics, profile explanations, manual overrides and JSONL event history.
- Kept dry-run enabled by default and added an explicit live-mode confirmation.
- Added config import from the previous Docker appdata path.
- Removed Docker deployment files and the Python controller.

## 0.1.2 - 2026-09-24

- Fixed weekday handling for overnight schedules: the after-midnight portion belongs to the weekday on which the schedule started.

## 0.1.1 - 2026-09-24

- Renamed the project direction to PowerPilot and added project-specific development and safety guidance.

## 0.1.0 - 2026-09-24

- Initial Docker implementation with schedules, four CPU profiles, metrics, generic rules, Steam A2S and Minecraft RCON.
- Added priority overrides, hold time, minimum dwell, manual override, dry-run mode and event history.
- Excluded dynamic storage/PCIe power management and host sleep/shutdown.