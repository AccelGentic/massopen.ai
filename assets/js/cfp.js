/* Mass Open — call for papers form.
   1. Fetch a single-use nonce so the server can time the fill and prove the
      page's JavaScript ran
   2. Live character counters
   3. Submit
*/
(function () {
  "use strict";

  var form = document.getElementById("cfp-form");
  if (!form) return;

  var note   = document.getElementById("cfp-note");
  var button = document.getElementById("cfp-submit");
  var nonce  = document.getElementById("cfp-nonce");

  /* --- 1. Nonce ------------------------------------------------------------ */
  function loadNonce() {
    return fetch("cfp_token.php", {
      headers: { Accept: "application/json" },
      cache: "no-store"
    })
      .then(function (res) { return res.json(); })
      .then(function (body) {
        if (body && body.ok && body.nonce) {
          nonce.value = body.nonce;
        }
      })
      .catch(function () { /* Submit will report it. */ });
  }

  loadNonce();

  /* --- 2. Character counters ----------------------------------------------- */
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

  countUp("cfp-bio", "bio-count");
  countUp("cfp-abstract", "abstract-count");

  /* --- 3. Submit ------------------------------------------------------------ */
  form.addEventListener("submit", function (e) {
    e.preventDefault();

    var required = ["cfp-name", "cfp-email", "cfp-topic", "cfp-bio", "cfp-abstract"];
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
          note.textContent = result.body.message || "Thanks! Check your email.";
          form.reset();
          countUp("cfp-bio", "bio-count");
          countUp("cfp-abstract", "abstract-count");
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
