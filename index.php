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
const BASE_HEADERS = ['cache-control: no-cache', 'accept: application/json'];
const THROTTLE_US = 120000;

/**
 * Get a trimmed HTTP GET parameter.
 *
 * @param string      $k Name of the query parameter.
 * @param string|null $d Default value returned when the parameter is missing.
 * @return string|null Trimmed value from $_GET or the provided default.
 */
function get_query(string $k, ?string $d = null): ?string
{
    return isset($_GET[$k]) ? trim((string) $_GET[$k]) : $d;
}

/**
 * Emit a JSON error response and terminate the script.
 * Convenience wrapper around out_json().
 *
 * @param string $where   Logical subsystem or step name where the failure occurred.
 * @param string $message Human-readable message.
 * @param int    $code    HTTP status code (default 400).
 * @param array  $extra   Extra key-value pairs merged into the JSON payload.
 * @return void This function never returns (script exits).
 */
function json_fail(string $where, string $message, int $code = 400, array $extra = []): void
{
    out_json(['status' => 'error', 'where' => $where, 'message' => $message] + $extra, $code);
}

/**
 * Output data as JSON with an HTTP status code and terminate.
 *
 * @param mixed $data Arbitrary data structure to be JSON-encoded.
 * @param int   $code HTTP status code (default 200).
 * @return void This function never returns (script exits).
 */
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

/**
 * Convert a date to Polish format (DD.MM.YYYY).
 *
 * Accepts either ISO format (YYYY-MM-DD) or already Polish format (DD.MM.YYYY).
 * Returns an empty string when input does not match the expected formats.
 *
 * @param string $in Input date string.
 * @return string Polish-formatted date or empty string on failure.
 */
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
 * Compute a date range for Tauron API based on a period and optional month/year.
 *
 * For:
 *  - period="monthly": $month may be "YYYY-MM"; defaults to the current month.
 *  - period="yearly" : $year may be  "YYYY";   defaults to the current year.
 *  - period="last_12_months": from the first day 11 months ago to the last day of the current month.
 *  - period="range": caller-provided from/to are used (this function returns ["",""].)
 *
 * @param string      $period One of ALLOWED_PERIODS.
 * @param string|null $month  Optional month (YYYY-MM) when period=monthly.
 * @param string|null $year   Optional year (YYYY) when period=yearly.
 * @return array{0:string,1:string} [start_date, end_date] in 'YYYY-MM-DD'; or ["",""] for period=range.
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

/**
 * Build a per-user cookie-jar path and ensure the file exists.
 *
 * @param string $user Login/username seed for the cookie file name.
 * @return string Absolute path to the cookie jar file.
 */
function cookie_file(string $user): string
{
    $hash = hash('sha256', $user . '|' . __FILE__);
    $path = __DIR__ . "/tauron_cookie_$hash.txt";
    if (!is_file($path))
        file_put_contents($path, '');
    return $path;
}

/**
 * Check whether a Tauron service cookie is present (in-memory or in file).
 *
 * @param resource|\CurlHandle $ch         cURL handle whose in-memory cookie list will be inspected.
 * @param string                $cookieFile Path to the cookie file persisted on disk.
 * @return array{ok:bool,mem_count:int,file_ok:bool} Presence flags and in-memory cookie count.
 */
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

/**
 * Initialize a cURL session with sensible defaults for this service.
 *
 * @param string $cookieFile Path to the cookie jar file (read+write).
 * @return resource|\CurlHandle Configured cURL handle.
 */
function init_curl(string $cookieFile)
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
        CURLOPT_ENCODING => '', // enable gzip/deflate/br if server supports
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_2TLS, // try HTTP/2 over TLS
    ]);
    return $ch;
}

/**
 * Perform an HTTP request with cURL.
 *
 * @param resource|\CurlHandle $ch     Initialized cURL handle.
 * @param string               $method HTTP method (e.g., 'GET', 'POST').
 * @param string               $url    Absolute request URL.
 * @param array                $opts   Optional options: ['headers'=>string[], 'data'=>array|string|null, 'referer'=>string].
 * @return array{
 *   ok:bool, errno:int, error:string, code:int, headers:string, body:string, len:int,
 *   url:string, method:string, eff_url:string, ctype:string
 * } Structured response with metadata and raw headers/body.
 */
