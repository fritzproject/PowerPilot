<?php
require_once __DIR__ . '/../src/network.php';
use function PowerPilot\networkExpandCpuList;
use function PowerPilot\networkCpuMask;
use function PowerPilot\networkTcpSettings;
use function PowerPilot\networkPlan;
use function PowerPilot\applyNetworkPlan;
use function PowerPilot\restoreNetworkSettings;

function check(bool $ok, string $message): void {
    if (!$ok) { fwrite(STDERR, "FAIL: $message\n"); exit(1); }
    echo "PASS: $message\n";
}
function put(string $path, string $value): void {
    if (!is_dir(dirname($path))) mkdir(dirname($path), 0777, true);
    file_put_contents($path, $value);
}
function removeTree(string $path): void {
    if (!is_dir($path)) { @unlink($path); return; }
    foreach (scandir($path) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') continue;
        removeTree($path . DIRECTORY_SEPARATOR . $entry);
    }
    rmdir($path);
}

check(networkExpandCpuList('0-2,5,8-9') === [0,1,2,5,8,9], 'Linux CPU lists expand and sort');
check(networkCpuMask(32) === '00000001,00000000', 'XPS bitmap spans 32-bit words correctly');
check(networkTcpSettings(1000)['net.core.rmem_max'] === '16777216', '1 GbE receives the 16 MiB TCP buffer tier');
check(networkTcpSettings(10000)['net.core.rmem_max'] === '67108864', '10 GbE receives the 64 MiB socket buffer tier');

$base = sys_get_temp_dir() . '/powerpilot-network-' . bin2hex(random_bytes(5));
$sys = $base . '/sys'; $proc = $base . '/proc';
put("$sys/devices/system/cpu/online", "0-3\n");
put("$sys/class/net/eno1/device/local_cpulist", "0-3\n");
put("$sys/class/net/eno1/device/numa_node", "0\n");
put("$sys/class/net/eno1/speed", "10000\n");
put("$sys/class/net/eno1/queues/tx-0/xps_cpus", "00000000\n");
put("$sys/class/net/eno1/queues/rx-0/rps_cpus", "00000000\n");
put("$sys/class/net/eno1/device/msi_irqs/42", "\n");
mkdir("$proc/irq/42", 0777, true);
put("$proc/irq/42/smp_affinity_list", "0-3\n");
$tcp = [
    'net/core/rmem_max'=>'212992', 'net/core/wmem_max'=>'212992',
    'net/ipv4/tcp_rmem'=>'4096 131072 6291456', 'net/ipv4/tcp_wmem'=>'4096 16384 4194304',
    'net/ipv4/tcp_mtu_probing'=>'0', 'net/ipv4/tcp_slow_start_after_idle'=>'1',
];
foreach ($tcp as $path=>$value) put("$proc/sys/$path", "$value\n");
$config = ['network'=>['tcp_enabled'=>true,'irq_enabled'=>true,'interfaces'=>['eno1'],'reserved_cpus'=>[0],'live_enabled'=>false]];
$plan = networkPlan($config, $sys, $proc);
check(count($plan['available_interfaces']) === 1, 'physical NIC discovery sees simulated eno1');
check($plan['irq']['interfaces'][0]['candidate_cpus'] === [1,2,3], 'CPU 0 is reserved from NIC placement');
check(file_get_contents("$proc/sys/net/core/rmem_max") === "212992\n", 'planning and dry-run do not write sysctl');
$state = [];
$result = applyNetworkPlan($config, $state, $sys, $proc);
check(!$result['applied'] && empty($state['network_original']), 'dry-run refuses kernel writes and captures no values');
$config['network']['live_enabled'] = true;
$result = applyNetworkPlan($config, $state, $sys, $proc);
check($result['applied'], 'live plan writes available simulated kernel settings');
check(file_get_contents("$proc/irq/42/smp_affinity_list") === "1\n", 'IRQ affinity uses first eligible local CPU');
check(file_get_contents("$sys/class/net/eno1/queues/tx-0/xps_cpus") === "00000002\n", 'XPS mask maps transmit queue to assigned CPU');
$restored = restoreNetworkSettings($state);
check(!$restored['errors'] && count($restored['restored']) > 0, 'captured network values restore without errors');
check(file_get_contents("$proc/irq/42/smp_affinity_list") === "0-3\n", 'IRQ affinity is restored to its original value');
check(file_get_contents("$proc/sys/net/core/rmem_max") === "212992\n", 'TCP sysctl is restored to its original value');
removeTree($base);
