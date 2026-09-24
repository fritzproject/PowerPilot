# Dynamic Power Manager for Unraid

GUI + controller that keeps an Unraid host always on while dynamically selecting a CPU power profile based on schedule and workload.

## What it does

- Schedule profiles by time and weekday.
- Four logical profiles: Performance, Balanced, Power Save, Power Super Save.
- Generic rules for Docker containers, Docker CPU, host CPU, load, memory, disk I/O, network throughput, process presence/CPU and TCP connections.
- Game-server player detection via Steam A2S (where supported) and Minecraft RCON.
- Priority-based overrides.
- Hold time and global minimum dwell to avoid profile flapping.
- Manual override with optional expiry.
- Dry-run mode and an event history page.

For schedules that cross midnight, the selected weekday is the day the interval
starts (for example, Monday 18:00–06:00 continues into Tuesday morning).

## Important for this first release

The controller does **not** manage server sleep/shutdown. It is designed for an Unraid host that must remain online.

The first release changes only CPU governor + EPP. It intentionally does not toggle SATA LPM, PCI ASPM, NVMe or HBA power states dynamically. Those are better treated as a separate, compatibility-sensitive layer on a server with multiple SAS disks and an HBA.

The four profile mappings are:

- Performance: governor=performance, EPP=performance
- Balanced: governor=powersave, EPP=balance_performance
- Power Save: governor=powersave, EPP=balance_power
- Power Super Save: governor=powersave, EPP=power

If Autotweak is installed, its own GUI can remain present, but do not let two independent tools fight over the same settings. Autotweak explicitly warns that it is not compatible with other plugins that alter the same settings.

## PowerPilot + Codex

This repository includes `AGENTS.md` with the project context, design constraints, safety boundaries, and development workflow. Open the repository folder in VS Code and let the Codex extension work from that folder.

## Unraid deployment

This container needs privileged host access because it writes CPU sysfs values and reads host processes/metrics. It also uses the Docker socket to inspect containers.

Recommended volumes:

- `/mnt/user/appdata/dynamic-power-manager:/data`
- `/sys:/sys:rw`
- `/var/run/docker.sock:/var/run/docker.sock`

Recommended settings:

- `privileged=true`
- `pid=host`
- Port `8787`
- Time zone `Europe/Rome`

Start it in dry-run first. Open `http://UNRAID-IP:8787` and verify which rule would win before disabling dry-run.

## Example generic game setup

You do not have to hard-code Minecraft, Valheim, SCUM or Arma into the application. The rule engine can target a container name/regex, a process, a TCP port, or a game-query endpoint. For games exposing Steam A2S, use `steam_a2s`; for Minecraft use RCON.

For games without a usable player query, use a container-running rule or a CPU/process/port rule. That makes the engine usable for new games without requiring a new Docker image.

## Security note

Giving a container access to `/var/run/docker.sock` is highly privileged. Keep the web UI on your trusted LAN or behind your existing reverse proxy/authentication.