function http_request($ch, string $method, string $url, array $opts = []): array
{
    $headers = $opts['headers'] ?? [];
    $data = $opts['data'] ?? null;
    $ref = $opts['referer'] ?? URL_SERVICE . '/';
    $headers = array_merge(BASE_HEADERS, $headers);

    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);

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
            }
        } else {
            curl_setopt($ch, CURLOPT_POSTFIELDS, (string) $data);
        }
    } else {
        curl_setopt($ch, CURLOPT_POSTFIELDS, null);
    }
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

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

/**
 * Quick check whether a response body looks like {"success":true,...}.
 *
 * @param string $body Raw JSON response body.
 * @return bool True if the body begins with a success=true JSON object.
 */
function body_json_success(string $body): bool
{
    return (bool)preg_match('/^\s*\{\s*"success"\s*:\s*true\b/i', $body);
}

/**
 * Try a single /energia/api root with an energy+typeKey pair.
 *
 * Attempts to fetch the whole range first; if that fails, falls back to
 * day-by-day requests (with throttling), aggregating the totals into
 * a synthetic response compatible with the "range" format.
 *
 * @param resource|\CurlHandle $ch      cURL handle.
 * @param string               $root    API root URL (URL_ENERGY or URL_ENERGY_WO).
 * @param string               $fromPL  From date in Polish format (DD.MM.YYYY).
 * @param string               $toPL    To date in Polish format (DD.MM.YYYY).
 * @param int                  $energy  1=consumption, 2=generation.
 * @param string               $typeKey 'consum' or 'oze'.
 * @return array{ok:bool,how:string,code:int,body:string,len:int} Outcome and payload.
 */
function fetch_energy_series($ch, string $root, string $fromPL, string $toPL, int $energy, string $typeKey): array
{
    // Try whole range at once
    $payload = [
        'from'    => $fromPL,
        'to'      => $toPL,
        'profile' => 'full time',
        'type'    => $typeKey,
        'energy'  => $energy
    ];
    $r = http_request($ch, 'POST', $root, ['data' => $payload]);

    if ($r['code'] === 200 && body_json_success($r['body'])) {
        return [
            'ok'   => true,
            'how'  => 'range',
            'code' => $r['code'],
            'body' => $r['body'],
            'len'  => $r['len'] ?? strlen($r['body']),
        ];
    }

    // Per-day fallback
    $sum       = 0.0;
    $zones     = [];
    $zonesName = null;
    $allData   = [];
    $tariff    = null;
    $gotAny    = false;

    $merge = function (array $json) use (&$sum, &$zones, &$zonesName, &$allData, &$tariff, &$gotAny) {
        $gotAny = true;
        $d = $json['data'] ?? [];
        $sum += (float)($d['sum'] ?? 0);
        if (isset($d['zonesName'])) $zonesName = $d['zonesName'];
        if (isset($d['tariff']))    $tariff    = $d['tariff'];
        if (!empty($d['zones']) && is_array($d['zones'])) {
            foreach ($d['zones'] as $k => $v) {
                $zones[$k] = ($zones[$k] ?? 0) + (float)$v;
            }
        }
        if (!empty($d['allData']) && is_array($d['allData'])) {
            foreach ($d['allData'] as $row) {
                $allData[] = $row;
            }
        }
    };

    $fromDt = DateTime::createFromFormat('d.m.Y', $fromPL);
    $toDt   = DateTime::createFromFormat('d.m.Y', $toPL);
    if (!$fromDt || !$toDt) {
        return [
            'ok'   => false,
            'how'  => 'none',
            'code' => 400,
            'body' => '{"success":false}',
            'len'  => strlen('{"success":false}'),
        ];
    }

    $fromIso = $fromDt->format('Y-m-d');
    $toIso   = $toDt->format('Y-m-d');

    $end = new DateTime($toIso);
    for ($d = new DateTime($fromIso); $d <= $end; $d->modify('+1 day')) {
        $pl = $d->format('d.m.Y');
        $p  = ['from' => $pl, 'to' => $pl, 'profile' => 'full time', 'type' => $typeKey, 'energy' => $energy];
        $rd = http_request($ch, 'POST', $root, ['data' => $p]);

        if ($rd['code'] === 200 && body_json_success($rd['body'])) {
            $json = json_decode($rd['body'], true);
            if (is_array($json)) $merge($json);
        }

        usleep(defined('THROTTLE_US') ? THROTTLE_US : 120000);
    }

    if ($gotAny) {
        $out = ['success' => true, 'data' => ['allData' => $allData, 'sum' => $sum, 'zones' => $zones]];
        if ($zonesName) $out['data']['zonesName'] = $zonesName;
        if ($tariff)    $out['data']['tariff']    = $tariff;

        $bodyOut = json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return [
            'ok'   => true,
            'how'  => 'per-day',
            'code' => 200,
            'body' => $bodyOut,
            'len'  => strlen($bodyOut),
        ];
    }

    // Nothing worked
    return [
        'ok'   => false,
        'how'  => 'none',
        'code' => $r['code'],
        'body' => $r['body'],
        'len'  => $r['len'] ?? strlen($r['body']),
    ];
}

