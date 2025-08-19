<?php
/**
 * tauronApiPhp
 * Author: Paweł 'Pavlus' Janisio
 * Website: https://github.com/PJanisio/tauronApiPhp
 * Receive jSON data from Tauron e-licznik (polish energy distributor)
 */

declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '0');
mb_internal_encoding('UTF-8');

/**
 * eLicznik bridge (login -> select meter -> fetch).
 * Query:
 *   user, pass, meter, from, to,
 *   type=consumption|generation,
 *   balanced=0|1,
 *   period=range|monthly|yearly|last_12_months,
 *   month=YYYY-MM (when period=monthly),
 *   year=YYYY (when period=yearly),
 *   total_only=0|1,
 *   save=0|1
 */

const URL_LOGIN = 'https://logowanie.tauron-dystrybucja.pl/login';
const URL_SERVICE = 'https://elicznik.tauron-dystrybucja.pl';
const URL_SELECT = URL_SERVICE . '/ustaw_punkt';
const URL_ENERGY = URL_SERVICE . '/energia/api';
const URL_ENERGY_WO = URL_SERVICE . '/energia/wo/api';
const URL_READINGS = URL_SERVICE . '/odczyty/api';
const ALLOWED_TYPES = ['consumption', 'generation'];
const ALLOWED_PERIODS = ['range', 'monthly', 'yearly', 'last_12_months'];

function q(string $k, ?string $d = null): ?string
{
    return isset($_GET[$k]) ? trim((string) $_GET[$k]) : $d;
}

function fail(string $where, string $message, int $code = 400, array $extra = []): void
{
    out_json(['status' => 'error', 'where' => $where, 'message' => $message] + $extra, $code);
}

function out_json($data, int $code = 200)
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');

    try {
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    } catch (Throwable $e) {
        // throw 500
        http_response_code(500);
        echo '{"status":"error","where":"encode","message":"JSON encoding failed"}';
    }
    exit;
}
function as_pl_date(string $in): string
{
    // accepts YYYY-MM-DD or DD.MM.YYYY -> returns DD.MM.YYYY
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $in)) {
        [$y, $m, $d] = explode('-', $in);
        return sprintf('%02d.%02d.%04d', (int) $d, (int) $m, (int) $y);
    }
    if (preg_match('/^\d{2}\.\d{2}\.\d{4}$/', $in))
        return $in;
    return '';
}

/**
 * Compute date range for Tauron API based on period and optional month/year.
 * Returns an array with two elements: [start_date, end_date] in 'YYYY-MM-DD' format.
 */
function compute_period_range(string $period, ?string $month, ?string $year): array
{

    $today = new DateTimeImmutable('today', new DateTimeZone('Europe/Warsaw'));
    if ($period === 'monthly') {
        if ($month && preg_match('/^\d{4}-\d{2}$/', $month)) {
            [$y, $m] = explode('-', $month);
            $first = new DateTimeImmutable(sprintf('%04d-%02d-01', (int)$y, (int)$m));
        } else {
            $first = $today->modify('first day of this month');
        }
        $last  = $first->modify('last day of this month');
        return [$first->format('Y-m-d'), $last->format('Y-m-d')];
    }
    if ($period === 'yearly') {
        $yy = ($year && preg_match('/^\d{4}$/', $year)) ? (int)$year : (int)$today->format('Y');
        return [sprintf('%04d-01-01', $yy), sprintf('%04d-12-31', $yy)];
    }
    if ($period === 'last_12_months') {
        // From the 1st day of the month 11 months ago to the last day of the current month
        $start = $today->modify('first day of this month')->modify('-11 months');
        $end   = $today->modify('last day of this month');
        return [$start->format('Y-m-d'), $end->format('Y-m-d')];
    }
    // 'range' -> keep caller-provided from/to
    return ['', ''];
}

function cookie_file(string $user): string
{
    $hash = hash('sha256', $user . '|' . __FILE__);
    $path = __DIR__ . "/tauron_cookie_$hash.txt";
    if (!is_file($path))
        @file_put_contents($path, '');
    return $path;
}

