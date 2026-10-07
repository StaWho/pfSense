<?php
/*
 * NordVPN server switcher for pfSense (webGUI page)
 * Install as /usr/local/www/nordvpn_switch.php
 *
 * Two ways to browse:
 *   1. Pick a country  -> all servers in that country, with their types
 *   2. Pick a type     -> countries that have it -> pick a country -> servers
 *
 * Untested on 2.9: check the system/nginx logs if the page errors.
 */
require_once("guiconfig.inc");
require_once("openvpn.inc");

ini_set('memory_limit', '512M');   // the full NordVPN server list is large
set_time_limit(90);

const CLIENT_DESCR = 'NordVPN';       // must match your OpenVPN client's Description
const TECH         = 'openvpn_udp';   // or openvpn_tcp, match your client's protocol
const CACHE_TTL    = 900;             // seconds to keep the server list cached
const PAGE_LIMIT   = 50;              // servers shown before "Show all"
const SELF         = 'nordvpn_switch.php';
const VERSION      = '1.4.0';         // bump this when you change the script
const AUTO_REFRESH_SECS = 5;          // delay between automatic status checks while connecting
const AUTO_REFRESH_MAX  = 5;          // maximum number of automatic checks

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES); }

function fmt_bytes($n) {
    $n = (float)$n;
    foreach (['B', 'KB', 'MB', 'GB', 'TB'] as $u) {
        if ($n < 1024 || $u === 'TB') { return round($n, $u === 'B' ? 0 : 1) . ' ' . $u; }
        $n /= 1024;
    }
}

/*
 * Live status of our OpenVPN client from pfSense's own status code.
 * Returns the client's status array, or null if it can't be determined.
 */
function vpn_live_status($vpnid, $descr) {
    if (!function_exists('openvpn_get_active_clients')) { return null; }
    try {
        foreach (openvpn_get_active_clients() as $c) {
            if ($vpnid !== null && (string)($c['vpnid'] ?? '') === (string)$vpnid) { return $c; }
        }
        foreach (openvpn_get_active_clients() as $c) {   // fallback: match by name
            if ($descr !== '' && stripos((string)($c['name'] ?? ''), $descr) !== false) { return $c; }
        }
    } catch (Throwable $e) {
        return null;
    }
    return null;
}

/*
 * Download the full server list once, keep only online servers that support TECH,
 * slim each record down and cache it in /tmp.
 */
function load_servers(&$warn) {
    $cache = '/tmp/nordvpn_servers_' . TECH . '.json';

    if (!empty($_GET['refresh']) && is_file($cache)) { @unlink($cache); }

    if (is_file($cache) && (time() - filemtime($cache)) < CACHE_TTL) {
        $d = json_decode(file_get_contents($cache), true);
        if (is_array($d) && $d) { return $d; }
    }

    $ch = curl_init('https://api.nordvpn.com/v1/servers?limit=16384');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_ENCODING       => '',
    ]);
    $raw = curl_exec($ch);
    curl_close($ch);
    $all = $raw ? json_decode($raw, true) : null;
    unset($raw);

    if (!is_array($all) || !$all) {
        if (is_file($cache)) {
            $warn = "Could not reach the NordVPN API, showing an older cached list.";
            $d = json_decode(file_get_contents($cache), true);
            return is_array($d) ? $d : [];
        }
        $warn = "Could not reach the NordVPN API.";
        return [];
    }

    $slim = [];
    foreach ($all as $s) {
        if (($s['status'] ?? '') !== 'online') { continue; }
        $ok = false;
        foreach ($s['technologies'] ?? [] as $t) {
            if (($t['identifier'] ?? '') === TECH && (($t['pivot']['status'] ?? 'online') === 'online')) {
                $ok = true;
                break;
            }
        }
        if (!$ok || empty($s['hostname'])) { continue; }

        $country = $s['locations'][0]['country'] ?? [];
        $types = [];
        foreach ($s['groups'] ?? [] as $g) {
            if (($g['type']['identifier'] ?? '') === 'regions') { continue; }  // skip purely regional groups
            if (!empty($g['identifier'])) { $types[$g['identifier']] = $g['title'] ?? $g['identifier']; }
        }
        $slim[] = [
            'h'  => $s['hostname'],
            'l'  => (int)($s['load'] ?? 0),
            'c'  => (int)($country['id'] ?? 0),
            'cn' => $country['name'] ?? '?',
            'ci' => $country['city']['name'] ?? '',
            'g'  => $types,
        ];
    }
    unset($all);

    @file_put_contents($cache, json_encode($slim));
    return $slim;
}

/* ---- locate the OpenVPN client ---- */
init_config_arr(array('openvpn', 'openvpn-client'));
$a_client = &$config['openvpn']['openvpn-client'];
$idx = null;
foreach ($a_client as $i => $c) {
    if (($c['description'] ?? '') == CLIENT_DESCR) { $idx = $i; break; }
}

