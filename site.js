// Flor-Es: gedeelde kop en voet, en inhoud uit de map data/.
// De inhoud in data/*.json wordt via het beheerscherm (Pages CMS, zie .pages.yml) beheerd.
(function () {
  var PAGINAS = [
    ["index.html", "Home"],
    ["over.html", "Over"],
    ["bloemwerk.html", "Bloemwerk"],
    ["workshops.html", "Workshops"],
    ["groei-en-bloei.html", "Groei en Bloei"],
    ["trouwen-op-maat.html", "Trouwen op maat"]
  ];
  var AMBTENAAR = ["trouwambtenaar.html", "Trouwambtenaar"];

  function el(tag, attrs, kinderen) {
    var e = document.createElement(tag);
    Object.keys(attrs || {}).forEach(function (k) {
      if (k === "tekst") e.textContent = attrs[k];
      else if (k === "klasse") e.className = attrs[k];
      else e.setAttribute(k, attrs[k]);
    });
    (kinderen || []).forEach(function (c) { if (c) e.appendChild(c); });
    return e;
  }
  function veiligeLink(url) {
    return /^(https?:\/\/|mailto:|tel:|\/|[\w-]+\.html)/i.test(url || "") ? url : "#";
  }
  function laad(bestand) {
    // "no-cache": de browser vraagt steeds of het bestand veranderd is, zodat wijzigingen uit het beheer meteen zichtbaar zijn
    return fetch(bestand, { cache: "no-cache" }).then(function (r) {
      if (!r.ok) throw new Error(bestand);
      return r.json();
    });
  }
  function foto(f, extra) {
    if (f && f.foto) {
      return el("img", { src: f.foto, alt: f.bijschrift || f.naam || "", loading: "lazy", klasse: "foto " + (extra || "") });
    }
    return el("div", { klasse: "ph " + (extra || ""), tekst: "Foto" });
  }

  var huidig = location.pathname.split("/").pop() || "index.html";
  if (huidig === "") huidig = "index.html";
  var params = new URLSearchParams(location.search);
  var voorbeeld = params.get("voorbeeld") === "1";

  // ---- Kop en voet ----
  function bouwKop(metAmbtenaar) {
    var lijst = PAGINAS.slice();
    if (metAmbtenaar) lijst.push(AMBTENAAR);
    var ul = el("ul", { id: "menu" });
    lijst.forEach(function (p) {
      var a = el("a", { href: p[0], tekst: p[1] });
      if (p[0] === huidig) a.setAttribute("aria-current", "page");
      ul.appendChild(el("li", {}, [a]));
    });
    ul.appendChild(el("li", {}, [el("a", { klasse: "btn small", href: "contact.html", tekst: "Contact" })]));
    var knop = el("button", { klasse: "menu-toggle", "aria-expanded": "false", "aria-controls": "menu", tekst: "Menu" });
    knop.addEventListener("click", function () {
      var open = ul.classList.toggle("open");
      knop.setAttribute("aria-expanded", open ? "true" : "false");
    });
    var merk = el("a", { klasse: "brand", href: "index.html" }, [
      el("img", { src: "img/logo.jpg", alt: "" }),
      el("span", { tekst: "Flor-Es" })
    ]);
    var kop = document.getElementById("site-header");
    if (!kop) return;
    kop.className = "site-header";
    kop.textContent = "";
    kop.appendChild(el("div", { klasse: "container nav" }, [merk, knop, ul]));
  }

  var inst = {};
  function bouwVoet(metAmbtenaar) {
    var voet = document.getElementById("site-footer");
    if (!voet) return;
    voet.className = "site-footer";
    voet.textContent = "";
    var lijst = PAGINAS.concat(metAmbtenaar ? [AMBTENAAR] : []).concat([["contact.html", "Contact"]]);
    var ul = el("ul", {});
    lijst.forEach(function (p) { ul.appendChild(el("li", {}, [el("a", { href: p[0], tekst: p[1] })])); });
    var contact = el("ul", {});
    [inst.email || "info@flor-es.nl", inst.telefoon || "06 - 00 00 00 00", inst.plaats || "Veenendaal e.o."].forEach(function (t) { contact.appendChild(el("li", { tekst: t })); });
    voet.appendChild(el("div", { klasse: "container" }, [
      el("div", { klasse: "foot-grid" }, [
        el("div", {}, [el("h3", { tekst: "Flor-Es" }), el("p", { tekst: inst.voet || "Bloemwerk, workshops en trouwen, persoonlijk en met aandacht." })]),
        el("div", {}, [el("h3", { tekst: "Pagina's" }), ul]),
        el("div", {}, [el("h3", { tekst: "Contact" }), contact])
      ]),
      el("div", { klasse: "copy", tekst: inst.kleinedruk || "© 2026 Flor-Es" })
    ]));
  }

  bouwKop(false);
  bouwVoet(false);

  // ---- Instellingen: is de trouwambtenaar-pagina live? ----
  var gate = document.body.hasAttribute("data-niet-live");
  laad("data/instellingen.json").catch(function () { return {}; }).then(function (gegevens) {
    inst = gegevens;
    [].forEach.call(document.querySelectorAll("[data-inst]"), function (n) {
      var w = inst[n.getAttribute("data-inst")];
      if (w) n.textContent = w;
    });
    var live = inst.trouwambtenaarLive === true;
    bouwKop(live || (gate && voorbeeld));
    bouwVoet(live || (gate && voorbeeld));
    if (gate) {
      if (live || voorbeeld) {
        document.body.classList.add("toon");
        if (!live) {
          var banner = el("div", { klasse: "proef-banner", tekst: "Proefpagina: deze pagina staat nog niet live en is niet zichtbaar in het menu." });
          document.body.insertBefore(banner, document.body.firstChild);
        }
      } else {
        location.replace("index.html");
      }
    }
  });

  // ---- Bloemwerk: producten per categorie ----
  var bw = document.getElementById("bloemwerk-lijst");
  if (bw) {
    laad("data/bloemwerk.json").then(function (d) {
      var cats = d.categorieen || [];
      var producten = d.producten || [];
      var nav = document.getElementById("bloemwerk-nav");
      cats.forEach(function (c, i) {
        var items = producten.filter(function (p) { return p.categorie === c.naam; });
        var id = "cat-" + i;
        if (nav) nav.appendChild(el("a", { href: "#" + id, klasse: "chip", tekst: c.naam }));
        var grid = el("div", { klasse: "producten" });
        items.forEach(function (p) {
          grid.appendChild(el("article", { klasse: "product" }, [
            foto(p, "product-foto"),
            el("div", { klasse: "body" }, [
              el("h3", { tekst: p.naam || "" }),
              p.omschrijving ? el("p", { tekst: p.omschrijving }) : null,
              p.prijs ? el("p", { klasse: "prijs", tekst: p.prijs }) : null
            ])
          ]));
        });
        if (!items.length) grid.appendChild(el("p", { klasse: "leeg", tekst: "Hier komen binnenkort producten." }));
        var tekstblok = null;
        if (c.werkwijze || c.tekstkop || c.knop2pagina) {
          var alineas = String(c.werkwijze || "").split(/\n+/).filter(Boolean).map(function (t) { return el("p", { tekst: t }); });
          var extraknop = null;
          if (c.knop2pagina && /^[\w-]+\.html$/.test(c.knop2pagina)) {
            var paginanaam = (PAGINAS.concat([["contact.html", "Contact"]]).filter(function (x) { return x[0] === c.knop2pagina; })[0] || [0, "Meer informatie"])[1];
            extraknop = el("p", { klasse: "btn-row" }, [el("a", { klasse: "btn small", href: c.knop2pagina, tekst: c.knop2 || paginanaam })]);
          }
          tekstblok = el("div", { klasse: "cat-tekst" }, [c.tekstkop ? el("h3", { klasse: "cat-kop", tekst: c.tekstkop }) : null].concat(alineas).concat([extraknop]));
        }
        bw.appendChild(el("section", { klasse: "section" + (i % 2 ? " tint" : ""), id: id }, [
          el("div", { klasse: "container" }, [
            el("div", { klasse: "kop-blok" }, [el("h2", { tekst: c.naam }), c.intro ? el("p", { klasse: "lead", tekst: c.intro }) : null]),
            grid,
            tekstblok,
            el("p", { klasse: "btn-row" }, [el("a", { klasse: "btn outline small", href: "contact.html?onderwerp=" + encodeURIComponent(c.naam), tekst: c.knop || "Wil je weten wat er mogelijk is? Neem contact op." })])
          ])
        ]));
      });
    }).catch(function () { bw.appendChild(el("p", { klasse: "container leeg", tekst: "De producten konden niet worden geladen." })); });
  }

  // ---- Bloemwerk: knop "Hoe werk ik?" toont de werkwijze uit de pagina Over ----
  var wknop = document.getElementById("werkwijze-knop");
  var wpaneel = document.getElementById("werkwijze-paneel");
  if (wknop && wpaneel) {
    var geladen = false;
    var wTekst = wknop.textContent.trim() || "Hoe werk ik?";
    wknop.addEventListener("click", function (e) {
      if (!wpaneel.hidden) {
        wpaneel.hidden = true; wknop.setAttribute("aria-expanded", "false"); wknop.textContent = wTekst;
        e.preventDefault(); return;
      }
      if (geladen) {
        wpaneel.hidden = false; wknop.setAttribute("aria-expanded", "true"); wknop.textContent = "Verberg werkwijze";
        e.preventDefault(); return;
      }
      e.preventDefault();
      fetch("over.html").then(function (r) { return r.text(); }).then(function (t) {
        var bron = new DOMParser().parseFromString(t, "text/html").getElementById("werkwijze");
        if (!bron) throw new Error("geen werkwijze");
        var kopie = document.importNode(bron, true);
        kopie.removeAttribute("id");
        wpaneel.appendChild(kopie);
        geladen = true; wpaneel.hidden = false;
        wknop.setAttribute("aria-expanded", "true"); wknop.textContent = "Verberg werkwijze";
      }).catch(function () { location.href = wknop.getAttribute("href"); });
    });
  }

  // ---- Workshops: foto's, prijzen, referenties ----
  var ws = document.getElementById("ws-data");
  if (ws) {
    laad("data/workshops.json").then(function (d) {
      var g = document.getElementById("ws-fotos");
      (d.fotos || []).forEach(function (f) { g.appendChild(foto(f)); });
      var p = document.getElementById("ws-prijzen");
      (d.prijzen || []).forEach(function (x) {
        p.appendChild(el("div", { klasse: "price" }, [
          el("h3", { tekst: x.titel || "" }),
          el("div", { klasse: "amount", tekst: x.bedrag || "" }),
          x.per ? el("p", {}, [el("small", { tekst: x.per })]) : null,
          x.toelichting ? el("p", { tekst: x.toelichting }) : null
        ]));
      });
      var r = document.getElementById("ws-referenties");
      (d.referenties || []).forEach(function (x) {
        r.appendChild(el("div", { klasse: "quote" }, [el("p", { tekst: "“" + (x.tekst || "") + "”" }), el("cite", { tekst: x.naam || "" })]));
      });
    });
  }

  // ---- Groei en Bloei: agenda en foto's ----
  var gb = document.getElementById("gb-agenda");
  if (gb) {
    laad("data/groei-en-bloei.json").then(function (d) {
      (d.agenda || []).forEach(function (x) {
        gb.appendChild(el("li", {}, [
          el("div", { klasse: "date" }, [el("b", { tekst: x.dag || "" }), el("span", { tekst: x.maand || "" })]),
          el("div", {}, [el("h3", { style: "margin:0", tekst: x.titel || "" }), el("span", { tekst: x.locatie || "" })]),
          el("a", { klasse: "btn small outline", href: veiligeLink(x.link || "https://www.groei.nl"), target: "_blank", rel: "noopener", tekst: "Meer info" })
        ]));
      });
      var g = document.getElementById("gb-fotos");
      (d.fotos || []).forEach(function (f) { g.appendChild(foto(f)); });
    });
  }

  // ---- Knop "Neem contact op" onderaan sommige pagina's kiest het bijbehorende onderwerp ----
  var ONDERWERP_PER_PAGINA = {
    "workshops.html": "Workshop voor bedrijf, instelling of particulieren",
    "groei-en-bloei.html": "Workshop via Groei en Bloei",
    "trouwen-op-maat.html": "Trouwen op maat"
  };
  if (ONDERWERP_PER_PAGINA[huidig]) {
    [].forEach.call(document.querySelectorAll(".cta a.btn"), function (a) {
      if (a.getAttribute("href") === "contact.html") a.setAttribute("href", "contact.html?onderwerp=" + encodeURIComponent(ONDERWERP_PER_PAGINA[huidig]));
    });
  }

  // ---- Contactformulier: verstuurt via contact/verstuur.php (alleen op de echte server met PHP) ----
  var form = document.getElementById("contactform");
  if (form) {
    // Onderwerp: voorgevuld met de categorie waarvandaan je kwam (?onderwerp=...), anders moet je zelf kiezen
    var kies = form.querySelector("select[name=onderwerp]");
    if (kies) {
      var uitleg = el("option", { value: "", tekst: "Kies een onderwerp…" });
      uitleg.disabled = true;
      kies.insertBefore(uitleg, kies.firstChild);
      kies.required = true;
      var gewenst = (params.get("onderwerp") || "").trim();
      var alias = { "projecten": "Bloemwerk voor een project", "boeketten op bestelling": "Boeket op bestelling" };
      var doel = alias[gewenst.toLowerCase()] || gewenst;
      var gevonden = null;
      [].forEach.call(kies.options, function (o) { if (doel && o.value && o.text.toLowerCase() === doel.toLowerCase()) gevonden = o; });
      if (!gevonden && doel) { gevonden = el("option", { tekst: gewenst }); kies.insertBefore(gevonden, kies.lastChild); }
      if (gevonden) gevonden.selected = true; else uitleg.selected = true;
    }
    var melding = document.getElementById("formmsg");
    var klaar = false;
    fetch("contact/vraag.php", { cache: "no-store" }).then(function (r) {
      if (!r.ok) throw new Error("geen php");
      return r.json();
    }).then(function (v) {
      if (!v.vraag) throw new Error("geen vraag");
      document.getElementById("vraag-tekst").textContent = v.vraag;
      document.getElementById("token").value = v.token;
      document.getElementById("vraag-blok").hidden = false;
      klaar = true;
    }).catch(function () { /* bijvoorbeeld op een voorbeeldadres zonder PHP */ });
    form.addEventListener("submit", function (e) {
      if (!klaar) {
        e.preventDefault();
        melding.textContent = "Dit is een voorbeeldversie: het formulier werkt alleen op de echte website.";
        melding.style.display = "block";
      }
    });
  }
})();
