from __future__ import annotations

import asyncio
import base64
import datetime as dt
import json
import logging
import os
import re
import socket
import sqlite3
import struct
import subprocess
import time
from pathlib import Path
from typing import Any

import docker
import psutil
from fastapi import FastAPI, HTTPException
from fastapi.responses import HTMLResponse
from pydantic import BaseModel

APP_NAME = "Dynamic Power Manager"
VERSION = "0.1.2"
DATA_DIR = Path(os.environ.get("DPM_DATA_DIR", "/data"))
DATA_DIR.mkdir(parents=True, exist_ok=True)
CONFIG_PATH = DATA_DIR / "config.json"
DB_PATH = DATA_DIR / "history.sqlite3"
CHECK_INTERVAL = max(2, int(os.environ.get("CHECK_INTERVAL_SEC", "10")))

logging.basicConfig(level=logging.INFO, format="%(asctime)s %(levelname)s %(message)s")
log = logging.getLogger(APP_NAME)

DEFAULT_CONFIG: dict[str, Any] = {
    "enabled": True,
    "dry_run": True,
    "engine": "native",
    "check_interval_sec": 10,
    "min_dwell_sec": 300,
    "default_profile": "balanced",
    "manual_override": None,
    "schedule": [
        {"days": [0, 1, 2, 3, 4, 5, 6], "start": "06:00", "end": "18:00", "profile": "performance"},
        {"days": [0, 1, 2, 3, 4, 5, 6], "start": "18:00", "end": "06:00", "profile": "power_super_save"},
    ],
    "profiles": {
        "performance": {"governor": "performance", "epp": "performance"},
        "balanced": {"governor": "powersave", "epp": "balance_performance"},
        "power_save": {"governor": "powersave", "epp": "balance_power"},
        "power_super_save": {"governor": "powersave", "epp": "power"},
    },
    "rules": [
        {
            "id": "game-containers",
            "name": "Configured game containers",
            "enabled": True,
            "priority": 100,
            "profile": "performance",
            "hold_sec": 15,
            "conditions": [
                {"type": "docker_running", "container": "minecraft"},
                {"type": "docker_running", "container": "valheim"},
                {"type": "docker_running", "container": "scum"},
                {"type": "docker_running", "container": "arma"},
            ],
            "match": "any",
        },
        {
            "id": "wolf-shadps4",
            "name": "Wolf / ShadPS4 activity",
            "enabled": True,
            "priority": 110,
            "profile": "performance",
            "hold_sec": 10,
            "conditions": [
                {"type": "docker_cpu_gte", "container": "wolf", "value": 5},
                {"type": "docker_cpu_gte", "container": "shadps4", "value": 5},
            ],
            "match": "any",
        },
        {
            "id": "jellyfin-transcode",
            "name": "Jellyfin / Plex activity",
            "enabled": True,
            "priority": 120,
            "profile": "performance",
            "hold_sec": 10,
            "conditions": [
                {"type": "docker_cpu_gte", "container": "jellyfin", "value": 8},
                {"type": "docker_cpu_gte", "container": "plex", "value": 8},
            ],
            "match": "any",
        },
        {
            "id": "high-system-load",
            "name": "High CPU load",
            "enabled": True,
            "priority": 90,
            "profile": "performance",
            "hold_sec": 30,
            "conditions": [{"type": "cpu_busy_gte", "value": 45}],
            "match": "all",
        },
        {
            "id": "heavy-io",
            "name": "Heavy disk I/O",
            "enabled": True,
            "priority": 80,
            "profile": "balanced",
            "hold_sec": 30,
            "conditions": [{"type": "io_mbps_gte", "value": 200}],
            "match": "all",
        },
    ],
}


def ensure_db() -> None:
    with sqlite3.connect(DB_PATH) as db:
        db.execute(
            "CREATE TABLE IF NOT EXISTS events (id INTEGER PRIMARY KEY AUTOINCREMENT, ts TEXT, profile TEXT, reason TEXT, applied INTEGER, metrics TEXT)"
        )
        db.commit()


