<?php
declare(strict_types=1);

namespace PowerPilot;

const VERSION = '0.3.1';
const CONFIG_DIR = '/boot/config/plugins/powerpilot';
const CONFIG_FILE = CONFIG_DIR . '/config.json';
const DATA_DIR = '/mnt/user/appdata/powerpilot';
const STATE_FILE = DATA_DIR . '/state.json';
const HISTORY_FILE = DATA_DIR . '/history.jsonl';
const SYS_CPU = '/sys/devices/system/cpu';

require_once __DIR__ . '/network.php';

function defaults(): array
{
    return [
        'enabled' => true, 'dry_run' => true, 'check_interval_sec' => 10,
        'min_dwell_sec' => 300, 'default_profile' => 'balanced', 'manual_override' => null,
        'network' => [
            'tcp_enabled' => false,
            'irq_enabled' => false,
            'interfaces' => [],
            'reserved_cpus' => [0],
            'live_enabled' => false,
        ],
        'schedule' => [
            ['days' => [0,1,2,3,4,5,6], 'start' => '06:00', 'end' => '18:00', 'profile' => 'performance'],
            ['days' => [0,1,2,3,4,5,6], 'start' => '18:00', 'end' => '06:00', 'profile' => 'power_super_save'],
        ],
        'profiles' => [
            'performance' => ['governor' => 'performance', 'epp' => 'performance'],
            'balanced' => ['governor' => 'powersave', 'epp' => 'balance_performance'],
            'power_save' => ['governor' => 'powersave', 'epp' => 'balance_power'],
            'power_super_save' => ['governor' => 'powersave', 'epp' => 'power'],
        ],
        'rules' => [
            ['id'=>'high-cpu','name'=>'High CPU activity','enabled'=>true,'priority'=>90,'profile'=>'performance','hold_sec'=>30,'match'=>'all','conditions'=>[['type'=>'cpu_busy_gte','value'=>45]]],
            ['id'=>'heavy-io','name'=>'Heavy disk I/O','enabled'=>true,'priority'=>80,'profile'=>'balanced','hold_sec'=>30,'match'=>'all','conditions'=>[['type'=>'io_mbps_gte','value'=>200]]],
        ],
    ];
}

function ensureDirs(): void
{
    foreach ([CONFIG_DIR, DATA_DIR] as $dir) {
        if (!is_dir($dir) && !@mkdir($dir, 0770, true) && !is_dir($dir)) {
            throw new \RuntimeException("Cannot create data directory: $dir");
        }
    }
}

function atomicJson(string $path, array $value): void
{
    $tmp = $path . '.tmp.' . getmypid();
    $json = json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($json === false || file_put_contents($tmp, $json . "\n", LOCK_EX) === false || !rename($tmp, $path)) {
        @unlink($tmp);
        throw new \RuntimeException("Cannot write $path");
    }
    @chmod($path, 0600);
}

function loadConfig(): array
{
    ensureDirs();
    if (!is_file(CONFIG_FILE)) {
        $legacy = '/mnt/user/appdata/dynamic-power-manager/config.json';
        $cfg = is_file($legacy) ? json_decode((string)file_get_contents($legacy), true) : null;
        if (!is_array($cfg) || !validConfig($cfg, $error)) {
            $cfg = defaults();
        } else {
            $cfg = configWithDefaults($cfg);
        }
        $cfg['dry_run'] = true;
        $cfg['network']['live_enabled'] = false;
        atomicJson(CONFIG_FILE, $cfg);
        return $cfg;
    }
    $cfg = json_decode((string)file_get_contents(CONFIG_FILE), true);
    if (!is_array($cfg) || !validConfig($cfg, $error)) {
        throw new \RuntimeException('Invalid config: ' . ($error ?? 'JSON parse error'));
    }
    return configWithDefaults($cfg);
}

