<?php
declare(strict_types=1);

namespace PowerPilot;

/** Expand a Linux CPU list such as "0-3,8,10-11" into sorted CPU IDs. */
function networkExpandCpuList(string $value): array
{
    $cpus = [];
    foreach (explode(',', trim($value)) as $part) {
        if ($part === '') {
            continue;
        }

        if (!preg_match('/^(\d+)(?:-(\d+))?$/', $part, $match)) {
            continue;
        }

        $first = (int)$match[1];
        $last = isset($match[2]) ? (int)$match[2] : $first;
        if ($last < $first || $last - $first > 4096) {
            continue;
        }

        for ($cpu = $first; $cpu <= $last; $cpu++) {
            $cpus[$cpu] = $cpu;
        }
    }

    sort($cpus, SORT_NUMERIC);
    return array_values($cpus);
}

/** Format one CPU as the hexadecimal bitmap expected by Linux XPS sysfs. */
function networkCpuMask(int $cpu): string
{
    if ($cpu < 0) {
        throw new \InvalidArgumentException('CPU number cannot be negative');
    }

    $wordIndex = intdiv($cpu, 32);
    $words = array_fill(0, $wordIndex + 1, 0);
    $words[$wordIndex] = 1 << ($cpu % 32);
    $words = array_reverse($words);

    return implode(',', array_map(static fn(int $word): string => sprintf('%08x', $word), $words));
}

function networkReadValue(string $path): ?string
{
    if (!is_readable($path)) {
        return null;
    }

    $value = @file_get_contents($path);
    return $value === false ? null : trim($value);
}

function networkInterfaceIrqs(string $name, string $sysRoot, string $procRoot): array
{
    $irqs = [];
    $msiDirectory = "$sysRoot/class/net/$name/device/msi_irqs";
    foreach (glob($msiDirectory . '/*') ?: [] as $path) {
        $irq = basename($path);
        if (ctype_digit($irq) && is_dir("$procRoot/irq/$irq")) {
            $irqs[] = (int)$irq;
        }
    }

    if (!$irqs) {
        $interrupts = @file("$procRoot/interrupts", FILE_IGNORE_NEW_LINES) ?: [];
        $namePattern = '/(?<![a-zA-Z0-9_])' . preg_quote($name, '/') . '(?=$|[\s:-])/i';
        foreach ($interrupts as $line) {
            if (preg_match('/^\s*(\d+):/', $line, $match) && preg_match($namePattern, $line)
                && is_dir("$procRoot/irq/{$match[1]}")) {
                $irqs[] = (int)$match[1];
            }
        }
    }

    $irqs = array_values(array_unique($irqs));
    sort($irqs, SORT_NUMERIC);
    return $irqs;
}

/** Return information for physical, kernel-visible network interfaces. */
function networkInterfaces(string $sysRoot = '/sys', string $procRoot = '/proc'): array
{
    $interfaces = [];
    foreach (glob("$sysRoot/class/net/*") ?: [] as $path) {
        $name = basename($path);
        if (!preg_match('/^[a-zA-Z0-9_.:-]{1,16}$/', $name) || !is_dir("$path/device")) {
            continue;
        }

        $speed = networkReadValue("$path/speed");
        $speed = $speed !== null && ctype_digit($speed) ? (int)$speed : null;
        $driverPath = @readlink("$path/device/driver");
        $interfaces[$name] = [
            'name' => $name,
            'driver' => $driverPath === false ? 'unknown' : basename($driverPath),
            'speed_mbps' => $speed,
            'numa_node' => (int)(networkReadValue("$path/device/numa_node") ?? -1),
            'local_cpus' => networkReadValue("$path/device/local_cpulist"),
            'rx_queues' => count(glob("$path/queues/rx-*", GLOB_ONLYDIR) ?: []),
            'tx_queues' => count(glob("$path/queues/tx-*", GLOB_ONLYDIR) ?: []),
            'irqs' => networkInterfaceIrqs($name, $sysRoot, $procRoot),
        ];
    }

    return $interfaces;
}