// Check if in cookie file there are any Tauron cookies first in cURL memory, then in the file
function has_service_cookie($ch, string $cookieFile): array
{
    $hostRe = '/\belicznik\.tauron-dystrybucja\.pl\b/i';
    $okMem = false;
    $memCount = 0;
    // 1) In-memory cookie jar (niezależne od zapisu do pliku)
    if (defined('CURLINFO_COOKIELIST')) {
        $list = @curl_getinfo($ch, CURLINFO_COOKIELIST);
        if (is_array($list)) {
            $memCount = count($list);
            foreach ($list as $line) {
                if (preg_match($hostRe, (string)$line)) {
                    $okMem = true;
                    break;
                }
            }
        }
    }
    // Cookie file check
    $okFile = false;
    if (!$okMem) {
        if (is_file($cookieFile) && filesize($cookieFile) > 0) {
            $txt = @file_get_contents($cookieFile);
            if ($txt !== false) {
                $okFile = (bool)preg_match($hostRe, $txt);
            }
        }
    }
    return ['ok' => ($okMem || $okFile), 'mem_count' => $memCount, 'file_ok' => $okFile];
}

function ch_init(string $cookieFile)
{
    $ch = curl_init();
    $ua = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome Safari';
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_USERAGENT => $ua,
        CURLOPT_COOKIEJAR => $cookieFile,
        CURLOPT_COOKIEFILE => $cookieFile,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HEADER => true,
    ]);
    return $ch;
}

function req($ch, string $method, string $url, array $opts = []): array
{
    $headers = $opts['headers'] ?? [];
    $data = $opts['data'] ?? null;
    $ref = $opts['referer'] ?? URL_SERVICE . '/';
    $headers = array_merge(['cache-control: no-cache', 'accept: application/json'], $headers);

    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_REFERER, $ref);

    if ($method === 'POST') {
        if (is_array($data)) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
            // ensure form content-type if not set
            $hasCt = false;
            foreach ($headers as $h)
                if (stripos($h, 'content-type:') === 0) {
                    $hasCt = true;
                    break;
                }
            if (!$hasCt) {
                $headers[] = 'Content-Type: application/x-www-form-urlencoded';
                curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            }
        } else {
            curl_setopt($ch, CURLOPT_POSTFIELDS, (string) $data);
        }
    } else {
        curl_setopt($ch, CURLOPT_POSTFIELDS, null);
    }

    $raw = curl_exec($ch);
    $errno = curl_errno($ch);
    $err = curl_error($ch);
    $code = ($raw !== false) ? (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE) : 0;
    $hsize = ($raw !== false) ? (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE) : 0;
    $hdr = ($raw !== false) ? substr($raw, 0, $hsize) : '';
    $body = ($raw !== false) ? substr($raw, $hsize) : '';

    $eff   = ($raw !== false) ? (string)curl_getinfo($ch, CURLINFO_EFFECTIVE_URL) : '';
    $ctype = ($raw !== false) ? (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE) : '';
    return [
        'ok'      => $errno === 0,
        'errno'   => $errno,
        'error'   => $err,
        'code'    => $code,
        'headers' => $hdr,
        'body'    => $body,
        'len'     => strlen($body),
        'url'     => $url,
        'method'  => $method,
        'eff_url' => $eff,
        'ctype'   => $ctype,
    ];
}
function body_json_success(string $body): bool
{
    return strpos(ltrim($body), '{"success":true') === 0;
}