function configWithDefaults(array $cfg): array
{
    $defaults = defaults();
    $merged = array_replace($defaults, $cfg);
    $merged['network'] = array_replace($defaults['network'], $cfg['network'] ?? []);
    return $merged;
}
function saveConfig(array $cfg): void
{
    if (!validConfig($cfg, $error)) throw new \InvalidArgumentException($error);
    ensureDirs();
    atomicJson(CONFIG_FILE, $cfg);
}

function regexMatches(string $pattern,string $subject): bool
{
    if(strlen($pattern)>256)return false;
    return @preg_match('~'.str_replace('~','\~',$pattern).'~i',$subject)===1;
}
function regexIsValid(string $pattern): bool
{
    return strlen($pattern)<=256 && @preg_match('~'.str_replace('~','\~',$pattern).'~i','')!==false;
}
function validConfig(array $cfg, ?string &$error = null): bool
{
    $profiles = ['performance','balanced','power_save','power_super_save'];
    foreach (['profiles','schedule','rules','default_profile'] as $key) {
        if (!array_key_exists($key, $cfg)) { $error = "Missing config key: $key"; return false; }
    }
    if (!in_array($cfg['default_profile'], $profiles, true)) { $error='Invalid default profile'; return false; }
    if (!is_array($cfg['profiles']) || !is_array($cfg['schedule']) || !is_array($cfg['rules'])) { $error='Profiles, schedule and rules must be lists/objects'; return false; }
    $network = $cfg['network'] ?? defaults()['network'];
    if (!is_array($network)
        || (isset($network['tcp_enabled']) && !is_bool($network['tcp_enabled']))
        || (isset($network['irq_enabled']) && !is_bool($network['irq_enabled']))
        || (isset($network['live_enabled']) && !is_bool($network['live_enabled']))
        || !is_array($network['interfaces'] ?? [])
        || !is_array($network['reserved_cpus'] ?? [0])) {
        $error = 'Invalid network tuning configuration';
        return false;
    }
    foreach ($network['interfaces'] ?? [] as $interface) {
        if (!is_string($interface) || !preg_match('/^[a-zA-Z0-9_.:-]{1,16}$/', $interface)) {
            $error = 'Invalid network interface name';
            return false;
        }
    }
    foreach ($network['reserved_cpus'] ?? [0] as $cpu) {
        if (!is_int($cpu) || $cpu < 0 || $cpu > 4095) {
            $error = 'Reserved CPU IDs must be integers from 0 to 4095';
            return false;
        }
    }
    $interval=(int)($cfg['check_interval_sec']??10); $dwell=(int)($cfg['min_dwell_sec']??300);
    if($interval<2||$interval>3600||$dwell<0||$dwell>86400){$error='Invalid controller timing values';return false;}
    if(isset($cfg['manual_override'])&&$cfg['manual_override']!==null&&!in_array($cfg['manual_override']['profile']??'',$profiles,true)){$error='Invalid manual override';return false;}
    foreach ($profiles as $id) {
        $spec = $cfg['profiles'][$id] ?? null;
        if (!is_array($spec) || !in_array($spec['governor'] ?? '', ['performance','powersave','schedutil','ondemand'], true)
            || !in_array($spec['epp'] ?? '', ['performance','balance_performance','balance_power','power'], true)) {
            $error = "Invalid profile mapping: $id"; return false;
        }
    }
    foreach ($cfg['schedule'] as $s) {
        if (!is_array($s) || !validTime($s['start'] ?? '') || !validTime($s['end'] ?? '')
            || !in_array($s['profile'] ?? '', $profiles, true) || !is_array($s['days'] ?? null)) { $error='Invalid schedule'; return false; }
        foreach ($s['days'] as $day) if (!is_numeric($day) || (int)$day < 0 || (int)$day > 6) { $error='Schedule weekdays must be 0–6'; return false; }
    }
    $types = ['cpu_busy_gte','load1_gte','memory_used_gte','io_mbps_gte','network_mbps_gte','docker_running','docker_cpu_gte','process_running','process_cpu_gte','tcp_connections_gte','game_players_gte'];
    $ids = [];
    foreach ($cfg['rules'] as $r) {
        if (!is_array($r) || empty($r['id']) || !preg_match('/^[a-zA-Z0-9_-]{1,64}$/', (string)$r['id'])
            || in_array($r['id'], $ids, true) || !in_array($r['profile'] ?? '', $profiles, true)
            || !in_array($r['match'] ?? 'all', ['all','any'], true) || !is_array($r['conditions'] ?? null) || !$r['conditions']) {
            $error='Invalid rule definition or duplicate rule ID'; return false;
        }
        $ids[] = $r['id'];
        foreach ($r['conditions'] as $c) {
            if (!is_array($c) || !in_array($c['type'] ?? '', $types, true)) { $error='Unknown rule condition'; return false; }
            if (in_array($c['type'], ['docker_running','docker_cpu_gte'], true)) {
                if(empty($c['container'])){$error='Container pattern is required';return false;}
                if(!regexIsValid((string)$c['container'])){$error='Invalid container regular expression';return false;}
            }
            if (in_array($c['type'], ['process_running','process_cpu_gte'], true)) {
                if(empty($c['process'])){$error='Process pattern is required';return false;}
                if(!regexIsValid((string)$c['process'])){$error='Invalid process regular expression';return false;}
            }
            if (isset($c['value']) && (!is_numeric($c['value']) || (float)$c['value'] < 0)) { $error='Condition value must be a non-negative number'; return false; }
            if ($c['type'] === 'tcp_connections_gte' && ((int)($c['port'] ?? 0) < 1 || (int)$c['port'] > 65535)) { $error='Invalid TCP port'; return false; }
            if ($c['type'] === 'game_players_gte') {
                $q = $c['query'] ?? [];
                if (!is_array($q) || !in_array($q['protocol'] ?? '', ['steam_a2s','minecraft_rcon'], true)
                    || empty($q['host']) || (int)($q['port'] ?? 0) < 1 || (int)$q['port'] > 65535
                    || (($q['protocol'] ?? '') === 'minecraft_rcon' && !isset($q['password']))) { $error='Invalid game query'; return false; }
            }
        }
    }
    return true;
}

