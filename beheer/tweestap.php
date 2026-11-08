<?php
// Tweestapsverificatie (TOTP) voor het beheer: na het wachtwoord vraagt het beheer een code van 6 cijfers uit een app.
// Het bestand beheer/tweede-stap.php bestaat alleen als het aan staat. Verwijder dat bestand via de File Manager om het uit te zetten.
// Werkt met de gewone apps: Google Authenticator, Microsoft Authenticator, Authy, 1Password enzovoort.

define('TWEESTAP_BESTAND', __DIR__ . '/tweede-stap.php');

// De gegevens staan als JSON achter een regel PHP die direct stopt, zodat ze nooit via de browser te lezen zijn.
// We lezen het bestand als tekst (niet met include), zodat PHP het niet een paar seconden uit het geheugen haalt.
function tweestap_data() {
    if (!is_file(TWEESTAP_BESTAND)) return [];
    clearstatcache(true, TWEESTAP_BESTAND);
    $t = (string)file_get_contents(TWEESTAP_BESTAND);
    $p = strpos($t, "\n");
    $d = $p === false ? null : json_decode(substr($t, $p + 1), true);
    return is_array($d) ? $d : [];
}
function tweestap_aan() { $d = tweestap_data(); return !empty($d['geheim']); }
function tweestap_schrijf($d) { file_put_contents(TWEESTAP_BESTAND, "<?php http_response_code(404); exit; ?>\n" . json_encode($d), LOCK_EX); }

/* ---------- Rekenwerk (RFC 4226 / 6238) ---------- */

function b32_encode($bin) {
    $alf = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'; $bits = ''; $uit = '';
    foreach (str_split($bin) as $c) $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
    foreach (str_split($bits, 5) as $s) $uit .= $alf[bindec(str_pad($s, 5, '0'))];
    return $uit;
}
function b32_decode($s) {
    $alf = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'; $bits = ''; $uit = '';
    foreach (str_split(strtoupper(preg_replace('~[^A-Za-z2-7]~', '', $s))) as $c) $bits .= str_pad(decbin(strpos($alf, $c)), 5, '0', STR_PAD_LEFT);
    foreach (str_split($bits, 8) as $b) if (strlen($b) === 8) $uit .= chr(bindec($b));
    return $uit;
}
function totp_stap($geheim, $stap) {
    $h = hash_hmac('sha1', pack('J', $stap), b32_decode($geheim), true);
    $o = ord($h[19]) & 15;
    $v = unpack('N', substr($h, $o, 4))[1] & 0x7fffffff;
    return str_pad((string)($v % 1000000), 6, '0', STR_PAD_LEFT);
}
// Geeft de tijdstap terug als de code klopt (met 30 seconden marge) en niet eerder is gebruikt; anders false.
function totp_controle($geheim, $code, $laatst) {
    $nu = (int)floor(time() / 30);
    for ($d = -1; $d <= 1; $d++) {
        $stap = $nu + $d;
        if ($stap <= (int)$laatst) continue;
        if (hash_equals(totp_stap($geheim, $stap), $code)) return $stap;
    }
    return false;
}
function herstelcode_norm($c) { return strtolower(preg_replace('~[^0-9a-fA-F]~', '', (string)$c)); }
function herstelcodes_maken() {
    $codes = []; $hashes = [];
    for ($i = 0; $i < 8; $i++) { $c = bin2hex(random_bytes(5)); $codes[] = substr($c, 0, 5) . '-' . substr($c, 5); $hashes[] = hash('sha256', $c); }
    return [$codes, $hashes];
}

/* ---------- Begrenzing van pogingen (zelfde bestand als het inloggen) ---------- */

function code_sleutel() { return 'c' . md5($_SERVER['REMOTE_ADDR'] ?? 'x'); }
function code_lees() { $d = is_file(DATA . '/inlog.json') ? json_decode((string)file_get_contents(DATA . '/inlog.json'), true) : []; return is_array($d) ? $d : []; }
function code_geblokkeerd() { $r = code_lees()[code_sleutel()] ?? ['n' => 0, 't' => 0]; return $r['n'] >= 5 && time() - $r['t'] < 900; }
function code_fout() { $d = code_lees(); $r = $d[code_sleutel()] ?? ['n' => 0, 't' => 0]; if ($r['n'] >= 5) $r = ['n' => 0, 't' => 0]; $d[code_sleutel()] = ['n' => $r['n'] + 1, 't' => time()]; schrijf_json(DATA . '/inlog.json', $d); }
function code_wis() { $d = code_lees(); unset($d[code_sleutel()]); schrijf_json(DATA . '/inlog.json', $d); }

