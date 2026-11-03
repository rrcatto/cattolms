/* The file on its own: presentations play one slide at a time, flash cards turn over, and quizzes mark themselves and
   fill their answer keys. The panels need no script. CattoLMS does not use this script. */
(function () {
  "use strict";
  function make(tag, className, text) {
    var node = document.createElement(tag);
    if (className) { node.className = className; }
    if (text !== undefined && text !== null) { node.textContent = text; }
    return node;
  }

  // The panels open and close by themselves. A link to a panel, such as #mod-m1 or #final, opens it.
  var linked = location.hash ? document.getElementById(location.hash.slice(1)) : null;
  var panel = linked ? (linked.tagName === "DETAILS" ? linked : linked.querySelector("details")) : null;
  if (panel) { panel.open = true; }

  document.querySelectorAll(".presentation[data-deck]").forEach(function (box) {
    var deck = PRESENTATIONS[box.getAttribute("data-deck")];
    if (!deck) { return; }
    var index = 0;
    function draw() {
      var slide = deck.slides[index];
      box.textContent = "";
      var face = make("div", "slide");
      if (slide.kicker || slide.chapter) { face.appendChild(make("p", "eyebrow", slide.kicker || slide.chapter)); }
      var heading = slide.heading || slide.question || slide.figure || slide.text;
      if (heading) { face.appendChild(make("h3", "", heading)); }
      ["sub", "label", "facts", "question", "caption", "source"].forEach(function (field) { if (slide[field] && slide[field] !== heading) { face.appendChild(make("p", "", slide[field])); } });
      if (slide.text && slide.text !== heading) { face.appendChild(make("p", "", slide.text)); }
      var list = slide.points || slide.steps;
      if (list) { var ul = make("ul"); list.forEach(function (item) { ul.appendChild(make("li", "", item)); }); face.appendChild(ul); }
      if (slide.events) { var events = make("ul"); slide.events.forEach(function (e) { events.appendChild(make("li", "", e.when + ": " + e.what)); }); face.appendChild(events); }
      if (slide.columns) { slide.columns.forEach(function (c) { face.appendChild(make("h4", "", c.heading)); face.appendChild(make("p", "", c.text || (c.points || []).join(", "))); }); }
      if (slide.picture) { var picture = make("figure"); picture.innerHTML = slide.picture; face.appendChild(picture); }
      if (slide.options) {
        slide.options.forEach(function (option, i) {
          var choice = make("button", "btn", option);
          choice.type = "button";
          choice.addEventListener("click", function () {
            face.appendChild(make("p", i === slide.answer ? "right" : "wrong", (i === slide.answer ? "Right. " : "Not quite. ") + slide.explanation));
          });
          face.appendChild(choice);
        });
      }
      if (slide.action_label) { var onward = make("button", "btn", slide.action_label); onward.type = "button"; onward.addEventListener("click", function () { var panel = box.closest("details"); if (panel) { panel.open = false; } }); face.appendChild(onward); }
      if (slide.outcome) { var reveal = make("button", "btn", "What happened"); reveal.type = "button"; reveal.addEventListener("click", function () { face.appendChild(make("p", "", slide.outcome)); }); face.appendChild(reveal); }
      box.appendChild(face);
      var controls = make("div", "slide-controls");
      var back = make("button", "btn", "Previous"); back.type = "button"; back.disabled = index === 0;
      back.addEventListener("click", function () { index--; draw(); });
      var next = make("button", "btn", "Next"); next.type = "button"; next.disabled = index === deck.slides.length - 1;
      next.addEventListener("click", function () { index++; draw(); });
      controls.appendChild(back); controls.appendChild(make("span", "", (index + 1) + " of " + deck.slides.length)); controls.appendChild(next);
      box.appendChild(controls);
    }
    draw();
  });

  document.querySelectorAll(".flashcards[data-cards]").forEach(function (box) {
    var cards = FLASHCARDS[box.getAttribute("data-cards")];
    if (!cards) { return; }
    var index = 0;
    function draw() {
      var card = cards[index];
      box.textContent = "";
      var face = make("div", "card");
      face.appendChild(make("p", "", card.front));
      if (card.hint) { face.appendChild(make("p", "eyebrow", "Hint: " + card.hint)); }
      var answer = make("p", "card-back", card.back); answer.hidden = true; face.appendChild(answer);
      box.appendChild(face);
      var controls = make("div", "card-controls");
      var turn = make("button", "btn", "Turn over"); turn.type = "button";
      turn.addEventListener("click", function () { answer.hidden = false; });
      var next = make("button", "btn", "Next card"); next.type = "button";
      next.addEventListener("click", function () { index = (index + 1) % cards.length; draw(); });
      controls.appendChild(turn); controls.appendChild(next); controls.appendChild(make("span", "", "Card " + (index + 1) + " of " + cards.length));
      box.appendChild(controls);
    }
    draw();
  });

  // The review's cards: each module's key points from REVIEW.
  var reviewCards = document.getElementById("reviewCards");
  if (reviewCards) {
    Object.keys(REVIEW).forEach(function (key) {
      var card = make("div", "review-card");
      card.appendChild(make("h4", "", REVIEW[key].t));
      var points = make("ul");
      (REVIEW[key].pts || []).forEach(function (point) { points.appendChild(make("li", "", point)); });
      card.appendChild(points);
      reviewCards.appendChild(card);
    });
  }

  // Each answer key lists the right answers with their explanations.
  document.querySelectorAll(".key-list[data-key]").forEach(function (list) {
    (QUIZ[list.getAttribute("data-key")] || []).forEach(function (question) {
      var item = make("li"); item.innerHTML = question.q;
      var answer = make("span", "key-answer"); answer.innerHTML = "Answer: " + question.o[question.a];
      item.appendChild(answer);
      if (question.e) { var why = make("span"); why.innerHTML = question.e; item.appendChild(why); }
      list.appendChild(item);
    });
  });

  document.querySelectorAll(".quiz[data-quiz]").forEach(function (box) {
    var key = box.getAttribute("data-quiz");
    var questions = QUIZ[key];
    if (!questions) { return; }
    var form = make("form");
    questions.forEach(function (question, q) {
      var block = make("fieldset", "question");
      var legend = make("legend"); legend.innerHTML = (q + 1) + ". " + question.q;
      block.appendChild(legend);
      question.o.forEach(function (option, i) {
        var label = make("label");
        var input = make("input"); input.type = "radio"; input.name = key + "-" + q; input.value = String(i);
        var words = make("span"); words.innerHTML = " " + option;
        label.appendChild(input); label.appendChild(words);
        block.appendChild(label); block.appendChild(make("br"));
      });
      block.appendChild(make("p", "verdict"));
      form.appendChild(block);
    });
    var controls = make("div", "quiz-controls");
    var check = make("button", "btn", "Check my answers"); check.type = "submit";
    var score = make("span");
    controls.appendChild(check); controls.appendChild(score);
    form.appendChild(controls);
    form.addEventListener("submit", function (event) {
      event.preventDefault();
      var got = 0, worth = 0;
      questions.forEach(function (question, q) {
        var chosen = form.querySelector("input[name='" + key + "-" + q + "']:checked");
        var ok = chosen !== null && Number(chosen.value) === question.a;
        worth += question.p || 1;
        if (ok) { got += question.p || 1; }
        var verdict = form.querySelectorAll(".verdict")[q];
        verdict.className = "verdict " + (ok ? "right" : "wrong");
        verdict.innerHTML = (ok ? "Right. " : "The answer is: " + question.o[question.a] + ". ") + (question.e || "");
      });
      var percent = Math.round(got / worth * 100);
      score.textContent = "You scored " + got + " of " + worth + " points (" + percent + "%).";
      // The module or final assessment this quiz belongs to shows its mark on its button.
      var owner = document.getElementById(key === "final" ? "final" : "mod-" + key);
      if (owner) {
        owner.classList.add("done");
        var grade = owner.querySelector(".m-grade");
        if (grade) { grade.textContent = percent + "%"; }
      }
    });
    box.appendChild(form);
  });
}());
