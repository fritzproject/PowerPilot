# PowerPilot

PowerPilot is a native Unraid plugin that selects CPU governor and Energy Performance Preference (EPP) from schedules and workload rules. It keeps the host online; it does not suspend or shut down Unraid.

## Features

- Performance, Balanced, Power Save and Power Super Save profiles.
- Weekday schedules, including intervals that cross midnight.
- Priority overrides with per-rule hold time and global minimum dwell.
- Rules for host CPU, load, memory, disk I/O, network, Docker containers, processes, TCP ports and game player counts.
- Steam A2S and Minecraft RCON queries.
- Manual profile override, event history, current metrics and a plain-language explanation of the winning decision.
- Dry-run is enabled on first install. Live mode requires a separate confirmation in the plugin page.

## Install on Unraid 7.2

1. Download powerpilot.plg from the GitHub release assets.
2. In Unraid, open Plugins → Install Plugin and paste the release URL for powerpilot.plg.
3. Open Settings → PowerPilot. Confirm dry-run decisions before enabling live control.

The release workflow builds a Slackware .txz payload and a checksum-pinned .plg installer. Tag a release as v0.2.0 to publish the first package. A source template is kept in plugin/powerpilot.plg.in.

## Data and migration

Configuration is stored at /boot/config/plugins/powerpilot/config.json. Event history and controller state are stored under /mnt/user/appdata/powerpilot/ to avoid frequent writes to the Unraid flash device.

On first start, the plugin imports /mnt/user/appdata/dynamic-power-manager/config.json when available. The imported configuration is forced to dry-run. Existing SQLite event history is not imported.

## CPU profile mappings

| Profile | Governor | EPP |
| --- | --- | --- |
| Performance | performance | performance |
| Balanced | powersave | balance_performance |
| Power Save | powersave | balance_power |
| Power Super Save | powersave | power |

Only CPU governor and EPP are modified. Storage link power management, PCIe ASPM, NVMe, HBA state, disk spin-down, host sleep and shutdown remain out of scope. Coordinate CPU ownership with Autotweak to prevent two controllers from changing the same settings.

## Development

Run engine checks with php plugin/tests/engine_test.php. Run syntax checks with php -l plugin/src/engine.php and php -l plugin/webui/powerpilot.page. Build a release payload with bash plugin/build-release.sh 0.2.0 on Linux with PHP, tar/xz and core utilities installed.

Live installation and CPU writes must be validated on a disposable Unraid 7.2 system before enabling them on a production host.