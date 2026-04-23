<?php
/**
 * ==========================================
 * API.PHP - API DE MÉTRICAS DO SERVIDOR
 * ==========================================
 * Aplicação : Sentinela Monitor CPU
 * Arquivo   : api.php
 * Função    : Coleta e retorna métricas em JSON
 *
 * Funcionalidades:
 * - CPU total e por core via /proc/stat (ARM e x86)
 * - Temperatura: Intel coretemp, AMD k10temp, ARM thermal_zone,
 *   NVIDIA GPU, fallback gracioso quando indisponível
 * - RAM via /proc/meminfo (mais preciso que free -m)
 * - Disco: espaço via disk_*_space()
 * - IO de disco: iostat com detecção de colunas (versão nova/antiga)
 * - Containers Docker: via socket sem sudo
 * - Frigate: opcional (não quebra se não existir)
 * - Hostname e IP para exibir no dashboard
 *
 * CORREÇÕES APLICADAS:
 * - Temperatura hardcoded para ARM → detecção multi-plataforma
 * - Frigate opcional (não retorna erro se container ausente)
 * - iostat: detecta coluna correta (r/s e w/s variam por versão)
 * - Sem dependência de sudo para Docker
 * ==========================================
 */

// ==========================================
// CORS — permite acesso do fleet.php
// (painel central em outro servidor da rede)
// ==========================================
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');
header('Content-Type: application/json');
error_reporting(0);

// ==========================================
// FUNÇÃO: executa comando com segurança
// ==========================================
function safeExec($cmd) {
    $out = @shell_exec($cmd . ' 2>/dev/null');
    return ($out !== null && trim($out) !== '') ? trim($out) : null;
}

// ==========================================
// CPU TOTAL + POR CORE
// ==========================================
// Lê /proc/stat duas vezes com intervalo para calcular delta.
// Funciona identicamente em ARM e x86.
$stat1 = @file('/proc/stat');
usleep(250000); // 250ms — mais preciso que 200ms
$stat2 = @file('/proc/stat');

$cpuUsage = 0;
$cores    = [];

if ($stat1 && $stat2) {
    foreach ($stat1 as $i => $line) {
        if (!preg_match('/^(cpu\d*)/', $line)) continue;

        $a = preg_split('/\s+/', trim($stat1[$i]));
        $b = preg_split('/\s+/', trim($stat2[$i]));

        // Campos: user nice system idle iowait irq softirq steal guest
        $idle1   = (int)($a[4] ?? 0);
        $idle2   = (int)($b[4] ?? 0);
        $total1  = array_sum(array_slice(array_map('intval', $a), 1));
        $total2  = array_sum(array_slice(array_map('intval', $b), 1));

        $dTotal  = $total2 - $total1;
        $dIdle   = $idle2  - $idle1;

        $usage = ($dTotal > 0) ? round(100 * ($dTotal - $dIdle) / $dTotal, 1) : 0;

        if ($a[0] === 'cpu') {
            $cpuUsage = $usage;
        } else {
            $coreId = (int)substr($a[0], 3);
            $cores["Core $coreId"] = $usage;
        }
    }
}

// ==========================================
// LOAD AVERAGE
// ==========================================
$load     = sys_getloadavg();
$load1m   = round($load[0] ?? 0, 2);
$load5m   = round($load[1] ?? 0, 2);
$load15m  = round($load[2] ?? 0, 2);

// ==========================================
// RAM via /proc/meminfo
// ==========================================
// Mais confiável que "free -m" em qualquer plataforma.
$ramTotal     = 0;
$ramAvailable = 0;
$ramUsed      = 0;

if (file_exists('/proc/meminfo')) {
    $meminfo = file_get_contents('/proc/meminfo');

    if (preg_match('/MemTotal:\s+(\d+)/i', $meminfo, $m))     $ramTotal     = (int)$m[1]; // kB
    if (preg_match('/MemAvailable:\s+(\d+)/i', $meminfo, $m)) $ramAvailable = (int)$m[1]; // kB

    $ramUsed = $ramTotal - $ramAvailable;
}

// Converte para MB para manter compatibilidade com o frontend
$ramTotalMB = (int)round($ramTotal / 1024);
$ramUsedMB  = (int)round($ramUsed  / 1024);