/* ---- request state ---- */
$view    = $_REQUEST['view'] ?? '';            // '', 'servers' or 'countries'
$country = (int)($_REQUEST['country'] ?? 0);
$group   = $_REQUEST['group'] ?? '';
if (!preg_match('/^[a-z0-9_]*$/', $group)) { $group = ''; }
$showall = !empty($_REQUEST['all']);

$msg = null;
$msgtype = 'info';

/* ---- switch action (POST) ---- */
if ($idx === null) {
    $msg = "No OpenVPN client with description '" . CLIENT_DESCR . "' found.";
    $msgtype = 'danger';
} elseif (($_POST['action'] ?? '') === 'switch') {
    $host = $_POST['host'] ?? '';
    if (preg_match('/^[a-z0-9-]+\.nordvpn\.com$/', $host)) {
        $a_client[$idx]['server_addr'] = $host;
        write_config("NordVPN switcher: server set to $host");
        openvpn_restart('client', $a_client[$idx]);
        $msg = "Switched to $host. Allow about 10 seconds to reconnect.";
        $msgtype = 'success';
    } else {
        $msg = "Invalid hostname.";
        $msgtype = 'danger';
    }
}

/* ---- data ---- */
$warn = null;
$servers = load_servers($warn);
if ($warn) { $msg = $warn; $msgtype = 'danger'; }

$countries = [];
$types = [];
$typesByCountry = [];
foreach ($servers as $s) {
    $countries[$s['c']] = $s['cn'];
    foreach ($s['g'] as $id => $title) {
        $types[$id] = $title;
        $typesByCountry[$s['c']][$id] = $title;
    }
}
asort($countries);
asort($types);
foreach ($typesByCountry as $cid => $t) { asort($typesByCountry[$cid]); }

function url($params) { return SELF . '?' . http_build_query($params); }

$pgtitle = [gettext("VPN"), "NordVPN Switcher"];
include("head.inc");

if ($msg) { print_info_box(h($msg), $msgtype); }
$current = $idx !== null ? ($a_client[$idx]['server_addr'] ?? '?') : '-';

/* ---- live connection status ---- */
function vpn_state($raw) {
    // pfSense 2.9 reports text like "Connected (Success)"; older versions report "up"/"down"
    $r = strtolower(trim((string)$raw));
    if ($r === '' || preg_match('/(down|disconnected|exiting|error|failed)/', $r)) {
        return ['Disconnected', 'danger'];
    }
    if ($r === 'up' || preg_match('/^connected\b/', $r)) {
        return ['Connected', 'success'];
    }
    return ['Connecting', 'warning'];
}

function pick($arr, $keys) {
    foreach ($keys as $k) {
        if (isset($arr[$k]) && $arr[$k] !== '') { return $arr[$k]; }
    }
    return '';
}

function addr_port($arr, $hostkeys, $portkeys) {
    $host = pick($arr, $hostkeys);
    $port = pick($arr, $portkeys);
    return $host === '' ? '' : $host . ($port !== '' ? ':' . $port : '');
}

$colors = ['success' => '#5cb85c', 'warning' => '#f0ad4e', 'danger' => '#d9534f', 'default' => '#777777'];
$st = null;
$label = 'Unknown';
$labelclass = 'default';
$note = 'No status information for this client.';
if ($idx !== null) {
    if (isset($a_client[$idx]['disable'])) {
        $label = 'Disconnected';
        $labelclass = 'danger';
        $note = 'This OpenVPN client is disabled in pfSense.';
    } else {
        $st = vpn_live_status($a_client[$idx]['vpnid'] ?? null, CLIENT_DESCR);
        if ($st === null) {
            $label = 'Status unavailable';
        } else {
            list($label, $labelclass) = vpn_state($st['status'] ?? '');
        }
    }
}