/** synth helpers */

/**
 * Check if a response looks successful and JSON has "success":true.
 *
 * @param array|null $r Result array from http_request()/fetch_energy_series().
 * @return bool True when HTTP code==200 and body begins with success=true.
 */
function is_success_json($r)
{
    return $r && $r['code'] === 200 && body_json_success($r['body']);
}

/**
 * Decode a JSON body from a result array.
 *
 * @param array|null $r Result array with a 'body' JSON string.
 * @return array|null Decoded associative array, or null on failure.
 */
function parse_json_body($r)
{
    return $r ? json_decode($r['body'], true) : null;
}

/**
 * Merge two series (primary vs other) into a per-hour, self-consumption-balanced series.
 *
 * Balancing rules:
 *  - mode='consumption' -> import = max(cons - gen, 0)
 *  - mode='generation'  -> export = max(gen  - cons, 0)
 *
 * NOTE: The same formula is used internally with different "primary" assignment;
 *       $primary should contain the series whose positive remainder you want.
 *
 * @param array|null  $primary JSON-decoded response for the primary series.
 * @param array|null  $other   JSON-decoded response for the other (opposite) series.
 * @param string      $mode    'consumption'|'generation' (used only for metadata/clarity).
 * @return array      Synthetic JSON-like array matching the API's shape (success, data=>allData,sum,zones,...).
 */
function build_balanced_series(?array $primary, ?array $other, string $mode): array
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
        // same formula; $p is chosen accordingly (consumption or generation)
        $net = max($p - $o, 0.0);
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
$user = get_query('user');
$pass = get_query('pass');
$meter = get_query('meter');
$fromIn = get_query('from');
$toIn = get_query('to');
$typeIn = strtolower(get_query('type', 'consumption') ?? 'consumption'); // consumption|generation
$balIn = get_query('balanced', '0');  // "1" enables per-hour self-consumption netting for consumption/generation
$period = strtolower(get_query('period', 'range') ?? 'range'); // range|monthly|yearly|last_12_months
$monthParam = get_query('month'); // YYYY-MM (when period=monthly)
$yearParam  = get_query('year');  // YYYY (when period=yearly)
$totalOnly  = (get_query('total_only', '0') === '1'); // return only one number (sum) + small meta
$balanced = ($balIn === '1');

// Always require auth + meter
if (!$user || !$pass || !$meter) {
    json_fail('inputs', 'Missing user, pass, or meter parameters.');
}

// Validate period value early
if (!in_array($period, ALLOWED_PERIODS, true)) {
    json_fail('inputs', "Invalid period '{$period}'. Allowed: " . implode(',', ALLOWED_PERIODS));
}

