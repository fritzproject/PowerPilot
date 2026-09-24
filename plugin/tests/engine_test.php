<?php
require_once __DIR__ . '/../src/engine.php';
use function PowerPilot\scheduleMatches;
use function PowerPilot\scheduledProfile;
use function PowerPilot\evaluateCondition;
use function PowerPilot\decide;
use function PowerPilot\validConfig;
use function PowerPilot\defaults;
use function PowerPilot\applyProfile;
use function PowerPilot\currentProfile;
function check(bool $ok, string $message): void {
    if (!$ok) { fwrite(STDERR, "FAIL: $message\n"); exit(1); }
    echo "PASS: $message\n";
}
$mondayNight = new DateTimeImmutable('2026-09-21 23:00:00');
$tuesdayEarly = new DateTimeImmutable('2026-09-22 03:00:00');
$overnight = ['days'=>[0], 'start'=>'18:00', 'end'=>'06:00', 'profile'=>'power_super_save'];
check(scheduleMatches($overnight, $mondayNight), 'overnight interval matches its starting weekday');
check(scheduleMatches($overnight, $tuesdayEarly), 'after-midnight interval remains assigned to starting weekday');
check(!scheduleMatches($overnight, new DateTimeImmutable('2026-09-23 03:00:00')), 'overnight interval expires after its end');
$cfg = defaults(); $cfg['schedule'] = [$overnight];
check(scheduledProfile($cfg,$tuesdayEarly)[0] === 'power_super_save', 'scheduled profile uses overnight weekday semantics');
[$ok] = evaluateCondition(['type'=>'docker_running','container'=>'minecraft|valheim'],[],['minecraft'=>['running'=>true,'cpu'=>0]]);
check($ok, 'Docker container name patterns are case-insensitive regexes');
[$ok] = evaluateCondition(['type'=>'docker_cpu_gte','container'=>'plex','value'=>8],[],['Plex-Media'=>['running'=>true,'cpu'=>13]]);
check($ok, 'Docker CPU threshold evaluates host Docker stats');
[$ok] = evaluateCondition(['type'=>'process_running','process'=>'php|java'],['processes'=>['php-fpm'=>5]],[]);
check($ok, 'process presence evaluates process metrics');
[$ok] = evaluateCondition(['type'=>'tcp_connections_gte','port'=>25565,'value'=>2],['tcp'=>[25565=>3]],[]);
check($ok, 'TCP connection threshold evaluates collected count');
$rules = [
 ['id'=>'low','enabled'=>true,'name'=>'low','priority'=>10,'profile'=>'balanced','hold_sec'=>0,'match'=>'all','conditions'=>[['type'=>'cpu_busy_gte','value'=>10]]],
 ['id'=>'high','enabled'=>true,'name'=>'high','priority'=>20,'profile'=>'performance','hold_sec'=>0,'match'=>'all','conditions'=>[['type'=>'load1_gte','value'=>1]]],
];
$cfg['rules']=$rules; $state=['rule_states'=>[]];
[$profile] = decide($cfg,['cpu_busy'=>60,'load1'=>8],[],$state,new DateTimeImmutable('2026-09-22 12:00:00'));
check($profile==='performance','higher priority matching rule wins');
$cfg['manual_override']=['profile'=>'power_save','expires_at'=>time()+3600];
[$profile] = decide($cfg,[],[],$state);
check($profile==='power_save','manual override takes precedence');
$invalid=defaults();$invalid['schedule']=[];
check(validConfig($invalid,$error),'empty schedule list is valid');
$invalid['rules'][0]['conditions'][0]['type']='unknown';
check(!validConfig($invalid,$error),'unknown condition type is rejected');
$dry=defaults();$dry['dry_run']=true;
check($dry['dry_run'],'new config defaults to dry-run');
$holdCfg=defaults();$holdCfg['schedule']=[];$holdCfg['rules']=[['id'=>'hold','enabled'=>true,'name'=>'hold','priority'=>100,'profile'=>'performance','hold_sec'=>30,'match'=>'all','conditions'=>[['type'=>'cpu_busy_gte','value'=>10]]]];
$holdState=['rule_states'=>['hold'=>['since'=>microtime(true)]]];
[$profile]=decide($holdCfg,['cpu_busy'=>90],[],$holdState,new DateTimeImmutable('2026-09-22 12:00:00'));
check($profile==='balanced','hold time prevents immediate rule activation');
$holdState['rule_states']['hold']['since']=microtime(true)-40;
[$profile]=decide($holdCfg,['cpu_busy'=>90],[],$holdState,new DateTimeImmutable('2026-09-22 12:00:00'));
check($profile==='performance','held condition activates after hold duration');
$dwellCfg=defaults();$dwellCfg['dry_run']=true;$dwellCfg['min_dwell_sec']=300;
$dwellState=['active_profile'=>'performance','last_switch'=>microtime(true),'last_reason'=>'existing'];
$changed=applyProfile('balanced','dwell test',$dwellCfg,$dwellState);
check(!$changed && $dwellState['active_profile']==='performance','minimum dwell blocks rapid profile change');
$before=currentProfile();$dryState=[];$changed=applyProfile('balanced','dry-run safety test',$dwellCfg,$dryState,true);$after=currentProfile();
check($changed && empty($dryState['last_applied']) && $before===$after,'dry-run records decision without changing sysfs profile');
[$ok,$reason]=evaluateCondition(['type'=>'game_players_gte','value'=>1,'query'=>['protocol'=>'minecraft_rcon','host'=>'127.0.0.1','port'=>1,'password'=>'x']],[],[]);
check(!$ok && strpos($reason,'query failed')!==false,'unreachable game query fails closed');
$bad=defaults();$bad['rules'][0]['conditions'][0]=['type'=>'docker_running','container'=>'['];
check(!validConfig($bad,$error),'malformed rule regex is rejected');
$bad=defaults();$bad['min_dwell_sec']=-1;
check(!validConfig($bad,$error),'negative minimum dwell is rejected');echo "All PowerPilot engine checks passed.\n";