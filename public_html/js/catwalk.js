/* CatWalk 1.0.0, the presentation engine (owner, 2026-10-28, decision 7A; DEVDOCS "Catwalk Presentation Engine -
   Specification", sections 7, 9 and 10). Turns each div.cw-deck into a player: one slide at a time, points that build
   with Next, quick questions answered on the slide, a progress bar by chapter, a contents list, full screen, narration
   and a transcript. Keys act only while focus is in the deck, so two decks on a page never answer the same key. The
   authored slides are never changed: the player only adds its controls around them, and removing it (destroy) leaves
   the readable page. No dependencies, no network requests of its own, and nothing CattoLMS-specific: the host gives
   its addresses in data-cw-host-links and listens for the cw: events if it wants them. */
(function (root) {
  'use strict';
  var VERSION = '1.0.0';
  var players = new WeakMap();

  function el(tag, className, text) {
    var node = document.createElement(tag);
    if (className) { node.className = className; }
    if (text !== undefined) { node.textContent = text; }
    return node;
  }

  function button(className, label, text) {
    var b = el('button', 'cw-btn ' + (className || ''), text);
    b.type = 'button';
    b.setAttribute('aria-label', label);
    if (text === undefined) { b.textContent = label; }
    return b;
  }

  function parseLinks(deck, options) {
    var links = {};
    try { links = JSON.parse(deck.getAttribute('data-cw-host-links') || '{}') || {}; } catch (e) { links = {}; }
    var given = (options && options.links) || {};
    Object.keys(given).forEach(function (k) { links[k] = given[k]; });
    return links;
  }

  function Player(deck, options) {
    var self = this;
    this.deck = deck;
    this.key = deck.getAttribute('data-cw-key') || '';
    this.kind = deck.getAttribute('data-cw-kind') || '';
    this.title = deck.getAttribute('data-cw-title') || 'Presentation';
    this.links = parseLinks(deck, options);
    this.slides = Array.prototype.filter.call(deck.children, function (c) { return c.matches && c.matches('section.cw-slide'); });
    this.total = this.slides.length;
    this.index = 0;
    this.revealed = 0;
    this.started = Date.now();
    this.completed = false;
    this.sound = false;
    this.audio = null;
    this.steps = this.slides.map(function (slide) { return self.collectSteps(slide); });
    this.build();
    this.go(Math.max(0, Math.min(this.total - 1, ((options && options.startAt) || 1) - 1)), false, true);
    this.emit('cw:ready', {key: this.key, kind: this.kind, total: this.total});
  }

  Player.prototype.collectSteps = function (slide) {
    var groups = {};
    var order = [];
    var counter = 0;
    Array.prototype.forEach.call(slide.querySelectorAll('.cw-step'), function (step) {
      var explicit = parseInt(step.getAttribute('data-cw-step') || '', 10);
      var number = isNaN(explicit) ? ++counter : explicit;
      if (!isNaN(explicit)) { counter = Math.max(counter, explicit); }
      if (!groups[number]) { groups[number] = []; order.push(number); }
      groups[number].push(step);
    });
    order.sort(function (a, b) { return a - b; });
    return order.map(function (n) { return groups[n]; });
  };

  Player.prototype.build = function () {
    var self = this, deck = this.deck;
    deck.classList.add('cw-ready');
    deck.setAttribute('role', 'region');
    deck.setAttribute('aria-roledescription', 'slide deck');
    deck.setAttribute('aria-label', this.title);
    if (!deck.hasAttribute('tabindex')) { deck.setAttribute('tabindex', '0'); }

    this.top = el('div', 'cw-bar cw-bar-top');
    this.topTitle = el('span', 'cw-bar-title', this.title);
    this.topChapter = el('span', 'cw-bar-chapter');
    this.top.appendChild(this.topTitle);
    this.top.appendChild(this.topChapter);

    this.viewport = el('div', 'cw-viewport');
    this.slides.forEach(function (slide, i) {
      slide.setAttribute('role', 'group');
      slide.setAttribute('aria-roledescription', 'slide');
      slide.setAttribute('aria-label', (i + 1) + ' of ' + self.total + ': ' + (slide.getAttribute('data-cw-title') || ''));
      self.viewport.appendChild(slide);
      self.prepareCheck(slide, i);
    });

    this.bottom = el('div', 'cw-bar cw-bar-bottom');
    this.progress = el('div', 'cw-progress');
    this.progress.setAttribute('aria-hidden', 'true');
    var chapter = null;
    this.segments = this.slides.map(function (slide, i) {
      var segment = el('span');
      var c = slide.getAttribute('data-cw-chapter');
      if (c && c !== chapter && i > 0) { segment.className = 'cw-chapter-start'; }
      if (c) { chapter = c; }
      self.progress.appendChild(segment);
      return segment;
    });
    this.prevButton = button('', 'Previous slide', '‹ Previous');
    this.nextButton = button('cw-btn-primary', 'Next', 'Next ›');
    this.counter = el('span', 'cw-counter');
    this.counter.setAttribute('aria-hidden', 'true');
    this.contentsButton = button('', 'Contents', 'Contents');
    this.contentsButton.setAttribute('aria-expanded', 'false');
    this.fullButton = button('', 'Full screen', 'Full screen');
    this.bottom.appendChild(this.progress);
    this.bottom.appendChild(this.prevButton);
    this.bottom.appendChild(this.counter);
    this.bottom.appendChild(this.nextButton);
    this.bottom.appendChild(el('span', 'cw-spacer'));
    var narrated = this.slides.some(function (s) { return s.hasAttribute('data-cw-audio'); });
    var hasNotes = this.slides.some(function (s) { return s.querySelector('.cw-notes'); });
    if (narrated) {
      this.soundButton = button('', 'Narration', 'Narration');
      this.soundButton.setAttribute('aria-pressed', 'false');
      this.bottom.appendChild(this.soundButton);
      this.soundButton.addEventListener('click', function () { self.toggleSound(); });
    }
    if (hasNotes) {
      this.transcriptButton = button('', 'Transcript', 'Transcript');
      this.transcriptButton.setAttribute('aria-pressed', 'false');
      this.bottom.appendChild(this.transcriptButton);
      this.transcriptButton.addEventListener('click', function () { self.toggleTranscript(); });
    }
    this.bottom.appendChild(this.contentsButton);
    this.bottom.appendChild(this.fullButton);

    this.transcript = el('div', 'cw-transcript');
    this.transcript.setAttribute('aria-live', 'polite');
    this.transcript.hidden = true;
    this.live = el('div', 'cw-live');
    this.live.setAttribute('aria-live', 'polite');

    deck.insertBefore(this.top, deck.firstChild);
    deck.appendChild(this.viewport);
    deck.appendChild(this.transcript);
    deck.appendChild(this.bottom);
    deck.appendChild(this.live);

    this.prevButton.addEventListener('click', function () { self.prev(); });
    this.nextButton.addEventListener('click', function () { self.next(); });
    this.contentsButton.addEventListener('click', function () { self.toggleContents(); });
    this.fullButton.addEventListener('click', function () { self.toggleFull(); });

    this.onKey = function (event) { self.key_(event); };
    deck.addEventListener('keydown', this.onKey);
    this.onClick = function (event) {
      if (event.target.closest('a, button, summary, details, input, select, textarea, .cw-choices')) { return; }
      if (self.viewport.contains(event.target)) { self.next(); }
    };
    deck.addEventListener('click', this.onClick);
    this.swipe();
    this.onAction = function (event) { self.action(event); };
    deck.addEventListener('click', this.onAction, true);
    this.onFullChange = function () { self.fullButton.textContent = document.fullscreenElement === deck ? 'Exit full screen' : 'Full screen'; };
    document.addEventListener('fullscreenchange', this.onFullChange);
  };

  Player.prototype.swipe = function () {
    var self = this, startX = null, startY = null;
    this.onDown = function (e) { if (e.pointerType !== 'mouse') { startX = e.clientX; startY = e.clientY; } };
    this.onUp = function (e) {
      if (startX === null) { return; }
      var dx = e.clientX - startX, dy = e.clientY - startY;
      startX = null;
      if (Math.abs(dx) > 45 && Math.abs(dx) > Math.abs(dy) * 1.5) { if (dx < 0) { self.next(); } else { self.prev(); } }
    };
    this.viewport.addEventListener('pointerdown', this.onDown);
    this.viewport.addEventListener('pointerup', this.onUp);
  };

  Player.prototype.prepareCheck = function (slide, index) {
    var self = this;
    var list = slide.querySelector('ol.cw-options[data-cw-answer]');
    if (!list) { return; }
    var answer = parseInt(list.getAttribute('data-cw-answer'), 10);
    var explain = slide.querySelector('details.cw-explain');
    var heading = slide.querySelector('h2');
    list.classList.add('cw-choices');
    list.setAttribute('role', 'group');
    if (heading) {
      if (!heading.id) { heading.id = 'cw-q-' + Math.random().toString(36).slice(2, 9); }
      list.setAttribute('aria-labelledby', heading.id);
    }
    var items = Array.prototype.slice.call(list.children);
    items.forEach(function (li, i) {
      var choice = el('button', 'cw-choice');
      choice.type = 'button';
      while (li.firstChild) { choice.appendChild(li.firstChild); }
      li.appendChild(choice);
      choice.addEventListener('click', function () {
        if (list.classList.contains('cw-answered')) { return; }
        list.classList.add('cw-answered');
        var right = i === answer;
        items.forEach(function (other, j) {
          var b = other.querySelector('.cw-choice');
          b.disabled = true;
          if (j === answer) { b.classList.add('cw-right'); b.appendChild(el('span', 'cw-mark', '✓ right answer')); }
          else if (j === i) { b.classList.add('cw-wrong'); b.appendChild(el('span', 'cw-mark', '✗ your answer')); }
        });
        if (explain) { explain.classList.add('cw-explain-locked'); explain.open = true; }
        var correct = items[answer] ? items[answer].textContent.replace(/✓ right answer$/, '').trim() : '';
        self.say(right ? 'Right.' : 'Not quite. The answer is ' + correct + '.');
        self.emit('cw:check', {key: self.key, index: index, chosen: i, correct: right});
      });
    });
    if (explain) { explain.classList.add('cw-explain-locked'); explain.open = false; }
  };

  Player.prototype.showSteps = function (slideIndex, count) {
    this.steps[slideIndex].forEach(function (group, i) {
      group.forEach(function (step) {
        var hidden = i >= count;
        step.classList.toggle('cw-step-hidden', hidden);
        if (hidden) { step.setAttribute('aria-hidden', 'true'); step.setAttribute('inert', ''); }
        else { step.removeAttribute('aria-hidden'); step.removeAttribute('inert'); }
      });
    });
  };

  Player.prototype.go = function (n, allSteps, quiet) {
    if (n < 0 || n >= this.total) { return; }
    var self = this;
    this.closeContents(false);
    this.index = n;
    this.revealed = allSteps ? this.steps[n].length : 0;
    this.slides.forEach(function (slide, i) {
      var current = i === n;
      slide.classList.toggle('cw-current', current);
      if (current) { slide.removeAttribute('inert'); slide.removeAttribute('aria-hidden'); }
      else { slide.setAttribute('inert', ''); slide.setAttribute('aria-hidden', 'true'); }
    });
    this.showSteps(n, this.revealed);
    this.segments.forEach(function (s, i) { s.classList.toggle('cw-done', i < n); s.classList.toggle('cw-here', i === n); });
    var slide = this.slides[n];
    var title = slide.getAttribute('data-cw-title') || '';
    var chapter = '';
    for (var i = n; i >= 0; i--) { if (this.slides[i].getAttribute('data-cw-chapter')) { chapter = this.slides[i].getAttribute('data-cw-chapter'); break; } }
    this.topChapter.textContent = chapter;
    this.counter.textContent = (n + 1) + ' / ' + this.total;
    this.prevButton.disabled = n === 0;
    this.nextButton.disabled = n === this.total - 1 && this.revealed >= this.steps[n].length;
    var notes = slide.querySelector('.cw-notes');
    this.transcript.textContent = notes ? notes.textContent : '';
    if (!quiet) { this.say('Slide ' + (n + 1) + ' of ' + this.total + ': ' + title); }
    this.playSound();
    this.emit('cw:slide', {key: this.key, index: n, total: this.total, title: title});
    if (n === this.total - 1 && !this.completed) {
      this.completed = true;
      this.emit('cw:complete', {key: this.key, seconds: Math.round((Date.now() - this.started) / 1000)});
    }
    self.slides[n].scrollTop = 0;
  };

  Player.prototype.next = function () {
    var groups = this.steps[this.index];
    if (this.revealed < groups.length) {
      this.revealed++;
      this.showSteps(this.index, this.revealed);
      var shown = groups[this.revealed - 1].map(function (s) { return s.textContent.trim(); }).join(' ');
      this.say(shown);
      this.nextButton.disabled = this.index === this.total - 1 && this.revealed >= groups.length;
      return;
    }
    if (this.index < this.total - 1) { this.go(this.index + 1, false); }
  };

  Player.prototype.prev = function () {
    if (this.index > 0) { this.go(this.index - 1, true); }
  };

  Player.prototype.key_ = function (event) {
    if (event.defaultPrevented || event.altKey || event.ctrlKey || event.metaKey) { return; }
    var target = event.target;
    var inField = target.closest && target.closest('input, textarea, select, [contenteditable="true"]');
    if (inField) { return; }
    var onControl = target.closest && target.closest('button, a, summary');
    switch (event.key) {
      case 'ArrowRight': case 'PageDown': this.next(); break;
      case ' ': case 'Spacebar': if (onControl) { return; } this.next(); break;
      case 'ArrowLeft': case 'PageUp': this.prev(); break;
      case 'Home': this.go(0, false); break;
      case 'End': this.go(this.total - 1, true); break;
      case 'f': case 'F': this.toggleFull(); break;
      case 'm': case 'M': if (this.soundButton) { this.toggleSound(); } else { return; } break;
      case 'Escape':
        if (this.contents) { this.closeContents(true); }
        else if (this.deck.classList.contains('cw-expanded')) { this.toggleFull(); }
        else { return; }
        break;
      default: return;
    }
    event.preventDefault();
  };

  Player.prototype.toggleContents = function () {
    if (this.contents) { this.closeContents(true); return; }
    var self = this;
    var panel = el('div', 'cw-contents');
    panel.setAttribute('role', 'dialog');
    panel.setAttribute('aria-label', 'Contents');
    panel.appendChild(el('h3', '', 'Contents'));
    var list = el('ol');
    var chapter = null;
    this.slides.forEach(function (slide, i) {
      var c = slide.getAttribute('data-cw-chapter');
      if (c && c !== chapter) { chapter = c; list.appendChild(el('li', 'cw-contents-chapter', c)); }
      var li = el('li');
      var b = el('button', '', (i + 1) + '. ' + (slide.getAttribute('data-cw-title') || ''));
      b.type = 'button';
      if (i === self.index) { b.setAttribute('aria-current', 'true'); }
      b.addEventListener('click', function () { self.go(i, true); self.deck.focus(); });
      li.appendChild(b);
      list.appendChild(li);
    });
    panel.appendChild(list);
    this.deck.appendChild(panel);
    this.contents = panel;
    this.contentsButton.setAttribute('aria-expanded', 'true');
    var current = panel.querySelector('[aria-current="true"]') || panel.querySelector('button');
    if (current) { current.focus(); }
  };

  Player.prototype.closeContents = function (refocus) {
    if (!this.contents) { return; }
    this.contents.remove();
    this.contents = null;
    this.contentsButton.setAttribute('aria-expanded', 'false');
    if (refocus) { this.contentsButton.focus(); }
  };

  Player.prototype.toggleFull = function () {
    var deck = this.deck;
    if (document.fullscreenElement === deck) { document.exitFullscreen(); return; }
    if (deck.classList.contains('cw-expanded')) {
      deck.classList.remove('cw-expanded');
      this.fullButton.textContent = 'Full screen';
      deck.focus();
      return;
    }
    if (deck.requestFullscreen && document.fullscreenEnabled !== false) {
      var self = this;
      deck.requestFullscreen().catch(function () { self.expand(); });
    } else {
      this.expand();
    }
  };

  Player.prototype.expand = function () {
    this.deck.classList.add('cw-expanded');
    this.fullButton.textContent = 'Close';
    this.deck.focus();
  };

  Player.prototype.toggleSound = function () {
    this.sound = !this.sound;
    this.soundButton.setAttribute('aria-pressed', this.sound ? 'true' : 'false');
    this.soundButton.textContent = this.sound ? 'Narration on' : 'Narration';
    if (this.sound) { this.playSound(); } else { this.stopSound(); }
  };

  Player.prototype.toggleTranscript = function () {
    var showing = this.transcript.hidden;
    this.transcript.hidden = !showing;
    this.transcriptButton.setAttribute('aria-pressed', showing ? 'true' : 'false');
  };

  Player.prototype.stopSound = function () {
    if (this.audio) { this.audio.pause(); this.audio = null; }
  };

  Player.prototype.playSound = function () {
    this.stopSound();
    if (!this.sound) { return; }
    var url = this.slides[this.index].getAttribute('data-cw-audio');
    if (!url) { return; }
    var self = this;
    var audio = new Audio(url);
    this.audio = audio;
    audio.addEventListener('ended', function () {
      if (self.audio === audio && self.deck.getAttribute('data-cw-advance') === 'auto') { self.next(); }
    });
    var playing = audio.play();
    if (playing && playing.catch) { playing.catch(function () { /* the browser asked for a click first */ }); }
  };

  Player.prototype.action = function (event) {
    var link = event.target.closest && event.target.closest('a.cw-action');
    if (!link || !this.deck.contains(link)) { return; }
    var action = link.getAttribute('data-cw-action') || '';
    if (action === 'goto') {
      event.preventDefault();
      var target = link.getAttribute('data-cw-target');
      var index = this.slides.findIndex(function (s) { return s.id === target; });
      // The link is on a slide now out of sight, so focus comes back to the deck and its keys keep working.
      if (index >= 0) { this.go(index, true); this.deck.focus(); }
      this.emit('cw:action', {key: this.key, action: action, href: '#' + target});
      return;
    }
    if (this.links[action]) { link.setAttribute('href', this.links[action]); }
    this.emit('cw:action', {key: this.key, action: action, href: link.getAttribute('href')});
  };

  Player.prototype.say = function (text) {
    var live = this.live;
    live.textContent = '';
    window.setTimeout(function () { live.textContent = text; }, 30);
  };

  Player.prototype.emit = function (name, detail) {
    this.deck.dispatchEvent(new CustomEvent(name, {detail: detail, bubbles: true}));
  };

  Player.prototype.destroy = function () {
    var deck = this.deck;
    this.stopSound();
    this.closeContents(false);
    deck.removeEventListener('keydown', this.onKey);
    deck.removeEventListener('click', this.onClick);
    deck.removeEventListener('click', this.onAction, true);
    document.removeEventListener('fullscreenchange', this.onFullChange);
    this.slides.forEach(function (slide) {
      ['role', 'aria-roledescription', 'aria-label', 'inert', 'aria-hidden'].forEach(function (a) { slide.removeAttribute(a); });
      slide.classList.remove('cw-current');
      Array.prototype.forEach.call(slide.querySelectorAll('.cw-step'), function (s) { s.classList.remove('cw-step-hidden'); s.removeAttribute('inert'); s.removeAttribute('aria-hidden'); });
      deck.insertBefore(slide, this.top);
    }, this);
    [this.top, this.viewport, this.transcript, this.bottom, this.live].forEach(function (n) { if (n) { n.remove(); } });
    deck.classList.remove('cw-ready', 'cw-expanded');
    ['role', 'aria-roledescription', 'aria-label'].forEach(function (a) { deck.removeAttribute(a); });
    players.delete(deck);
  };

  Player.prototype.public_ = function () {
    var self = this;
    return {
      go: function (n) { self.go(n - 1, false); },
      next: function () { self.next(); },
      prev: function () { self.prev(); },
      destroy: function () { self.destroy(); }
    };
  };

  function mount(deck, options) {
    if (!deck || !deck.classList || !deck.classList.contains('cw-deck')) { return null; }
    if (players.has(deck)) { return players.get(deck); }
    var player = new Player(deck, options || {});
    if (player.total === 0) { return null; }
    var handle = player.public_();
    players.set(deck, handle);
    return handle;
  }

  function mountAll(scope) {
    var found = [];
    Array.prototype.forEach.call((scope || document).querySelectorAll('.cw-deck:not([data-cw-manual])'), function (deck) {
      var player = mount(deck);
      if (player) { found.push(player); }
    });
    return found;
  }

  root.Catwalk = {version: VERSION, mount: mount, mountAll: mountAll};
  if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', function () { mountAll(document); }); }
  else { mountAll(document); }
  // Decks that htmx swaps into the page are mounted too. After adding decks any other way, call Catwalk.mountAll(container).
  document.addEventListener('htmx:after:swap', function (event) { mountAll(event.target || document); });
}(window));
