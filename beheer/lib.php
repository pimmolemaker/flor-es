<?php
// Hulpfuncties voor het beheer van Flor-Es (naar het voorbeeld van pimmolemaker.nl).
// Werkt met PHP 7.4 of nieuwer, zonder database. De inhoud van de site staat in data/*.json,
// die door site.js wordt ingelezen. Privé-gegevens (inlog, reservekopieën, mailadres) staan in beheer/data/.

define('BASIS', dirname(__DIR__));
define('DATA', __DIR__ . '/data');          // privé, niet via de browser te lezen
define('OPENBAAR', BASIS . '/data');        // inhoud van de site
define('MEDIA', BASIS . '/img');
define('MEDIA_URL', '/img/');

function e($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

/* ---------- Opslag ---------- */

function lees_pad($pad, $standaard = []) {
    if (!is_file($pad)) return $standaard;
    $d = json_decode((string)file_get_contents($pad), true);
    return is_array($d) ? $d : $standaard;
}
function lees($naam, $standaard = []) { return lees_pad(OPENBAAR . "/$naam.json", $standaard); }
function lees_prive($naam, $standaard = []) { return lees_pad(DATA . "/$naam.json", $standaard); }

function schrijf_json($pad, $data) {
    $tmp = $pad . '.tmp';
    if (file_put_contents($tmp, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), LOCK_EX) === false) {
        throw new RuntimeException('Opslaan is mislukt. Controleer of de map schrijfrechten heeft.');
    }
    rename($tmp, $pad);
}

// Maakt eerst een reservekopie van de vorige versie (de laatste 30 per bestand blijven bewaard).
function bewaar_pad($pad, $naam, $data) {
    if (!is_dir(DATA . '/backup')) @mkdir(DATA . '/backup', 0755, true);
    if (is_file($pad)) {
        // twee wijzigingen in dezelfde seconde krijgen elk een eigen bestand
        $stempel = date('Ymd-His'); $nr = count(glob(DATA . "/backup/$naam-$stempel*.json") ?: []) + 1;
        @copy($pad, DATA . "/backup/$naam-$stempel-" . str_pad((string)$nr, 3, '0', STR_PAD_LEFT) . '.json');
        $oud = glob(DATA . "/backup/$naam-*.json") ?: [];
        sort($oud);
        while (count($oud) > 80) { @unlink(array_shift($oud)); }
    }
    schrijf_json($pad, $data);
}
function bewaar($naam, $data) { bewaar_pad(OPENBAAR . "/$naam.json", $naam, $data); }
function bewaar_prive($naam, $data) { bewaar_pad(DATA . "/$naam.json", "prive-$naam", $data); }

/* ---------- Afbeeldingen ---------- */

const BESCHERMD = ['logo.jpg']; // het beeldmerk is geen foto voor de pagina's

function media_lijst() {
    $uit = [];
    foreach (glob(MEDIA . '/*') ?: [] as $f) {
        if (!is_file($f) || in_array(basename($f), BESCHERMD, true)) continue;
        if (!in_array(strtolower(pathinfo($f, PATHINFO_EXTENSION)), ['jpg', 'jpeg', 'png', 'webp', 'gif'], true)) continue;
        $uit[] = ['naam' => basename($f), 'url' => MEDIA_URL . basename($f), 'grootte' => filesize($f), 'tijd' => filemtime($f)];
    }
    usort($uit, function ($a, $b) { return $b['tijd'] <=> $a['tijd']; });
    return $uit;
}
function media_bestaat($url) {
    foreach (media_lijst() as $m) if ($m['url'] === $url) return true;
    return false;
}
// Welke foto's worden nog gebruikt? (voor de waarschuwing bij verwijderen)
function media_gebruik($url) {
    $n = 0;
    foreach (['bloemwerk', 'workshops', 'groei-en-bloei'] as $b) $n += substr_count(json_encode(lees($b), JSON_UNESCAPED_SLASHES), '"' . $url . '"');
    return $n;
}

function bestandsnaam_schoon($naam) {
    $ext = strtolower(pathinfo($naam, PATHINFO_EXTENSION));
    $basis = strtolower(pathinfo($naam, PATHINFO_FILENAME));
    $basis = preg_replace('~[^a-z0-9]+~', '-', iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $basis) ?: $basis);
    return [trim($basis, '-') ?: 'foto', $ext];
}

// Verwerkt één geüpload bestand. Geeft [true, url] of [false, foutmelding].
function verwerk_upload($f) {
    if (!isset($f['error']) || $f['error'] !== UPLOAD_ERR_OK) {
        $codes = [UPLOAD_ERR_INI_SIZE => 'De foto is groter dan de server toestaat (' . ini_get('upload_max_filesize') . ').', UPLOAD_ERR_FORM_SIZE => 'De foto is te groot.', UPLOAD_ERR_NO_FILE => 'Er is geen foto gekozen.'];
        return [false, $codes[$f['error'] ?? 0] ?? 'Uploaden is mislukt.'];
    }
    list($basis, $ext) = bestandsnaam_schoon($f['name']);
    if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true)) return [false, 'Dit bestandstype is niet toegestaan. Kies een jpg, png, webp of gif.'];
    $i = @getimagesize($f['tmp_name']);
    if (!$i || !in_array($i[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_GIF, IMAGETYPE_WEBP], true)) return [false, 'Dit is geen geldige afbeelding.'];
    if ($f['size'] > 15 * 1024 * 1024) return [false, 'De foto is groter dan 15 MB.'];
    if (!is_dir(MEDIA)) mkdir(MEDIA, 0755, true);
    $naam = "$basis.$ext"; $n = 2;
    while (file_exists(MEDIA . "/$naam") || in_array($naam, BESCHERMD, true)) { $naam = "$basis-$n.$ext"; $n++; }
    if (!move_uploaded_file($f['tmp_name'], MEDIA . "/$naam")) return [false, 'Opslaan is mislukt. Controleer of de map img schrijfrechten heeft.'];
    verklein_afbeelding(MEDIA . "/$naam", $i[2]);
    return [true, MEDIA_URL . $naam];
}

// Maakt grote foto's kleiner (max 2000 px breed) zodat de site snel blijft. Doet niets als GD ontbreekt.
function verklein_afbeelding($pad, $type) {
    if (!function_exists('imagecreatetruecolor') || $type === IMAGETYPE_GIF) return;
    $i = getimagesize($pad);
    if ($i[0] <= 2000) return;
    $im = $type === IMAGETYPE_JPEG ? @imagecreatefromjpeg($pad) : ($type === IMAGETYPE_PNG ? @imagecreatefrompng($pad) : @imagecreatefromwebp($pad));
    if (!$im) return;
    if ($type === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
        $ex = @exif_read_data($pad);
        $o = $ex['Orientation'] ?? 1;
        if ($o == 3) $im = imagerotate($im, 180, 0);
        elseif ($o == 6) $im = imagerotate($im, -90, 0);
        elseif ($o == 8) $im = imagerotate($im, 90, 0);
    }
    $nieuw = imagescale($im, 2000);
    if (!$nieuw) return;
    if ($type === IMAGETYPE_JPEG) imagejpeg($nieuw, $pad, 85);
    elseif ($type === IMAGETYPE_PNG) imagepng($nieuw, $pad, 6);
    else imagewebp($nieuw, $pad, 85);
}

function veilige_url($u) { return $u === '' || preg_match('~^(https?://|mailto:|tel:|/)~i', $u) === 1; }
