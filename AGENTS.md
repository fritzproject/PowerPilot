# AGENTS.md — PowerPilot / Dynamic Power Manager

## Project identity

Project name: **PowerPilot**
Working title before rename: Dynamic Power Manager for Unraid.

PowerPilot is a Dockerized, web-GUI controller for an always-on Unraid host. Its purpose is to dynamically select CPU power/performance profiles from schedule + workload signals, without putting the server to sleep or shutting it down.

## Current target environment

Primary target:
- Unraid
- Dell PowerEdge R740
- Intel Xeon Gold 6146, currently observed as 1 socket / 24 logical CPUs
- Large SAS storage pool(s)
- LSI SAS HBA
- 10 GbE networking
- Docker services including media and game workloads
- Europe/Rome timezone

Known workloads include Jellyfin/Plex, qBittorrent, Minecraft, Wolf/Games-on-Whales, ShadPS4, and other game servers. The product must remain generic and must not hard-code support around one game.

## Product goals

1. Provide a simple GUI for schedules and rules.
2. Keep the Unraid host online 24/7. Never implement sleep/shutdown unless explicitly requested as a future feature.
3. Support four logical profiles:
   - Performance
   - Balanced
   - Power Save
   - Power Super Save
4. Combine time-based schedules with automatic workload overrides.
5. Explain why the current profile was selected.
6. Avoid profile flapping with hold times and minimum dwell time.
7. Be extensible: new game/server types should generally be supported by configuration/adapters, not by rewriting the core rule engine.
8. Start in dry-run mode for safety.

## Current profile mappings

Performance:
- governor = performance
- EPP = performance

Balanced:
- governor = powersave
- EPP = balance_performance

Power Save:
- governor = powersave
- EPP = balance_power

Power Super Save:
- governor = powersave
- EPP = power

## Important safety boundary

Version 0.1 intentionally changes only CPU governor + EPP.

Do NOT dynamically toggle these in the core loop unless explicitly designed, tested, and documented:
- SATA link power management
- PCIe ASPM
- NVMe power policy
- HBA/controller power state
- disk spin-down
- host sleep
- host shutdown

The target server has SAS disks and an HBA, so aggressive storage/PCIe power transitions are compatibility-sensitive.

## Automation philosophy

Use a scheduled base profile, then allow higher-priority rules to override it.

Example desired default:
- 06:00–18:00: Performance
- 18:00–06:00: Power Super Save

Example overrides:
- Minecraft players >= 1 -> Performance
- Valheim/SCUM/Arma player query >= 1 -> Performance when supported
- Jellyfin transcoding -> Performance
- Wolf/ShadPS4 active -> Performance
- Significant CPU/load/I/O/network activity -> configurable profile

Rules should be generic. Useful primitives include:
- Docker container running
- Docker container CPU threshold
- host CPU busy threshold
- load average threshold
- memory threshold
- disk I/O threshold
- network throughput threshold
- process presence / CPU
- TCP connections by port
- Steam A2S player count
- Minecraft RCON player count

Prefer true player-count signals over indirect CPU/port signals when available. Keep indirect rules as fallbacks.

## Hysteresis / anti-flapping

Every workload rule may have a hold time before activation. The controller should also have a global minimum dwell time before another automatic switch.

Never oscillate rapidly between Performance and Power Super Save just because a metric crosses a threshold by a small amount.

When an override clears, return to the current scheduled/base profile only after the configured cooldown/dwell conditions are satisfied.

## Generic game support

Do not hard-code Minecraft, Valheim, SCUM, Arma, SCUM, or any single game into core logic.

Game integrations should use adapters/protocols, for example:
- steam_a2s
- minecraft_rcon
- future game-specific adapters

Games without a query protocol must still be supported through:
- container running state
- process presence / CPU
- TCP connection count
- CPU/load/I/O/network thresholds

## GUI expectations

Normal users should not need to edit JSON.

GUI areas should eventually include:
- Dashboard
- Schedules
- Rules
- Game Servers / Queries
- Containers / Processes
- Hardware / metrics
- Event history
- Settings
- Dry-run / Apply controls

The dashboard should always show:
- current profile
- scheduled/base profile
- active override(s)
- winning rule and priority
- reason for current decision
- relevant live metrics
- next reevaluation

A "Why?" explanation is a core feature, not optional decoration.

## Dry-run

Dry-run must be safe and easy to understand. In dry-run:
- evaluate all rules
- log the selected profile
- explain the winning conditions
- do not modify host CPU settings

A visible Apply/Enable control should be required before live changes.

## Architecture preferences

Current prototype is Python + FastAPI + simple web frontend.

Preferred separation:
- metrics collectors
- condition evaluators
- rule engine
- scheduler
- profile backend
- integrations/adapters
- persistence/history
- API/UI

Keep the decision engine deterministic and unit-testable. Do not embed business rules directly in HTTP handlers.

## Host access

The container may require:
- privileged=true
- pid=host
- /sys:/sys:rw
- /var/run/docker.sock:/var/run/docker.sock

Treat Docker socket access as highly privileged. The UI must be intended for the trusted LAN or protected behind authentication/reverse proxy.

## Compatibility with Autotweak

Autotweak may be installed on the target Unraid host. If PowerPilot takes ownership of CPU governor/EPP, two independent controllers must not fight over the same settings.

Support one clear ownership mode; document that Autotweak scheduling/automation should be disabled or coordinated when PowerPilot is the active controller.

## Development rules

- Do not remove existing working functionality without a clear reason.
- Favor configuration over hard-coded game logic.
- Keep backward compatibility for config files when practical.
- Add tests for new rule types and decision precedence.
- Keep logs useful and concise.
- Update CHANGELOG.md for every user-visible or behavior-changing release.
- Update README.md/RULES.md when configuration or behavior changes.
- Before declaring a release, validate Python syntax and build the Docker image when Docker is available.
- Never claim a feature works unless it was actually tested or clearly mark it unverified.

## Versioning

Current release line: 0.1.x.

When making changes:
- increment patch for fixes/documentation/internal improvements
- increment minor for new user-visible feature sets
- update CHANGELOG.md

## Current known limitations

- Only CPU governor + EPP are dynamically controlled.
- Steam A2S and Minecraft RCON are the current player-query integrations.
- Other game support relies on generic rules until an adapter is added.
- Authentication is not yet the focus of v0.1.
- Host storage/PCIe power-management transitions are deliberately out of scope for the first live controller.

## Working style for Codex

Before changing code:
1. Read README.md, RULES.md, CHANGELOG.md, and this AGENTS.md.
2. Inspect the existing implementation and preserve current behavior.
3. Identify the smallest coherent change that solves the request.
4. Implement + test.
5. Update documentation and CHANGELOG.md.
6. Summarize changed files, tests run, and any unverified items.

When the user asks for a release ZIP, rebuild the ZIP with the new files and include the updated CHANGELOG.md.