function validTime(string $value): bool { return (bool)preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $value); }

function loadState(): array
{
    $state = json_decode((string)@file_get_contents(STATE_FILE), true);
    return is_array($state) ? $state : ['active_profile'=>null,'last_switch'=>0,'last_reason'=>'Waiting for first evaluation','rule_states'=>[],'samples'=>[],'metrics'=>[]];
}

function scheduleMatches(array $rule, \DateTimeImmutable $now): bool
{
    $start = (string)$rule['start']; $end = (string)$rule['end']; $current = $now->format('H:i');
    $days = array_map('intval', $rule['days'] ?? []); $weekday = (int)$now->format('N') - 1;
    if ($start === $end) return in_array($weekday, $days, true);
    if ($start < $end) return in_array($weekday, $days, true) && $current >= $start && $current < $end;
    if ($current >= $start) return in_array($weekday, $days, true);
    if ($current < $end) return in_array(($weekday + 6) % 7, $days, true);
    return false;
}

function scheduledProfile(array $cfg, \DateTimeImmutable $now): array
{
    foreach ($cfg['schedule'] as $s) if (scheduleMatches($s, $now)) return [$s['profile'], "schedule {$s['start']}–{$s['end']}"];
    return [$cfg['default_profile'], 'default profile'];
}