def load_config() -> dict[str, Any]:
    if not CONFIG_PATH.exists():
        save_config(DEFAULT_CONFIG)
        return json.loads(json.dumps(DEFAULT_CONFIG))
    try:
        return json.loads(CONFIG_PATH.read_text())
    except Exception as exc:
        log.error("Cannot load config: %s", exc)
        return json.loads(json.dumps(DEFAULT_CONFIG))


def save_config(cfg: dict[str, Any]) -> None:
    tmp = CONFIG_PATH.with_suffix(".tmp")
    tmp.write_text(json.dumps(cfg, indent=2, sort_keys=True))
    tmp.replace(CONFIG_PATH)


def now_local() -> dt.datetime:
    return dt.datetime.now().astimezone()


def in_schedule(rule: dict[str, Any], now: dt.datetime) -> bool:
    start = dt.datetime.strptime(rule["start"], "%H:%M").time()
    end = dt.datetime.strptime(rule["end"], "%H:%M").time()
    current = now.time().replace(second=0, microsecond=0)
    days = rule.get("days", [])
    if start == end:
        return now.weekday() in days
    if start < end:
        return now.weekday() in days and start <= current < end
    # An overnight interval belongs to the day on which it starts. Before
    # its end time, check the previous weekday (including Sunday -> Monday).
    if current >= start:
        return now.weekday() in days
    if current < end:
        return (now.weekday() - 1) % 7 in days
    return False


def norm_container_pattern(value: str) -> re.Pattern[str]:
    return re.compile(value, re.IGNORECASE)


class DockerProbe:
    def __init__(self) -> None:
        self.client = None
        try:
            self.client = docker.from_env(timeout=2)
            self.client.ping()
        except Exception:
            self.client = None

    def list_stats(self) -> dict[str, dict[str, Any]]:
        if not self.client:
            return {}
        out: dict[str, dict[str, Any]] = {}
        try:
            for c in self.client.containers.list(all=True):
                name = c.name
                try:
                    stats = c.stats(stream=False)
                    cpu = docker_cpu_percent(stats)
                    net = docker_net_mbps(stats)
                    out[name] = {"running": c.status == "running", "cpu": cpu, "net_mbps": net}
                except Exception:
                    out[name] = {"running": c.status == "running", "cpu": 0.0, "net_mbps": 0.0}
        except Exception as exc:
            log.debug("Docker probe failed: %s", exc)
        return out


def docker_cpu_percent(stats: dict[str, Any]) -> float:
    try:
        cpu_delta = stats["cpu_stats"]["cpu_usage"]["total_usage"] - stats["precpu_stats"]["cpu_usage"]["total_usage"]
        system_delta = stats["cpu_stats"]["system_cpu_usage"] - stats["precpu_stats"]["system_cpu_usage"]
        online = stats["cpu_stats"].get("online_cpus") or psutil.cpu_count() or 1
        if cpu_delta <= 0 or system_delta <= 0:
            return 0.0
        return max(0.0, (cpu_delta / system_delta) * online * 100.0)
    except Exception:
        return 0.0


def docker_net_mbps(stats: dict[str, Any]) -> float:
    try:
        total = sum((v.get("rx_bytes", 0) + v.get("tx_bytes", 0)) for v in stats.get("networks", {}).values())
        # Single instantaneous Docker stats sample lacks delta. Treat total as a marker only here.
        return float(total) / (1024**2)
    except Exception:
        return 0.0


def aggregate_disk_mbps() -> tuple[float, float]:
    counters = psutil.disk_io_counters(perdisk=True) or {}
    filtered = {k: v for k, v in counters.items() if not k.startswith(("loop", "ram", "md", "dm-", "zram", "sr"))}
    state = APP_STATE.setdefault("io_state", {})
    now = time.monotonic()
    reads = sum(v.read_bytes for v in filtered.values())
    writes = sum(v.write_bytes for v in filtered.values())
    old = state.get("sample")
    state["sample"] = {"t": now, "r": reads, "w": writes}
    if not old:
        return 0.0, 0.0
    delta = max(0.25, now - old["t"])
    return max(0.0, (reads - old["r"]) / delta / (1024**2)), max(0.0, (writes - old["w"]) / delta / (1024**2))