// ==========================================
// TEMPERATURA — MULTI-PLATAFORMA
// ==========================================
// Estratégia por prioridade:
// 1. Intel: coretemp via hwmon (mais preciso)
// 2. AMD:   k10temp via hwmon
// 3. NVIDIA GPU: nvidia-smi
// 4. ARM:   thermal_zone (várias zonas)
// 5. Qualquer zona thermal disponível
// 6. 0 (não disponível)

function detectarTemperatura() {

    // === 1. hwmon: Intel coretemp e AMD k10temp ===
    // Lê todos os sensores hwmon e procura coretemp ou k10temp
    $hwmonBase = '/sys/class/hwmon';
    if (is_dir($hwmonBase)) {
        $hwmons = glob($hwmonBase . '/hwmon*') ?: [];
        foreach ($hwmons as $hwmon) {
            $nameFile = $hwmon . '/name';
            if (!file_exists($nameFile)) continue;
            $name = trim(file_get_contents($nameFile));

            // Intel coretemp ou AMD k10temp
            if (in_array($name, ['coretemp', 'k10temp', 'cpu_thermal', 'acpitz'])) {
                // Procura temp1_input, temp2_input, etc.
                $tempFiles = glob($hwmon . '/temp*_input') ?: [];
                $temps = [];
                foreach ($tempFiles as $tf) {
                    $val = (int)@file_get_contents($tf);
                    if ($val > 0) $temps[] = $val / 1000; // millidegrees → °C
                }
                if (!empty($temps)) {
                    // Retorna a temperatura máxima dos cores
                    return round(max($temps), 1);
                }
            }
        }

        // Segunda passagem: qualquer hwmon com temp disponível
        foreach ($hwmons as $hwmon) {
            $tempFiles = glob($hwmon . '/temp*_input') ?: [];
            foreach ($tempFiles as $tf) {
                $val = (int)@file_get_contents($tf);
                if ($val > 10000 && $val < 120000) { // entre 10°C e 120°C
                    return round($val / 1000, 1);
                }
            }
        }
    }

    // === 2. NVIDIA GPU via nvidia-smi ===
    $nvTemp = safeExec('nvidia-smi --query-gpu=temperature.gpu --format=csv,noheader');
    if ($nvTemp !== null && is_numeric(trim($nvTemp))) {
        return (float)trim($nvTemp);
    }

    // === 3. ARM: thermal_zone (várias zonas) ===
    // Tenta zonas 0 a 9, retorna a mais quente relevante
    $thermalTemps = [];
    for ($z = 0; $z <= 9; $z++) {
        $zoneFile = "/sys/class/thermal/thermal_zone{$z}/temp";
        $typeFile = "/sys/class/thermal/thermal_zone{$z}/type";
        if (!file_exists($zoneFile)) continue;

        $val  = (int)@file_get_contents($zoneFile);
        $type = file_exists($typeFile) ? trim(@file_get_contents($typeFile)) : "zone$z";

        // Filtra valores absurdos
        if ($val > 10000 && $val < 120000) {
            $thermalTemps[$type] = $val / 1000;
        }
    }

    if (!empty($thermalTemps)) {
        // Prioriza tipos conhecidos de CPU/SoC
        // Jetson: 'CPU-therm', 'GPU-therm', 'AO-therm', 'PMIC-Die'
        foreach (['cpu-thermal', 'soc-thermal', 'cpu', 'pkg-temp-0',
                  'CPU-therm', 'AO-therm', 'Tboard_tegra', 'Tdiode_tegra'] as $preferred) {
            if (isset($thermalTemps[$preferred])) {
                return round($thermalTemps[$preferred], 1);
            }
        }
        // Fallback: máxima entre todas
        return round(max($thermalTemps), 1);
    }

    // === 4. sensors (lm-sensors) ===
    $sensors = safeExec('sensors -j');
    if ($sensors) {
        $data = json_decode($sensors, true);
        if ($data) {
            foreach ($data as $chip => $values) {
                foreach ($values as $key => $val) {
                    if (is_array($val)) {
                        foreach ($val as $k => $v) {
                            if (strpos(strtolower($k), 'temp') !== false && is_numeric($v)) {
                                if ($v > 10 && $v < 120) return round($v, 1);
                            }
                        }
                    }
                }
            }
        }
    }

    return 0; // Não disponível
}

$temp = detectarTemperatura();