// Require from/to only for range; otherwise compute them
if ($period === 'range') {
    if (!$fromIn || !$toIn) {
        json_fail('inputs', 'Missing "from" and/or "to" parameters for period=range.');
    }
} else {
    [$autoFrom, $autoTo] = compute_period_range($period, $monthParam, $yearParam);
    if ($autoFrom !== '' && $autoTo !== '') {
        $fromIn = $autoFrom;
        $toIn   = $autoTo;
    } else {
        json_fail('inputs', 'Unable to compute date range for the requested period');
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
    json_fail('inputs', 'Invalid date format. Use YYYY-MM-DD or DD.MM.YYYY');
}


// Validate type and balanced switch
if (!in_array($typeIn, ALLOWED_TYPES, true)) {
    if ($typeIn === 'balanced') {
        json_fail('inputs', "type=balanced is deprecated. Use type=consumption&balanced=1 or type=generation&balanced=1");
    }
    json_fail('inputs', "Invalid type '{$typeIn}'. Allowed: " . implode(',', ALLOWED_TYPES));
}
if ($balIn !== '0' && $balIn !== '1') {
    json_fail('inputs', "Invalid balanced value '{$balIn}'. Use 0 or 1.");
}
// Ensure chronological order
try {
    $fromDt = new DateTimeImmutable($fromIso);
    $toDt = new DateTimeImmutable($toIso);
    if ($fromDt > $toDt) {
        json_fail('inputs', "'from' must be earlier than or equal to 'to'");
    }
} catch (Throwable $e) {
    json_fail('inputs', 'Invalid date(s) provided');
}

/* ------------ session/login/select meter ------------ */
$cookie = cookie_file($user);
$ch = init_curl($cookie);
$steps = [];

$warm = http_request($ch, 'GET', URL_SERVICE . '/');
$steps[] = ['step' => 'warm', 'code' => $warm['code'], 'len' => $warm['len']];

$loginPayload = ['username' => $user, 'password' => $pass, 'service' => URL_SERVICE];
$login1 = http_request($ch, 'POST', URL_LOGIN, ['data' => $loginPayload, 'headers' => []]);
$steps[] = ['step' => 'login_post_1', 'code' => $login1['code'], 'len' => $login1['len']];

if ($login1['code'] >= 300) {
    $login2 = http_request($ch, 'POST', URL_LOGIN, ['data' => $loginPayload, 'headers' => []]);
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

$sel = http_request($ch, 'POST', URL_SELECT, ['data' => ['site[client]' => $meter]]);
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
    $t = fetch_energy_series($ch, $root, $fromPL, $toPL, $energy, $typeKey);
    $attempts[] = ['root' => $root, 'code' => $t['code'], 'how' => $t['how'], 'len' => $t['len']];
    if ($t['ok']) {
        $primary = $t;
        $pickedRoot = $root;
        break;
    }
}

if (!$primary) {
    // fallback to readings (only for non-balanced simple mode)
    if (!$balanced) {
        $rd = http_request($ch, 'POST', URL_READINGS, ['data' => ['from' => $fromPL, 'to' => $toPL, 'type' => ($isGen ? 'energia-oddana' : 'energia-pobrana')]]);
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
        $other = fetch_energy_series($ch, $pickedRoot, $fromPL, $toPL, $otherEnergy, $otherType);

        $attempts[] = [
            'root'       => $pickedRoot,
            'code_other' => $other['code'],
            'how_other'  => $other['how'],
            'len_other'  => $other['len'],
        ];

        if (!$other['ok']) {
            // try alternate root if needed
            foreach ($roots as $root) {
                if ($root === $pickedRoot)
                    continue;
                $alt = fetch_energy_series($ch, $root, $fromPL, $toPL, $otherEnergy, $otherType);
                $attempts[] = [
                    'root'       => $root,
                    'code_other' => $alt['code'],
                    'how_other'  => $alt['how'],
                    'len_other'  => $alt['len'],
                ];
                if ($alt['ok']) {
                    $other = $alt;
                    break;
                }
            }
        }

        if (is_success_json($primary)) {
            // synth balanced import/export
            $pJson = parse_json_body($primary);
            $oJson = parse_json_body($other);
            $mode = $isGen ? 'generation' : 'consumption';
            $balJson = build_balanced_series($pJson, $oJson, $mode);
            $result = ['ok' => true, 'how' => "{$typeIn}_balanced", 'code' => 200, 'body' => json_encode($balJson, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)];
        }
    }
}


/* ------------ output ------------ */
$save = (get_query('save', '0') === '1');

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
                'user'     => substr($user, 0, 2) . '***',
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
            file_put_contents(
                __DIR__ . DIRECTORY_SEPARATOR . $fname,
                json_encode($minimal, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                LOCK_EX
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
        file_put_contents($fpath, json_encode($responseData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
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
