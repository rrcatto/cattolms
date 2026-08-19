(() => {
  'use strict';
  const editor = document.getElementById('question-editor');
  const addQuestionButton = document.getElementById('add-question');
  if (!editor || !addQuestionButton) return;

  const remediationTemplate = document.getElementById('diagnostic-remediation-options');
  const diagnosticMode = remediationTemplate instanceof HTMLTemplateElement;
  const remediationOptions = diagnosticMode ? remediationTemplate.innerHTML : '';
  const escapeAttribute = (value) => String(value)
    .replaceAll('&', '&amp;').replaceAll('"', '&quot;').replaceAll('<', '&lt;').replaceAll('>', '&gt;');

  const optionMarkup = (q, o, value = '') => `<div class="input-group mb-2" data-option><span class="input-group-text"><input type="radio" name="correct_option[${q}]" value="${o}" aria-label="Correct option"></span><input class="form-control" name="option_html[${q}][${o}]" value="${escapeAttribute(value)}" required><button class="btn btn-outline-danger" type="button" data-remove-option aria-label="Remove option">×</button></div>`;

  const diagnosticQuestionMarkup = (i) => `<div class="cl-question-editor" data-question><div class="d-flex justify-content-between align-items-center gap-3"><h3 class="h5 mb-0">Question <span data-question-number>${i + 1}</span></h3><button class="btn btn-sm btn-outline-danger" type="button" data-remove-question>Remove</button></div><label class="form-label mt-3">Question</label><textarea class="form-control mb-3" name="question_html[${i}]" required></textarea><div class="row g-3"><div class="col-md-2"><label class="form-label">Points</label><input class="form-control" type="number" min="1" name="question_points[${i}]" value="1"></div><div class="col-md-5"><label class="form-label">Explanation</label><textarea class="form-control" name="question_explanation[${i}]"></textarea></div><div class="col-md-5"><label class="form-label">Suggest modules when incorrect</label><select class="form-select" name="remediation_module_keys[${i}][]" multiple size="4">${remediationOptions}</select></div></div><input type="hidden" name="question_difficulty[${i}]" value="standard"><input type="hidden" name="practice_eligible[${i}]" value="1"><input type="hidden" name="incorrect_points[${i}]" value="0"><div class="cl-option-editor mt-3" data-options>${optionMarkup(i, 0)}${optionMarkup(i, 1)}${optionMarkup(i, 2)}${optionMarkup(i, 3)}</div><button class="btn btn-sm btn-outline-secondary" type="button" data-add-option>Add option</button></div>`;

  const standardQuestionMarkup = (i) => `<div class="cl-question-editor" data-question><div class="d-flex justify-content-between align-items-center gap-3"><h3 class="h5 mb-0">Question <span data-question-number>${i + 1}</span></h3><button class="btn btn-sm btn-outline-danger" type="button" data-remove-question>Remove</button></div><label class="form-label mt-3">Question</label><textarea class="form-control mb-3" name="question_html[${i}]" required></textarea><div class="row g-3"><div class="col-md-2"><label class="form-label">Points</label><input class="form-control" type="number" min="1" name="question_points[${i}]" value="1" required></div><div class="col-md-3"><label class="form-label">Difficulty</label><select class="form-select" name="question_difficulty[${i}]"><option value="introductory">Introductory</option><option value="standard" selected>Standard</option><option value="advanced">Advanced</option></select></div><div class="col-md-2"><label class="form-label">Wrong points</label><input class="form-control" type="number" max="0" step="0.25" name="incorrect_points[${i}]" value="0"></div><div class="col-md-5"><label class="form-label">Explanation</label><textarea class="form-control" name="question_explanation[${i}]"></textarea></div><div class="col-12 d-flex gap-4"><label class="form-check"><input class="form-check-input" type="checkbox" name="practice_eligible[${i}]" value="1" checked> <span class="form-check-label">Practice eligible</span></label><label class="form-check"><input class="form-check-input" type="checkbox" name="graded_eligible[${i}]" value="1" checked> <span class="form-check-label">Graded eligible</span></label></div></div><div class="cl-option-editor mt-3" data-options>${optionMarkup(i, 0)}${optionMarkup(i, 1)}${optionMarkup(i, 2)}${optionMarkup(i, 3)}</div><button class="btn btn-sm btn-outline-secondary" type="button" data-add-option>Add option</button></div>`;

  function renumber() {
    editor.querySelectorAll('[data-question]').forEach((question, qi) => {
      const number = question.querySelector('[data-question-number]');
      if (number) number.textContent = String(qi + 1);
      const mappings = [
        ['textarea[name^="question_html"]', `question_html[${qi}]`],
        ['input[name^="question_points"]', `question_points[${qi}]`],
        ['select[name^="question_difficulty"]', `question_difficulty[${qi}]`],
        ['input[name^="question_difficulty"]', `question_difficulty[${qi}]`],
        ['input[name^="incorrect_points"]', `incorrect_points[${qi}]`],
        ['textarea[name^="question_explanation"]', `question_explanation[${qi}]`],
        ['input[name^="practice_eligible"]', `practice_eligible[${qi}]`],
        ['input[name^="graded_eligible"]', `graded_eligible[${qi}]`],
        ['select[name^="remediation_module_keys"]', `remediation_module_keys[${qi}][]`],
      ];
      mappings.forEach(([selector, name]) => {
        const field = question.querySelector(selector);
        if (field) field.name = name;
      });
      question.querySelectorAll('[data-option]').forEach((option, oi) => {
        const radio = option.querySelector('input[type="radio"]');
        const input = option.querySelector('input.form-control');
        if (radio) {
          radio.name = `correct_option[${qi}]`;
          radio.value = String(oi);
        }
        if (input) input.name = `option_html[${qi}][${oi}]`;
      });
    });
  }

  function addQuestion() {
    const index = editor.querySelectorAll('[data-question]').length;
    editor.insertAdjacentHTML('beforeend', diagnosticMode ? diagnosticQuestionMarkup(index) : standardQuestionMarkup(index));
    renumber();
    editor.lastElementChild?.scrollIntoView({ behavior: 'smooth', block: 'center' });
  }

  addQuestionButton.addEventListener('click', addQuestion);
  editor.addEventListener('click', (event) => {
    const target = event.target;
    if (!(target instanceof HTMLElement)) return;
    const removeQuestion = target.closest('[data-remove-question]');
    if (removeQuestion) {
      const question = removeQuestion.closest('[data-question]');
      if (question && confirm('Remove this question?')) {
        question.remove();
        renumber();
      }
      return;
    }
    const addOption = target.closest('[data-add-option]');
    if (addOption) {
      const question = addOption.closest('[data-question]');
      const options = question?.querySelector('[data-options]');
      if (!question || !options) return;
      const qi = [...editor.querySelectorAll('[data-question]')].indexOf(question);
      options.insertAdjacentHTML('beforeend', optionMarkup(qi, options.querySelectorAll('[data-option]').length));
      renumber();
      return;
    }
    const removeOption = target.closest('[data-remove-option]');
    if (removeOption) {
      const options = removeOption.closest('[data-options]');
      if (!options) return;
      if (options.querySelectorAll('[data-option]').length <= 2) {
        alert('Every question requires at least two options.');
        return;
      }
      removeOption.closest('[data-option]')?.remove();
      renumber();
    }
  });

  document.getElementById('question-editor-form')?.addEventListener('submit', (event) => {
    renumber();
    for (const question of editor.querySelectorAll('[data-question]')) {
      if (!question.querySelector('input[type="radio"]:checked')) {
        event.preventDefault();
        question.scrollIntoView({ behavior: 'smooth', block: 'center' });
        alert('Select the correct option for every question.');
        return;
      }
    }
  });

  if (editor.querySelectorAll('[data-question]').length === 0) addQuestion();
  renumber();
})();