function networkTcpSettings(int $maxSpeedMbps): array
{
    $settings = [];
    if ($maxSpeedMbps >= 1000) {
        $maximum = $maxSpeedMbps >= 10000 ? 67108864 : ($maxSpeedMbps >= 2500 ? 33554432 : 16777216);
        $tcpMaximum = min($maximum, 33554432);
        $settings = [
            'net.core.rmem_max' => (string)$maximum,
            'net.core.wmem_max' => (string)$maximum,
            'net.ipv4.tcp_rmem' => "4096 87380 $tcpMaximum",
            'net.ipv4.tcp_wmem' => "4096 65536 $tcpMaximum",
        ];
    }

    $settings['net.ipv4.tcp_mtu_probing'] = '1';
    if ($maxSpeedMbps >= 10000) {
        $settings['net.ipv4.tcp_slow_start_after_idle'] = '0';
    }

    return $settings;
}

function networkSelectedCpus(array $interface, array $reservedCpus, string $sysRoot): array
{
    $online = networkExpandCpuList(networkReadValue("$sysRoot/devices/system/cpu/online") ?? '');
    $local = networkExpandCpuList((string)($interface['local_cpus'] ?? ''));
    $available = $local ?: $online;
    $reserved = array_map('intval', $reservedCpus);

    $available = array_values(array_filter(
        $available,
        static fn(int $cpu): bool => in_array($cpu, $online, true) && !in_array($cpu, $reserved, true)
    ));

    return $available;
}

/** Create a read-only plan for TCP settings and IRQ/XPS placement. */
function networkPlan(array $config, string $sysRoot = '/sys', string $procRoot = '/proc'): array
{
    $network = $config['network'] ?? [];
    $interfaces = networkInterfaces($sysRoot, $procRoot);
    $maxSpeed = 0;
    foreach ($interfaces as $interface) {
        $maxSpeed = max($maxSpeed, (int)($interface['speed_mbps'] ?? 0));
    }

    $tcpSettings = networkTcpSettings($maxSpeed);
    $tcpCurrent = [];
    foreach (array_keys($tcpSettings) as $name) {
        $tcpCurrent[$name] = networkReadValue($procRoot . '/sys/' . str_replace('.', '/', $name));
    }

    $reservedCpus = array_values(array_unique(array_map('intval', $network['reserved_cpus'] ?? [0])));
    sort($reservedCpus, SORT_NUMERIC);
    $selectedNames = array_values(array_unique($network['interfaces'] ?? []));
    $irqPlans = [];

    foreach ($interfaces as $name => $interface) {
        if (!in_array($name, $selectedNames, true)) {
            continue;
        }

        $cpus = networkSelectedCpus($interface, $reservedCpus, $sysRoot);
        $irqAssignments = [];
        foreach ($interface['irqs'] as $index => $irq) {
            $cpu = $cpus ? $cpus[$index % count($cpus)] : null;
            $irqAssignments[] = [
                'irq' => $irq,
                'cpu' => $cpu,
                'current' => networkReadValue("$procRoot/irq/$irq/smp_affinity_list"),
            ];
        }

        $txAssignments = [];
        for ($queue = 0; $queue < $interface['tx_queues']; $queue++) {
            $cpu = $cpus ? $cpus[$queue % count($cpus)] : null;
            $path = "$sysRoot/class/net/$name/queues/tx-$queue/xps_cpus";
            $txAssignments[] = [
                'queue' => $queue,
                'cpu' => $cpu,
                'mask' => $cpu === null ? null : networkCpuMask($cpu),
                'current' => networkReadValue($path),
            ];
        }

        $warnings = [];
        if (!$cpus) {
            $warnings[] = 'No online CPU remains after the reserved CPU list is applied.';
        }
        if (!$interface['irqs']) {
            $warnings[] = 'No dedicated network IRQs were detected; IRQ affinity will be skipped.';
        }
        $irqPlans[] = [
            'name' => $name,
            'driver' => $interface['driver'],
            'speed_mbps' => $interface['speed_mbps'],
            'numa_node' => $interface['numa_node'],
            'candidate_cpus' => $cpus,
            'irq_assignments' => $irqAssignments,
            'tx_assignments' => $txAssignments,
            'warnings' => $warnings,
        ];
    }

    return [
        'tcp' => [
            'enabled' => !empty($network['tcp_enabled']),
            'max_speed_mbps' => $maxSpeed,
            'settings' => $tcpSettings,
            'current' => $tcpCurrent,
        ],
        'available_interfaces' => array_values($interfaces),
        'irq' => [
            'enabled' => !empty($network['irq_enabled']),
            'selected_interfaces' => $selectedNames,
            'reserved_cpus' => $reservedCpus,
            'interfaces' => $irqPlans,
        ],
        'live_enabled' => !empty($network['live_enabled']),
        'autotweak_detected' => is_file('/var/log/plugins/autotweak.plg')
            || is_dir('/usr/local/emhttp/plugins/autotweak'),
    ];
}