def network_mbps() -> float:
    state = APP_STATE.setdefault("net_state", {})
    c = psutil.net_io_counters()
    now = time.monotonic()
    total = c.bytes_recv + c.bytes_sent
    old = state.get("sample")
    state["sample"] = {"t": now, "b": total}
    if not old:
        return 0.0
    delta = max(0.25, now - old["t"])
    return max(0.0, (total - old["b"]) / delta * 8 / 1_000_000)


def process_cpu(name_regex: str) -> float:
    pat = re.compile(name_regex, re.IGNORECASE)
    best = 0.0
    for p in psutil.process_iter(["name"]):
        try:
            name = p.info.get("name") or ""
            if pat.search(name):
                best = max(best, p.cpu_percent(interval=0.0))
        except Exception:
            continue
    return best


def tcp_connections(port: int) -> int:
    try:
        return sum(1 for c in psutil.net_connections(kind="inet") if c.laddr and c.laddr.port == port and c.status == psutil.CONN_ESTABLISHED)
    except Exception:
        return 0


def steam_a2s_info(host: str, port: int, timeout: float = 1.5) -> dict[str, Any]:
    packet = b"\xff\xff\xff\xffTSource Engine Query\x00"
    with socket.socket(socket.AF_INET, socket.SOCK_DGRAM) as s:
        s.settimeout(timeout)
        s.sendto(packet, (host, port))
        data, _ = s.recvfrom(4096)
    if not data.startswith(b"\xff\xff\xff\xffI"):
        raise ValueError("Unexpected A2S response")
    pos = 6
    pos += 1  # protocol
    fields = []
    for _ in range(4):
        end = data.find(b"\x00", pos)
        if end < 0:
            raise ValueError("Malformed A2S response")
        fields.append(data[pos:end].decode("utf-8", errors="replace"))
        pos = end + 1
    if pos + 7 > len(data):
        raise ValueError("Short A2S response")
    appid = struct.unpack_from("<H", data, pos)[0]
    pos += 2
    players = data[pos]
    maxplayers = data[pos + 1]
    bots = data[pos + 2]
    return {"name": fields[0], "map": fields[1], "folder": fields[2], "game": fields[3], "appid": appid, "players": players, "maxplayers": maxplayers, "bots": bots}


def minecraft_rcon(host: str, port: int, password: str, command: str = "list", timeout: float = 2.0) -> dict[str, Any]:
    def packet(req_id: int, typ: int, body: bytes) -> bytes:
        payload = struct.pack("<iii", 4 + len(body) + 2, req_id, typ) + body + b"\x00\x00"
        return payload

    def send_recv(sock: socket.socket, req_id: int, typ: int, body: bytes) -> bytes:
        sock.sendall(packet(req_id, typ, body))
        header = read_n(sock, 4)
        size = struct.unpack("<i", header)[0]
        data = read_n(sock, size)
        return data

    def read_n(sock: socket.socket, n: int) -> bytes:
        out = b""
        while len(out) < n:
            chunk = sock.recv(n - len(out))
            if not chunk:
                raise ConnectionError("RCON closed")
            out += chunk
        return out

    with socket.create_connection((host, port), timeout=timeout) as sock:
        auth = send_recv(sock, 1, 3, password.encode())
        if len(auth) < 8 or struct.unpack_from("<i", auth, 4)[0] != 1:
            raise PermissionError("RCON authentication failed")
        resp = send_recv(sock, 2, 2, command.encode())
        if len(resp) < 12:
            return {"players": 0, "raw": ""}
        body = resp[8:-2].decode("utf-8", errors="replace")
        match = re.search(r"There are (\d+) of a max of (\d+) players online", body, re.I)
        if not match:
            match = re.search(r"(?:Players|player list):?\s*(\d+)", body, re.I)
        players = int(match.group(1)) if match else 0
        return {"players": players, "raw": body}