// Controleert een app-code of herstelcode en werkt het bestand bij. Geeft 'app', 'herstel' of false.
function code_accepteren($ingevoerd) {
    $d = tweestap_data(); $code = preg_replace('~\s+~', '', (string)$ingevoerd);
    if (preg_match('~^\d{6}$~', $code)) {
        $st = totp_controle($d['geheim'], $code, $d['laatst'] ?? 0);
        if ($st !== false) { $d['laatst'] = $st; tweestap_schrijf($d); return 'app'; }
        return false;
    }
    $h = hash('sha256', herstelcode_norm($code));
    foreach ($d['herstel'] ?? [] as $i => $x) {
        if (hash_equals($x, $h)) { unset($d['herstel'][$i]); $d['herstel'] = array_values($d['herstel']); tweestap_schrijf($d); return 'herstel'; }
    }
    return false;
}

/* ---------- Pagina's: na het wachtwoord ---------- */

function tweestap_login_route($a, $post) {
    if ($a !== 'code') return;
    if (ingelogd() || empty($_SESSION['stap2']) || time() - $_SESSION['stap2'] > 600 || !tweestap_aan()) { unset($_SESSION['stap2']); ga('inloggen'); }
    if ($post) {
        controleer_csrf();
        if (code_geblokkeerd()) { unset($_SESSION['stap2']); melding('Te veel mislukte pogingen. Probeer het over 15 minuten opnieuw.', true); ga('inloggen'); }
        $res = code_accepteren($_POST['code'] ?? '');
        if ($res) {
            code_wis(); unset($_SESSION['stap2']); session_regenerate_id(true); $_SESSION['in'] = true;
            if ($res === 'herstel') melding('Je hebt een herstelcode gebruikt. Je hebt er nog ' . count(tweestap_data()['herstel'] ?? []) . ' over.');
            ga();
        }
        code_fout(); sleep(1); melding('Die code klopt niet. Probeer het opnieuw.', true); ga('code');
    }
    kader('Tweede stap', '<p>Open de verificatie-app op je telefoon en vul de code van 6 cijfers in. Die verandert elke 30 seconden.</p>'
        . '<form method="post" action="/beheer/?a=code" class="b-form">' . csrf_veld()
        . '<label>Code<input name="code" required autofocus autocomplete="one-time-code" inputmode="numeric" maxlength="20"></label>'
        . '<button class="b-knop">Doorgaan</button></form>'
        . '<p class="b-hulp">Telefoon niet bij de hand? Vul dan een van je herstelcodes in (bijvoorbeeld a1b2c-3d4e5). Elke herstelcode werkt één keer.</p>'
        . '<p><a href="/beheer/?a=inloggen">Terug naar inloggen</a></p>', false);
}

/* ---------- Pagina's: als je bent ingelogd ---------- */