/** try a single /energia/api root with an energy+typeKey pair */
function try_energy_root($ch, string $root, string $fromPL, string $toPL, int $energy, string $typeKey): array
{
    // whole range at once
    $payload = ['from' => $fromPL, 'to' => $toPL, 'profile' => 'full time', 'type' => $typeKey, 'energy' => $energy];
    $r = req($ch, 'POST', $root, ['data' => $payload]);
    if ($r['code'] === 200 && body_json_success($r['body'])) {
        return ['ok' => true, 'how' => 'range', 'code' => $r['code'], 'body' => $r['body']];
    }
    // per-day fallback
    $sum = 0;
    $zones = [];
    $zonesName = null;
    $allData = [];
    $tariff = null;
    $gotAny = false;
    $merge = function (array $json) use (&$sum, &$zones, &$zonesName, &$allData, &$tariff, &$gotAny) {
        $gotAny = true;
        $d = $json['data'] ?? [];
        $sum += (float) ($d['sum'] ?? 0);
        if (isset($d['zonesName']))
            $zonesName = $d['zonesName'];
        if (isset($d['tariff']))
            $tariff = $d['tariff'];
        if (isset($d['zones']) && is_array($d['zones'])) {
            foreach ($d['zones'] as $k => $v)
                $zones[$k] = ($zones[$k] ?? 0) + (float) $v;
        }
        if (isset($d['allData']) && is_array($d['allData'])) {
            foreach ($d['allData'] as $row)
                $allData[] = $row;
        }
    };
    $fromIso = DateTime::createFromFormat('d.m.Y', $fromPL)->format('Y-m-d');
    $toIso = DateTime::createFromFormat('d.m.Y', $toPL)->format('Y-m-d');
    for ($d = new DateTime($fromIso); $d <= new DateTime($toIso); $d->modify('+1 day')) {
        $pl = $d->format('d.m.Y');
        $p = ['from' => $pl, 'to' => $pl, 'profile' => 'full time', 'type' => $typeKey, 'energy' => $energy];
        $rd = req($ch, 'POST', $root, ['data' => $p]);
        if ($rd['code'] === 200 && body_json_success($rd['body'])) {
            $json = json_decode($rd['body'], true);
            if (is_array($json))
                $merge($json);
        }
        usleep(120000);
    }
    if ($gotAny) {
        $out = ['success' => true, 'data' => ['allData' => $allData, 'sum' => $sum, 'zones' => $zones]];
        if ($zonesName)
            $out['data']['zonesName'] = $zonesName;
        if ($tariff)
            $out['data']['tariff'] = $tariff;
        return ['ok' => true, 'how' => 'per-day', 'code' => 200, 'body' => json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)];
    }
    return ['ok' => false, 'how' => 'none', 'code' => $r['code'], 'body' => $r['body']];
}

/** synth helpers */
function decode_ok($r)
{
    return $r && $r['code'] === 200 && body_json_success($r['body']);
}
function to_json($r)
{
    return $r ? json_decode($r['body'], true) : null;
}

/**
 * merge with self-consumption balancing:
 *   mode='consumption' -> import = max(cons - gen, 0)
 *   mode='generation'  -> export = max(gen  - cons, 0)
 */

function synth_balanced(?array $primary, ?array $other, string $mode): array
{
    $rowsP = [];
    $rowsO = [];
    $zonesName = null;
    $tariff = null;
    $pull = function (?array $json, array &$dst, ?array &$zonesName, ?string &$tariff) {
        if (!$json || !isset($json['data']['allData']) || !is_array($json['data']['allData']))
            return;
        if (isset($json['data']['zonesName']))
            $zonesName = $json['data']['zonesName'];
        if (isset($json['data']['tariff']))
            $tariff = $json['data']['tariff'];
        foreach ($json['data']['allData'] as $r) {
            $d = (string) ($r['Date'] ?? '');
            $h = (string) ($r['Hour'] ?? '');
            $ec = (float) ($r['EC'] ?? 0);
            if ($d === '' || $h === '')
                continue;
            $dst[$d . '|' . $h] = ['Date' => $d, 'Hour' => $h, 'EC' => $ec, 'Zone' => $r['Zone'] ?? '1', 'ZoneName' => $r['ZoneName'] ?? 'Cała doba', 'Taryfa' => $r['Taryfa'] ?? ($json['data']['tariff'] ?? 'G11')];
        }
    };
    $pull($primary, $rowsP, $zonesName, $tariff);
    $pull($other, $rowsO, $zonesName, $tariff);

    $keys = array_unique(array_merge(array_keys($rowsP), array_keys($rowsO)));
    sort($keys);
    $outAll = [];
    $sum = 0.0;
    $zonesAgg = [];
    foreach ($keys as $k) {
        $p = $rowsP[$k]['EC'] ?? 0.0;
        $o = $rowsO[$k]['EC'] ?? 0.0;
        $net = ($mode === 'consumption') ? max($p - $o, 0.0) : max($p - $o, 0.0); // same formula; p is chosen accordingly
        $base = $rowsP[$k] ?? $rowsO[$k] ?? ['Date' => '', 'Hour' => '', 'Zone' => '1', 'ZoneName' => 'Cała doba', 'Taryfa' => $tariff ?? 'G11'];
        $row = [
            'EC' => (string) (0 + $net),
            'Date' => $base['Date'],
            'Hour' => (string) $base['Hour'],
            'Status' => '0',
            'Extra' => 'N',
            'Zone' => $base['Zone'],
            'ZoneName' => $base['ZoneName'],
            'Taryfa' => $base['Taryfa'],
        ];
        $outAll[] = $row;
        $z = $row['Zone'];
        $zonesAgg[$z] = ($zonesAgg[$z] ?? 0) + $net;
        $sum += $net;
    }
    $out = ['success' => true, 'data' => ['allData' => $outAll, 'sum' => $sum, 'zones' => $zonesAgg]];
    if ($zonesName)
        $out['data']['zonesName'] = $zonesName;
    if ($tariff)
        $out['data']['tariff'] = $tariff;
    return $out;
}

