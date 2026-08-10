/* Mass Open — front-end behaviour
   1. Mobile nav toggle
   2. Scroll-spy: highlight the nav link for the section in view

   The newsletter signup handler was removed when subscriptions moved to the
   Ghost site at news.massopen.ai. The CFP form has its own script, cfp.js.
*/
(function () {
  "use strict";

  /* --- 1. Mobile nav toggle ----------------------------------------------- */
  var toggle = document.querySelector(".nav__toggle");
  var menu = document.getElementById("nav-menu");

  if (toggle && menu) {
    toggle.addEventListener("click", function () {
      var open = menu.classList.toggle("is-open");
      toggle.setAttribute("aria-expanded", open ? "true" : "false");
    });

    // Close the menu after tapping a link (mobile).
    menu.addEventListener("click", function (e) {
      if (e.target.classList.contains("nav__link")) {
        menu.classList.remove("is-open");
        toggle.setAttribute("aria-expanded", "false");
      }
    });
  }

  /* --- 2. Scroll-spy ------------------------------------------------------- */
  // Nav anchors are written as "/#section" so they work from standalone pages
  // too. Only spy on links whose target section actually exists on this page.
  var links = Array.prototype.slice.call(document.querySelectorAll(".nav__link"));
  var spied = [];

  links.forEach(function (link) {
    if (!link.hash) return;
    var section = document.getElementById(link.hash.slice(1));
    if (section) spied.push({ link: link, section: section });
  });

  if ("IntersectionObserver" in window && spied.length) {
    var observer = new IntersectionObserver(
      function (entries) {
        entries.forEach(function (entry) {
          if (!entry.isIntersecting) return;
          spied.forEach(function (pair) {
            pair.link.classList.toggle("is-active", pair.section === entry.target);
          });
        });
      },
      { rootMargin: "-45% 0px -50% 0px", threshold: 0 }
    );
    spied.forEach(function (pair) { observer.observe(pair.section); });
  }

})();
