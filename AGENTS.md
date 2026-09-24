# AGENTS.md — PowerPilot

## Project identity

PowerPilot is a native Unraid 7.2 plugin that selects CPU governor and EPP from schedules and workload signals.

## Target environment

The primary host is a Dell PowerEdge R740 with an Intel Xeon Gold 6146 (24 logical CPUs), SAS storage pools, an LSI SAS HBA and 10 GbE. The server runs in Europe/Rome and hosts media, torrent, game-server and game-streaming containers. Keep integrations generic and configurable.

## Safety boundaries

- Keep Unraid online. Never add sleep or shutdown behavior.
- Live control may change CPU governor/EPP plus explicitly enabled TCP buffers and selected NIC IRQ/XPS CPU affinity.
- Do not dynamically change SATA link power management, PCIe ASPM, NVMe policy, HBA/controller state or disk spin-down.
- New installations and migrated configurations must start in dry-run. Require an explicit WebGUI confirmation to enable live writes.
- Treat the plugin page as privileged host control. Keep writes behind Unraid WebGUI authentication and CSRF validation.
- Refuse network live mode while AutoTweak is installed; CPU governor/EPP ownership must also be coordinated.

## Product behavior

Support four profiles: Performance, Balanced, Power Save and Power Super Save. Combine weekly schedules with priority workload overrides, rule hold times, global minimum dwell, manual override, current metrics, event history and a clear reason for each decision.

Rules stay generic: host metrics, Docker container state/CPU, processes, TCP connections, Steam A2S and Minecraft RCON. Do not hard-code one game into the decision engine. Game queries are optional signals and must fail closed when unreachable.

## Architecture

- The Unraid .plg installer installs a Slackware package with the WebGUI .page, PHP engine and service script.
- PHP CLI runs the controller on the host; collect Linux metrics from procfs/sysfs, Docker data through the host Docker CLI, and network tuning through sysctl procfs and NIC IRQ/XPS sysfs.
- Keep configuration in /boot/config/plugins/powerpilot/; put frequent state and history writes under /mnt/user/appdata/powerpilot/.
- Keep schedule/rule evaluation deterministic and independently testable.
- Keep TCP and NIC tuning disabled by default; require its own explicit live confirmation, preserve original values, and restore them when disabled/uninstalled.
- Do not add a PowerPilot Docker deployment.

## Source readability and preservation

- Keep authored source code readable for manual maintenance. Do not minify, compress or collapse PHP, JavaScript, shell, YAML or other source into long one-line blocks; use normal indentation and line breaks, with one logical operation per line where practical.
- Preserve existing source files and their implementation, especially files with many lines of code. Do not truncate, replace with stubs or delete substantial code just to simplify a change; refactor in small, reviewable edits and retain unrelated behavior.
- Generated release artifacts may use packaging formats required by their tools, but the maintained source must remain human-readable.
## Development rules

- Preserve the existing JSON configuration shape where practical and import the old Docker app configuration on first start, forcing dry-run.
- Add checks for rule types, precedence, schedule boundaries, hold/dwell and dry-run safety.
- Update README.md, RULES.md and CHANGELOG.md for behavior changes.
- Version user-visible feature releases with semantic minor increments (0.x).
- Validate PHP syntax and package construction. Do not claim successful installation without testing on Unraid 7.2.
- Uninstall must stop the service and remove plugin files while preserving user configuration and event history.