// ==========================================
// DISCO — ESPAÇO
// ==========================================
$diskTotal = round(disk_total_space('/') / (1024 ** 3), 1);
$diskFree  = round(disk_free_space('/')  / (1024 ** 3), 1);
$diskUsed  = round($diskTotal - $diskFree, 1);

// ==========================================
// DISCO — IO (iostat)
// ==========================================
// Problema: colunas variam entre versões do sysstat.
// Solução: parseia o cabeçalho dinamicamente.
$diskRead  = 0;
$diskWrite = 0;

function parseIostat() {
    // Detecta dispositivo principal — ordem de prioridade:
    // 1. lsblk (moderno)
    // 2. /proc/diskstats direto (funciona em kernel 4.9 do Jetson)
    // Suporta: sda, nvme0n1, mmcblk0 (Jetson/RPi/eMMC)
    $device = null;

    $lsblk = safeExec("lsblk -dno NAME,TYPE 2>/dev/null | grep -E '\\bdisk\\b' | grep -vE 'loop|ram' | awk '{print \$1}' | head -1");
    if ($lsblk) {
        $device = trim($lsblk);
    }

    // Fallback: lê /proc/diskstats e pega primeiro dispositivo real
    if (!$device && file_exists('/proc/diskstats')) {
        $lines = file('/proc/diskstats') ?: [];
        foreach ($lines as $l) {
            $p = preg_split('/\s+/', trim($l));
            $name = $p[2] ?? '';
            // Aceita sda, nvme0n1, mmcblk0, vda — não aceita partições nem ram
            if (preg_match('/^(sd[a-z]|nvme\d+n\d+|mmcblk\d+|vda)$/', $name)) {
                $device = $name;
                break;
            }
        }
    }

    $device = $device ?: 'sda';

    // iostat com intervalo 1s, 2 amostras (descarta a primeira que é desde o boot)
    $io = safeExec("iostat -dx 1 2 2>/dev/null");
    if (!$io) {
        // Fallback: /proc/diskstats
        return parseDiscstatsFallback($device);
    }

    // Divide em blocos por linha em branco
    $blocos  = preg_split('/\n\s*\n/', $io);
    $bloco   = end($blocos); // usa o segundo relatório (mais preciso)
    $linhas  = explode("\n", trim($bloco));

    // Encontra linha de cabeçalho
    $header  = null;
    $dataLine = null;

    foreach ($linhas as $linha) {
        if (strpos($linha, 'Device') !== false) {
            $header = preg_split('/\s+/', trim($linha));
        } elseif ($header && (strpos($linha, $device) !== false || preg_match('/^\s*(sd|nvme|vd|hd|mmcblk)/', $linha))) {
            $dataLine = preg_split('/\s+/', trim($linha));
            break;
        }
    }

    if (!$header || !$dataLine) return [0, 0];

    // Encontra colunas r/s e w/s (ou rkB/s e wkB/s)
    $rIdx = array_search('r/s', $header)
         ?: array_search('rkB/s', $header)
         ?: array_search('kB_read/s', $header)
         ?: false;

    $wIdx = array_search('w/s', $header)
         ?: array_search('wkB/s', $header)
         ?: array_search('kB_wrtn/s', $header)
         ?: false;

    $read  = ($rIdx !== false && isset($dataLine[$rIdx])) ? (float)$dataLine[$rIdx] : 0;
    $write = ($wIdx !== false && isset($dataLine[$wIdx])) ? (float)$dataLine[$wIdx] : 0;

    return [round($read, 2), round($write, 2)];
}

function parseDiscstatsFallback($device) {
    // Lê /proc/diskstats diretamente (sempre disponível)
    static $prev = null;
    $lines = @file('/proc/diskstats') ?: [];

    $curr = [];
    foreach ($lines as $l) {
        $p = preg_split('/\s+/', trim($l));
        if (($p[2] ?? '') === $device) {
            $curr = $p;
            break;
        }
    }

    if (empty($curr) || $prev === null) {
        $prev = $curr;
        return [0, 0];
    }

    // Campos: sectores lidos (5) e escritos (9), 512 bytes cada
    $readSectors  = (int)($curr[5]  ?? 0) - (int)($prev[5]  ?? 0);
    $writeSectors = (int)($curr[9]  ?? 0) - (int)($prev[9]  ?? 0);
    $prev = $curr;

    // Converte para KB/s (intervalo ~250ms do usleep acima)
    return [
        round($readSectors  * 512 / 1024 / 0.25, 2),
        round($writeSectors * 512 / 1024 / 0.25, 2),
    ];
}

