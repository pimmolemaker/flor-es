<?php
// Beheerpagina van Flor-Es  →  https://<jouw domein>/beheer/
// Zelfde opzet als het beheer van pimmolemaker.nl: eigen inlog, tweede stap (app), reservekopieën.
require __DIR__ . '/lib.php';
require __DIR__ . '/tweestap.php';

$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
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

function kader($titel, $inhoud, $nav = true) {
    $m = '';
    if (!empty($_SESSION['melding'])) { list($t, $f) = $_SESSION['melding']; unset($_SESSION['melding']); $m = '<p class="melding' . ($f ? ' fout' : '') . '" role="status">' . e($t) . '</p>'; }
    $menu = '';
    if ($nav && ingelogd()) {
        $menu = '<nav><a href="/beheer/">Start</a><a href="/beheer/?a=lijst&l=bloemwerk">Bloemwerk</a><a href="/beheer/?a=workshops">Workshops</a><a href="/beheer/?a=groei">Groei en Bloei</a>'
              . '<a href="/beheer/?a=media">Foto\'s</a><a href="/beheer/?a=instellingen">Instellingen</a><a href="/" target="_blank" rel="noopener">Bekijk site ↗</a>'
              . '<form method="post" action="/beheer/?a=uitloggen">' . csrf_veld() . '<button>Uitloggen</button></form></nav>';
    }
    echo '<!doctype html><html lang="nl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex,nofollow">'
       . '<title>' . e($titel) . ' – Beheer Flor-Es</title><link rel="stylesheet" href="/beheer/beheer.css"></head><body>'
       . '<header class="b-kop"><strong>Beheer Flor-Es</strong>' . $menu . '</header><main class="b-inhoud">' . $m . '<h1>' . e($titel) . '</h1>' . $inhoud . '</main></body></html>';
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
if ($a === 'categorieen') {
    $data = lees('bloemwerk'); $cats = $data['categorieen'] ?? [];
    if ($post) {
        controleer_csrf();
        foreach ($cats as $k => $c) $cats[$k]['intro'] = p('intro_' . $k);
        if (p('nieuw_naam') !== '') {
            foreach ($cats as $c) if (mb_strtolower($c['naam']) === mb_strtolower(p('nieuw_naam'))) { melding('Deze categorie bestaat al.', true); ga('categorieen'); }
            $cats[] = ['naam' => p('nieuw_naam'), 'intro' => p('nieuw_intro')];
        }
        $data['categorieen'] = $cats; bewaar('bloemwerk', $data);
        melding('Opgeslagen.'); ga('categorieen');
    }
    $f = '<form method="post" class="b-form" action="/beheer/?a=categorieen">' . csrf_veld();
    foreach ($cats as $k => $c) $f .= '<label>' . e($c['naam']) . ': inleidende tekst op de pagina<textarea name="intro_' . $k . '" rows="3">' . e($c['intro'] ?? '') . '</textarea></label>';
    $f .= '<fieldset><legend>Nieuwe categorie toevoegen</legend><label>Naam<input name="nieuw_naam"></label><label>Inleidende tekst<textarea name="nieuw_intro" rows="3"></textarea></label></fieldset>'
        . '<p><button class="b-knop">Opslaan</button> <a href="/beheer/?a=lijst&l=bloemwerk">Terug naar de producten</a></p>'
        . '<p class="b-hulp">Een categorie hernoemen of weghalen kan niet vanaf hier, omdat producten eraan hangen. Vraag dat desnoods aan Pim.</p></form>';
    kader('Categorieën bloemwerk', $f);
}

/* ---------- Overzichten ---------- */
function overzicht($titel, $regels) {
    $h = '<div class="b-start">';
    foreach ($regels as $r) { $h .= '<a href="/beheer/?a=lijst&l=' . $r[0] . '"><strong>' . e($r[1]) . '</strong><span>' . $r[2] . '</span></a>'; }
    kader($titel, $h . '</div><p class="b-hulp">De gewone teksten van de pagina\'s (bijvoorbeeld de uitleg bovenaan) staan in de pagina\'s zelf. Wil je daar iets aan veranderen? Vraag het dan aan Pim.</p>');
}
function aantal($b, $s) { return count(lees($b)[$s] ?? []); }
if ($a === 'workshops') overzicht('Workshops', [['ws-fotos', 'Foto\'s', aantal('workshops', 'fotos') . ' foto\'s'], ['ws-prijzen', 'Richtprijzen', aantal('workshops', 'prijzen') . ' prijzen'], ['ws-referenties', 'Referenties', aantal('workshops', 'referenties') . ' referenties']]);
if ($a === 'groei') overzicht('Groei en Bloei', [['gb-agenda', 'Agenda', aantal('groei-en-bloei', 'agenda') . ' workshops'], ['gb-fotos', 'Foto\'s', aantal('groei-en-bloei', 'fotos') . ' foto\'s']]);

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
        bewaar_prive('site', array_merge($prive, ['ontvanger' => $ontv, 'afzender' => $afz]));
        $open['trouwambtenaarLive'] = !empty($_POST['live']);
        $open['email'] = p('email'); $open['telefoon'] = p('telefoon'); $open['plaats'] = p('plaats');
        bewaar('instellingen', $open);
        melding('Instellingen opgeslagen.'); ga('instellingen');
    }
    $f = function ($n, $l, $v, $t = 'text') { return '<label>' . $l . '<input type="' . $t . '" name="' . $n . '" value="' . e($v) . '"></label>'; };
    kader('Instellingen', '<form method="post" class="b-form" action="/beheer/?a=instellingen">' . csrf_veld()
        . '<fieldset><legend>Contactgegevens op de site</legend>' . $f('email', 'E-mailadres', $open['email'] ?? '', 'email') . $f('telefoon', 'Telefoonnummer', $open['telefoon'] ?? '') . $f('plaats', 'Plaats of regio', $open['plaats'] ?? '') . '</fieldset>'
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
$mailwaarschuwing = (lees_prive('site')['ontvanger'] ?? '') === '' ? '<p class="melding fout">Let op: er is nog geen e-mailadres ingesteld voor het contactformulier. <a href="/beheer/?a=instellingen">Doe dat nu</a>, anders komen berichten niet aan.</p>' : '';
kader('Welkom', $mailwaarschuwing . '<div class="b-start">'
    . '<a href="/beheer/?a=item&l=bloemwerk&i=nieuw"><strong>Nieuw product</strong><span>Bijvoorbeeld een corsage of bloemstuk</span></a>'
    . '<a href="/beheer/?a=lijst&l=bloemwerk"><strong>Bloemwerk</strong><span>' . aantal('bloemwerk', 'producten') . ' producten</span></a>'
    . '<a href="/beheer/?a=workshops"><strong>Workshops</strong><span>Foto\'s, prijzen, referenties</span></a>'
    . '<a href="/beheer/?a=groei"><strong>Groei en Bloei</strong><span>Agenda en foto\'s</span></a>'
    . '<a href="/beheer/?a=media"><strong>Foto\'s</strong><span>Uploaden en opruimen</span></a>'
    . '<a href="/beheer/?a=instellingen"><strong>Instellingen</strong><span>Contactgegevens, wachtwoord, tweede stap</span></a></div>');
