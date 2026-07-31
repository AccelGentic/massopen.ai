/* Mass Open — front-end behaviour
   1. Mobile nav toggle
   2. Scroll-spy: highlight the nav link for the section in view
   3. Email signup form submission to submit.php
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

  /* --- 3. Signup form ------------------------------------------------------ */
  var form = document.getElementById("signup");
  if (!form) return;

  var email = document.getElementById("email");
  var note = document.getElementById("note");
  var button = form.querySelector("button");

  form.addEventListener("submit", function (e) {
    e.preventDefault();

    if (!email.checkValidity() || !email.value.trim()) {
      note.style.color = "#ff8a8a";
      note.textContent = "Please enter a valid email address.";
      email.focus();
      return;
    }

    button.disabled = true;
    note.style.color = "var(--accent)";
    note.textContent = "Submitting…";

    var data = new FormData();
    data.append("email", email.value.trim());

    fetch(form.action, {
      method: "POST",
      body: data,
      headers: { Accept: "application/json" }
    })
      .then(function (res) {
        return res.json().then(function (body) {
          return { ok: res.ok, body: body };
        });
      })
      .then(function (result) {
        if (result.ok && result.body.ok) {
          note.style.color = "var(--accent)";
          note.textContent = result.body.message || "Thanks! We'll keep you posted.";
          form.reset();
        } else {
          note.style.color = "#ff8a8a";
          note.textContent =
            (result.body && result.body.message) ||
            "Something went wrong. Please try again.";
        }
      })
      .catch(function () {
        note.style.color = "#ff8a8a";
        note.textContent = "Network error. Please try again.";
      })
      .finally(function () {
        button.disabled = false;
      });
  });
})();
