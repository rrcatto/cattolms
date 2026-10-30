/* Recording a Presentation's narration in the browser (owner, 2026-10-28: "I'd like to be able to narrate the slides,
   so please allow for recording audio if it's possible"; 2026-10-29: Discard, Remove, and saving made plain). Each
   slide's Narration box shows what is saved for the slide apart from a new take. A take, recorded with the microphone
   (MediaRecorder) or chosen as a file, is heard first, then kept or discarded. Keep sends it to the site at once and
   Remove deletes the saved narration at once, so a recording never waits for the form's Save. The markup is the
   server's: this script only fills, shows and hides it. Without it, an uploaded file is saved with the form. */
(function () {
  'use strict';

  var TYPES = ['audio/webm;codecs=opus', 'audio/webm', 'audio/ogg;codecs=opus', 'audio/mp4'];
  var pending = 0;

  function canRecord() {
    return !!(window.MediaRecorder && navigator.mediaDevices && navigator.mediaDevices.getUserMedia);
  }

  function chooseType() {
    for (var i = 0; i < TYPES.length; i++) {
      if (window.MediaRecorder.isTypeSupported && window.MediaRecorder.isTypeSupported(TYPES[i])) { return TYPES[i]; }
    }
    return '';
  }

  function extension(type) {
    if (type.indexOf('ogg') !== -1) { return 'ogg'; }
    if (type.indexOf('mp4') !== -1) { return 'm4a'; }
    return 'webm';
  }

  function csrf() {
    var input = document.querySelector('input[name="csrf"]');
    return input ? input.value : '';
  }

  function send(url, data) {
    data.append('csrf', csrf());
    return fetch(url, {method: 'POST', body: data, credentials: 'same-origin', headers: {'Accept': 'application/json'}})
      .then(function (response) {
        return response.json().catch(function () { return {}; }).then(function (body) { return {ok: response.ok && !!body.ok, body: body}; });
      });
  }

  function setUp(box) {
    var area = box.querySelector('.cl-narration-recorder');
    if (!area) { return; }
    var url = box.getAttribute('data-narration-url');
    var slide = box.getAttribute('data-narration-slide');
    var current = box.querySelector('.cl-narration-current');
    var none = current.querySelector('.cl-narration-none');
    var removeArea = box.querySelector('.cl-narration-remove');
    var remove = removeArea.querySelector('button');
    var record = area.querySelector('[id$="-record"]');
    var stop = area.querySelector('[id$="-stop"]');
    var take = area.querySelector('.cl-narration-take');
    var takeAudio = take.querySelector('audio');
    var keep = take.querySelector('[id$="-keep"]');
    var discard = take.querySelector('[id$="-discard"]');
    var status = area.querySelector('.cl-narration-status');
    var file = box.querySelector('input[type="file"]');
    var recorder = null, chunks = [], blob = null, name = '', seconds = null, startedAt = 0, timer = null, stream = null, type = '';
    // A recording nobody kept would be lost on leaving the page; a chosen file still goes with the form's Save.
    var counted = false;

    area.hidden = false;
    if (!canRecord()) {
      record.hidden = true;
      stop.hidden = true;
    }
    stop.disabled = true;

    function say(text) { status.textContent = text; }

    function showTake(newBlob, newName, newSeconds, label, recorded) {
      if (recorded && !counted) { pending++; counted = true; }
      blob = newBlob; name = newName; seconds = newSeconds;
      if (takeAudio.src) { URL.revokeObjectURL(takeAudio.src); }
      takeAudio.src = URL.createObjectURL(blob);
      take.querySelector('.cl-narration-label').textContent = label;
      take.hidden = false;
      keep.disabled = false;
      discard.disabled = false;
    }

    function clearTake() {
      if (counted) { pending--; counted = false; }
      blob = null; name = ''; seconds = null;
      if (takeAudio.src) { URL.revokeObjectURL(takeAudio.src); takeAudio.removeAttribute('src'); }
      take.hidden = true;
      if (file) { file.value = ''; }
    }

    function showSaved(src) {
      var old = current.querySelector('audio');
      if (old) { old.remove(); }
      if (src) {
        var audio = document.createElement('audio');
        audio.controls = true;
        audio.preload = 'none';
        audio.src = src;
        current.insertBefore(audio, none);
      }
      none.hidden = !!src;
      removeArea.hidden = !src;
    }

    record.addEventListener('click', function () {
      navigator.mediaDevices.getUserMedia({audio: true}).then(function (s) {
        stream = s;
        type = chooseType();
        chunks = [];
        recorder = type ? new MediaRecorder(stream, {mimeType: type}) : new MediaRecorder(stream);
        type = recorder.mimeType || type || 'audio/webm';
        recorder.addEventListener('dataavailable', function (e) { if (e.data && e.data.size) { chunks.push(e.data); } });
        recorder.addEventListener('stop', function () {
          stream.getTracks().forEach(function (track) { track.stop(); });
          window.clearInterval(timer);
          var length = (Date.now() - startedAt) / 1000;
          clearTake();
          showTake(new Blob(chunks, {type: type.split(';')[0]}), 'narration.' + extension(type), length, 'New recording, not saved yet', true);
          record.disabled = false;
          stop.disabled = true;
          say('Recorded ' + Math.round(length) + ' seconds. Listen to it, then press Keep this recording to save it, or Discard.');
        });
        recorder.start();
        startedAt = Date.now();
        record.disabled = true;
        stop.disabled = false;
        keep.disabled = true;
        discard.disabled = true;
        say('Recording. Press Stop recording when you have finished.');
        timer = window.setInterval(function () { say('Recording: ' + Math.round((Date.now() - startedAt) / 1000) + ' seconds. Press Stop recording when you have finished.'); }, 1000);
      }).catch(function () {
        say('The microphone could not be used. Allow it for this site in the browser, or upload an audio file below.');
      });
    });

    stop.addEventListener('click', function () {
      if (recorder && recorder.state === 'recording') { recorder.stop(); }
    });

    if (file) {
      file.addEventListener('change', function () {
        var chosen = file.files && file.files[0];
        if (!chosen) { return; }
        if (counted) { pending--; counted = false; }
        showTake(chosen, chosen.name, null, 'Chosen file, not saved yet: ' + chosen.name, false);
        say('Listen to it, then press Keep this recording to save it, or Discard.');
      });
    }

    keep.addEventListener('click', function () {
      if (!blob) { return; }
      var data = new FormData();
      if (seconds !== null) { data.append('duration', String(seconds)); }
      data.append('audio', blob, name || 'narration');
      keep.disabled = true;
      discard.disabled = true;
      say('Saving the recording...');
      send(url, data).then(function (result) {
        if (!result.ok) { keep.disabled = false; discard.disabled = false; say(result.body.message || 'The recording could not be saved. Try again.'); return; }
        clearTake();
        showSaved(result.body.url);
        say('Saved for this slide. Learners hear it when they turn the narration on.');
      }).catch(function () {
        keep.disabled = false; discard.disabled = false;
        say('The recording could not be sent. Check your connection and try again.');
      });
    });

    discard.addEventListener('click', function () {
      clearTake();
      say('Discarded. Nothing was saved, and the slide keeps what it had.');
    });

    // Remove acts at once here; without this script the button sends the form, which removes it there.
    remove.addEventListener('click', function (event) {
      event.preventDefault();
      if (!window.confirm('Remove this slide\'s narration? This cannot be undone.')) { return; }
      remove.disabled = true;
      send(url + '/remove', new FormData()).then(function (result) {
        remove.disabled = false;
        if (!result.ok) { say(result.body.message || 'The narration could not be removed. Try again.'); return; }
        showSaved('');
        say('Removed. The slide has no narration now.');
      }).catch(function () {
        remove.disabled = false;
        say('The narration could not be removed. Check your connection and try again.');
      });
    });

    box.setAttribute('data-narration-ready', slide || '1');
  }

  function setUpAll() {
    Array.prototype.forEach.call(document.querySelectorAll('.cl-narration[data-narration-url]'), setUp);
    // A recording nobody kept is lost on leaving the page, so the browser asks first.
    window.addEventListener('beforeunload', function (event) {
      if (pending > 0) { event.preventDefault(); event.returnValue = ''; }
    });
  }

  if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', setUpAll); } else { setUpAll(); }
}());