function evaluateCondition(array $c, array $m, array $containers): array
{
    $type = $c['type']; $target = (float)($c['value'] ?? 0); $ok = false; $actual = 0.0; $desc = $type;
    if (in_array($type, ['cpu_busy_gte','load1_gte','memory_used_gte','io_mbps_gte','network_mbps_gte'], true)) {
        $key = ['cpu_busy_gte'=>'cpu_busy','load1_gte'=>'load1','memory_used_gte'=>'memory_used','io_mbps_gte'=>'io_mbps','network_mbps_gte'=>'network_mbps'][$type];
        $actual=(float)($m[$key] ?? 0); $ok=$actual >= $target; $unit=$type === 'load1_gte' ? '' : ($type === 'network_mbps_gte' ? ' Mb/s' : ($type === 'io_mbps_gte' ? ' MB/s' : '%'));
        $desc=sprintf('%s %.1f%s %s %.1f%s', $key, $actual, $unit, $ok?'≥':'<', $target, $unit);
    } elseif (in_array($type, ['docker_running','docker_cpu_gte'], true)) {
        $pattern=(string)$c['container']; $hits=[];
        foreach ($containers as $name=>$stats) if (regexMatches($pattern,(string)$name)) $hits[$name]=$stats;
        if ($type === 'docker_running') { $ok=false; foreach ($hits as $s) $ok=$ok || !empty($s['running']); $desc='container /'.$pattern.'/ '.($ok?'running':'not running'); }
        else { foreach ($hits as $s) $actual=max($actual,(float)($s['cpu']??0)); $ok=$actual >= $target; $desc=sprintf('Docker /%s/ CPU %.1f%% %s %.1f%%',$pattern,$actual,$ok?'≥':'<',$target); }
    } elseif (in_array($type, ['process_running','process_cpu_gte'], true)) {
        $pattern=(string)$c['process']; $hits=[];
        foreach (($m['processes'] ?? []) as $name=>$cpu) if (regexMatches($pattern,(string)$name)) $hits[$name]=(float)$cpu;
        if ($type === 'process_running') { $ok=(bool)$hits; $desc='process /'.$pattern.'/ '.($ok?'found':'not found'); }
        else { $actual=$hits ? max($hits) : 0; $ok=$actual >= $target; $desc=sprintf('process /%s/ CPU %.1f%% %s %.1f%%',$pattern,$actual,$ok?'≥':'<',$target); }
    } elseif ($type === 'tcp_connections_gte') {
        $actual=(float)($m['tcp'][$c['port']] ?? 0); $target=(int)($c['value']??1); $ok=$actual >= $target; $desc=sprintf('TCP :%d connections %.0f %s %.0f',$c['port'],$actual,$ok?'≥':'<',$target);
    } elseif ($type === 'game_players_gte') {
        try { $q=$c['query']; $result=$q['protocol']==='steam_a2s' ? steamA2s($q['host'],(int)$q['port']) : minecraftRcon($q['host'],(int)$q['port'],(string)$q['password']); $actual=(float)$result['players']; $ok=$actual >= $target; $desc=sprintf('%s %s:%d players=%.0f %s %.0f',$q['protocol'],$q['host'],$q['port'],$actual,$ok?'≥':'<',$target); }
        catch (\Throwable $e) { return [false, ($c['query']['protocol']??'game').' query failed: '.$e->getMessage()]; }
    }
    return [$ok,$desc];
}

