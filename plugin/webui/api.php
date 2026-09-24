<?php
declare(strict_types=1);

ini_set('display_errors', '0');

$enginePath = __DIR__ . '/src/engine.php';
if (!is_file($enginePath)) {
    $enginePath = dirname(__DIR__) . '/src/engine.php';
}
require_once $enginePath;

use function PowerPilot\applyNetworkPlan;
use function PowerPilot\applyProfile;
use function PowerPilot\atomicJson;
use function PowerPilot\currentProfile;
use function PowerPilot\history;
use function PowerPilot\loadConfig;
use function PowerPilot\loadState;
use function PowerPilot\networkConfigHash;
use function PowerPilot\networkPlan;
use function PowerPilot\restoreNetworkSettings;
use function PowerPilot\saveConfig;
use function PowerPilot\scheduledProfile;
use function PowerPilot\validConfig;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');

function powerPilotReply(array $body, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function powerPilotCsrfToken(): string
{
    foreach (['/var/local/emhttp/var.ini', '/usr/local/emhttp/state/var.ini'] as $path) {
        if (!is_readable($path)) {
            continue;
        }

        $vars = @parse_ini_file($path);
        if (is_array($vars) && !empty($vars['csrf_token'])) {
            return (string)$vars['csrf_token'];
        }
    }

    return '';
}

try {
    $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    $cfg = loadConfig();

    if ($method === 'POST') {
        $input = $_POST;
        $posted = json_decode((string)($input['payload'] ?? '{}'), true);
        if (!is_array($posted)) {
            powerPilotReply(['error' => 'Invalid request payload'], 400);
        }

        $input = array_merge($input, $posted);
        $csrf = powerPilotCsrfToken();
        if ($csrf === '' || !hash_equals($csrf, (string)($input['csrf_token'] ?? ''))) {
            powerPilotReply(['error' => 'Invalid CSRF token'], 403);
        }

        $action = (string)($input['action'] ?? '');
        $state = loadState();

        if ($action === 'save_config') {
            $next = $input['config'] ?? null;
            if (!is_array($next)) {
                powerPilotReply(['error' => 'Missing config'], 400);
            }

            $next['dry_run'] = (bool)($cfg['dry_run'] ?? true);
            $next = PowerPilot\configWithDefaults($next);
            $next['network']['live_enabled'] = !empty($cfg['network']['live_enabled']);
            if (!validConfig($next, $error)) {
                powerPilotReply(['error' => $error], 400);
            }

            saveConfig($next);
            powerPilotReply(['ok' => true]);
        }

        if ($action === 'mode') {
            if (!in_array($input['mode'] ?? '', ['dry_run', 'live'], true) || empty($input['confirmed'])) {
                powerPilotReply(['error' => 'Explicit mode confirmation required'], 400);
            }

            $enableLive = $input['mode'] === 'live';
            $wasDry = !empty($cfg['dry_run']);
            $cfg['dry_run'] = !$enableLive;
            saveConfig($cfg);
            if ($enableLive && $wasDry) {
                $state['active_profile'] = null;
                $state['last_switch'] = 0;
                $state['last_applied'] = false;
                atomicJson(PowerPilot\STATE_FILE, $state);
            }

            powerPilotReply(['ok' => true, 'dry_run' => $cfg['dry_run']]);
        }

        if ($action === 'network_mode') {
            if (!in_array($input['mode'] ?? '', ['dry_run', 'live'], true) || empty($input['confirmed'])) {
                powerPilotReply(['error' => 'Explicit network mode confirmation required'], 400);
            }

            if ($input['mode'] === 'live') {
                $preview = networkPlan($cfg);
                if ($preview['autotweak_detected']) {
                    powerPilotReply(['error' => 'AutoTweak is installed. Disable or uninstall it before enabling PowerPilot network tuning.'], 409);
                }
                if (empty($cfg['network']['tcp_enabled']) && empty($cfg['network']['irq_enabled'])) {
                    powerPilotReply(['error' => 'Enable TCP tuning or NIC IRQ affinity and save first.'], 400);
                }
                if (!empty($cfg['network']['irq_enabled']) && empty($cfg['network']['interfaces'])) {
                    powerPilotReply(['error' => 'Select at least one physical NIC for IRQ affinity.'], 400);
                }

                $cfg['network']['live_enabled'] = true;
                saveConfig($cfg);
                $result = applyNetworkPlan($cfg, $state);
                $state['network_status'] = [
                    'live_enabled' => true,
                    'applied' => $result['applied'],
                    'errors' => $result['errors'],
                    'updated_at' => date(DATE_ATOM),
                ];
                $state['network_status']['boot_id'] = PowerPilot\networkReadValue('/proc/sys/kernel/random/boot_id') ?? 'unknown';
                $state['network_status']['config_hash'] = networkConfigHash($cfg['network']);
                atomicJson(PowerPilot\STATE_FILE, $state);
                powerPilotReply(['ok' => true, 'applied' => $result['applied'], 'errors' => $result['errors']]);
            }

            $cfg['network']['live_enabled'] = false;
            saveConfig($cfg);
            $restore = restoreNetworkSettings($state);
            $state['network_status'] = [
                'live_enabled' => false,
                'applied' => empty($restore['errors']),
                'errors' => $restore['errors'],
                'updated_at' => date(DATE_ATOM),
            ];
            atomicJson(PowerPilot\STATE_FILE, $state);
            powerPilotReply(['ok' => true, 'restored' => $restore['restored'], 'errors' => $restore['errors']]);
        }

        if ($action === 'manual') {
            $profile = $input['profile'] ?? null;
            if ($profile !== null && !isset($cfg['profiles'][$profile])) {
                powerPilotReply(['error' => 'Unknown profile'], 400);
            }

            if ($profile === null) {
                $cfg['manual_override'] = null;
            } else {
                $duration = max(0, min(604800, (int)($input['duration_sec'] ?? 0)));
                $cfg['manual_override'] = [
                    'profile' => $profile,
                    'expires_at' => $duration > 0 ? time() + $duration : null,
                ];
            }

            saveConfig($cfg);
            powerPilotReply(['ok' => true]);
        }

        if ($action === 'apply') {
            $profile = (string)($input['profile'] ?? '');
            if (!isset($cfg['profiles'][$profile])) {
                powerPilotReply(['error' => 'Unknown profile'], 400);
            }

            $changed = applyProfile($profile, 'manual apply', $cfg, $state, true);
            atomicJson(PowerPilot\STATE_FILE, $state);
            powerPilotReply(['ok' => true, 'changed' => $changed, 'dry_run' => $cfg['dry_run']]);
        }

        powerPilotReply(['error' => 'Unknown action'], 400);
    }

    if ($method !== 'GET') {
        header('Allow: GET, POST');
        powerPilotReply(['error' => 'Method not allowed'], 405);
    }

    [$base, $baseReason] = scheduledProfile($cfg, new DateTimeImmutable('now'));
    $state = loadState();
    powerPilotReply([
        'version' => PowerPilot\VERSION,
        'config' => $cfg,
        'state' => $state,
        'current' => currentProfile(),
        'base_profile' => $base,
        'base_reason' => $baseReason,
        'history' => history(100),
        'network_plan' => networkPlan($cfg),
        'csrf_token' => powerPilotCsrfToken(),
    ]);
} catch (Throwable $error) {
    powerPilotReply(['error' => $error->getMessage()], 500);
}
