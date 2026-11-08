// Menu op mobiel
var toggle = document.querySelector(".menu-toggle");
var menu = document.getElementById("menu");
if (toggle && menu) {
  toggle.addEventListener("click", function () {
    var open = menu.classList.toggle("open");
    toggle.setAttribute("aria-expanded", open ? "true" : "false");
  });
}

// Contactformulier: nog niet werkend, toont alleen een melding
var form = document.getElementById("contactform");
if (form) {
  form.addEventListener("submit", function (e) {
    e.preventDefault();
    document.getElementById("formmsg").style.display = "block";
  });
}