function decide(array $cfg, array $metrics, array $containers, array &$state, ?\DateTimeImmutable $now=null): array
{
    $now=$now??new \DateTimeImmutable('now'); $manual=$cfg['manual_override']??null;
    if (is_array($manual)) {
        if (empty($manual['expires_at']) || time() < (int)$manual['expires_at']) return [$manual['profile'],'manual override'];
    }
    [$base,$baseReason]=scheduledProfile($cfg,$now); $candidates=[]; $mono=microtime(true);
    foreach ($cfg['rules'] as $r) {
        if (empty($r['enabled'])) { $state['rule_states'][$r['id']]['since']=null; continue; }
        $parts=[]; $matches=[];
        foreach ($r['conditions'] as $c) { [$conditionMatch,$reason]=evaluateCondition($c,$metrics,$containers); $matches[]=$conditionMatch; $parts[]=$reason; }
        $matched=($r['match']??'all')==='any' ? in_array(true,$matches,true) : !in_array(false,$matches,true);
        $ruleState=$state['rule_states'][$r['id']]??['since'=>null];
        if ($matched) {
            if ($ruleState['since']===null) $ruleState['since']=$mono;
            if ($mono-(float)$ruleState['since'] >= (int)($r['hold_sec']??0)) $candidates[]=['priority'=>(int)($r['priority']??0),'profile'=>$r['profile'],'reason'=>'rule: '.($r['name']??$r['id']).' — '.implode('; ',$parts)];
        } else $ruleState['since']=null;
        $state['rule_states'][$r['id']]=$ruleState;
    }
    if ($candidates) { usort($candidates,fn($a,$b)=>$b['priority']<=>$a['priority']); return [$candidates[0]['profile'],$candidates[0]['reason']]; }
    return [$base,$baseReason];
}

function readProcMetrics(array &$state): array
{
    $now=microtime(true); $cpuLine=@file_get_contents('/proc/stat'); preg_match('/^cpu\s+(.+)$/m',(string)$cpuLine,$cm); $cpu=0.0;
    if (!empty($cm[1])) { $v=array_map('intval',preg_split('/\s+/',trim($cm[1]))); $idle=$v[3]+($v[4]??0); $total=array_sum($v); $old=$state['samples']['cpu']??null; if ($old && $total>$old['total']) $cpu=100*(1-($idle-$old['idle'])/($total-$old['total'])); $state['samples']['cpu']=['idle'=>$idle,'total'=>$total]; }
    $mem=@file_get_contents('/proc/meminfo'); preg_match('/MemTotal:\s+(\d+)/',(string)$mem,$mt); preg_match('/MemAvailable:\s+(\d+)/',(string)$mem,$ma); $memory=isset($mt[1],$ma[1])&&$mt[1]>0 ? 100*(1-$ma[1]/$mt[1]) : 0;
    $disk=[]; foreach (file('/proc/diskstats', FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES) ?: [] as $line) { $p=preg_split('/\s+/',trim($line)); if (count($p)<14 || preg_match('/^(loop|ram|md|dm-|zram|sr)/',$p[2])) continue; $disk[$p[2]]=['read'=>(int)$p[5]*512,'write'=>(int)$p[9]*512]; }
    $old=$state['samples']['disk']??null; $io=0.0; if ($old) { $bytes=0; foreach ($disk as $n=>$d) if(isset($old[$n])) $bytes+=max(0,$d['read']-$old[$n]['read'])+max(0,$d['write']-$old[$n]['write']); $io=$bytes/max(0.25,$now-(float)$state['samples']['time'])/1048576; } $state['samples']['disk']=$disk;
    $net=[]; foreach (array_slice(file('/proc/net/dev',FILE_IGNORE_NEW_LINES)?:[],2) as $line) { if (!str_contains($line,':')) continue; [$name,$values]=array_map('trim',explode(':',$line,2)); $v=preg_split('/\s+/',trim($values)); $net[$name]=(int)$v[0]+(int)$v[8]; }
    $oldNet=$state['samples']['net']??null; $mbps=0.0; if($oldNet){$b=0;foreach($net as $n=>$v)if(isset($oldNet[$n]))$b+=max(0,$v-$oldNet[$n]);$mbps=$b*8/max(.25,$now-(float)$state['samples']['time'])/1000000;}
    $state['samples']['net']=$net; $state['samples']['time']=$now;
    $tcp=[]; foreach (['/proc/net/tcp','/proc/net/tcp6'] as $f) foreach(array_slice(file($f,FILE_IGNORE_NEW_LINES)?:[],1) as $line){$p=preg_split('/\s+/',trim($line));if(count($p)<4||$p[3]!=='01')continue;$addr=explode(':',$p[1]);$port=hexdec(end($addr));$tcp[$port]=($tcp[$port]??0)+1;}
    $proc=[]; foreach (glob('/proc/[0-9]*/comm')?:[] as $file) {$name=trim((string)@file_get_contents($file));if($name!=='')$proc[$name]=max((float)($proc[$name]??0),0.0);}
    $load=sys_getloadavg(); return ['cpu_busy'=>$cpu,'load1'=>(float)($load[0]??0),'memory_used'=>$memory,'io_mbps'=>$io,'network_mbps'=>$mbps,'uptime_sec'=>(float)(explode(' ',(string)@file_get_contents('/proc/uptime'))[0]??0),'tcp'=>$tcp,'processes'=>$proc,'docker_count'=>0,'docker_running'=>0];
}