/* ---- auto-refresh while connecting (limited number of checks) ---- */
$ar = max(0, (int)($_GET['ar'] ?? 0));          // automatic refreshes done so far
$autorefresh = ($label === 'Connecting' && $ar < AUTO_REFRESH_MAX);
$next = ['view' => $view, 'country' => $country, 'group' => $group, 'ar' => $ar + 1];
if ($showall) { $next['all'] = 1; }
?>
<div class="panel panel-default">
  <div class="panel-heading">
    <h2 class="panel-title">
      Client Instance Statistics
      <span class="label" style="background-color:<?= h($colors[$labelclass]) ?>;color:#fff;font-size:90%;margin-left:8px"><?= h($label) ?></span>
    </h2>
  </div>
  <div class="panel-body table-responsive">
    <table class="table table-striped table-hover table-condensed">
      <thead>
        <tr>
          <th>Name</th>
          <th>Status</th>
          <th>Last Change</th>
          <th>Local Address</th>
          <th>Virtual Address</th>
          <th>Remote Host</th>
        </tr>
      </thead>
      <tbody>
      <?php if ($st !== null):
          $vpnid  = $a_client[$idx]['vpnid'] ?? ($st['vpnid'] ?? '');
          $rawst  = trim((string)($st['status'] ?? ''));
      ?>
        <tr>
          <td>ovpnc<?= h($vpnid) ?><br><?= h($st['name'] ?? CLIENT_DESCR) ?></td>
          <td><?= h($rawst !== '' ? $rawst : 'down') ?></td>
          <td><?= h(pick($st, ['connect_time', 'last_change', 'time'])) ?></td>
          <td><?= h(addr_port($st, ['local_host', 'local', 'local_addr'], ['local_port'])) ?></td>
          <td><?= h(pick($st, ['virtual_addr', 'virtual_address'])) ?></td>
          <td><?= h(addr_port($st, ['remote_host', 'remote'], ['remote_port'])) ?></td>
        </tr>
      <?php else: ?>
        <tr><td colspan="6"><?= h($note) ?></td></tr>
      <?php endif; ?>
      </tbody>
    </table>
    <a class="btn btn-default btn-sm" href="<?= h(url(['view' => $view, 'country' => $country, 'group' => $group])) ?>">Refresh status</a>
    <small>After switching servers, wait about 10 seconds, then refresh.</small>
    <?php if ($autorefresh): ?>
      <p class="text-warning" style="margin-top:8px">
        Connecting&hellip; checking again in <?= (int)AUTO_REFRESH_SECS ?> seconds
        (automatic check <?= $ar + 1 ?> of <?= (int)AUTO_REFRESH_MAX ?>).
      </p>
      <script>
        setTimeout(function () { window.location.href = <?= json_encode(url($next)) ?>; }, <?= (int)AUTO_REFRESH_SECS * 1000 ?>);
      </script>
    <?php elseif ($label === 'Connecting'): ?>
      <p class="text-warning" style="margin-top:8px">
        Still connecting after <?= (int)AUTO_REFRESH_MAX ?> automatic checks. Tap Refresh status to check again.
      </p>
    <?php endif; ?>
    <?php if (!empty($_GET['debug']) && $st !== null): ?>
      <pre style="margin-top:10px"><?= h(print_r($st, true)) ?></pre>
    <?php endif; ?>
  </div>
</div>

<div class="panel panel-default">
  <div class="panel-heading">
    <h2 class="panel-title">Current server: <?= h($current) ?></h2>
  </div>
  <div class="panel-body">
    <small>
      Script version <?= h(VERSION) ?> &middot;
      <?= count($servers) ?> online servers with <?= h(TECH) ?>.
      <a href="<?= h(url(['refresh' => 1, 'view' => $view, 'country' => $country, 'group' => $group])) ?>">Refresh list</a>
    </small>
  </div>
</div>

<div class="row">
  <div class="col-md-6">
<div class="panel panel-default">
  <div class="panel-heading"><h2 class="panel-title">Browse by country</h2></div>
  <div class="panel-body">
    <form method="get" action="<?= SELF ?>">
      <input type="hidden" name="view" value="servers">
      <label for="country">Country</label>
      <select id="country" name="country" class="form-control">
        <option value="0">Select a country</option>
        <?php foreach ($countries as $id => $name): ?>
          <option value="<?= (int)$id ?>" <?= ($country === (int)$id) ? 'selected' : '' ?>><?= h($name) ?></option>
        <?php endforeach; ?>
      </select>
      <br>
      <label for="group_c">Server type (optional)</label>
      <select id="group_c" name="group" class="form-control">
        <option value="">Any type</option>
        <?php foreach (($typesByCountry[$country] ?? []) as $id => $title): ?>
          <option value="<?= h($id) ?>" <?= ($group === $id) ? 'selected' : '' ?>><?= h($title) ?></option>
        <?php endforeach; ?>
      </select>
      <br>
      <button class="btn btn-primary" type="submit">Show servers</button>
    </form>
    <script>
    // Keep the type dropdown limited to types that exist in the chosen country
    (function () {
      var byCountry = <?= json_encode($typesByCountry) ?>;
      var cSel = document.getElementById('country');
      var tSel = document.getElementById('group_c');
      cSel.addEventListener('change', function () {
        var keep = tSel.value;
        var opts = byCountry[cSel.value] || {};
        tSel.innerHTML = '';
        tSel.add(new Option('Any type', ''));
        Object.keys(opts).forEach(function (id) {
          tSel.add(new Option(opts[id], id, false, id === keep));
        });
      });
    })();
    </script>
  </div>
