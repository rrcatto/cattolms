/* Flash cards (owner, 2026-10-28, decision 1A): the list of cards the server draws (FlashCards::render), worked through
   one card at a time. The learner reads the front, turns the card over, and says "I knew it" or "Again"; a card marked
   Again comes back later in the round, until every card is known. Nothing is sent anywhere: progress in a course stays
   assessment-only. Without JavaScript the list stays as it is, each answer behind "Show the answer". */
(function () {
  'use strict';

  function el(tag, className, text) {
    var node = document.createElement(tag);
    if (className) { node.className = className; }
    if (text !== undefined) { node.textContent = text; }
    return node;
  }

  function button(className, text) {
    var b = el('button', 'cl-flashcards-button ' + className, text);
    b.type = 'button';
    return b;
  }

  function shuffle(list) {
    for (var i = list.length - 1; i > 0; i--) {
      var j = Math.floor(Math.random() * (i + 1));
      var t = list[i]; list[i] = list[j]; list[j] = t;
    }
    return list;
  }

  function mount(root) {
    if (root.getAttribute('data-cl-flashcards-ready') === '1') { return; }
    var cards = Array.prototype.map.call(root.querySelectorAll('.cl-flashcard'), function (li) {
      var hint = li.querySelector('.cl-flashcard-hint');
      return {
        front: (li.querySelector('.cl-flashcard-front') || {}).textContent || '',
        back: ((li.querySelector('.cl-flashcard-back p') || {}).textContent) || '',
        hint: hint ? hint.textContent.replace(/^Hint:\s*/, '') : ''
      };
    });
    if (cards.length === 0) { return; }
    root.setAttribute('data-cl-flashcards-ready', '1');
    var list = root.querySelector('.cl-flashcards-list');

    var player = el('div', 'cl-flashcards-player');
    var status = el('p', 'cl-flashcards-status');
    status.setAttribute('aria-live', 'polite');
    var card = el('div', 'cl-flashcards-card');
    card.setAttribute('tabindex', '-1');
    var side = el('p', 'cl-flashcards-side');
    var front = el('p', 'cl-flashcards-front');
    var hintText = el('p', 'cl-flashcards-hint');
    var back = el('p', 'cl-flashcards-back');
    card.appendChild(side); card.appendChild(front); card.appendChild(hintText); card.appendChild(back);
    var actions = el('div', 'cl-flashcards-actions');
    var hintButton = button('cl-flashcards-secondary', 'Show a hint');
    var turnButton = button('cl-flashcards-primary', 'Turn over');
    var knewButton = button('cl-flashcards-primary', 'I knew it');
    var againButton = button('cl-flashcards-secondary', 'Again');
    var restartButton = button('cl-flashcards-primary', 'Go through them again');
    var shuffleButton = button('cl-flashcards-secondary', 'Shuffle and start again');
    [hintButton, turnButton, knewButton, againButton, restartButton, shuffleButton].forEach(function (b) { actions.appendChild(b); });
    player.appendChild(status); player.appendChild(card); player.appendChild(actions);
    root.insertBefore(player, list);
    list.hidden = true;
    var showList = button('cl-flashcards-link', 'Show all the cards as a list');
    showList.setAttribute('aria-expanded', 'false');
    root.appendChild(showList);
    showList.addEventListener('click', function () {
      list.hidden = !list.hidden;
      showList.setAttribute('aria-expanded', list.hidden ? 'false' : 'true');
      showList.textContent = list.hidden ? 'Show all the cards as a list' : 'Hide the list';
    });

    var queue, known, current, turned, rounds;

    function start(order) {
      queue = order.slice();
      known = 0; rounds = 0;
      next();
    }

    function show() {
      var c = cards[current];
      side.textContent = turned ? 'Answer' : 'Question';
      front.textContent = c.front;
      back.textContent = c.back;
      back.hidden = !turned;
      hintText.hidden = true;
      hintText.textContent = c.hint ? 'Hint: ' + c.hint : '';
      card.classList.toggle('is-turned', turned);
      hintButton.hidden = turned || !c.hint;
      turnButton.hidden = turned;
      knewButton.hidden = !turned;
      againButton.hidden = !turned;
      restartButton.hidden = true;
      shuffleButton.hidden = true;
      status.textContent = 'Card ' + (known + 1) + ' of ' + cards.length + ' to learn' + (rounds > 0 ? ', ' + queue.length + ' coming back' : '') + '.';
    }

    function next() {
      if (queue.length === 0) { finish(); return; }
      current = queue.shift();
      turned = false;
      show();
    }

    function finish() {
      side.textContent = 'Done';
      front.textContent = 'You knew all ' + cards.length + ' cards.';
      back.hidden = true; hintText.hidden = true;
      [hintButton, turnButton, knewButton, againButton].forEach(function (b) { b.hidden = true; });
      restartButton.hidden = false; shuffleButton.hidden = false;
      status.textContent = 'Every card known' + (rounds > 0 ? ', after going over ' + rounds + ' again' : ' at the first try') + '.';
      restartButton.focus();
    }

    hintButton.addEventListener('click', function () { hintText.hidden = false; hintButton.hidden = true; });
    turnButton.addEventListener('click', function () { turned = true; show(); knewButton.focus(); });
    knewButton.addEventListener('click', function () { known++; next(); turnButton.focus(); });
    againButton.addEventListener('click', function () {
      rounds++;
      // The card comes back after a few others, not straight away.
      queue.splice(Math.min(queue.length, 3), 0, current);
      next();
      turnButton.focus();
    });
    restartButton.addEventListener('click', function () { start(cards.map(function (c, i) { return i; })); turnButton.focus(); });
    shuffleButton.addEventListener('click', function () { start(shuffle(cards.map(function (c, i) { return i; }))); turnButton.focus(); });
    card.addEventListener('keydown', function (event) {
      if (event.key === ' ' || event.key === 'Enter') { event.preventDefault(); if (!turned) { turnButton.click(); } }
    });

    start(cards.map(function (c, i) { return i; }));
  }

  function mountAll(scope) {
    Array.prototype.forEach.call((scope || document).querySelectorAll('[data-cl-flashcards]'), mount);
  }

  if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', function () { mountAll(document); }); }
  else { mountAll(document); }
  document.addEventListener('htmx:after:swap', function (event) { mountAll(event.target || document); });
}());
