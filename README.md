# Flor-Es

Website van bloemiste Esther Leefoge: gewone HTML, CSS en JavaScript, zonder build-stap.

## Pagina's
`index`, `over`, `workshops`, `groei-en-bloei`, `bloemwerk`, `trouwbegeleiding`, `contact`
en de proefpagina `trouwambtenaar` (nog niet live, zie hieronder).

## Inhoud beheren
Producten, foto's, prijzen, referenties en de agenda staan in `data/*.json`. Het beheerscherm
(Pages CMS, instelling in `.pages.yml`) bewerkt die bestanden: inloggen op https://app.pagescms.org
met GitHub en de repository `flor-es` kiezen. Een wijziging wordt als commit opgeslagen en Vercel
zet de site automatisch online. Foto's komen in de map `img/`.

## Pagina Trouwambtenaar
Staat niet in het menu, is op `noindex` gezet en stuurt bezoekers door naar de homepage zolang
`trouwambtenaarLive` in `data/instellingen.json` op `false` staat. Bekijken kan met
`trouwambtenaar.html?voorbeeld=1`. Live zetten: de instelling op `true` zetten (of via het beheerscherm).
De tekst van de pagina staat wel in de bestanden van de site, dit is dus geen beveiliging.

Het contactformulier verstuurt nog niets. Teksten en prijzen met "00" zijn voorbeeldtekst.