</div>
  </div>
  <div class="col-md-6">
<div class="panel panel-default">
  <div class="panel-heading"><h2 class="panel-title">Browse by type</h2></div>
  <div class="panel-body">
    <form method="get" action="<?= SELF ?>">
      <input type="hidden" name="view" value="countries">
      <label for="group_t">Server type</label>
      <select id="group_t" name="group" class="form-control">
        <?php foreach ($types as $id => $title): ?>
          <option value="<?= h($id) ?>" <?= ($group === $id) ? 'selected' : '' ?>><?= h($title) ?></option>
        <?php endforeach; ?>
      </select>
      <br>
      <button class="btn btn-primary" type="submit">Show countries</button>
    </form>
  </div>
</div>
  </div>
</div>

<?php
/* ---- results: countries that support a type ---- */
if ($view === 'countries'):
    if ($group === '' || !isset($types[$group])):
        print_info_box("Choose a server type first.", 'warning');
    else:
        $counts = [];
        foreach ($servers as $s) {
            if (isset($s['g'][$group])) { $counts[$s['c']] = ($counts[$s['c']] ?? 0) + 1; }
        }
        uksort($counts, fn($a, $b) => strcmp($countries[$a] ?? '', $countries[$b] ?? ''));
?>
<div class="panel panel-default">
  <div class="panel-heading">
    <h2 class="panel-title"><?= h($types[$group]) ?>: <?= count($counts) ?> countries</h2>
  </div>
  <div class="panel-body">
    <?php foreach ($counts as $cid => $n): ?>
      <a class="btn btn-default btn-block" style="text-align:left"
         href="<?= h(url(['view' => 'servers', 'country' => $cid, 'group' => $group])) ?>">
        <strong><?= h($countries[$cid] ?? '?') ?></strong> &middot; <?= (int)$n ?> server<?= $n == 1 ? '' : 's' ?>
      </a>
    <?php endforeach; ?>
  </div>
</div>
<?php
    endif;
endif;

/* ---- results: servers in a country (optionally filtered by type) ---- */
if ($view === 'servers'):
    if ($country === 0 || !isset($countries[$country])):
        print_info_box("Select a country first.", 'warning');
    else:
        $list = array_values(array_filter($servers, function ($s) use ($country, $group) {
            return $s['c'] === $country && ($group === '' || isset($s['g'][$group]));
        }));
        usort($list, fn($a, $b) => $a['l'] <=> $b['l']);
        $total = count($list);
        $shown = $showall ? $list : array_slice($list, 0, PAGE_LIMIT);
        $heading = $countries[$country] . ($group !== '' && isset($types[$group]) ? ' / ' . $types[$group] : '');
?>
<div class="panel panel-default">
  <div class="panel-heading">
    <h2 class="panel-title"><?= h($heading) ?>: <?= $total ?> server<?= $total == 1 ? '' : 's' ?>, lowest load first</h2>
  </div>
  <div class="panel-body">
    <?php if ($group !== ''): ?>
      <p><a href="<?= h(url(['view' => 'countries', 'group' => $group])) ?>">&laquo; Back to countries for this type</a></p>
    <?php endif; ?>

    <?php if ($total === 0): ?>
      <p>No servers match this country and type with <?= h(TECH) ?>. Some types are not offered in every country,
         and obfuscated servers need XOR technologies.</p>
    <?php endif; ?>

    <?php foreach ($shown as $s): ?>
      <form method="post" action="<?= SELF ?>" style="margin-bottom:8px">
        <input type="hidden" name="action" value="switch">
        <input type="hidden" name="host" value="<?= h($s['h']) ?>">
        <input type="hidden" name="view" value="servers">
        <input type="hidden" name="country" value="<?= $country ?>">
        <input type="hidden" name="group" value="<?= h($group) ?>">
        <?php if ($showall): ?><input type="hidden" name="all" value="1"><?php endif; ?>
        <button class="btn btn-default btn-block" type="submit" style="text-align:left">
          <strong><?= h($s['h']) ?></strong> (load <?= (int)$s['l'] ?>%)<?= $s['ci'] ? ' &middot; ' . h($s['ci']) : '' ?>
          <br><small><?= h($s['g'] ? implode(', ', $s['g']) : 'Standard') ?></small>
        </button>
      </form>
    <?php endforeach; ?>

    <?php if (!$showall && $total > PAGE_LIMIT): ?>
      <a class="btn btn-link"
         href="<?= h(url(['view' => 'servers', 'country' => $country, 'group' => $group, 'all' => 1])) ?>">
        Show all <?= $total ?> servers
      </a>
    <?php endif; ?>
  </div>
</div>
<?php
    endif;
endif;

include("foot.inc");
