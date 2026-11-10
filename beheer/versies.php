<?php
// Vorige versies van de teksten en gegevens: bekijken en terugzetten.
// Bij elke wijziging bewaart het beheer de vorige stand in beheer/data/backup. Een versie terugzetten bewaart ook de huidige stand.

const VERSIE_DATA = [
    'bloemwerk' => 'Bloemwerk: producten en categorieën',
    'workshops' => 'Workshops: foto\'s, prijzen en referenties',
    'groei-en-bloei' => 'Groei en Bloei: agenda en foto\'s',
    'instellingen' => 'Instellingen: contactgegevens en voettekst',
];
const VERSIE_BEGRIPPEN = ['producten' => 'Product', 'categorieen' => 'Categorie', 'fotos' => 'Foto', 'prijzen' => 'Richtprijs', 'referenties' => 'Referentie', 'agenda' => 'Workshop'];

function versie_datum($bestand) {
    if (!preg_match('~-(\d{4})(\d{2})(\d{2})-(\d{2})(\d{2})(\d{2})(?:-\d+)?\.(?:json|html)$~', $bestand, $m)) return $bestand;
    $mnd = ['', 'jan', 'feb', 'mrt', 'apr', 'mei', 'jun', 'jul', 'aug', 'sep', 'okt', 'nov', 'dec'];
    return (int)$m[3] . ' ' . $mnd[(int)$m[2]] . ' ' . $m[1] . ' om ' . $m[4] . '.' . $m[5];
}

function versie_sleutel_ok($s) {
    if (strpos($s, 'pagina:') === 0) return isset(TEKSTPAGINAS[substr($s, 7)]);
    if (strpos($s, 'data:') === 0) return isset(VERSIE_DATA[substr($s, 5)]);
    return false;
}
function versie_titel($s) { return strpos($s, 'pagina:') === 0 ? 'Teksten: ' . TEKSTPAGINAS[substr($s, 7)] : VERSIE_DATA[substr($s, 5)]; }

// Bestanden met eerdere standen, de nieuwste eerst
function versie_bestanden($s) {
    $soort = substr($s, 0, strpos($s, ':')); $naam = substr($s, strpos($s, ':') + 1);
    $re = $soort === 'pagina' ? '~^pagina-' . preg_quote($naam, '~') . '-\d{8}-\d{6}(?:-\d{3})?\.html$~' : '~^' . preg_quote($naam, '~') . '-\d{8}-\d{6}(?:-\d{3})?\.json$~';
    $f = array_filter(glob(DATA . '/backup/*') ?: [], function ($p) use ($re) { return preg_match($re, basename($p)) === 1; });
    rsort($f);
    return array_values($f);
}

function versie_kort($t, $n = 60) {
    $t = trim(preg_replace('~\s+~', ' ', (string)$t));
    if ($t === '') return '(leeg)';
    return function_exists('mb_substr') && mb_strlen($t) > $n ? mb_substr($t, 0, $n) . '…' : $t;
}

/* ---------- Teksten van een pagina ---------- */
function versie_pagina_map($html) { $m = []; foreach (tekst_velden_uit($html) as $v) $m[$v[0]] = $v; return $m; }

function versie_pagina_verschil($nu, $toen) {
    $regels = [];
    foreach ($toen as $k => $v) {
        if (!isset($nu[$k]) || $nu[$k][4] === $v[4]) continue;
        $delen = explode('::', $v[1], 2);
        $regels[] = ($delen[0] === 'Webpagina' ? 'Google' : $delen[0]) . ' – ' . ($delen[1] ?? $delen[0]) . ': nu «' . versie_kort($nu[$k][3]) . '», in deze versie «' . versie_kort($v[3]) . '»';
    }
    return $regels;
}

function versies_van_pagina($naam) {
    $nu = versie_pagina_map((string)@file_get_contents(tekst_pad($naam)));
    $stand = function ($m) { $r = []; foreach ($m as $k => $v) $r[$k] = $v[4]; return md5(json_encode($r)); };
    $gezien = [$stand($nu) => true]; $uit = [];
    foreach (versie_bestanden('pagina:' . $naam) as $f) {
        $m = versie_pagina_map((string)@file_get_contents($f)); $k = $stand($m);
        if (isset($gezien[$k])) continue;
        $gezien[$k] = true;
        $uit[] = ['bestand' => basename($f), 'regels' => versie_pagina_verschil($nu, $m)];
        if (count($uit) >= 40) break;
    }
    return $uit;
}