function dockerStats(): array
{
    if (!is_executable('/usr/bin/docker') && !is_executable('/usr/local/bin/docker') && trim((string)shell_exec('command -v docker 2>/dev/null'))==='') return [];
    $rows=[]; $out=[]; $code=0; @exec("docker ps -a --format '{{.Names}}|{{.State}}' 2>/dev/null",$out,$code); if($code!==0)return [];
    foreach($out as $line){[$name,$status]=array_pad(explode('|',$line,2),2,'');$rows[$name]=['running'=>$status==='running','cpu'=>0.0];}
    $stats=[]; @exec("docker stats --no-stream --format '{{.Name}}|{{.CPUPerc}}' 2>/dev/null",$stats,$code);
    foreach($stats as $line){[$name,$pct]=array_pad(explode('|',$line,2),2,'0');if(isset($rows[$name]))$rows[$name]['cpu']=(float)str_replace('%','',$pct);}
    return $rows;
}

function steamA2s(string $host,int $port): array
{
    $s=@stream_socket_client("udp://$host:$port",$errno,$errstr,2,STREAM_CLIENT_CONNECT);if(!$s)throw new \RuntimeException('connection failed');
    stream_set_timeout($s,2);$packet="\xff\xff\xff\xffTSource Engine Query\0";fwrite($s,$packet);$data=fread($s,4096);
    if(strlen($data)>=9 && substr($data,0,5)==="\xff\xff\xff\xffA"){$challenge=substr($data,5,4);fwrite($s,$packet."\xff".$challenge);$data=fread($s,4096);}
    fclose($s);if(strlen($data)<6||substr($data,0,5)!=="\xff\xff\xff\xffI")throw new \RuntimeException('invalid A2S response');
    $pos=6;for($i=0;$i<4;$i++){$end=strpos($data,"\0",$pos);if($end===false)throw new \RuntimeException('malformed A2S response');$pos=$end+1;}
    if(strlen($data)<$pos+5)throw new \RuntimeException('short A2S response');return ['players'=>ord($data[$pos+2])];
}
function readExact($s,int $n): string { $out='';while(strlen($out)<$n){$part=fread($s,$n-strlen($out));if($part===false||$part==='')throw new \RuntimeException('RCON connection closed');$out.=$part;}return $out; }
function rconPacket($s,int $id,int $type,string $body): string { $payload=pack('V2',$id,$type).$body."\0\0";fwrite($s,pack('V',strlen($payload)).$payload);$n=unpack('V',readExact($s,4))[1];if($n<10||$n>65536)throw new \RuntimeException('invalid RCON packet size');return readExact($s,$n); }
function minecraftRcon(string $host,int $port,string $password): array
{
    $s=@stream_socket_client("tcp://$host:$port",$errno,$errstr,2,STREAM_CLIENT_CONNECT);if(!$s)throw new \RuntimeException('connection failed');stream_set_timeout($s,2);
    try{$auth=rconPacket($s,1,3,$password);if(strlen($auth)<8||unpack('V',substr($auth,4,4))[1]!==1)throw new \RuntimeException('authentication failed');$resp=rconPacket($s,2,2,'list');$body=substr($resp,8,-2);if(!preg_match('/There are (\d+) of a max/i',$body,$m))throw new \RuntimeException('unrecognized RCON response');return ['players'=>(int)$m[1]];}finally{fclose($s);}
}

