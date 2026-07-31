/* Mass Open — contact form.
   1. Fetch a single-use nonce so the server can time the fill and prove the
      page's JavaScript ran
   2. Live character counter
   3. Submit
*/
(function () {
  "use strict";

  var form = document.getElementById("contact-form");
  if (!form) return;

  var note   = document.getElementById("contact-note");
  var button = document.getElementById("contact-submit");
  var nonce  = document.getElementById("contact-nonce");

  /* --- 1. Nonce ------------------------------------------------------------ */
  // Absolute, and supplied by the template so it honours `baseurl`. A relative
  // path would resolve against /contact/ rather than the web root.
  var tokenUrl = form.getAttribute("data-token-url") || "/cfp_token.php";

  function loadNonce() {
    return fetch(tokenUrl, {
      headers: { Accept: "application/json" },
      cache: "no-store"
    })
      .then(function (res) {
        if (!res.ok) throw new Error("HTTP " + res.status);
        return res.json();
      })
      .then(function (body) {
        if (!body || !body.ok || !body.nonce) throw new Error("no nonce");
        nonce.value = body.nonce;
      })
      .catch(function (err) {
        // Say so rather than leaving the form looking like it is still
        // loading — a broken endpoint is otherwise invisible until submit.
        note.style.color = "#ff8a8a";
        note.textContent =
          "The form couldn't finish loading. Please refresh, or email us directly.";
        if (window.console) console.error("Contact: nonce request failed:", err);
      });
  }

  loadNonce();

  /* --- 2. Character counter ------------------------------------------------ */
  function countUp(fieldId, outputId) {
    var field  = document.getElementById(fieldId);
    var output = document.getElementById(outputId);
    if (!field || !output) return;

    var update = function () {
      // Match the server, which counts characters rather than bytes.
      var used = Array.from(field.value).length;
      output.textContent = String(used);
      output.parentNode.classList.toggle(
        "field__hint--full",
        used >= Number(field.getAttribute("maxlength"))
      );
    };

    field.addEventListener("input", update);
    update();
  }

  countUp("contact-message", "message-count");

  /* --- 3. Submit ------------------------------------------------------------ */
  form.addEventListener("submit", function (e) {
    e.preventDefault();

    var required = ["contact-name", "contact-email", "contact-subject", "contact-message"];
    for (var i = 0; i < required.length; i++) {
      var field = document.getElementById(required[i]);
      if (!field.value.trim() || !field.checkValidity()) {
        note.style.color = "#ff8a8a";
        note.textContent = "Please complete every field with a valid entry.";
        field.focus();
        return;
      }
    }

    if (!nonce.value) {
      note.style.color = "#ff8a8a";
      note.textContent = "Still preparing the form — give it a moment and try again.";
      loadNonce();
      return;
    }

    button.disabled = true;
    note.style.color = "var(--accent)";
    note.textContent = "Sending…";

    fetch(form.action, {
      method: "POST",
      body: new FormData(form),
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
          note.textContent = result.body.message || "Thanks! We'll be in touch.";
          form.reset();
          countUp("contact-message", "message-count");
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
        // Each nonce is single use, so a second submission needs a fresh one.
        nonce.value = "";
        loadNonce();
      });
  });
})();