class ConditionEvaluator:
    def __init__(self, docker_stats: dict[str, dict[str, Any]], metrics: dict[str, Any]):
        self.docker_stats = docker_stats
        self.metrics = metrics

    def _match_container(self, pattern: str) -> list[dict[str, Any]]:
        try:
            pat = norm_container_pattern(pattern)
        except re.error:
            return []
        return [v for k, v in self.docker_stats.items() if pat.search(k)]

    def evaluate(self, cond: dict[str, Any]) -> tuple[bool, str]:
        t = cond.get("type")
        if t == "cpu_busy_gte":
            ok = self.metrics["cpu_busy"] >= float(cond["value"])
            return ok, f"CPU {self.metrics['cpu_busy']:.1f}% {'≥' if ok else '<'} {cond['value']}%"
        if t == "load1_gte":
            ok = self.metrics["load1"] >= float(cond["value"])
            return ok, f"load1 {self.metrics['load1']:.2f} {'≥' if ok else '<'} {cond['value']}"
        if t == "memory_used_gte":
            ok = self.metrics["memory_used"] >= float(cond["value"])
            return ok, f"RAM {self.metrics['memory_used']:.1f}% {'≥' if ok else '<'} {cond['value']}%"
        if t == "io_mbps_gte":
            ok = self.metrics["io_mbps"] >= float(cond["value"])
            return ok, f"disk I/O {self.metrics['io_mbps']:.1f} MB/s {'≥' if ok else '<'} {cond['value']}"
        if t == "network_mbps_gte":
            ok = self.metrics["network_mbps"] >= float(cond["value"])
            return ok, f"network {self.metrics['network_mbps']:.1f} Mb/s {'≥' if ok else '<'} {cond['value']}"
        if t == "docker_running":
            matches = self._match_container(cond["container"])
            ok = any(x["running"] for x in matches)
            return ok, f"container /{cond['container']}/ {'running' if ok else 'not running'}"
        if t == "docker_cpu_gte":
            matches = self._match_container(cond["container"])
            value = max([x["cpu"] for x in matches] or [0.0])
            ok = value >= float(cond["value"])
            return ok, f"Docker /{cond['container']}/ CPU {value:.1f}% {'≥' if ok else '<'} {cond['value']}%"
        if t == "process_running":
            pat = re.compile(cond["process"], re.I)
            ok = any(bool(pat.search(p.info.get("name") or "")) for p in psutil.process_iter(["name"]))
            return ok, f"process /{cond['process']}/ {'found' if ok else 'not found'}"
        if t == "process_cpu_gte":
            value = process_cpu(cond["process"])
            ok = value >= float(cond["value"])
            return ok, f"process /{cond['process']}/ CPU {value:.1f}% {'≥' if ok else '<'} {cond['value']}%"
        if t == "tcp_connections_gte":
            value = tcp_connections(int(cond["port"]))
            ok = value >= int(cond["value"])
            return ok, f"TCP :{cond['port']} connections {value} {'≥' if ok else '<'} {cond['value']}"
        if t == "game_players_gte":
            q = cond["query"]
            try:
                if q["protocol"] == "steam_a2s":
                    result = steam_a2s_info(q["host"], int(q["port"]))
                elif q["protocol"] == "minecraft_rcon":
                    result = minecraft_rcon(q["host"], int(q["port"]), q["password"])
                else:
                    raise ValueError("Unsupported protocol")
                players = int(result.get("players", 0))
                ok = players >= int(cond["value"])
                return ok, f"{q['protocol']} {q['host']}:{q['port']} players={players} {'≥' if ok else '<'} {cond['value']}"
            except Exception as exc:
                return False, f"{q.get('protocol','game')} query failed: {exc}"
        return False, f"Unknown condition: {t}"


def collect_metrics(docker_stats: dict[str, dict[str, Any]]) -> dict[str, Any]:
    cpu_busy = psutil.cpu_percent(interval=None)
    vm = psutil.virtual_memory()
    rmb, wmb = aggregate_disk_mbps()
    return {
        "cpu_busy": cpu_busy,
        "load1": os.getloadavg()[0] if hasattr(os, "getloadavg") else 0.0,
        "memory_used": vm.percent,
        "io_read_mbps": rmb,
        "io_write_mbps": wmb,
        "io_mbps": rmb + wmb,
        "network_mbps": network_mbps(),
        "uptime_sec": time.time() - psutil.boot_time(),
        "docker_count": len(docker_stats),
        "docker_running": sum(1 for v in docker_stats.values() if v["running"]),
    }


