# Rule reference

A rule has:

- `enabled`: true/false
- `priority`: larger number wins
- `profile`: profile to request when the rule matches
- `hold_sec`: condition must stay true this long before it activates
- `match`: `all` or `any`
- `conditions`: one or more conditions

Schedules use weekday numbers from Python's `datetime`: Monday is `0` and
Sunday is `6`. For an overnight interval such as `18:00`–`06:00`, the selected
weekday refers to the day the interval starts. For example, a Monday-only rule
is active from Monday at 18:00 through Tuesday at 06:00.

## Conditions

### CPU / host

```json
{"type":"cpu_busy_gte","value":45}
{"type":"load1_gte","value":10}
{"type":"memory_used_gte","value":85}
```

### Disk / network

```json
{"type":"io_mbps_gte","value":200}
{"type":"network_mbps_gte","value":500}
```

`io_mbps_gte` is the aggregate host disk read+write rate, excluding obvious virtual devices such as loop, md, dm and zram.

### Docker

The `container` value is a case-insensitive regular expression, so both an exact name and a group can be targeted.

```json
{"type":"docker_running","container":"minecraft"}
{"type":"docker_cpu_gte","container":"jellyfin|plex","value":8}
```

### Processes

```json
{"type":"process_running","process":"java|dotnet"}
{"type":"process_cpu_gte","process":"ffmpeg","value":20}
```

### TCP connections

```json
{"type":"tcp_connections_gte","port":2456,"value":1}
```

### Game player count

Steam A2S:

```json
{"type":"game_players_gte","value":1,"query":{"protocol":"steam_a2s","host":"192.168.1.50","port":2456}}
```

Minecraft RCON:

```json
{"type":"game_players_gte","value":1,"query":{"protocol":"minecraft_rcon","host":"192.168.1.51","port":25575,"password":"secret"}}
```

For a game that does not expose a supported player query, combine `docker_running`, `docker_cpu_gte`, `process_running` or `tcp_connections_gte`. Adding game-specific adapters later does not require changing the rule engine.

## Recommended philosophy

Use player-count rules for true game activity when available. Use container/process/CPU/port rules as fallbacks. Keep `hold_sec` at 10-30 seconds and the global `min_dwell_sec` at 3-10 minutes to avoid rapid profile switching.
