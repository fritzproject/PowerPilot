# Rule reference

Manage schedules and rules in the PowerPilot page in the Unraid WebGUI. Conditions can be combined with all (AND) or any (OR). A higher numeric priority wins when multiple rules match. Each rule has a hold time before activation; the global minimum dwell prevents rapid profile switching.

The dashboard fetches its state from the plugin's dedicated PHP endpoint. Write requests include Unraid's WebGUI CSRF token and are rejected when the token is missing or invalid.

Weekday numbers in imported configuration follow Python conventions: Monday is 0, Sunday is 6. For an overnight interval such as 18:00–06:00, selected days refer to the day when the interval starts. A Monday-only interval therefore remains active through Tuesday at 06:00.

## Supported condition types

| Type | GUI inputs |
| --- | --- |
| cpu_busy_gte | Host CPU busy percentage and threshold |
| load1_gte | One-minute load average threshold |
| memory_used_gte | Used memory percentage threshold |
| io_mbps_gte | Aggregate host disk read/write MB/s threshold |
| network_mbps_gte | Aggregate network throughput threshold |
| docker_running | Case-insensitive container-name regular expression |
| docker_cpu_gte | Container-name regular expression and CPU percentage |
| process_running | Process-name regular expression |
| process_cpu_gte | Process-name regular expression and CPU percentage |
| tcp_connections_gte | TCP port and established connection count |
| game_players_gte | Threshold plus Steam A2S or Minecraft RCON endpoint |

Steam A2S configuration example:

    {"type":"game_players_gte","value":1,"query":{"protocol":"steam_a2s","host":"192.168.1.50","port":2456}}

Minecraft RCON configuration example:

    {"type":"game_players_gte","value":1,"query":{"protocol":"minecraft_rcon","host":"192.168.1.51","port":25575,"password":"secret"}}

The plugin queries Docker on the host; PowerPilot itself is not a container. If a game has no query protocol, use container, process, port or host-metric rules. Prefer player counts when available and use hold_sec (10–30 seconds) plus min_dwell_sec (3–10 minutes) to reduce profile flapping.
## Network tuning

Network tuning is separate from CPU profiles and is disabled by default. Enable TCP tuning to preview buffer sizes selected from the fastest negotiated physical NIC. Enable IRQ affinity to select physical NICs and distribute their dedicated IRQs and transmit queue XPS masks across NUMA-local online CPUs. CPU 0 is reserved by default; the reserved CPU list can be edited in the GUI.

The preview reports detected interfaces, drivers, speeds, queue counts, current TCP values and proposed IRQ/XPS placement. Unknown speeds use only MTU probing; unsupported IRQs are skipped and reported. Applying the network plan needs a separate explicit live confirmation. PowerPilot captures the current kernel values and restores them when network live mode is disabled or on plugin removal. AutoTweak must be disabled or removed; network live mode is blocked while PowerPilot detects it.
