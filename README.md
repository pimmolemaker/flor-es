# Flor-Es

Website van bloemiste Esther Leefoge. Gewone HTML, CSS en JavaScript, met een klein beheer in PHP
(dezelfde opzet als pimmolemaker.nl). Draait op Hostnet (PHP 7.4 of nieuwer, geen database).

## Pagina's
`index`, `over`, `workshops`, `groei-en-bloei`, `bloemwerk`, `trouwen-op-maat`, `contact`
en de proefpagina `trouwambtenaar` (nog niet live, zie hieronder).

## 1. Uploaden naar Hostnet
Zet alles uit deze repository in de map van het domein (vaak `public_html` of `httpdocs`),
behalve `README.md`, `.git` en `.gitignore`.

## 2. Beheer eenmalig instellen
1. Ga naar `https://<domein>/beheer/`. De eerste keer maakt de site zelf het bestand
   `beheer/data/eerste-keer-code.txt` aan. Open dat via het bestandsbeheer van Hostnet.
2. Vul de code in en kies een wachtwoord van minstens 10 tekens.
3. Ga naar Instellingen en vul het e-mailadres in waar de berichten uit het contactformulier binnenkomen,
   en een afzenderadres van het eigen domein (anders komt de mail vaak in de spam). Zet ook de contactgegevens neer.
4. Zet bij Instellingen de tweestapsverificatie aan (aanbevolen).
5. Stuur jezelf een testbericht via het contactformulier.

## 3. Controleren
- Open `https://<domein>/beheer/data/site.json`. Je moet een foutmelding (403) krijgen. Zie je tekst,
  vraag Hostnet dan de map `beheer/data` af te schermen.
- De mappen `data`, `img` en `beheer/data` moeten door de website beschreven kunnen worden (standaard bij Hostnet).

## Wat zit er in
- `*.html`, `style.css`, `site.js`: de pagina's en het ontwerp
- `data/*.json`: de inhoud die het beheer aanpast (producten, workshopprijzen, referenties, agenda, instellingen).
  `site.js` leest dit in en toont het op de pagina's
- `img/`: foto's (de foto's die je via het beheer uploadt komen hier)
- `beheer/`: het beheer (inloggen, tweede stap, producten, foto's, instellingen) met reservekopieën van de laatste 30 wijzigingen in `beheer/data/backup`
- `contact/`: verstuurt het contactformulier, met spambeveiliging (verborgen veld, rekenvraag, wachttijd, limiet per bezoeker)

## Let op: de inhoud staat op de server, niet alleen hier
Wat Esther via het beheer wijzigt, staat in `data/*.json` en `img/` **op Hostnet**. Als je later alle bestanden
uit deze repository opnieuw uploadt, overschrijf je die. Download dan eerst `data/` en `img/` van Hostnet
(of upload alleen `*.html`, `style.css`, `site.js`, `beheer/*.php` en `contact/*.php`).

## Pagina Trouwambtenaar
Staat niet in het menu, is op `noindex` gezet en stuurt bezoekers door naar de homepage zolang
"Pagina Trouwambtenaar zichtbaar" bij Instellingen uit staat. Bekijken kan met `trouwambtenaar.html?voorbeeld=1`.
De tekst van de pagina staat wel in de bestanden van de site, dit is dus geen beveiliging.

Teksten en prijzen met "00" zijn voorbeeldtekst. Zonder PHP (bijvoorbeeld een Vercel-preview) werken het beheer en het contactformulier niet.