function tweestap_ingelogd_route($a, $post) {
    global $cfg;
    if ($a === 'tweestap-start' && $post) {
        controleer_csrf();
        if (tweestap_aan()) ga('instellingen');
        $_SESSION['nieuw_geheim'] = b32_encode(random_bytes(20));
        ga('tweestap-instellen');
    }
    if ($a === 'tweestap-instellen') {
        if (empty($_SESSION['nieuw_geheim']) || tweestap_aan()) ga('instellingen');
        $g = $_SESSION['nieuw_geheim'];
        $uri = 'otpauth://totp/' . rawurlencode('Flor-Es:beheer') . '?secret=' . $g . '&issuer=' . rawurlencode('Flor-Es') . '&algorithm=SHA1&digits=6&period=30';
        kader('Tweestapsverificatie instellen',
            '<ol class="b-stappen"><li><strong>Installeer een app</strong> op je telefoon, bijvoorbeeld Google Authenticator of Microsoft Authenticator (gratis).</li>'
            . '<li><strong>Scan deze QR-code</strong> met de app (kies "Code scannen" of het plusje).<div id="qr" class="b-qr" data-uri="' . e($uri) . '" role="img" aria-label="QR-code voor de verificatie-app"></div>'
            . '<p class="b-hulp">Lukt scannen niet? Kies in de app voor "Sleutel handmatig invoeren" en typ: <code id="sleutel">' . e(trim(chunk_split($g, 4, ' '))) . '</code></p></li>'
            . '<li><strong>Vul de code in</strong> die de app nu toont om te bevestigen dat het werkt.</li></ol>'
            . '<form method="post" action="/beheer/?a=tweestap-bevestig" class="b-form">' . csrf_veld()
            . '<label>Code van 6 cijfers<input name="code" required autofocus autocomplete="off" inputmode="numeric" maxlength="6" pattern="[0-9]{6}"></label>'
            . '<button class="b-knop">Bevestigen en aanzetten</button></form><p><a href="/beheer/?a=instellingen">Annuleren</a></p>'
            . '<script src="/beheer/qrcode.js"></script><script>(function(){var d=document.getElementById("qr");try{var q=qrcode(0,"M");q.addData(d.getAttribute("data-uri"));q.make();d.innerHTML=q.createSvgTag(5,2);}catch(e){d.textContent="QR-code kon niet worden getoond. Gebruik de sleutel hieronder.";}})();</script>');
    }
    if ($a === 'tweestap-bevestig' && $post) {
        controleer_csrf();
        if (empty($_SESSION['nieuw_geheim']) || tweestap_aan()) ga('instellingen');
        $st = totp_controle($_SESSION['nieuw_geheim'], preg_replace('~\s+~', '', (string)($_POST['code'] ?? '')), 0);
        if ($st === false) { melding('Die code klopt niet. Controleer de tijd op je telefoon en probeer het opnieuw.', true); ga('tweestap-instellen'); }
        list($codes, $hashes) = herstelcodes_maken();
        tweestap_schrijf(['geheim' => $_SESSION['nieuw_geheim'], 'laatst' => $st, 'herstel' => $hashes, 'sinds' => date('Y-m-d H:i')]);
        unset($_SESSION['nieuw_geheim']); $_SESSION['herstel_nieuw'] = $codes;
        ga('tweestap-herstel');
    }
    if ($a === 'tweestap-herstel') {
        if (empty($_SESSION['herstel_nieuw'])) ga('instellingen');
        $codes = $_SESSION['herstel_nieuw']; unset($_SESSION['herstel_nieuw']);
        kader('Tweestapsverificatie staat aan', '<p class="melding">Gelukt! Vanaf nu vraagt het beheer na je wachtwoord om een code uit je app.</p>'
            . '<h2>Bewaar je herstelcodes</h2><p>Dit zijn je noodcodes voor als je telefoon kwijt is. <strong>Je ziet ze maar één keer.</strong> Schrijf ze op of bewaar ze in een wachtwoordmanager, niet op dezelfde telefoon. Elke code werkt één keer.</p>'
            . '<ul class="b-codes">' . implode('', array_map(function ($c) { return '<li><code>' . e($c) . '</code></li>'; }, $codes)) . '</ul>'
            . '<p><a class="b-knop" href="/beheer/?a=instellingen">Ik heb ze bewaard</a></p>');
    }
    if ($a === 'tweestap-uit' && $post) {
        controleer_csrf();
        if (!tweestap_aan()) ga('instellingen');
        if (code_geblokkeerd()) { melding('Te veel mislukte pogingen. Probeer het over 15 minuten opnieuw.', true); ga('instellingen'); }
        if (!password_verify((string)($_POST['wachtwoord'] ?? ''), $cfg['hash']) || !code_accepteren($_POST['code'] ?? '')) {
            code_fout(); sleep(1); melding('Het wachtwoord of de code klopt niet. Tweestapsverificatie blijft aan.', true); ga('instellingen');
        }
        code_wis(); @unlink(TWEESTAP_BESTAND); melding('Tweestapsverificatie is uitgezet.'); ga('instellingen');
    }
}

// Het blok op de pagina Instellingen
function tweestap_blok() {
    if (!tweestap_aan()) {
        return '<h2>Tweestapsverificatie</h2><p>Staat <strong>uit</strong>. Met een code uit een app op je telefoon naast je wachtwoord kan niemand anders inloggen, ook niet met je wachtwoord.</p>'
            . '<form method="post" action="/beheer/?a=tweestap-start">' . csrf_veld() . '<button class="b-knop">Tweestapsverificatie instellen</button></form>';
    }
    $over = count(tweestap_data()['herstel'] ?? []);
    return '<h2>Tweestapsverificatie</h2><p class="melding">Staat <strong>aan</strong>. Je hebt nog ' . $over . ' herstelcode' . ($over === 1 ? '' : 's') . ' over.</p>'
        . '<form method="post" class="b-form" action="/beheer/?a=tweestap-uit">' . csrf_veld()
        . '<p class="b-hulp">Uitzetten kan alleen met je wachtwoord en een code.</p>'
        . '<label>Wachtwoord<input type="password" name="wachtwoord" required autocomplete="current-password"></label>'
        . '<label>Code uit je app (of herstelcode)<input name="code" required autocomplete="off" inputmode="numeric"></label>'
        . '<button class="b-knop gevaar">Uitzetten</button></form>';
}