function versie_pagina_terugzetten($naam, $bestand) {
    $oud = versie_pagina_map((string)file_get_contents(DATA . '/backup/' . $bestand));
    $html = (string)file_get_contents(tekst_pad($naam)); $orig = $html;
    $html = preg_replace_callback(TEKST_PATROON, function ($m) use ($oud) {
        return isset($oud[$m[1]]) ? '<!--t:' . $m[1] . '|' . $m[2] . '|' . $m[3] . '-->' . $oud[$m[1]][4] . '<!--/t-->' : $m[0];
    }, $html);
    if (isset($oud['_titel'])) $html = preg_replace_callback('~<title>.*?</title>~s', function () use ($oud) { return '<title>' . $oud['_titel'][4] . '</title>'; }, $html, 1);
    if (isset($oud['_omschrijving'])) $html = preg_replace_callback('~(<meta name="description" content=")[^"]*(">)~', function ($m) use ($oud) { return $m[1] . $oud['_omschrijving'][4] . $m[2]; }, $html, 1);
    if ($html === $orig) return false;
    if (strpos($html, '<main>') === false || strpos($html, '</main>') === false) throw new RuntimeException('De pagina zou beschadigd raken. Er is niets teruggezet.');
    tekst_reservekopie($naam);
    $tmp = tekst_pad($naam) . '.tmp';
    if (file_put_contents($tmp, $html, LOCK_EX) === false) throw new RuntimeException('Opslaan is mislukt. Controleer de schrijfrechten.');
    rename($tmp, tekst_pad($naam));
    return true;
}

/* ---------- Gegevens (producten, prijzen, enzovoort) ---------- */
function versie_vlak($d, $pad = '') {
    $uit = [];
    foreach ($d as $k => $v) {
        $p = $pad === '' ? (string)$k : $pad . '/' . $k;
        if (is_array($v)) { $uit += versie_vlak($v, $p); if (!$v) $uit[$p] = '[]'; }
        else $uit[$p] = is_bool($v) ? ($v ? 'aan' : 'uit') : (string)$v;
    }
    return $uit;
}

// Is dit een lijst van items (bijvoorbeeld de producten)? Dan vergelijken we op naam en niet op plaats in de lijst.
function versie_is_itemlijst($v) {
    if (!is_array($v) || !$v || array_keys($v) !== range(0, count($v) - 1)) return false;
    foreach ($v as $x) if (!is_array($x)) return false;
    return true;
}
function versie_items($lijst) {
    $uit = []; $teller = [];
    foreach ((array)$lijst as $it) {
        $id = '';
        foreach (['naam', 'titel', 'foto', 'tekst', 'bijschrift'] as $f) { if (!empty($it[$f]) && is_string($it[$f])) { $id = $it[$f]; break; } }
        $teller[$id] = ($teller[$id] ?? 0) + 1;
        $uit[$id . '#' . $teller[$id]] = ['naam' => $id, 'item' => $it];
    }
    return $uit;
}
function versie_veld_regels($label, $nu, $toen) {
    $a = versie_vlak($nu); $b = versie_vlak($toen); $regels = [];
    foreach (array_unique(array_merge(array_keys($a), array_keys($b))) as $pad) {
        if (($a[$pad] ?? null) === ($b[$pad] ?? null)) continue;
        $regels[] = $label . ($pad !== '' ? ($label !== '' ? ' – ' : '') . str_replace('/', ' ', $pad) : '') . ': nu «' . versie_kort($a[$pad] ?? '') . '», in deze versie «' . versie_kort($b[$pad] ?? '') . '»';
    }
    return $regels;
}

function versie_data_verschil($nu, $toen) {
    $regels = [];
    foreach (array_unique(array_merge(array_keys($nu), array_keys($toen))) as $sl) {
        $x = $nu[$sl] ?? null; $y = $toen[$sl] ?? null;
        if (!versie_is_itemlijst($x) && !versie_is_itemlijst($y)) {
            $regels = array_merge($regels, versie_veld_regels('', [$sl => $x ?? ''], [$sl => $y ?? '']));
            continue;
        }
        $begrip = VERSIE_BEGRIPPEN[$sl] ?? ucfirst($sl);
        $ni = versie_items($x ?? []); $ti = versie_items($y ?? []);
        foreach ($ti as $id => $t) if (!isset($ni[$id])) $regels[] = $begrip . ' «' . versie_kort($t['naam'], 40) . '»: komt terug (staat nu niet meer op de site)';
        foreach ($ni as $id => $n) if (!isset($ti[$id])) $regels[] = $begrip . ' «' . versie_kort($n['naam'], 40) . '»: verdwijnt (staat nu wel op de site, in deze versie nog niet)';
        foreach ($ni as $id => $n) if (isset($ti[$id])) {
            foreach (versie_veld_regels('', $n['item'], $ti[$id]['item']) as $r) $regels[] = $begrip . ' «' . versie_kort($n['naam'], 40) . '» – ' . $r;
        }
        if (array_keys($ni) !== array_keys($ti) && !array_diff_key($ni, $ti) && !array_diff_key($ti, $ni)) $regels[] = 'De volgorde van de ' . strtolower($begrip) . 's is anders';
    }
    return $regels;
}

