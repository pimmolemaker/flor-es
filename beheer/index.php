<?php
// Beheerpagina van Flor-Es  →  https://<jouw domein>/beheer/
// Zelfde opzet als het beheer van pimmolemaker.nl: eigen inlog, tweede stap (app), reservekopieën.
require __DIR__ . '/lib.php';
require __DIR__ . '/tweestap.php';
require __DIR__ . '/versies.php';

$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
ini_set('session.use_strict_mode', '1');
session_name('beheer_flores');
session_set_cookie_params(['lifetime' => 0, 'path' => '/beheer/', 'secure' => $https, 'httponly' => true, 'samesite' => 'Strict']);
session_start();
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header('Cache-Control: no-store');

/* ---------- Hulpjes ---------- */
$CONFIG = __DIR__ . '/config.php';
$SETUPCODE = DATA . '/eerste-keer-code.txt';
function config() { global $CONFIG; return is_file($CONFIG) ? (include $CONFIG) : []; }
function ingelogd() { return !empty($_SESSION['in']); }
function csrf() { if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(24)); return $_SESSION['csrf']; }
function csrf_veld() { return '<input type="hidden" name="csrf" value="' . e(csrf()) . '">'; }
function controleer_csrf() {
    if (!hash_equals(csrf(), (string)($_POST['csrf'] ?? ''))) { http_response_code(400); exit('Ongeldig verzoek. Ga terug, ververs de pagina en probeer het opnieuw.'); }
}
function melding($tekst, $fout = false) { $_SESSION['melding'] = [$tekst, $fout]; }
function ga($a = '', $extra = '') { header('Location: /beheer/' . ($a ? '?a=' . $a . $extra : '')); exit; }
function vraag_inlog() { if (!ingelogd()) ga('inloggen'); }
function p($k, $d = '') { return trim((string)($_POST[$k] ?? $d)); }

