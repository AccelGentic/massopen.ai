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
  var links = Array.prototype.slice.call(document.querySelectorAll(".nav__link"));
  var sections = links
    .map(function (link) {
      var id = link.getAttribute("href").slice(1);
      return document.getElementById(id);
    })
    .filter(Boolean);

  if ("IntersectionObserver" in window && sections.length) {
    var observer = new IntersectionObserver(
      function (entries) {
        entries.forEach(function (entry) {
          if (!entry.isIntersecting) return;
          links.forEach(function (link) {
            var active = link.getAttribute("href") === "#" + entry.target.id;
            link.classList.toggle("is-active", active);
          });
        });
      },
      { rootMargin: "-45% 0px -50% 0px", threshold: 0 }
    );
    sections.forEach(function (section) { observer.observe(section); });
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