function versies_van_data($naam) {
    $nu = lees($naam); $gezien = [md5(json_encode($nu)) => true]; $uit = [];
    foreach (versie_bestanden('data:' . $naam) as $f) {
        $d = json_decode((string)@file_get_contents($f), true);
        if (!is_array($d)) continue;
        $k = md5(json_encode($d));
        if (isset($gezien[$k])) continue;
        $gezien[$k] = true;
        $uit[] = ['bestand' => basename($f), 'regels' => versie_data_verschil($nu, $d)];
        if (count($uit) >= 40) break;
    }
    return $uit;
}

function versies_van($s) { return strpos($s, 'pagina:') === 0 ? versies_van_pagina(substr($s, 7)) : versies_van_data(substr($s, 5)); }

/* ---------- Schermen ---------- */
function versies_route($a, $post) {
    if ($a !== 'versies' && $a !== 'versie-terug') return;
    $b = (string)($_GET['b'] ?? '');

    if ($a === 'versie-terug' && $post) {
        controleer_csrf();
        $b = (string)($_POST['b'] ?? ''); $v = (string)($_POST['v'] ?? '');
        if (!versie_sleutel_ok($b) || !in_array($v, array_map('basename', versie_bestanden($b)), true)) { melding('Die versie is niet gevonden.', true); ga('versies'); }
        if (strpos($b, 'pagina:') === 0) {
            versie_pagina_terugzetten(substr($b, 7), $v);
        } else {
            $d = json_decode((string)file_get_contents(DATA . '/backup/' . $v), true);
            if (!is_array($d)) { melding('Die versie kon niet worden gelezen.', true); ga('versies', '&b=' . $b); }
            bewaar(substr($b, 5), $d);
        }
        melding('De versie van ' . versie_datum($v) . ' is teruggezet. De stand van vlak daarvoor vind je terug onder Vorige versies.');
        ga('versies', '&b=' . $b);
    }

    if ($b === '' || !versie_sleutel_ok($b)) {
        $rij = function ($s) { $n = count(versies_van($s)); return '<tr><td><strong>' . e(versie_titel($s)) . '</strong><br><small>' . ($n ? $n . ' eerdere ' . ($n === 1 ? 'versie' : 'versies') : 'nog geen eerdere versies') . '</small></td>'
            . '<td class="b-acties">' . ($n ? '<a class="b-knop klein" href="/beheer/?a=versies&b=' . e($s) . '">Bekijken</a>' : '') . '</td></tr>'; };
        $t = '';
        foreach (TEKSTPAGINAS as $n => $titel) $t .= $rij('pagina:' . $n);
        $g = '';
        foreach (VERSIE_DATA as $n => $titel) $g .= $rij('data:' . $n);
        kader('Vorige versies', '<p class="b-hulp">Bij elke wijziging bewaart het beheer de vorige stand. Heb je per ongeluk iets gewijzigd of gewist, zet dan hier een eerdere versie terug. Je huidige stand blijft dan ook bewaard.</p>'
            . '<h2 class="b-h2">Teksten van de pagina\'s</h2><table class="b-tabel">' . $t . '</table>'
            . '<h2 class="b-h2">Producten, prijzen en andere gegevens</h2><table class="b-tabel">' . $g . '</table>');
    }

    $lijst = versies_van($b); $uit = '';
    foreach ($lijst as $v) {
        $uit .= '<div class="b-versie"><div><strong>' . e(versie_datum($v['bestand'])) . '</strong> <small>(zo stond het toen)</small>';
        if ($v['regels']) {
            $uit .= '<ul class="b-verschil">';
            foreach (array_slice($v['regels'], 0, 12) as $r) $uit .= '<li>' . e($r) . '</li>';
            if (count($v['regels']) > 12) $uit .= '<li>… en nog ' . (count($v['regels']) - 12) . ' andere wijzigingen</li>';
            $uit .= '</ul>';
        } else $uit .= '<p class="b-hulp">Verschilt van nu in onderdelen die hier niet worden getoond.</p>';
        $uit .= '</div><form method="post" action="/beheer/?a=versie-terug">' . csrf_veld() . '<input type="hidden" name="b" value="' . e($b) . '"><input type="hidden" name="v" value="' . e($v['bestand']) . '">'
            . '<button class="b-knop klein" onclick="return confirm(\'Deze versie terugzetten? De huidige stand blijft bewaard onder Vorige versies.\')">Zet deze versie terug</button></form></div>';
    }
    kader('Vorige versies: ' . versie_titel($b), '<p><a href="/beheer/?a=versies">← Alle onderdelen</a></p>'
        . '<p class="b-hulp">Hieronder staat per eerdere versie wat er zou veranderen als je hem terugzet. De nieuwste versie staat bovenaan.</p>'
        . ($uit ?: '<p class="melding">Er zijn nog geen eerdere versies. Ze verschijnen zodra je iets wijzigt.</p>'));
}