/* ------------ inputs ------------ */
$user = q('user');
$pass = q('pass');
$meter = q('meter');
$fromIn = q('from');
$toIn = q('to');
$typeIn = strtolower(q('type', 'consumption') ?? 'consumption'); // consumption|generation
$balIn = q('balanced', '0');  // "1" enables per-hour self-consumption netting for consumption/generation
$period = strtolower(q('period', 'range') ?? 'range'); // range|monthly|yearly|last_12_months
$monthParam = q('month'); // YYYY-MM (when period=monthly)
$yearParam  = q('year');  // YYYY (when period=yearly)
$totalOnly  = (q('total_only', '0') === '1'); // return only one number (sum) + small meta
$balanced = ($balIn === '1');

if (!$user || !$pass || !$meter || !$fromIn || !$toIn) {
    // period auto-range can fill from/to, so only enforce when period=range
    if ($period === 'range') {
        fail('inputs', 'Missing user, pass, meter, from, to');
    }
}

// If period != range, auto-derive from/to
if (!in_array($period, ALLOWED_PERIODS, true)) {
    fail('inputs', "Invalid period '{$period}'. Allowed: " . implode(',', ALLOWED_PERIODS));
}
if ($period !== 'range') {
    [$autoFrom, $autoTo] = compute_period_range($period, $monthParam, $yearParam);
    if ($autoFrom !== '' && $autoTo !== '') {
        $fromIn = $autoFrom;
        $toIn   = $autoTo;
    } else {
        fail('inputs', 'Unable to compute date range for the requested period');
    }
}


$fromIso = preg_match('/^\d{4}-\d{2}-\d{2}$/', $fromIn) ? $fromIn : '';
$toIso = preg_match('/^\d{4}-\d{2}-\d{2}$/', $toIn) ? $toIn : '';
if (!$fromIso) {
    if (preg_match('/^\d{2}\.\d{2}\.\d{4}$/', $fromIn)) {
        [$d, $m, $y] = explode('.', $fromIn);
        $fromIso = sprintf('%04d-%02d-%02d', $y, $m, $d);
    }
}
if (!$toIso) {
    if (preg_match('/^\d{2}\.\d{2}\.\d{4}$/', $toIn)) {
        [$d, $m, $y] = explode('.', $toIn);
        $toIso = sprintf('%04d-%02d-%02d', $y, $m, $d);
    }
}
$fromPL = as_pl_date($fromIso ?: $fromIn);
$toPL = as_pl_date($toIso ?: $toIn);

if (!$fromIso || !$toIso || !$fromPL || !$toPL) {
    fail('inputs', 'Invalid date format. Use YYYY-MM-DD or DD.MM.YYYY');
}


// Validate type and balanced switch
if (!in_array($typeIn, ALLOWED_TYPES, true)) {
    if ($typeIn === 'balanced') {
        fail('inputs', "type=balanced is deprecated. Use type=consumption&balanced=1 or type=generation&balanced=1");
    }
    fail('inputs', "Invalid type '{$typeIn}'. Allowed: " . implode(',', ALLOWED_TYPES));
}
if ($balIn !== '0' && $balIn !== '1') {
    fail('inputs', "Invalid balanced value '{$balIn}'. Use 0 or 1.");
}
// Ensure chronological order
try {
    $fromDt = new DateTimeImmutable($fromIso);
    $toDt = new DateTimeImmutable($toIso);
    if ($fromDt > $toDt) {
        fail('inputs', "'from' must be earlier than or equal to 'to'");
    }
} catch (Throwable $e) {
    fail('inputs', 'Invalid date(s) provided');
}

