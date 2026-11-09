// Waarschuwt in het beheer als er wijzigingen zijn die nog niet zijn opgeslagen.
(function () {
  var formulieren = [].filter.call(document.querySelectorAll("form.b-form"), function (f) {
    // niet voor inloggen, wachtwoorden en de tweede stap
    return !f.querySelector("input[type=password]") && !/a=(inloggen|instellen|code|wachtwoord|tweestap)/.test(f.getAttribute("action") || "");
  });
  if (!formulieren.length) return;

  function stand(f) {
    var delen = [];
    new FormData(f).forEach(function (w, k) {
      if (k === "csrf") return;
      delen.push(k + "=" + (typeof w === "string" ? w : (w && w.name) || ""));
    });
    return delen.join("&");
  }

  var begin = new Map();
  var vuil = new Set();
  var balk = document.createElement("div");
  balk.className = "b-niet";
  balk.hidden = true;
  balk.setAttribute("role", "status");
  var tekst = document.createElement("span");
  tekst.textContent = "Je hebt wijzigingen die nog niet zijn opgeslagen.";
  var knop = document.createElement("button");
  knop.type = "button";
  knop.className = "b-knop";
  balk.appendChild(tekst);
  balk.appendChild(knop);
  document.body.appendChild(balk);

  function knoptekst(f) {
    var k = f.querySelector(".b-knop:not(.gevaar)");
    return k ? k.textContent.trim() : "Opslaan";
  }
  function ververs() {
    balk.hidden = vuil.size === 0;
    if (vuil.size) {
      var f = vuil.values().next().value;
      knop.textContent = knoptekst(f) + " nu";
      knop.onclick = function () { if (f.requestSubmit) f.requestSubmit(); else f.submit(); };
    }
  }

  formulieren.forEach(function (f) {
    begin.set(f, stand(f));
    var controleer = function () {
      if (stand(f) !== begin.get(f)) vuil.add(f); else vuil.delete(f);
      ververs();
    };
    f.addEventListener("input", controleer);
    f.addEventListener("change", controleer);
    f.addEventListener("submit", function () { vuil.delete(f); ververs(); });
  });

  window.addEventListener("beforeunload", function (e) {
    if (vuil.size) { e.preventDefault(); e.returnValue = ""; }
  });
})();