APP_STATE: dict[str, Any] = {
    "active_profile": None,
    "last_switch": 0.0,
    "last_reason": "startup",
    "metrics": {},
    "rule_states": {},
    "io_state": {},
    "net_state": {},
}

CONFIG = load_config()
DOCKER = DockerProbe()
ensure_db()
app = FastAPI(title=APP_NAME, version=VERSION)


class ConfigModel(BaseModel):
    config: dict[str, Any]


class ManualProfile(BaseModel):
    profile: str | None
    duration_sec: int | None = None


def read_current_profile() -> dict[str, Any]:
    gov = epp = None
    try:
        gov = Path("/sys/devices/system/cpu/cpu0/cpufreq/scaling_governor").read_text().strip()
    except Exception:
        pass
    try:
        epp = Path("/sys/devices/system/cpu/cpu0/cpufreq/energy_performance_preference").read_text().strip()
    except Exception:
        pass
    return {"governor": gov, "epp": epp}


def apply_profile(profile: str, reason: str, force: bool = False) -> bool:
    cfg = CONFIG
    if profile not in cfg["profiles"]:
        log.warning("Unknown profile %s", profile)
        return False
    if not force and profile == APP_STATE.get("active_profile"):
        return False
    elapsed = time.monotonic() - APP_STATE.get("last_switch", 0)
    if not force and APP_STATE.get("active_profile") and elapsed < int(cfg.get("min_dwell_sec", 300)):
        return False
    spec = cfg["profiles"][profile]
    applied = not bool(cfg.get("dry_run", True))
    if applied:
        governor = spec.get("governor")
        epp = spec.get("epp")
        errors = []
        for p in Path("/sys/devices/system/cpu").glob("cpu[0-9]*/cpufreq/scaling_governor"):
            try:
                if governor:
                    p.write_text(str(governor))
            except Exception as exc:
                errors.append(f"{p}: {exc}")
        for p in Path("/sys/devices/system/cpu").glob("cpu[0-9]*/cpufreq/energy_performance_preference"):
            try:
                if epp:
                    p.write_text(str(epp))
            except Exception as exc:
                errors.append(f"{p}: {exc}")
        if errors:
            reason = reason + "; apply errors: " + " | ".join(errors[:3])
    APP_STATE["active_profile"] = profile
    APP_STATE["last_switch"] = time.monotonic()
    APP_STATE["last_reason"] = reason
    with sqlite3.connect(DB_PATH) as db:
        db.execute("INSERT INTO events(ts, profile, reason, applied, metrics) VALUES (?,?,?,?,?)", (now_local().isoformat(), profile, reason, int(applied), json.dumps(APP_STATE.get("metrics", {}))))
        db.commit()
    log.info("Profile -> %s (%s) dry_run=%s", profile, reason, cfg.get("dry_run", True))
    return True


def scheduled_profile(now: dt.datetime) -> tuple[str, str]:
    for r in CONFIG.get("schedule", []):
        if in_schedule(r, now):
            return r["profile"], f"schedule {r['start']}-{r['end']}"
    return CONFIG.get("default_profile", "balanced"), "default profile"


def rule_matches(rule: dict[str, Any], evaluator: ConditionEvaluator) -> tuple[bool, str]:
    states = []
    reasons = []
    for c in rule.get("conditions", []):
        ok, reason = evaluator.evaluate(c)
        states.append(ok)
        reasons.append(reason)
    matched = all(states) if rule.get("match", "all") == "all" else any(states)
    return matched, "; ".join(reasons)