/* ------------ session/login/select meter ------------ */
$cookie = cookie_file($user);
$ch = ch_init($cookie);
$steps = [];

$warm = req($ch, 'GET', URL_SERVICE . '/');
$steps[] = ['step' => 'warm', 'code' => $warm['code'], 'len' => $warm['len']];

$loginPayload = ['username' => $user, 'password' => $pass, 'service' => URL_SERVICE];
$login1 = req($ch, 'POST', URL_LOGIN, ['data' => $loginPayload, 'headers' => []]);
$steps[] = ['step' => 'login_post_1', 'code' => $login1['code'], 'len' => $login1['len']];

if ($login1['code'] >= 300) {
    $login2 = req($ch, 'POST', URL_LOGIN, ['data' => $loginPayload, 'headers' => []]);
    $steps[] = ['step' => 'login_post_2', 'code' => $login2['code'], 'len' => $login2['len']];
    $loginRes  = $login2;
} else {
    $loginRes  = $login1;
}

// Hardened criteria for successful login related with the service host
$serviceHostTmp = parse_url(URL_SERVICE, PHP_URL_HOST);
$serviceHost    = is_string($serviceHostTmp) ? $serviceHostTmp : '';

$effUrl     = (string)($loginRes['eff_url'] ?? '');
$effHostTmp = $effUrl !== '' ? parse_url($effUrl, PHP_URL_HOST) : null;
$effHost    = is_string($effHostTmp) && $effHostTmp !== '' ? $effHostTmp : null; // ?string

$okByRedirect = ($effHost !== null && $serviceHost !== '') && (strcasecmp($effHost, $serviceHost) === 0);
$cookieInfo   = has_service_cookie($ch, $cookie);
$okByCookie   = $cookieInfo['ok'];

// Cookie and host are required for successful login
if (!$okByRedirect || !$okByCookie) {
    $steps[] = [
        'step'            => 'login_check',
        'eff_url'         => $effUrl,
        'service_host'    => $serviceHost,
        'ok_by_redirect'  => $okByRedirect,
        'ok_by_cookie'    => $okByCookie,
        'cookie_mem_count' => $cookieInfo['mem_count'] ?? 0,
        'cookie_file_ok'  => $cookieInfo['file_ok'] ?? false,
    ];
    out_json(
        [
            'status'  => 'error',
            'where'   => 'login',
            'message' => 'Login did not look successful',
            'hint'    => 'Check credentials / rate limits',
            'steps'   => $steps
        ],
        502
    );
}

$sel = req($ch, 'POST', URL_SELECT, ['data' => ['site[client]' => $meter]]);
$steps[] = ['step' => 'select_meter', 'code' => $sel['code'], 'len' => $sel['len']];
if ($sel['code'] < 200 || $sel['code'] >= 400) {
    out_json(['status' => 'error', 'where' => 'select_meter', 'message' => 'Failed to select meter', 'steps' => $steps], 502);
}

/* ------------ data fetch ------------ */
$roots = [URL_ENERGY, URL_ENERGY_WO];
$result = null;
$attempts = [];

// consumption or generation
$isGen = ($typeIn === 'generation');
$energy = $isGen ? 2 : 1;
$typeKey = $isGen ? 'oze' : 'consum';

// primary fetch
$primary = null;
$pickedRoot = null;
foreach ($roots as $root) {
    $t = try_energy_root($ch, $root, $fromPL, $toPL, $energy, $typeKey);
    $attempts[] = ['root' => $root, 'code' => $t['code'], 'how' => $t['how'], 'len' => strlen($t['body'])];
    if ($t['ok']) {
        $primary = $t;
        $pickedRoot = $root;
        break;
    }
}

