/* The parts added to a course after its original file was written: presentations play one slide at a time and flash cards
   turn over. Everything else in the file is its own script. CattoLMS does not use this script. */
(function () {
  "use strict";
  function make(tag, className, text) {
    var node = document.createElement(tag);
    if (className) { node.className = className; }
    if (text !== undefined && text !== null) { node.textContent = text; }
    return node;
  }

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
          var choice = make("button", "btn btn-grade", option);
          choice.type = "button";
          choice.addEventListener("click", function () {
            face.appendChild(make("p", i === slide.answer ? "right" : "wrong", (i === slide.answer ? "Right. " : "Not quite. ") + slide.explanation));
          });
          face.appendChild(choice);
        });
      }
      if (slide.action_label) { var onward = make("button", "btn btn-grade", slide.action_label); onward.type = "button"; onward.addEventListener("click", function () { var panel = box.closest("details"); if (panel) { panel.open = false; } }); face.appendChild(onward); }
      if (slide.outcome) { var reveal = make("button", "btn btn-grade", "What happened"); reveal.type = "button"; reveal.addEventListener("click", function () { face.appendChild(make("p", "", slide.outcome)); }); face.appendChild(reveal); }
      box.appendChild(face);
      var controls = make("div", "slide-controls");
      var back = make("button", "btn btn-grade", "Previous"); back.type = "button"; back.disabled = index === 0;
      back.addEventListener("click", function () { index--; draw(); });
      var next = make("button", "btn btn-grade", "Next"); next.type = "button"; next.disabled = index === deck.slides.length - 1;
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
      var turn = make("button", "btn btn-grade", "Turn over"); turn.type = "button";
      turn.addEventListener("click", function () { answer.hidden = false; });
      var next = make("button", "btn btn-grade", "Next card"); next.type = "button";
      next.addEventListener("click", function () { index = (index + 1) % cards.length; draw(); });
      controls.appendChild(turn); controls.appendChild(next); controls.appendChild(make("span", "", "Card " + (index + 1) + " of " + cards.length));
      box.appendChild(controls);
    }
    draw();
  });
}());