function currentProfile(): array
{
    $read=fn($f)=>is_readable($f)?trim((string)file_get_contents($f)):null;
    return ['governor'=>$read(SYS_CPU.'/cpu0/cpufreq/scaling_governor'),'epp'=>$read(SYS_CPU.'/cpu0/cpufreq/energy_performance_preference')];
}

function applyProfile(string $profile,string $reason,array $cfg,array &$state,bool $force=false): bool
{
    if(!isset($cfg['profiles'][$profile]))throw new \InvalidArgumentException('Unknown profile');
    if(!$force&&$profile===($state['active_profile']??null)&&(!empty($cfg['dry_run'])||!empty($state['last_applied']))){$state['last_reason']=$reason;return false;}
    if(!$force&&!empty($state['active_profile'])&&microtime(true)-(float)($state['last_switch']??0)<(int)$cfg['min_dwell_sec']){$state['last_reason']=$reason.' (minimum dwell in progress)';$state['pending_profile']=$profile;return false;}
    $applied=false; $errors=[];
    if(empty($cfg['dry_run'])) {
        $spec=$cfg['profiles'][$profile];
        $governorFiles=glob(SYS_CPU.'/cpu[0-9]*/cpufreq/scaling_governor')?:[];
        $eppFiles=glob(SYS_CPU.'/cpu[0-9]*/cpufreq/energy_performance_preference')?:[];
        if(!$governorFiles)$errors[]='no CPU governor sysfs files found';
        if(!$eppFiles)$errors[]='no CPU EPP sysfs files found';
        foreach($governorFiles as $file)if(@file_put_contents($file,$spec['governor'])===false)$errors[]="write failed: $file";
        foreach($eppFiles as $file)if(@file_put_contents($file,$spec['epp'])===false)$errors[]="write failed: $file";
        $applied=!$errors;
        if($errors){$reason.='; apply errors: '.implode(', ',array_slice($errors,0,3));}
    }
    $state['active_profile']=$profile;$state['last_switch']=microtime(true);$state['last_reason']=$reason;$state['last_applied']=$applied;$state['pending_profile']=null;
    appendEvent(['ts'=>date(DATE_ATOM),'profile'=>$profile,'reason'=>$reason,'applied'=>$applied,'dry_run'=>!empty($cfg['dry_run']),'metrics'=>$state['metrics']??[]]);
    return true;
}

function appendEvent(array $event): void
{
    ensureDirs();$json=json_encode($event,JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE);if($json!==false)file_put_contents(HISTORY_FILE,$json."\n",FILE_APPEND|LOCK_EX);
}

function networkConfigHash(array $network): string
{
    $relevant = [
        'tcp_enabled' => !empty($network['tcp_enabled']),
        'irq_enabled' => !empty($network['irq_enabled']),
        'interfaces' => array_values($network['interfaces'] ?? []),
        'reserved_cpus' => array_values($network['reserved_cpus'] ?? [0]),
    ];
    return hash('sha256', (string)json_encode($relevant));
}