/* ---------- Website tijdelijk offline ---------- */
const OFFLINE_STANDAARD = 'Onze website is tijdelijk niet beschikbaar. Binnenkort zijn we weer online.';
function offline_flag() { return DATA . '/offline.flag'; }
function is_offline() { return is_file(offline_flag()); }
function offline_html($tekst, $open) {
    $mail = trim((string)($open['email'] ?? '')); $tel = trim((string)($open['telefoon'] ?? ''));
    $contact = ($mail !== '' || $tel !== '') ? '<p class="c">Dringende vraag? ' . ($mail !== '' ? '<a href="mailto:' . e($mail) . '">' . e($mail) . '</a>' : '') . ($mail !== '' && $tel !== '' ? ' of bel ' : '') . ($tel !== '' ? e($tel) : '') . '</p>' : '';
    return '<!DOCTYPE html>
<html lang="nl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Flor-Es | Tijdelijk offline</title>
<style>
body{margin:0;min-height:100vh;display:grid;place-items:center;background:#fdf3f1;color:#1e3150;font:18px/1.7 "Helvetica Neue",Arial,sans-serif;text-align:center;padding:2rem}
main{max-width:34rem}
img{width:150px;height:150px;border-radius:50%;object-fit:cover;box-shadow:0 20px 40px -20px rgba(30,49,80,.45)}
h1{font:500 2.8rem/1.1 Georgia,"Times New Roman",serif;margin:1.2rem 0 .6rem}
p{margin:0 0 1rem}.c{font-size:.95rem;color:#5b6a80}a{color:#c9473a}
</style>
</head>
<body>
<main>
<img src="/img/logo.jpg" alt="">
<h1>Flor-Es</h1>
<p>' . nl2br(e($tekst)) . '</p>
' . $contact . '
</main>
</body>
</html>
';
}

// Welk menu-item hoort bij welke actie (zodat het juiste item gemarkeerd wordt)
function nav_actief($a, $l = '') {
    $lijstActies = ['lijst', 'item', 'item-omhoog', 'item-omlaag'];
    if ($a === '') return 'start';
    if ($a === 'teksten' || $a === 'tekst') return 'teksten';
    if ($a === 'categorieen' || (in_array($a, $lijstActies, true) && $l === 'bloemwerk')) return 'bloemwerk';
    if ($a === 'workshops' || (in_array($a, $lijstActies, true) && strpos($l, 'ws-') === 0)) return 'workshops';
    if ($a === 'groei' || (in_array($a, $lijstActies, true) && strpos($l, 'gb-') === 0)) return 'groei';
    if ($a === 'versies' || $a === 'versie-terug') return 'versies';
    if (in_array($a, ['instellingen', 'wachtwoord', 'tweestap-start', 'tweestap-instellen', 'tweestap-bevestig', 'tweestap-herstel', 'tweestap-uit', 'voorbeeld-uit'], true)) return 'instellingen';
    return $a;
}

function beheer_menu($a, $l = '') {
    $groepen = [
        '' => [['start', '', 'Start']],
        'Inhoud' => [['bloemwerk', '?a=lijst&l=bloemwerk', 'Bloemwerk'], ['teksten', '?a=teksten', 'Teksten van de pagina\'s'], ['workshops', '?a=workshops', 'Workshops'],
                     ['groei', '?a=groei', 'Groei en Bloei'], ['media', '?a=media', 'Foto\'s']],
        'Beheer' => [['versies', '?a=versies', 'Vorige versies'], ['instellingen', '?a=instellingen', 'Instellingen en veiligheid']],
    ];
    $actief = nav_actief($a, $l); $uit = '';
    foreach ($groepen as $kop => $items) {
        $uit .= '<div class="b-groep">' . ($kop !== '' ? '<p class="b-groepkop">' . e($kop) . '</p>' : '') . '<ul>';
        foreach ($items as $it) $uit .= '<li><a href="/beheer/' . $it[1] . '"' . ($it[0] === $actief ? ' aria-current="page"' : '') . '>' . e($it[2]) . '</a></li>';
        $uit .= '</ul></div>';
    }
    return $uit;
}

function kader($titel, $inhoud, $nav = true) {
    $m = '';
    if (!empty($_SESSION['melding'])) { list($t, $f) = $_SESSION['melding']; unset($_SESSION['melding']); $m = '<p class="melding' . ($f ? ' fout' : '') . '" role="status">' . e($t) . '</p>'; }
    $ingelogd = $nav && ingelogd();
    $kop = '<header class="b-kop">' . ($ingelogd ? '<button type="button" class="b-menuknop" aria-expanded="false" aria-controls="b-zij">Menu</button>' : '') . '<strong class="b-merk">Beheer Flor-Es</strong>'
         . ($ingelogd ? '<span class="b-kop-rechts"><a href="' . (is_offline() ? '/beheer/?a=voorbeeld' : '/') . '" target="_blank" rel="noopener">Bekijk de site ↗</a><form method="post" action="/beheer/?a=uitloggen">' . csrf_veld() . '<button>Uitloggen</button></form></span>' : '') . '</header>';
    $zij = $ingelogd ? '<nav id="b-zij" class="b-zij" aria-label="Beheer">' . beheer_menu($_GET['a'] ?? '', $_GET['l'] ?? '') . '</nav>' : '';
    echo '<!doctype html><html lang="nl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex,nofollow">'
       . '<title>' . e($titel) . ' – Beheer Flor-Es</title><link rel="stylesheet" href="/beheer/beheer.css?v=2"></head><body' . ($ingelogd ? '' : ' class="b-los"') . '>'
       . $kop . '<div class="b-lay">' . $zij . '<main class="b-inhoud" id="b-inhoud">' . $m . '<h1>' . e($titel) . '</h1>' . $inhoud . '</main></div>'
       . '<script src="/beheer/beheer.js?v=2" defer></script></body></html>';
    exit;
}

set_exception_handler(function ($x) {
    kader('Er ging iets mis', '<p class="melding fout">' . e($x->getMessage()) . '</p><p><a href="/beheer/">Terug naar de start</a></p>', ingelogd());
});

$a = $_GET['a'] ?? '';
$post = $_SERVER['REQUEST_METHOD'] === 'POST';

/* ---------- Eerste keer: wachtwoord instellen ---------- */
$cfg = config();
if (empty($cfg['hash'])) {
    if (!is_file($SETUPCODE)) { @file_put_contents($SETUPCODE, bin2hex(random_bytes(5)), LOCK_EX); }
    if ($post && $a === 'instellen') {
        controleer_csrf();
        $code = is_file($SETUPCODE) ? trim(file_get_contents($SETUPCODE)) : '';
        $w = (string)($_POST['wachtwoord'] ?? '');
        if ($code === '' || !hash_equals($code, p('code'))) melding('De code klopt niet. Je vindt hem in het bestand beheer/data/eerste-keer-code.txt.', true);
        elseif (strlen($w) < 10) melding('Kies een wachtwoord van minstens 10 tekens.', true);
        elseif ($w !== (string)($_POST['wachtwoord2'] ?? '')) melding('De twee wachtwoorden zijn niet gelijk.', true);
        else {
            file_put_contents($CONFIG, "<?php\nreturn " . var_export(['hash' => password_hash($w, PASSWORD_DEFAULT)], true) . ";\n", LOCK_EX);
            @unlink($SETUPCODE);
            session_regenerate_id(true); $_SESSION['in'] = true;
            melding('Het wachtwoord is ingesteld. Je bent ingelogd.'); ga();
        }
        ga('');
    }
    kader('Beheer instellen', '<p>Welkom! Kies een wachtwoord voor het beheer van Flor-Es. Je hebt ook de code nodig uit het bestand <code>beheer/data/eerste-keer-code.txt</code>. Dat bestand staat op de server en is te openen via het bestandsbeheer van Hostnet.</p>'
        . '<form method="post" action="/beheer/?a=instellen" class="b-form">' . csrf_veld()
        . '<label>Code uit het bestand<input name="code" required autocomplete="off"></label>'
        . '<label>Nieuw wachtwoord (minstens 10 tekens)<input type="password" name="wachtwoord" required minlength="10" autocomplete="new-password"></label>'
        . '<label>Nogmaals<input type="password" name="wachtwoord2" required autocomplete="new-password"></label>'
        . '<button class="b-knop">Wachtwoord instellen</button></form>', false);
}

/* ---------- Inloggen ---------- */
function inlog_pad() { return DATA . '/inlog.json'; }
function inlog_status() {
    $d = is_file(inlog_pad()) ? json_decode((string)file_get_contents(inlog_pad()), true) : [];
    return [is_array($d) ? $d : [], md5($_SERVER['REMOTE_ADDR'] ?? 'x')];
}
if ($a === 'inloggen') {
    if (ingelogd()) ga();
    if ($post) {
        controleer_csrf();
        list($d, $k) = inlog_status();
        $r = $d[$k] ?? ['n' => 0, 't' => 0];
        if ($r['n'] >= 5 && time() - $r['t'] < 900) { melding('Te veel mislukte pogingen. Probeer het over 15 minuten opnieuw.', true); ga('inloggen'); }
        if ($r['n'] >= 5) $r = ['n' => 0, 't' => 0];
        if (password_verify((string)($_POST['wachtwoord'] ?? ''), $cfg['hash'])) {
            unset($d[$k]); schrijf_json(inlog_pad(), $d);
            session_regenerate_id(true);
            if (tweestap_aan()) { $_SESSION['stap2'] = time(); ga('code'); }
            $_SESSION['in'] = true;
            ga();
        }
        $d[$k] = ['n' => $r['n'] + 1, 't' => time()]; schrijf_json(inlog_pad(), $d);
        sleep(1);
        melding('Het wachtwoord klopt niet.', true); ga('inloggen');
    }
    kader('Inloggen', '<form method="post" action="/beheer/?a=inloggen" class="b-form">' . csrf_veld()
        . '<label>Wachtwoord<input type="password" name="wachtwoord" required autofocus autocomplete="current-password"></label><button class="b-knop">Inloggen</button></form>', false);
}
if ($a === 'uitloggen' && $post) { controleer_csrf(); $_SESSION = []; session_destroy(); header('Location: /beheer/?a=inloggen'); exit; }

tweestap_login_route($a, $post);
vraag_inlog();
tweestap_ingelogd_route($a, $post);

/* ---------- Voorbeeld: de site bekijken terwijl hij offline staat ---------- */
if ($a === 'voorbeeld') {
    setcookie('flores_voorbeeld', '1', ['expires' => 0, 'path' => '/', 'secure' => $https, 'httponly' => true, 'samesite' => 'Lax']);
    header('Location: /'); exit;
}
if ($a === 'voorbeeld-uit') {
    setcookie('flores_voorbeeld', '', ['expires' => time() - 3600, 'path' => '/', 'secure' => $https, 'httponly' => true, 'samesite' => 'Lax']);
    melding('Je ziet de site nu weer zoals bezoekers hem zien.'); ga('instellingen');
}

/* ---------- Lijsten: producten, foto's, prijzen, referenties, agenda ---------- */

function categorie_namen() { return array_map(function ($c) { return $c['naam']; }, lees('bloemwerk')['categorieen'] ?? []); }

function lijsten() {
    $foto = ['foto', 'Foto', 'foto'];
    return [
        'bloemwerk' => ['bestand' => 'bloemwerk', 'sleutel' => 'producten', 'titel' => 'Bloemwerk: producten', 'enkel' => 'product', 'groep' => 'categorie', 'kop' => 'naam', 'sub' => 'prijs', 'terug' => '',
            'velden' => [['naam', 'Naam van het product', 'tekst'], ['categorie', 'Categorie', 'categorie'], ['omschrijving', 'Omschrijving', 'lang'], ['prijs', 'Richtprijs (bijvoorbeeld "vanaf € 45" of "op aanvraag")', 'tekst'], $foto]],
        'ws-fotos' => ['bestand' => 'workshops', 'sleutel' => 'fotos', 'titel' => 'Workshops: foto\'s', 'enkel' => 'foto', 'kop' => 'bijschrift', 'sub' => '', 'terug' => 'workshops',
            'velden' => [$foto, ['bijschrift', 'Bijschrift', 'tekst']]],
        'ws-prijzen' => ['bestand' => 'workshops', 'sleutel' => 'prijzen', 'titel' => 'Workshops: richtprijzen', 'enkel' => 'richtprijs', 'kop' => 'titel', 'sub' => 'bedrag', 'terug' => 'workshops',
            'velden' => [['titel', 'Titel (bijvoorbeeld Particulier)', 'tekst'], ['bedrag', 'Bedrag (bijvoorbeeld "€ 35")', 'tekst'], ['per', 'Per (bijvoorbeeld "per persoon, vanaf 6 deelnemers")', 'tekst'], ['toelichting', 'Toelichting', 'lang']]],
        'ws-referenties' => ['bestand' => 'workshops', 'sleutel' => 'referenties', 'titel' => 'Workshops: referenties', 'enkel' => 'referentie', 'kop' => 'naam', 'sub' => 'tekst', 'terug' => 'workshops',
            'velden' => [['tekst', 'Reactie van de klant', 'lang'], ['naam', 'Naam en organisatie', 'tekst']]],
        'gb-agenda' => ['bestand' => 'groei-en-bloei', 'sleutel' => 'agenda', 'titel' => 'Groei en Bloei: agenda', 'enkel' => 'workshop', 'kop' => 'titel', 'sub' => 'locatie', 'terug' => 'groei',
            'velden' => [['dag', 'Dag (bijvoorbeeld 14)', 'tekst'], ['maand', 'Maand (bijvoorbeeld maart)', 'tekst'], ['titel', 'Titel van de workshop', 'tekst'], ['locatie', 'Locatie en tijd', 'tekst'], ['link', 'Link voor meer informatie (leeg = website van Groei en Bloei)', 'link']]],
        'gb-fotos' => ['bestand' => 'groei-en-bloei', 'sleutel' => 'fotos', 'titel' => 'Groei en Bloei: foto\'s', 'enkel' => 'foto', 'kop' => 'bijschrift', 'sub' => '', 'terug' => 'groei',
            'velden' => [$foto, ['bijschrift', 'Bijschrift', 'tekst']]],
    ];
}
function lijst_of_stop($l) { $L = lijsten(); if (!isset($L[$l])) ga(); return $L[$l]; }
function veld_waarde($rij, $naam) { return (string)($rij[$naam] ?? ''); }
function leeg_item($def) { $r = []; foreach ($def['velden'] as $v) $r[$v[0]] = ''; return $r; }
function krap($t, $n) { $t = trim(preg_replace('~\s+~', ' ', $t)); return function_exists('mb_substr') ? (mb_strlen($t) > $n ? mb_substr($t, 0, $n) . '…' : $t) : substr($t, 0, $n); }

// Verplaatst een item omhoog of omlaag. Bij groepen (categorieën) alleen binnen dezelfde groep.
function verplaats_item(&$lijst, $i, $richting, $groep) {
    $j = $i + $richting;
    while (isset($lijst[$j]) && $groep && ($lijst[$j][$groep] ?? '') !== ($lijst[$i][$groep] ?? '')) $j += $richting;
    if (isset($lijst[$i], $lijst[$j])) { $t = $lijst[$i]; $lijst[$i] = $lijst[$j]; $lijst[$j] = $t; }
}

function veld_html($v, $waarde) {
    list($n, $label, $soort) = $v;
    if ($soort === 'lang') return '<label>' . e($label) . '<textarea name="' . e($n) . '" rows="4">' . e($waarde) . '</textarea></label>';
    if ($soort === 'categorie') {
        $o = ''; foreach (categorie_namen() as $c) $o .= '<option' . ($c === $waarde ? ' selected' : '') . '>' . e($c) . '</option>';
        return '<label>' . e($label) . '<select name="' . e($n) . '">' . $o . '</select></label>';
    }
    if ($soort === 'foto') {
        $t = '<label class="b-kies-rij"><span>Geen foto</span><input type="radio" name="' . e($n) . '" value=""' . ($waarde === '' ? ' checked' : '') . '></label>';
        $kies = '<div class="b-kies">';
        foreach (media_lijst() as $m) $kies .= '<label><input type="radio" name="' . e($n) . '" value="' . e($m['url']) . '"' . ($waarde === $m['url'] ? ' checked' : '') . '><img src="' . e($m['url']) . '" alt="" loading="lazy"><span>' . e($m['naam']) . '</span></label>';
        $kies .= '</div>';
        return '<fieldset><legend>' . e($label) . '</legend>' . $t . $kies . '<label>Of een nieuwe foto uploaden<input type="file" name="' . e($n) . '_upload" accept="image/*"></label></fieldset>';
    }
    return '<label>' . e($label) . '<input name="' . e($n) . '" value="' . e($waarde) . '"></label>';
}

if ($a === 'lijst') {
    $l = $_GET['l'] ?? ''; $def = lijst_of_stop($l);
    $data = lees($def['bestand']); $items = $data[$def['sleutel']] ?? [];
    $rijen = ''; $vorige = null;
    foreach ($items as $i => $it) {
        if ($def['groep'] ?? '') {
            $g = $it[$def['groep']] ?? '';
            if ($g !== $vorige) { $rijen .= '<tr><td colspan="2"><strong>' . e($g ?: '(geen categorie)') . '</strong></td></tr>'; $vorige = $g; }
        }
        $mini = isset($it['foto']) || in_array('foto', array_column($def['velden'], 0), true) ? (($it['foto'] ?? '') !== '' ? '<img class="b-mini" src="' . e($it['foto']) . '" alt="">' : '<span class="b-mini"></span>') : '';
        $kop = veld_waarde($it, $def['kop']) ?: '(' . $def['enkel'] . ')';
        $sub = $def['sub'] ? krap(veld_waarde($it, $def['sub']), 80) : '';
        $rijen .= '<tr><td>' . $mini . '<strong>' . e($kop) . '</strong>' . ($sub ? '<br><small>' . e($sub) . '</small>' : '') . '</td><td class="b-acties">'
            . '<form method="post" action="/beheer/?a=item-omhoog&l=' . e($l) . '&i=' . $i . '">' . csrf_veld() . '<button title="Omhoog">↑</button></form>'
            . '<form method="post" action="/beheer/?a=item-omlaag&l=' . e($l) . '&i=' . $i . '">' . csrf_veld() . '<button title="Omlaag">↓</button></form>'
            . '<a class="b-knop klein" href="/beheer/?a=item&l=' . e($l) . '&i=' . $i . '">Bewerken</a></td></tr>';
    }
    $extra = $l === 'bloemwerk' ? ' <a class="b-knop klein" style="background:#fff;color:var(--g);border:1px solid var(--g)" href="/beheer/?a=categorieen">Categorieën en inleidende teksten</a>' : '';
    $extra .= ' <a href="/beheer/?a=versies&b=data:' . e($def['bestand']) . '">Vorige versies</a>';
    $terug = $def['terug'] ? '<p><a href="/beheer/?a=' . $def['terug'] . '">← Terug</a></p>' : '';
    kader($def['titel'], $terug . '<p><a class="b-knop" href="/beheer/?a=item&l=' . e($l) . '&i=nieuw">+ Nieuw ' . e($def['enkel']) . '</a>' . $extra . '</p>'
        . ($items ? '<table class="b-tabel">' . $rijen . '</table>' : '<p class="b-hulp">Er staat hier nog niets.</p>')
        . '<p class="b-hulp">De volgorde hier is de volgorde op de site.</p>');
}
if (($a === 'item-omhoog' || $a === 'item-omlaag') && $post) {
    controleer_csrf(); $l = $_GET['l'] ?? ''; $def = lijst_of_stop($l);
    $data = lees($def['bestand']); $items = $data[$def['sleutel']] ?? [];
    verplaats_item($items, (int)($_GET['i'] ?? -1), $a === 'item-omhoog' ? -1 : 1, $def['groep'] ?? '');
    $data[$def['sleutel']] = $items; bewaar($def['bestand'], $data); ga('lijst', '&l=' . $l);
}
if ($a === 'item') {
    $l = $_GET['l'] ?? ''; $def = lijst_of_stop($l);
    $data = lees($def['bestand']); $items = $data[$def['sleutel']] ?? [];
    $nieuw = ($_GET['i'] ?? '') === 'nieuw'; $i = (int)($_GET['i'] ?? 0);
    if (!$nieuw && !isset($items[$i])) ga('lijst', '&l=' . $l);
    $it = $nieuw ? leeg_item($def) : $items[$i];
    $weer = '&l=' . $l . '&i=' . ($nieuw ? 'nieuw' : $i);
    if ($post) {
        controleer_csrf();
        if (isset($_POST['verwijder']) && !$nieuw) {
            array_splice($items, $i, 1); $data[$def['sleutel']] = $items; bewaar($def['bestand'], $data);
            melding('Verwijderd. De site is meteen bijgewerkt.'); ga('lijst', '&l=' . $l);
        }
        $nieuwe = $nieuw ? [] : $it;
        foreach ($def['velden'] as $v) {
            list($n, , $soort) = $v;
            $w = str_replace("\r", '', (string)($_POST[$n] ?? ''));
            if ($soort === 'foto') {
                if (!empty($_FILES[$n . '_upload']['name'])) {
                    list($ok, $r) = verwerk_upload($_FILES[$n . '_upload']);
                    if (!$ok) { melding($r, true); ga('item', $weer); }
                    $w = $r;
                } elseif ($w !== '' && !media_bestaat($w)) $w = '';
            } elseif ($soort === 'categorie') {
                if (!in_array($w, categorie_namen(), true)) { melding('Kies een categorie.', true); ga('item', $weer); }
            } elseif ($soort === 'link') {
                $w = trim($w); if (!veilige_url($w)) { melding('Dit is geen geldige link. Begin met https://.', true); ga('item', $weer); }
            } else $w = trim($w);
            $nieuwe[$n] = $w;
        }
        if (veld_waarde($nieuwe, $def['kop']) === '' && !in_array('foto', array_column($def['velden'], 0), true)) { melding('Vul de eerste regel in.', true); ga('item', $weer); }
        if ($nieuw) $items[] = $nieuwe; else $items[$i] = $nieuwe;
        $data[$def['sleutel']] = $items; bewaar($def['bestand'], $data);
        melding('Opgeslagen. De wijziging staat meteen op de site.');
        ga('lijst', '&l=' . $l);
    }
    $form = '<form method="post" enctype="multipart/form-data" class="b-form" action="/beheer/?a=item' . e($weer) . '">' . csrf_veld();
    foreach ($def['velden'] as $v) $form .= veld_html($v, veld_waarde($it, $v[0]));
    $form .= '<p><button class="b-knop">Opslaan</button> <a href="/beheer/?a=lijst&l=' . e($l) . '">Annuleren</a></p>'
        . (!$nieuw ? '<p class="gevaar"><button class="b-knop gevaar" name="verwijder" value="1" formnovalidate onclick="return confirm(\'Dit definitief verwijderen?\')">Verwijderen</button></p>' : '') . '</form>';
    kader(($nieuw ? 'Nieuw ' : '') . ucfirst($def['enkel']) . ($nieuw ? '' : ' bewerken'), $form);
}

/* ---------- Categorieën van het bloemwerk ---------- */
function knop_opties($gekozen) {
    $o = '<option value="">Geen extra knop</option>';
    foreach (KNOPPAGINAS as $f => $naam) $o .= '<option value="' . e($f) . '"' . ($f === $gekozen ? ' selected' : '') . '>' . e($naam) . '</option>';
    return $o;
}
const KNOPPAGINAS = ['trouwen-op-maat.html' => 'Trouwen op maat', 'over.html' => 'Over', 'workshops.html' => 'Workshops', 'groei-en-bloei.html' => 'Groei en Bloei', 'contact.html' => 'Contact'];
if ($a === 'categorieen') {
    $data = lees('bloemwerk'); $cats = $data['categorieen'] ?? []; $prods = $data['producten'] ?? [];
    $aantal_in = function ($naam) use ($prods) { return count(array_filter($prods, function ($x) use ($naam) { return ($x['categorie'] ?? '') === $naam; })); };
    if ($post) {
        controleer_csrf();
        $nieuwe = []; $hernoem = []; $namen = [];
        foreach ($cats as $k => $c) {
            if (!empty($_POST['weg_' . $k])) {
                if ($aantal_in($c['naam']) > 0) { melding('De categorie ' . $c['naam'] . ' heeft nog producten en kan niet worden verwijderd. Verplaats of verwijder die eerst.', true); ga('categorieen'); }
                continue;
            }
            $naam = p('naam_' . $k) !== '' ? p('naam_' . $k) : $c['naam'];
            if ($naam !== $c['naam']) $hernoem[$c['naam']] = $naam;
            $nieuwe[] = ['naam' => $naam, 'intro' => p('intro_' . $k), 'tekstkop' => mb_substr(p('tekstkop_' . $k), 0, 150), 'werkwijze' => mb_substr(trim(str_replace("\r", '', (string)($_POST['werkwijze_' . $k] ?? ''))), 0, 3000), 'knop' => mb_substr(p('knop_' . $k), 0, 120), 'knop2' => mb_substr(p('knop2_' . $k), 0, 60), 'knop2pagina' => isset(KNOPPAGINAS[p('knop2pagina_' . $k)]) ? p('knop2pagina_' . $k) : ''];
        }
        if (p('nieuw_naam') !== '') $nieuwe[] = ['naam' => p('nieuw_naam'), 'intro' => p('nieuw_intro'), 'tekstkop' => mb_substr(p('nieuw_tekstkop'), 0, 150), 'werkwijze' => mb_substr(trim(str_replace("\r", '', (string)($_POST['nieuw_werkwijze'] ?? ''))), 0, 3000), 'knop' => mb_substr(p('nieuw_knop'), 0, 120), 'knop2' => mb_substr(p('nieuw_knop2'), 0, 60), 'knop2pagina' => isset(KNOPPAGINAS[p('nieuw_knop2pagina')]) ? p('nieuw_knop2pagina') : ''];
        foreach ($nieuwe as $c) {
            $l = mb_strtolower($c['naam']);
            if (isset($namen[$l])) { melding('Twee categorieën hebben dezelfde naam: ' . $c['naam'] . '.', true); ga('categorieen'); }
            $namen[$l] = true;
        }
        foreach ($prods as $i => $pr) if (isset($hernoem[$pr['categorie'] ?? ''])) $prods[$i]['categorie'] = $hernoem[$pr['categorie']];
        $data['categorieen'] = $nieuwe; $data['producten'] = $prods; bewaar('bloemwerk', $data);
        melding('Opgeslagen. De wijzigingen staan meteen op de site.'); ga('categorieen');
    }
    $f = '<form method="post" class="b-form" action="/beheer/?a=categorieen">' . csrf_veld();
    foreach ($cats as $k => $c) {
        $n = $aantal_in($c['naam']);
        $f .= '<fieldset><legend>' . e($c['naam']) . '</legend><label>Naam van de categorie<input name="naam_' . $k . '" value="' . e($c['naam']) . '"></label>'
            . '<label>Inleidende tekst op de pagina Bloemwerk<textarea name="intro_' . $k . '" rows="3">' . e($c['intro'] ?? '') . '</textarea></label>'
            . '<label>Kopje boven de tekst onder de producten (niet verplicht)<input name="tekstkop_' . $k . '" value="' . e($c['tekstkop'] ?? '') . '"></label>'
            . '<label>Korte tekst onder de producten (niet verplicht; een nieuwe regel is een nieuwe alinea)<textarea name="werkwijze_' . $k . '" rows="6">' . e($c['werkwijze'] ?? '') . '</textarea></label>'
            . '<details class="b-geavanceerd"><summary>Geavanceerd (zelden nodig): extra knop, knoptekst en verwijderen</summary><div class="b-gea">'
            . '<label>Extra knop onder de tekst: deze pagina openen (niet verplicht)<select name="knop2pagina_' . $k . '">' . knop_opties($c['knop2pagina'] ?? '') . '</select></label>'
            . '<label>Tekst op die extra knop (leeg = de naam van de pagina)<input name="knop2_' . $k . '" value="' . e($c['knop2'] ?? '') . '"></label>'
            . '<label>Tekst op de knop onder deze categorie (leeg = Wil je weten wat er mogelijk is? Neem contact op.)<input name="knop_' . $k . '" value="' . e($c['knop'] ?? '') . '"></label>'
            . ($n === 0 ? '<label class="b-aan"><input type="checkbox" name="weg_' . $k . '" value="1"> Deze categorie verwijderen (er staan geen producten in)</label>' : '<p class="b-hulp">' . $n . ' product' . ($n === 1 ? '' : 'en') . ' in deze categorie.</p>')
            . '</div></details></fieldset>';
    }
    $f .= '<fieldset><legend>Nieuwe categorie toevoegen</legend><label>Naam<input name="nieuw_naam"></label><label>Inleidende tekst<textarea name="nieuw_intro" rows="3"></textarea></label><label>Kopje boven de tekst onder de producten (niet verplicht)<input name="nieuw_tekstkop"></label><label>Korte tekst onder de producten (niet verplicht)<textarea name="nieuw_werkwijze" rows="4"></textarea></label><label>Extra knop onder de tekst: deze pagina openen (niet verplicht)<select name="nieuw_knop2pagina">' . knop_opties('') . '</select></label><label>Tekst op die extra knop (leeg = de naam van de pagina)<input name="nieuw_knop2"></label><label>Tekst op de knop (leeg = standaard)<input name="nieuw_knop"></label></fieldset>'
        . '<p><button class="b-knop">Opslaan</button> <a href="/beheer/?a=lijst&l=bloemwerk">Terug naar de producten</a></p>'
        . '<p class="b-hulp">Hernoem je een categorie, dan gaan de producten erin vanzelf mee.</p></form>';
    kader('Categorieën bloemwerk', $f);
}

/* ---------- Overzichten ---------- */
function overzicht($titel, $regels) {
    $h = '<div class="b-start">';
    foreach ($regels as $r) { $h .= '<a href="/beheer/?a=lijst&l=' . $r[0] . '"><strong>' . e($r[1]) . '</strong><span>' . $r[2] . '</span></a>'; }
    kader($titel, $h . '</div><p class="b-hulp">De gewone teksten van de pagina\'s (bijvoorbeeld de uitleg bovenaan) pas je aan bij <a href="/beheer/?a=teksten">Teksten</a>.</p>');
}
function aantal($b, $s) { return count(lees($b)[$s] ?? []); }
if ($a === 'workshops') overzicht('Workshops', [['ws-fotos', 'Foto\'s', aantal('workshops', 'fotos') . ' foto\'s'], ['ws-prijzen', 'Richtprijzen', aantal('workshops', 'prijzen') . ' prijzen'], ['ws-referenties', 'Referenties', aantal('workshops', 'referenties') . ' referenties']]);
if ($a === 'groei') overzicht('Groei en Bloei', [['gb-agenda', 'Agenda', aantal('groei-en-bloei', 'agenda') . ' workshops'], ['gb-fotos', 'Foto\'s', aantal('groei-en-bloei', 'fotos') . ' foto\'s']]);

/* ---------- Teksten van de pagina's ---------- */
// De teksten staan in de pagina's zelf, tussen markeringen <!--t:sleutel|sectie::naam|kort/lang-->tekst<!--/t-->.
// Het beheer leest die markeringen en schrijft de tekst terug. Voor elke wijziging wordt een reservekopie van de pagina bewaard.
const TEKSTPAGINAS = ['index' => 'Home', 'over' => 'Over', 'bloemwerk' => 'Bloemwerk', 'workshops' => 'Workshops', 'groei-en-bloei' => 'Groei en Bloei',
    'trouwen-op-maat' => 'Trouwen op maat', 'trouwambtenaar' => 'Trouwambtenaar (proefpagina)', 'contact' => 'Contact'];
const TEKST_PATROON = '~<!--t:([a-z0-9]+)\|([^|]*?)\|(kort|lang)-->(.*?)<!--/t-->~s';

function tekst_pad($naam) { return BASIS . '/' . $naam . '.html'; }
function html_naar_tekst($h) {
    $h = preg_replace('~<br\s*/?>~i', "\n", $h);
    $h = preg_replace('~<em>(.*?)</em>~s', '*$1*', $h);
    return html_entity_decode(strip_tags($h), ENT_QUOTES | ENT_HTML5, 'UTF-8');
}
function tekst_naar_html($t) {
    $h = htmlspecialchars(trim(str_replace("\r", '', $t)), ENT_NOQUOTES, 'UTF-8');
    $h = preg_replace('~\*([^*\n]+)\*~', '<em>$1</em>', $h);
    return str_replace("\n", '<br>', $h);
}
function tekst_velden_uit($html) {
    $v = [];
    if (preg_match('~<title>(.*?)</title>~s', $html, $m)) $v[] = ['_titel', 'Webpagina::Titel (tabblad van de browser en Google)', 'kort', html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'), $m[1]];
    if (preg_match('~<meta name="description" content="([^"]*)">~', $html, $m)) $v[] = ['_omschrijving', 'Webpagina::Korte omschrijving voor Google', 'lang', html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'), $m[1]];
    if (preg_match_all(TEKST_PATROON, $html, $ms, PREG_SET_ORDER)) foreach ($ms as $m) $v[] = [$m[1], $m[2], $m[3], html_naar_tekst($m[4]), $m[4]];
    return $v;
}
function tekst_velden($naam) { return tekst_velden_uit((string)@file_get_contents(tekst_pad($naam))); }
function tekst_reservekopie($naam) {
    if (!is_dir(DATA . '/backup')) @mkdir(DATA . '/backup', 0755, true);
    $stempel = date('Ymd-His'); $nr = count(glob(DATA . "/backup/pagina-$naam-$stempel*.html") ?: []) + 1; // twee wijzigingen in één seconde krijgen elk een eigen bestand
    @copy(tekst_pad($naam), DATA . "/backup/pagina-$naam-$stempel-" . str_pad((string)$nr, 3, '0', STR_PAD_LEFT) . '.html');
    $oud = glob(DATA . "/backup/pagina-$naam-*.html") ?: [];
    sort($oud);
    while (count($oud) > 80) { @unlink(array_shift($oud)); }
}

if ($a === 'teksten') {
    $rijen = '';
    foreach (TEKSTPAGINAS as $n => $titel) {
        $rijen .= '<tr><td><strong>' . e($titel) . '</strong><br><small>' . count(tekst_velden($n)) . ' teksten</small></td><td class="b-acties"><a class="b-knop klein" href="/beheer/?a=tekst&p=' . e($n) . '">Teksten bewerken</a></td></tr>';
    }
    kader('Teksten van de pagina\'s', '<p>Kies een pagina om de teksten aan te passen. Wijzigingen staan meteen op de site, en van elke pagina wordt eerst een reservekopie bewaard.</p><table class="b-tabel">' . $rijen . '</table>'
        . '<p class="b-hulp">Foto\'s, producten, prijzen, referenties en de agenda pas je aan via de andere onderdelen van het beheer. Menu en contactgegevens staan bij Instellingen.</p>');
}
if ($a === 'tekst') {
    $n = $_GET['p'] ?? ''; if (!isset(TEKSTPAGINAS[$n])) ga('teksten');
    $velden = tekst_velden($n);
    if ($post) {
        controleer_csrf();
        $html = (string)file_get_contents(tekst_pad($n)); $orig = $html;
        $html = preg_replace_callback(TEKST_PATROON, function ($m) { $k = 't_' . $m[1]; return isset($_POST[$k]) ? '<!--t:' . $m[1] . '|' . $m[2] . '|' . $m[3] . '-->' . tekst_naar_html(mb_substr((string)$_POST[$k], 0, 3000)) . '<!--/t-->' : $m[0]; }, $html);
        if (isset($_POST['t__titel']) && trim($_POST['t__titel']) !== '') $html = preg_replace_callback('~<title>.*?</title>~s', function () { return '<title>' . htmlspecialchars(trim(mb_substr((string)$_POST['t__titel'], 0, 200)), ENT_NOQUOTES, 'UTF-8') . '</title>'; }, $html, 1);
        if (isset($_POST['t__omschrijving'])) $html = preg_replace_callback('~(<meta name="description" content=")[^"]*(">)~', function ($m) { return $m[1] . htmlspecialchars(trim(str_replace("\n", ' ', mb_substr((string)$_POST['t__omschrijving'], 0, 300))), ENT_COMPAT, 'UTF-8') . $m[2]; }, $html, 1);
        if ($html === $orig) { melding('Er was niets veranderd.'); ga('tekst', '&p=' . $n); }
        if (strpos($html, '<main>') === false || strpos($html, '</main>') === false) throw new RuntimeException('De pagina zou beschadigd raken. Er is niets opgeslagen.');
        tekst_reservekopie($n);
        $tmp = tekst_pad($n) . '.tmp';
        if (file_put_contents($tmp, $html, LOCK_EX) === false) throw new RuntimeException('Opslaan is mislukt. Controleer de schrijfrechten.');
        rename($tmp, tekst_pad($n));
        melding('Opgeslagen. De wijzigingen staan meteen op de site.'); ga('tekst', '&p=' . $n);
    }
    $f = '<p><a href="/beheer/?a=teksten">← Alle pagina\'s</a> · <a href="' . ($n === 'index' ? '/' : '/' . e($n) . '.html') . ($n === 'trouwambtenaar' ? '?voorbeeld=1' : '') . '" target="_blank" rel="noopener">Bekijk de pagina ↗</a> · <a href="/beheer/?a=versies&b=pagina:' . e($n) . '">Vorige versies</a></p>'
        . '<form method="post" class="b-form" action="/beheer/?a=tekst&p=' . e($n) . '">' . csrf_veld()
        . '<p class="b-hulp">Zet een woord tussen sterretjes, bijvoorbeeld <code>*karakter*</code>, om het schuin (en op de homepage ook gekleurd) te tonen. Een nieuwe regel blijft een nieuwe regel. Links, foto\'s en de volgorde van de teksten staan vast.</p>';
    $wijzer = ['bloemwerk' => 'De categorieën (zoals Bruidswerk en Rouwwerk) met hun inleidende tekst, en alle producten met omschrijving en prijs, pas je aan bij <a href="/beheer/?a=lijst&l=bloemwerk">Bloemwerk</a> en <a href="/beheer/?a=categorieen">Categorieën en inleidende teksten</a>.',
        'workshops' => 'De foto\'s, richtprijzen en referenties pas je aan bij <a href="/beheer/?a=workshops">Workshops</a>.',
        'groei-en-bloei' => 'De agenda en de foto\'s pas je aan bij <a href="/beheer/?a=groei">Groei en Bloei</a>.',
        'contact' => 'Het e-mailadres, telefoonnummer en de plaats pas je aan bij <a href="/beheer/?a=instellingen">Instellingen</a>.'];
    if (isset($wijzer[$n])) $f .= '<p class="melding">' . $wijzer[$n] . '</p>';
    $blokken = [];   // aaneengesloten velden met dezelfde sectienaam horen bij elkaar
    foreach ($velden as $v) {
        list($k, $label, $soort, $tekst) = $v;
        $delen = explode('::', $label, 2); $sectie = $delen[0]; $naam = $delen[1] ?? $delen[0];
        $veld = '<label>' . e($naam) . ($soort === 'lang'
            ? '<textarea name="t_' . e($k) . '" rows="' . max(3, min(9, (int)ceil(mb_strlen($tekst) / 70) + 1)) . '">' . e($tekst) . '</textarea>'
            : '<input name="t_' . e($k) . '" value="' . e($tekst) . '">') . '</label>';
        if ($blokken && $blokken[count($blokken) - 1][0] === $sectie) $blokken[count($blokken) - 1][1] .= $veld; else $blokken[] = [$sectie, $veld];
    }
    $webpagina = '';
    foreach ($blokken as $bl) {
        if ($bl[0] === 'Webpagina') $webpagina .= $bl[1];
        else $f .= '<fieldset><legend>' . e($bl[0]) . '</legend>' . $bl[1] . '</fieldset>';
    }
    if ($webpagina !== '') $f .= '<details class="b-geavanceerd"><summary>Geavanceerd (zelden nodig): titel en omschrijving voor Google</summary><div class="b-gea">' . $webpagina . '</div></details>';
    $f .= '<p><button class="b-knop">Opslaan</button> <a href="/beheer/?a=teksten">Annuleren</a></p></form>';
    kader('Teksten: ' . TEKSTPAGINAS[$n], $f);
}

versies_route($a, $post);

/* ---------- Foto's ---------- */
if ($a === 'media') {
    if ($post) {
        controleer_csrf();
        if (isset($_POST['verwijder'])) {
            $n = basename((string)$_POST['verwijder']); $url = MEDIA_URL . $n; $f = MEDIA . '/' . $n;
            if (media_gebruik($url) > 0) melding('Deze foto wordt nog gebruikt op de site. Haal hem eerst weg bij het product of de pagina waar hij staat.', true);
            elseif ($n !== '' && is_file($f) && media_bestaat($url)) { unlink($f); melding('Foto verwijderd.'); }
        } elseif (!empty($_FILES['bestand'])) {
            $fouten = []; $goed = 0; $fs = $_FILES['bestand'];
            for ($k = 0; $k < count((array)$fs['name']); $k++) {
                $enkel = ['name' => $fs['name'][$k], 'tmp_name' => $fs['tmp_name'][$k], 'error' => $fs['error'][$k], 'size' => $fs['size'][$k]];
                if ($enkel['error'] === UPLOAD_ERR_NO_FILE) continue;
                list($ok, $r) = verwerk_upload($enkel);
                if ($ok) $goed++; else $fouten[] = $enkel['name'] . ': ' . $r;
            }
            if ($fouten) melding(implode(' ', $fouten), true); elseif ($goed) melding($goed . ' foto\'s geüpload.');
        } else melding('Er is niets geüpload. De foto is mogelijk te groot voor de server (' . ini_get('post_max_size') . ').', true);
        ga('media');
    }
    $tegels = '<div class="b-tegels" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(11rem,1fr));gap:1rem">';
    foreach (media_lijst() as $m) {
        $gebruik = media_gebruik($m['url']);
        $tegels .= '<figure class="b-tegel" style="margin:0;background:#fff;border:1px solid var(--l);border-radius:.4rem;overflow:hidden"><img src="' . e($m['url']) . '" alt="" loading="lazy" style="width:100%;height:9rem;object-fit:cover;display:block">'
            . '<figcaption style="padding:.5rem;font-size:.8rem;word-break:break-all"><code>' . e($m['naam']) . '</code><br><small>' . round($m['grootte'] / 1024) . ' KB · ' . ($gebruik ? 'in gebruik' : 'niet in gebruik') . '</small>'
            . '<form method="post" action="/beheer/?a=media" style="margin-top:.4rem">' . csrf_veld() . '<button name="verwijder" value="' . e($m['naam']) . '" class="b-knop klein gevaar" onclick="return confirm(\'Deze foto verwijderen?\')">Verwijderen</button></form></figcaption></figure>';
    }
    kader('Foto\'s', '<form method="post" enctype="multipart/form-data" class="b-form" action="/beheer/?a=media">' . csrf_veld()
        . '<label>Nieuwe foto\'s kiezen (jpg, png, webp of gif; je mag er meerdere kiezen)<input type="file" name="bestand[]" multiple accept="image/*"></label><button class="b-knop">Uploaden</button></form>'
        . '<p class="b-hulp">Grote foto\'s worden automatisch verkleind. Je kiest een foto daarna bij het product of de workshop waar hij moet komen.</p>' . $tegels . '</div>');
}

/* ---------- Instellingen ---------- */
if ($a === 'instellen') ga();
if ($a === 'instellingen') {
    $prive = lees_prive('site'); $open = lees('instellingen');
    if ($post) {
        controleer_csrf();
        $ontv = p('ontvanger'); $afz = p('afzender');
        foreach ([$ontv, $afz, p('email')] as $m) if ($m !== '' && !filter_var($m, FILTER_VALIDATE_EMAIL)) { melding('Een e-mailadres klopt niet.', true); ga('instellingen'); }
        $offlineTekst = mb_substr(p('offline_tekst'), 0, 600) !== '' ? mb_substr(p('offline_tekst'), 0, 600) : OFFLINE_STANDAARD;
        bewaar_prive('site', array_merge($prive, ['ontvanger' => $ontv, 'afzender' => $afz, 'offlineTekst' => $offlineTekst]));
        $open['trouwambtenaarLive'] = !empty($_POST['live']);
        $open['email'] = p('email'); $open['telefoon'] = p('telefoon'); $open['plaats'] = p('plaats');
        $open['voet'] = p('voet'); $open['kleinedruk'] = p('kleinedruk');
        bewaar('instellingen', $open);
        if (file_put_contents(BASIS . '/offline.html', offline_html($offlineTekst, $open), LOCK_EX) === false) throw new RuntimeException('De pagina offline.html kon niet worden opgeslagen. Controleer de schrijfrechten.');
        if (!empty($_POST['offline'])) { if (file_put_contents(offline_flag(), date('c'), LOCK_EX) === false) throw new RuntimeException('De website kon niet offline worden gezet. Controleer de schrijfrechten van beheer/data.'); }
        else @unlink(offline_flag());
        melding(!empty($_POST['offline']) ? 'Instellingen opgeslagen. De website staat nu OFFLINE: bezoekers zien alleen een melding.' : 'Instellingen opgeslagen.'); ga('instellingen');
    }
    $f = function ($n, $l, $v, $t = 'text') { return '<label>' . $l . '<input type="' . $t . '" name="' . $n . '" value="' . e($v) . '"></label>'; };
    $offlineBlok = '<fieldset><legend>Website online of offline</legend><label class="b-aan"><input type="checkbox" name="offline" value="1"' . (is_offline() ? ' checked' : '') . '> Zet de hele website tijdelijk offline</label>'
        . '<label>Tekst voor bezoekers<textarea name="offline_tekst" rows="3">' . e($prive['offlineTekst'] ?? OFFLINE_STANDAARD) . '</textarea></label>'
        . '<p class="b-hulp">Staat dit aan, dan zien bezoekers alleen een pagina met deze tekst (en je e-mailadres en telefoonnummer, als je die hebt ingevuld). Het beheer blijft gewoon werken. Via <strong>Bekijk site</strong> bovenin zie je zelf nog wel de hele site. ' . (is_offline() ? '<a href="/beheer/?a=voorbeeld-uit">Zie de site zoals bezoekers hem zien</a>.' : '') . '</p></fieldset>';
    kader('Instellingen', (is_offline() ? '<p class="melding fout">De website staat nu <strong>offline</strong>. Bezoekers zien alleen een melding.</p>' : '') . '<form method="post" class="b-form" action="/beheer/?a=instellingen">' . csrf_veld() . $offlineBlok
        . '<fieldset><legend>Contactgegevens op de site</legend>' . $f('email', 'E-mailadres', $open['email'] ?? '', 'email') . $f('telefoon', 'Telefoonnummer', $open['telefoon'] ?? '') . $f('plaats', 'Plaats of regio', $open['plaats'] ?? '') . '</fieldset>'
        . '<details class="b-geavanceerd"><summary>Geavanceerd (zelden nodig): tekst onderaan elke pagina</summary><div class="b-gea">' . $f('voet', 'Korte tekst onder de naam Flor-Es', $open['voet'] ?? '') . $f('kleinedruk', 'Kleine regel helemaal onderaan (leeg = © 2026 Flor-Es)', $open['kleinedruk'] ?? '') . '</div></details>'
        . '<fieldset><legend>Contactformulier</legend>' . $f('ontvanger', 'Berichten uit het contactformulier komen binnen op dit e-mailadres', $prive['ontvanger'] ?? '', 'email')
        . $f('afzender', 'Afzenderadres (een adres van je eigen domein, anders komt de mail vaak in de spam)', $prive['afzender'] ?? '', 'email') . '</fieldset>'
        . '<fieldset><legend>Pagina Trouwambtenaar</legend><label class="b-aan"><input type="checkbox" name="live" value="1"' . (!empty($open['trouwambtenaarLive']) ? ' checked' : '') . '> Pagina Trouwambtenaar zichtbaar op de site en in het menu</label>'
        . '<p class="b-hulp">Staat dit uit, dan zien bezoekers de pagina niet. Je kunt hem zelf bekijken via <code>/trouwambtenaar.html?voorbeeld=1</code>.</p></fieldset>'
        . '<button class="b-knop">Opslaan</button></form>'
        . '<h2>Wachtwoord wijzigen</h2><form method="post" class="b-form" action="/beheer/?a=wachtwoord">' . csrf_veld()
        . '<label>Huidig wachtwoord<input type="password" name="oud" required autocomplete="current-password"></label><label>Nieuw wachtwoord (minstens 10 tekens)<input type="password" name="nieuw" required minlength="10" autocomplete="new-password"></label><button class="b-knop">Wachtwoord wijzigen</button></form>'
        . tweestap_blok());
}
if ($a === 'wachtwoord' && $post) {
    controleer_csrf();
    if (!password_verify((string)($_POST['oud'] ?? ''), $cfg['hash'])) melding('Het huidige wachtwoord klopt niet.', true);
    elseif (strlen((string)$_POST['nieuw']) < 10) melding('Kies een wachtwoord van minstens 10 tekens.', true);
    else { file_put_contents($CONFIG, "<?php\nreturn " . var_export(['hash' => password_hash($_POST['nieuw'], PASSWORD_DEFAULT)], true) . ";\n", LOCK_EX); melding('Wachtwoord gewijzigd.'); }
    ga('instellingen');
}

/* ---------- Startpagina ---------- */
/* ---------- Startpagina ---------- */
$todo = [];
if ((lees_prive('site')['ontvanger'] ?? '') === '') $todo[] = ['Er is nog geen e-mailadres ingesteld voor het contactformulier, dus berichten komen niet aan.', '/beheer/?a=instellingen', 'Instellen'];
if (is_offline()) $todo[] = ['De hele website staat offline. Bezoekers zien alleen een melding.', '/beheer/?a=instellingen', 'Online zetten'];
if (!tweestap_aan()) $todo[] = ['Tweestapsverificatie staat nog uit. Dat maakt het beheer veel veiliger.', '/beheer/?a=instellingen', 'Instellen'];
$zonderFoto = 0; foreach (lees('bloemwerk')['producten'] ?? [] as $pr) if (trim((string)($pr['foto'] ?? '')) === '') $zonderFoto++;
if ($zonderFoto) $todo[] = [$zonderFoto . ' product' . ($zonderFoto === 1 ? ' heeft' : 'en hebben') . ' nog geen foto.', '/beheer/?a=lijst&l=bloemwerk', 'Bekijken'];
$ins = lees('instellingen');
if (trim((string)($ins['email'] ?? '')) === '' || trim((string)($ins['telefoon'] ?? '')) === '') $todo[] = ['De contactgegevens op de site (e-mailadres of telefoonnummer) zijn nog niet allemaal ingevuld.', '/beheer/?a=instellingen', 'Invullen'];
$lijst = '';
foreach ($todo as $t) $lijst .= '<li><span>' . e($t[0]) . '</span> <a class="b-knop klein" href="' . e($t[1]) . '">' . e($t[2]) . '</a></li>';
kader('Welkom', '<h2 class="b-h2">Nu te doen</h2>' . ($lijst ? '<ul class="b-todo">' . $lijst . '</ul>' : '<p class="melding">Alles is bijgewerkt. Er is niets dat op je wacht.</p>')
    . '<h2 class="b-h2">Snel aan de slag</h2><div class="b-start">'
    . '<a href="/beheer/?a=item&l=bloemwerk&i=nieuw"><strong>Nieuw product</strong><span>Bijvoorbeeld een corsage of bloemstuk</span></a>'
    . '<a href="/beheer/?a=teksten"><strong>Een tekst aanpassen</strong><span>De teksten op alle pagina\'s</span></a>'
    . '<a href="/beheer/?a=media"><strong>Foto\'s uploaden</strong><span>Toevoegen en opruimen</span></a>'
    . '<a href="/beheer/?a=item&l=gb-agenda&i=nieuw"><strong>Workshop in de agenda</strong><span>Groei en Bloei</span></a></div>');