function networkWriteSetting(string $path, string $value, array &$state): bool
{
    $current = networkReadValue($path);
    if ($current === null || !is_writable($path)) {
        return false;
    }
    if ($current === $value) {
        return true;
    }

    $state['network_original'][$path] ??= $current;
    return @file_put_contents($path, $value . "\n") !== false;
}

/** Apply the enabled network plan. All writes are guarded by network.live_enabled. */
function applyNetworkPlan(array $config, array &$state, string $sysRoot = '/sys', string $procRoot = '/proc'): array
{
    $plan = networkPlan($config, $sysRoot, $procRoot);
    $network = $config['network'] ?? [];
    $errors = [];
    if (empty($network['live_enabled'])) {
        return ['applied' => false, 'errors' => [], 'plan' => $plan];
    }

    if (!empty($network['tcp_enabled'])) {
        foreach ($plan['tcp']['settings'] as $name => $value) {
            $path = $procRoot . '/sys/' . str_replace('.', '/', $name);
            if (!networkWriteSetting($path, $value, $state)) {
                $errors[] = "TCP setting unavailable or write failed: $name";
            }
        }
    }

    if (!empty($network['irq_enabled'])) {
        foreach ($plan['irq']['interfaces'] as $interface) {
            foreach ($interface['irq_assignments'] as $assignment) {
                if ($assignment['cpu'] === null) {
                    continue;
                }
                $path = "$procRoot/irq/{$assignment['irq']}/smp_affinity_list";
                if (!networkWriteSetting($path, (string)$assignment['cpu'], $state)) {
                    $errors[] = "IRQ affinity write failed for IRQ {$assignment['irq']}";
                }
            }
            foreach ($interface['tx_assignments'] as $assignment) {
                if ($assignment['cpu'] === null) {
                    continue;
                }
                $path = "$sysRoot/class/net/{$interface['name']}/queues/tx-{$assignment['queue']}/xps_cpus";
                if (!networkWriteSetting($path, (string)$assignment['mask'], $state)) {
                    $errors[] = "XPS write failed for {$interface['name']} queue {$assignment['queue']}";
                }
            }
        }
    }

    return ['applied' => !$errors, 'errors' => $errors, 'plan' => $plan];
}

/** Restore values captured before PowerPilot first changed them. */
function restoreNetworkSettings(array &$state): array
{
    $restored = [];
    $errors = [];
    foreach ($state['network_original'] ?? [] as $path => $value) {
        if (!is_file($path) || !is_writable($path) || @file_put_contents($path, (string)$value . "\n") === false) {
            $errors[] = "Could not restore $path";
            continue;
        }
        $restored[] = $path;
        unset($state['network_original'][$path]);
    }

    if (empty($state['network_original'])) {
        unset($state['network_original']);
    }

    return ['restored' => $restored, 'errors' => $errors];
}