function applyConfiguredNetwork(array $cfg, array &$state): void
{
    $network = $cfg['network'] ?? defaults()['network'];
    if (empty($network['live_enabled']) || (empty($network['tcp_enabled']) && empty($network['irq_enabled']))) {
        return;
    }

    $plan = networkPlan($cfg);
    if ($plan['autotweak_detected']) {
        $restore = restoreNetworkSettings($state);
        $cfg['network']['live_enabled'] = false;
        saveConfig($cfg);
        $state['network_status'] = [
            'live_enabled' => false,
            'applied' => false,
            'errors' => array_merge(['AutoTweak detected; network tuning disabled to avoid competing writes.'], $restore['errors']),
            'updated_at' => date(DATE_ATOM),
        ];
        return;
    }

    $bootId = networkReadValue('/proc/sys/kernel/random/boot_id') ?? 'unknown';
    $configHash = networkConfigHash($network);
    $previous = $state['network_status'] ?? [];
    $newBoot = ($previous['boot_id'] ?? null) !== $bootId;
    $changedConfig = ($previous['config_hash'] ?? null) !== $configHash;
    if (!$newBoot && !$changedConfig && !empty($previous['applied'])) {
        return;
    }

    if ($newBoot) {
        unset($state['network_original']);
    } elseif ($changedConfig) {
        restoreNetworkSettings($state);
    }

    $result = applyNetworkPlan($cfg, $state);
    $state['network_status'] = [
        'live_enabled' => true,
        'applied' => $result['applied'],
        'errors' => $result['errors'],
        'updated_at' => date(DATE_ATOM),
    ];
    $state['network_status']['boot_id'] = $bootId;
    $state['network_status']['config_hash'] = $configHash;
}
function runTick(): array
{
    $cfg=loadConfig();$state=loadState();applyConfiguredNetwork($cfg,$state);$metrics=readProcMetrics($state);$containers=dockerStats();$metrics['docker_count']=count($containers);$metrics['docker_running']=count(array_filter($containers,fn($c)=>$c['running']));$metrics['processes']=processCpuMap();$state['metrics']=$metrics;
    if(!empty($cfg['manual_override']['expires_at']) && time() >= (int)$cfg['manual_override']['expires_at']) { $cfg['manual_override']=null; saveConfig($cfg); }
    if(!empty($cfg['enabled'])){[$profile,$reason]=decide($cfg,$metrics,$containers,$state);$state['desired_profile']=$profile;$state['decision_reason']=$reason;applyProfile($profile,$reason,$cfg,$state);}
    $state['next_reevaluation']=time()+max(2,(int)$cfg['check_interval_sec']);$state['config_error']=null;atomicJson(STATE_FILE,$state);return $state;
}

function processCpuMap(): array
{
    $out=[];$lines=[];@exec('ps -eo comm=,pcpu= 2>/dev/null',$lines);foreach($lines as $line){if(preg_match('/^\s*(\S+)\s+([0-9.]+)/',$line,$m))$out[$m[1]]=max((float)($out[$m[1]]??0),(float)$m[2]);}return $out;
}

function history(int $limit=100): array
{
    $lines=@file(HISTORY_FILE,FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES)?:[];$rows=[];
    foreach(array_slice($lines,-max(1,min(500,$limit))) as $line){$row=json_decode($line,true);if(is_array($row))$rows[]=$row;}
    return array_reverse($rows);
}

if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    $command=$argv[1]??'daemon';
    if($command==='restore-network'){$state=loadState();$hadState=is_file(STATE_FILE);$hadOriginals=!empty($state['network_original']);$result=restoreNetworkSettings($state);if($hadState||$hadOriginals)atomicJson(STATE_FILE,$state);if($result['errors']){fwrite(STDERR,implode(PHP_EOL,$result['errors']).PHP_EOL);exit(1);}exit(0);}
    if($command==='tick'){try{runTick();}catch(\Throwable $e){fwrite(STDERR,$e->getMessage()."\n");exit(1);}exit(0);}
    if($command==='daemon') { while(true){try{runTick();}catch(\Throwable $e){error_log('[PowerPilot] '.$e->getMessage());$state=loadState();$state['config_error']=$e->getMessage();atomicJson(STATE_FILE,$state);} $cfg=is_file(CONFIG_FILE)?json_decode((string)file_get_contents(CONFIG_FILE),true):[];sleep(max(2,(int)($cfg['check_interval_sec']??10)));} }
    fwrite(STDERR,"Usage: php engine.php [daemon|tick]\n");exit(2);
}