if (!$primary) {
    // fallback to readings (only for non-balanced simple mode)
    if (!$balanced) {
        $rd = req($ch, 'POST', URL_READINGS, ['data' => ['from' => $fromPL, 'to' => $toPL, 'type' => ($isGen ? 'energia-oddana' : 'energia-pobrana')]]);
        $attempts[] = ['root' => URL_READINGS, 'code' => $rd['code'], 'how' => 'readings', 'len' => $rd['len']];
        if ($rd['code'] === 200 && body_json_success($rd['body'])) {
            $result = ['ok' => true, 'how' => 'readings', 'code' => 200, 'body' => $rd['body']];
        }
    }
} else {
    if (!$balanced) {
        // raw series straight through
        $result = $primary;
    } else {
        // balanced requested: need the opposite series too
        $otherEnergy = $isGen ? 1 : 2;
        $otherType = $isGen ? 'consum' : 'oze';

        // try same root first
        $other = try_energy_root($ch, $pickedRoot, $fromPL, $toPL, $otherEnergy, $otherType);
        $attempts[] = ['root' => $pickedRoot, 'code_other' => $other['code'], 'how_other' => $other['how']];

        if (!$other['ok']) {
            // try alternate root if needed
            foreach ($roots as $root) {
                if ($root === $pickedRoot)
                    continue;
                $alt = try_energy_root($ch, $root, $fromPL, $toPL, $otherEnergy, $otherType);
                $attempts[] = ['root' => $root, 'code_other' => $alt['code'], 'how_other' => $alt['how']];
                if ($alt['ok']) {
                    $other = $alt;
                    break;
                }
            }
        }

        if (decode_ok($primary) || decode_ok($other)) {
            // synth balanced import/export
            $pJson = to_json($primary);
            $oJson = to_json($other);
            $mode = $isGen ? 'generation' : 'consumption';
            $balJson = synth_balanced($pJson, $oJson, $mode);
            $result = ['ok' => true, 'how' => "{$typeIn}_balanced", 'code' => 200, 'body' => json_encode($balJson, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)];
        }
    }
}


/* ------------ output ------------ */
$save = (q('save', '0') === '1');

if ($result) {
    $decoded = json_decode($result['body'], true);

    // Optional compact output for HA monthly/yearly sensors
    if ($totalOnly) {
        $sumVal = (float)($decoded['data']['sum'] ?? 0);
        $minimal = [
            'status'  => 'ok',
            'where'   => 'data',
            'how'     => $result['how'],
            'period'  => $period,
            'input'   => [
                'meter'    => $meter,
                'type'     => $typeIn,
                'balanced' => $balanced ? 1 : 0,
                'from'     => $fromIso,
                'to'       => $toIso,
            ],
            'value'   => $sumVal,
        ];
        if ($save) {
            $balTag = $balanced ? 'bal1' : 'bal0';
            $fname  = sprintf(
                'tauron_%s_%s_%s_%s_%s.min.json',
                $meter,
                $typeIn,
                $balTag,
                str_replace('-', '', $fromIso),
                str_replace('-', '', $toIso)
            );
            @file_put_contents(
                __DIR__ . DIRECTORY_SEPARATOR . $fname,
                json_encode($minimal, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            );
            $minimal['saved_file'] = $fname;
        }
        out_json($minimal);
    }

    // Full response (default)
    $responseData = [
        'status'   => 'ok',
        'where'    => 'data',
        'how'      => $result['how'],
        'period'   => $period,
        'input'    => [
            'user'     => substr($user, 0, 2) . '***',
            'meter'    => $meter,
            'type'     => $typeIn,
            'balanced' => $balanced ? 1 : 0,
            'from'     => $fromIso,
            'to'       => $toIso,
        ],
        'attempts' => $attempts,
        'data'     => $decoded,
    ];

    if ($save) {
        $balTag = $balanced ? 'bal1' : 'bal0';
        $fname = sprintf(
            'tauron_%s_%s_%s_%s_%s.json',
            $meter,
            $typeIn,
            $balTag,
            str_replace('-', '', $fromIso),
            str_replace('-', '', $toIso)
        );
        $fpath = __DIR__ . DIRECTORY_SEPARATOR . $fname;
        file_put_contents($fpath, json_encode($responseData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $responseData['saved_file'] = $fname;
    }

    out_json($responseData);
}

/* No successful result -> return explicit JSON error */
out_json([
    'status' => 'error',
    'where' => 'fetch',
    'message' => 'No data returned from any root (login ok, meter selected).',
    'input' => [
        'user' => substr($user, 0, 2) . '***',
        'meter' => $meter,
        'type' => $typeIn,
        'balanced' => $balanced ? 1 : 0,
        'from' => $fromIso,
        'to' => $toIso,
        'period' => $period
    ],
    'attempts' => $attempts
], 502);