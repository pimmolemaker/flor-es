<?php
// Verwerkt het contactformulier en mailt het bericht naar Flor-Es.
// Het ontvangstadres en het afzenderadres stel je in via het beheer (Instellingen).
$inst = @json_decode((string)@file_get_contents(__DIR__ . '/../beheer/data/site.json'), true) ?: [];
$ontvanger = $inst['ontvanger'] ?? '';
$afzender  = (!empty($inst['afzender']) && filter_var($inst['afzender'], FILTER_VALIDATE_EMAIL)) ? $inst['afzender'] : '';
// Hostnet: het afzenderadres moet een bestaand e-mailadres bij Hostnet zijn, van dezelfde domeinnaam als de site.

header('Content-Type: text/html; charset=utf-8');

function pagina($titel, $tekst, $fout = false) {
    $klasse = $fout ? 'form-msg' : 'form-msg';
    echo '<!doctype html><html lang="nl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
       . '<title>' . htmlspecialchars($titel) . ' – Flor-Es</title><link rel="stylesheet" href="/style.css"></head><body>'
       . '<main class="container" style="padding:4rem 0"><h1>' . htmlspecialchars($titel) . '</h1><p class="' . $klasse . '" style="display:block">' . $tekst . '</p>'
       . '<p><a href="javascript:history.back()">Terug naar het formulier</a> · <a href="/">Naar de homepage</a></p></main></body></html>';
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: /contact.html'); exit; }
if ($ontvanger === '' || $afzender === '') { pagina('Nog niet ingesteld', 'Het contactformulier is nog niet ingesteld. Probeer het later opnieuw of bel ons.', true); }

// Spamcontrole 1: het verborgen veld moet leeg zijn (bots vullen het in)
if (!empty($_POST['website'])) { pagina('Bedankt', 'Je bericht is verstuurd.'); }

// Spamcontrole 2: rekenvraag (ondertekend, eenmalig, minstens 3 seconden na het openen)
require __DIR__ . '/spam.php';
try {
    $fout = controleer_rekenvraag($_POST['token'] ?? '', $_POST['antwoord'] ?? '');
    if ($fout !== null) { pagina('Controle mislukt', htmlspecialchars($fout), true); }
    // Spamcontrole 3: maximaal 3 berichten per uur per bezoeker
    if (!mag_versturen()) { pagina('Even rust', 'Je hebt kort geleden al meerdere berichten gestuurd. Probeer het later nog eens.', true); }
} catch (Throwable $x) {
    pagina('Er ging iets mis', 'Het formulier kon niet worden gecontroleerd. Probeer het later nog eens.', true);
}

function schoon($s) { return trim(str_replace(["\r", "\n"], ' ', (string)$s)); } // voorkomt misbruik van mailkoppen
$naam      = schoon($_POST['naam'] ?? '');
$email     = schoon($_POST['email'] ?? '');
$telefoon  = schoon($_POST['telefoon'] ?? '');
$datum     = schoon($_POST['datum'] ?? '');
$onderwerp = schoon($_POST['onderwerp'] ?? '');
$bericht   = trim((string)($_POST['bericht'] ?? ''));

if ($naam === '' || $onderwerp === '' || $bericht === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    pagina('Er ging iets mis', 'Vul je naam, een geldig e-mailadres en je bericht in.', true);
}
if (mb_strlen($bericht) > 5000 || mb_strlen($naam) > 100 || mb_strlen($onderwerp) > 150 || mb_strlen($telefoon) > 40 || mb_strlen($datum) > 80) {
    pagina('Er ging iets mis', 'Een van de velden is te lang.', true);
}

$tekst = "Nieuw bericht via de website van Flor-Es\n\nOnderwerp: $onderwerp\nNaam: $naam\nE-mail: $email\nTelefoon: " . ($telefoon ?: '-') . "\nGewenste datum: " . ($datum ?: '-') . "\n\n$bericht\n";
$koppen = "MIME-Version: 1.0\r\nFrom: Website Flor-Es <$afzender>\r\nReply-To: $naam <$email>\r\nContent-Type: text/plain; charset=utf-8\r\nX-Mailer: PHP/" . phpversion();
// Vijfde parameter "-f..." zet het 'envelope from'-adres, zoals Hostnet vraagt
$ok = mail($ontvanger, '=?UTF-8?B?' . base64_encode('Website Flor-Es: ' . $onderwerp) . '?=', $tekst, $koppen, '-f' . $afzender);

if ($ok) { pagina('Bedankt', 'Je bericht is verstuurd. Esther neemt zo snel mogelijk contact met je op.'); }
pagina('Verzenden mislukt', 'Het bericht kon niet worden verstuurd. Probeer het later nog eens.', true);
