<?php
// Eenmalige updates van de pagina's, met behoud van de teksten die in het beheer zijn aangepast.
// Elke update is veilig om vaker uit te voeren: wat al is bijgewerkt, wordt overgeslagen.

// Trouwen op maat heet voortaan Trouwplanner: nieuwe naam en adres, verwijzingen, en een knop op de homepage.
function migratie_trouwplanner_pagina($naam, $html) {
    $nieuw = preg_replace('~href="trouwen-op-maat\.html~', 'href="trouwplanner.html', $html);
    // Alleen teksten die nog precies de oude naam hebben; teksten die zijn aangepast blijven zoals ze zijn
    $nieuw = preg_replace_callback(TEKST_PATROON, function ($m) use ($naam) {
        $inner = $m[4];
        if ($naam === 'trouwplanner' && $inner === 'Trouwen op maat' && strpos($m[2], 'Kleine kop boven de titel') !== false) $inner = 'Begeleiding bij jullie trouwdag';
        elseif ($inner === 'Trouwen op maat') $inner = 'Trouwplanner';
        elseif ($inner === 'Combineren met bloemwerk en Trouwen op maat') $inner = 'Combineren met bloemwerk en Trouwplanner';
        return '<!--t:' . $m[1] . '|' . $m[2] . '|' . $m[3] . '-->' . $inner . '<!--/t-->';
    }, $nieuw);
    $nieuw = str_replace('<title>Trouwen op maat | Flor-Es</title>', '<title>Trouwplanner | Flor-Es</title>', $nieuw);
    if ($naam === 'contact') $nieuw = str_replace('<option>Trouwen op maat</option>', '<option>Trouwplanner</option>', $nieuw);
    if ($naam === 'index' && strpos($nieuw, 'Tekst op de knop 3|') === false) {
        preg_match_all('~<!--t:k(\d+)\|~', $nieuw, $ks);
        $volgende = $ks[1] ? max(array_map('intval', $ks[1])) + 1 : 1;
        $knop = '<a class="btn outline" href="trouwplanner.html"><!--t:k' . sprintf('%02d', $volgende) . '|Bovenaan de pagina::Tekst op de knop 3|kort-->Trouwplanner<!--/t--></a>';
        $nieuw = preg_replace_callback('~<a class="btn outline" href="over\.html">.*?</a>~s', function ($m) use ($knop) { return $m[0] . "\n        " . $knop; }, $nieuw, 1);
    }
    return $nieuw;
}

// Geeft een lijst met wat er verandert. Met $uitvoeren = true wordt het ook gedaan (met eerst een reservekopie van elke pagina).
function migratie_trouwplanner($uitvoeren) {
    $wijzigingen = [];
    $oud = BASIS . '/trouwen-op-maat.html'; $nw = BASIS . '/trouwplanner.html';
    $hernoem = is_file($oud) && !is_file($nw);
    if ($hernoem) {
        $wijzigingen[] = 'De pagina Trouwen op maat krijgt het adres trouwplanner.html. Het oude adres stuurt door naar het nieuwe, en de eerdere versies van de pagina blijven bewaard.';
        if ($uitvoeren) {
            if (!rename($oud, $nw)) throw new RuntimeException('De pagina kon niet worden hernoemd. Controleer de schrijfrechten.');
            foreach (glob(DATA . '/backup/pagina-trouwen-op-maat-*.html') ?: [] as $f) @rename($f, str_replace('pagina-trouwen-op-maat-', 'pagina-trouwplanner-', $f));
        }
    }
    foreach (array_keys(TEKSTPAGINAS) as $naam) {
        $pad = BASIS . '/' . $naam . '.html';
        if (!is_file($pad) && $naam === 'trouwplanner' && $hernoem && !$uitvoeren) $pad = $oud;
        if (!is_file($pad)) continue;
        $html = (string)file_get_contents($pad); $nieuw = migratie_trouwplanner_pagina($naam, $html);
        if ($nieuw === $html) continue;
        $wijzigingen[] = 'Pagina ' . TEKSTPAGINAS[$naam] . ': de naam Trouwplanner en de verwijzingen worden bijgewerkt' . ($naam === 'index' && strpos($html, 'Tekst op de knop 3|') === false ? ', en er komt een knop Trouwplanner bij de knoppen bovenaan' : '') . '.';
        if ($uitvoeren) {
            if (strpos($nieuw, '<main>') === false || strpos($nieuw, '</main>') === false) throw new RuntimeException('Een pagina zou beschadigd raken. Er is niets meer gewijzigd.');
            tekst_reservekopie($naam);
            $tmp = $pad . '.tmp';
            if (file_put_contents($tmp, $nieuw, LOCK_EX) === false) throw new RuntimeException('Opslaan is mislukt. Controleer de schrijfrechten.');
            rename($tmp, $pad);
        }
    }
    $d = lees('bloemwerk'); $gewijzigd = false;
    foreach ($d['categorieen'] ?? [] as $i => $c) {
        if (($c['knop2pagina'] ?? '') === 'trouwen-op-maat.html') {
            $d['categorieen'][$i]['knop2pagina'] = 'trouwplanner.html'; $gewijzigd = true;
            if (($c['knop2'] ?? '') === 'Trouwen op maat') $d['categorieen'][$i]['knop2'] = 'Trouwplanner';
        }
    }
    if ($gewijzigd) { $wijzigingen[] = 'Bloemwerk: de extra knop die naar Trouwen op maat wees, wijst voortaan naar Trouwplanner.'; if ($uitvoeren) bewaar('bloemwerk', $d); }
    return $wijzigingen;
}

function migratie_nodig() { return migratie_trouwplanner(false) !== []; }

function migraties_route($a, $post) {
    if ($a === 'migratie-uitvoeren' && $post) {
        controleer_csrf();
        $w = migratie_trouwplanner(true);
        melding($w ? 'De pagina\'s zijn bijgewerkt. Van elke pagina is eerst een reservekopie bewaard (zie Vorige versies).' : 'Er was niets bij te werken.');
        ga('');
    }
    if ($a !== 'migratie') return;
    $w = migratie_trouwplanner(false); $lijst = '';
    foreach ($w as $r) $lijst .= '<li>' . e($r) . '</li>';
    kader('Pagina\'s bijwerken', $w
        ? '<p>Trouwen op maat heet voortaan <strong>Trouwplanner</strong>. Dit wordt bijgewerkt:</p><ul>' . $lijst . '</ul><p class="b-hulp">Teksten die Esther of jij al hebt aangepast, blijven zoals ze zijn. Alleen de teksten die nog precies "Trouwen op maat" zeggen, veranderen mee.</p>'
          . '<form method="post" action="/beheer/?a=migratie-uitvoeren">' . csrf_veld() . '<button class="b-knop">Nu bijwerken</button> <a href="/beheer/">Annuleren</a></form>'
        : '<p class="melding">Alles is al bijgewerkt.</p><p><a href="/beheer/">Terug naar de start</a></p>');
}