[$diskRead, $diskWrite] = parseIostat();

// ==========================================
// CONTAINERS DOCKER
// ==========================================
// Acessa via socket sem sudo.
// Não quebra se Docker não estiver disponível.
$containers  = [];
$dockerOk    = false;

$dockerBin = null;
foreach (['/usr/bin/docker', '/usr/local/bin/docker'] as $p) {
    if (file_exists($p) && is_executable($p)) { $dockerBin = $p; break; }
}

if ($dockerBin && is_readable('/var/run/docker.sock')) {
    $dockerList = safeExec($dockerBin . " ps -a --format '{{.Names}}|{{.State}}|{{.Status}}'");
    if ($dockerList) {
        $dockerOk = true;
        foreach (explode("\n", $dockerList) as $line) {
            $line = trim($line);
            if (!$line || !strpos($line, '|') !== false) continue;
            $parts = explode('|', $line, 3);
            $containers[] = [
                'name'   => trim($parts[0] ?? ''),
                'state'  => trim($parts[1] ?? ''),
                'status' => trim($parts[2] ?? ''),
            ];
        }
        // Ordena: running primeiro (compatível PHP 7.2)
        usort($containers, function($a, $b) {
            $pa = ($a['state'] === 'running') ? 0 : 1;
            $pb = ($b['state'] === 'running') ? 0 : 1;
            return $pa - $pb;
        });
    }
}

// ==========================================
// FRIGATE CPU (OPCIONAL)
// ==========================================
// Não retorna erro se container não existir.
$frigateCpu = 0;

if ($dockerOk && $dockerBin) {
    // Verifica se container frigate existe
    $frigateCheck = safeExec($dockerBin . " ps -a --format '{{.Names}}' | grep -i frigate");
    if ($frigateCheck) {
        $frigateStats = safeExec($dockerBin . " stats frigate --no-stream --format '{{.CPUPerc}}'");
        if ($frigateStats) {
            $frigateCpu = (float)str_replace('%', '', $frigateStats);
        }
    }
}

// ==========================================
// INFO DO SERVIDOR
// ==========================================
$hostname = gethostname();
$serverIp = $_SERVER['SERVER_ADDR'] ?? gethostbyname($hostname);

// Detecta plataforma para o frontend saber o contexto
$platform = 'unknown';
if (file_exists('/proc/device-tree/model')) {
    $model = strtolower(rtrim(trim(file_get_contents('/proc/device-tree/model')), "\0"));
    if (strpos($model, 'jetson') !== false || strpos($model, 'tegra') !== false) {
        $platform = 'jetson';
    } else {
        $platform = 'arm';
    }
} elseif (file_exists('/sys/class/dmi/id/product_name')) {
    $platform = 'x86';
} elseif (file_exists('/proc/cpuinfo')) {
    $cpuinfo = file_get_contents('/proc/cpuinfo');
    if (preg_match('/tegra/i', $cpuinfo)) $platform = 'jetson';
}

// Detecta se temperatura está disponível
$tempAvailable = ($temp > 0);

// ==========================================
// RESPOSTA JSON
// ==========================================
echo json_encode([
    // Identificação
    'hostname'        => $hostname,
    'server_ip'       => $serverIp,
    'platform'        => $platform,

    // CPU
    'cpu'             => $cpuUsage,
    'cores'           => $cores,

    // Load
    'load'            => $load1m,
    'load_5m'         => $load5m,
    'load_15m'        => $load15m,

    // Memória (MB)
    'ram_used'        => $ramUsedMB,
    'ram_total'       => $ramTotalMB,

    // Temperatura
    'temp'            => $temp,
    'temp_available'  => $tempAvailable,

    // Disco
    'disk_used'       => $diskUsed,
    'disk_total'      => $diskTotal,
    'disk_read'       => $diskRead,
    'disk_write'      => $diskWrite,

    // Docker
    'docker_ok'       => $dockerOk,
    'containers'      => $containers,
    'frigate_cpu'     => $frigateCpu,
    'frigate_present' => ($frigateCpu > 0 || !empty($frigateCheck ?? '')),
]);
