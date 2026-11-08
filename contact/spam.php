<?php
// Gedeelde hulpfuncties tegen spam voor het contactformulier (rekenvraag + limiet per bezoeker).
// Doet niets als je dit bestand direct opent.

function spam_sleutel() {
    $pad = __DIR__ . '/../beheer/data/geheim.key';
    if (!is_file($pad)) { @file_put_contents($pad, bin2hex(random_bytes(32)), LOCK_EX); }
    $k = is_file($pad) ? trim((string)file_get_contents($pad)) : '';
    if ($k === '') { throw new RuntimeException('geheim.key kan niet worden aangemaakt'); }
    return $k;
}

// Maakt een ondertekende vraag-token: tijd.antwoord.nonce.handtekening
function maak_token($antwoord) {
    $basis = time() . '.' . (int)$antwoord . '.' . bin2hex(random_bytes(6));
    return $basis . '.' . hash_hmac('sha256', $basis, spam_sleutel());
}

function spam_bestand() { return __DIR__ . '/../beheer/data/limiet.json'; }

// Leest en bewerkt de limietgegevens onder een slot. $fn krijgt de data en geeft [nieuwe data, resultaat].
function met_limiet($fn) {
    $f = fopen(spam_bestand(), 'c+');
    if (!$f) { return null; }
    flock($f, LOCK_EX);
    $d = json_decode((string)stream_get_contents($f), true);
    if (!is_array($d)) { $d = []; }
    $nu = time();
    $d['ip'] = $d['ip'] ?? []; $d['gebruikt'] = $d['gebruikt'] ?? [];
    foreach ($d['ip'] as $k => $lijst) { $d['ip'][$k] = array_values(array_filter($lijst, function ($t) use ($nu) { return $t > $nu - 86400; })); if (!$d['ip'][$k]) unset($d['ip'][$k]); }
    foreach ($d['gebruikt'] as $k => $t) { if ($t < $nu - 7200) unset($d['gebruikt'][$k]); }
    list($d, $res) = $fn($d, $nu);
    ftruncate($f, 0); rewind($f); fwrite($f, json_encode($d)); fflush($f); flock($f, LOCK_UN); fclose($f);
    return $res;
}

// Controleert de token en het antwoord. Geeft null als alles klopt, anders een melding voor de bezoeker.
function controleer_rekenvraag($token, $antwoord) {
    $d = explode('.', (string)$token);
    if (count($d) !== 4) return 'De controlevraag ontbreekt. Ververs de pagina en probeer het opnieuw.';
    list($tijd, $juist, $nonce, $sig) = $d;
    if (!hash_equals(hash_hmac('sha256', "$tijd.$juist.$nonce", spam_sleutel()), $sig)) return 'De controlevraag is ongeldig. Ververs de pagina en probeer het opnieuw.';
    $leeftijd = time() - (int)$tijd;
    if ($leeftijd < 3) return 'Dat ging wel erg snel. Probeer het over een paar seconden nog eens.';
    if ($leeftijd > 7200) return 'Het formulier stond te lang open. Ververs de pagina en probeer het opnieuw.';
    $nieuw = met_limiet(function ($d, $nu) use ($nonce) {
        if (isset($d['gebruikt'][$nonce])) return [$d, false];
        $d['gebruikt'][$nonce] = $nu; return [$d, true];  // elke vraag kan maar één keer worden beantwoord
    });
    if ($nieuw !== true) return 'Deze controlevraag is al gebruikt. Ververs de pagina en probeer het opnieuw.';
    if (trim((string)$antwoord) !== (string)(int)$juist) return 'Het antwoord op de rekenvraag klopt niet. Ververs de pagina voor een nieuwe vraag.';
    return null;
}

// Maximaal 3 berichten per uur en 8 per dag per bezoeker. Telt meteen mee als het mag. Geeft true/false.
function mag_versturen() {
    $id = hash_hmac('sha256', $_SERVER['REMOTE_ADDR'] ?? 'onbekend', spam_sleutel());
    return met_limiet(function ($d, $nu) use ($id) {
        $l = $d['ip'][$id] ?? [];
        $uur = count(array_filter($l, function ($t) use ($nu) { return $t > $nu - 3600; }));
        if ($uur >= 3 || count($l) >= 8) return [$d, false];
        $d['ip'][$id][] = $nu; return [$d, true];
    }) === true;
}