def decide() -> tuple[str, str]:
    global CONFIG
    manual = CONFIG.get("manual_override")
    if manual:
        expires = manual.get("expires_at")
        if expires and time.time() >= float(expires):
            CONFIG["manual_override"] = None
            save_config(CONFIG)
        else:
            return manual["profile"], "manual override"

    now = now_local()
    base_profile, base_reason = scheduled_profile(now)
    docker_stats = DOCKER.list_stats()
    metrics = collect_metrics(docker_stats)
    APP_STATE["metrics"] = metrics
    evaluator = ConditionEvaluator(docker_stats, metrics)

    candidates = []
    for rule in CONFIG.get("rules", []):
        if not rule.get("enabled", True):
            continue
        matched, reason = rule_matches(rule, evaluator)
        state = APP_STATE["rule_states"].setdefault(rule["id"], {"since": None})
        if matched:
            if state["since"] is None:
                state["since"] = time.monotonic()
            hold = int(rule.get("hold_sec", 0))
            if time.monotonic() - state["since"] >= hold:
                candidates.append((int(rule.get("priority", 0)), rule["profile"], f"rule: {rule.get('name', rule['id'])} — {reason}"))
        else:
            state["since"] = None
    if candidates:
        candidates.sort(key=lambda x: x[0], reverse=True)
        _, profile, reason = candidates[0]
        return profile, reason
    return base_profile, base_reason


async def controller_loop() -> None:
    await asyncio.sleep(1)
    while True:
        try:
            CONFIG = load_config()
            if CONFIG.get("enabled", True):
                profile, reason = decide()
                apply_profile(profile, reason)
        except Exception as exc:
            log.exception("Controller loop error: %s", exc)
        await asyncio.sleep(max(2, int(CONFIG.get("check_interval_sec", CHECK_INTERVAL))))


@app.on_event("startup")
async def startup() -> None:
    asyncio.create_task(controller_loop())


@app.get("/", response_class=HTMLResponse)
async def index() -> str:
    return (Path(__file__).parent / "templates" / "index.html").read_text()


@app.get("/api/status")
async def status() -> dict[str, Any]:
    return {
        "version": VERSION,
        "config": CONFIG,
        "state": APP_STATE,
        "current": read_current_profile(),
        "time": now_local().isoformat(),
    }


@app.get("/api/history")
async def history(limit: int = 100) -> list[dict[str, Any]]:
    with sqlite3.connect(DB_PATH) as db:
        db.row_factory = sqlite3.Row
        rows = db.execute("SELECT id,ts,profile,reason,applied,metrics FROM events ORDER BY id DESC LIMIT ?", (min(limit, 500),)).fetchall()
        return [dict(r) for r in rows]


@app.post("/api/config")
async def update_config(payload: ConfigModel) -> dict[str, Any]:
    global CONFIG
    cfg = payload.config
    for key in ("profiles", "schedule", "rules", "default_profile"):
        if key not in cfg:
            raise HTTPException(400, f"Missing config key: {key}")
    CONFIG = cfg
    save_config(CONFIG)
    return CONFIG


@app.post("/api/manual")
async def manual(payload: ManualProfile) -> dict[str, Any]:
    global CONFIG
    if payload.profile is not None and payload.profile not in CONFIG["profiles"]:
        raise HTTPException(400, "Unknown profile")
    if payload.profile is None:
        CONFIG["manual_override"] = None
    else:
        expires = None if not payload.duration_sec else time.time() + max(60, payload.duration_sec)
        CONFIG["manual_override"] = {"profile": payload.profile, "expires_at": expires}
    save_config(CONFIG)
    return {"manual_override": CONFIG["manual_override"]}


@app.post("/api/apply")
async def apply_now(payload: ManualProfile) -> dict[str, Any]:
    if payload.profile not in CONFIG["profiles"]:
        raise HTTPException(400, "Unknown profile")
    changed = apply_profile(payload.profile, "manual apply", force=True)
    return {"changed": changed, "profile": payload.profile, "dry_run": CONFIG.get("dry_run", True)}


@app.post("/api/test")
async def test_conditions() -> dict[str, Any]:
    docker_stats = DOCKER.list_stats()
    metrics = collect_metrics(docker_stats)
    evaluator = ConditionEvaluator(docker_stats, metrics)
    results = []
    for rule in CONFIG.get("rules", []):
        matched, reason = rule_matches(rule, evaluator)
        results.append({"id": rule["id"], "name": rule.get("name"), "matched": matched, "reason": reason})
    return {"metrics": metrics, "rules": results}
