(() => {
  'use strict';
  const editor = document.getElementById('question-editor');
  const addQuestionButton = document.getElementById('add-question');
  const questionTemplate = document.getElementById('question-editor-question-template');
  const optionTemplate = document.getElementById('question-editor-option-template');
  if (!editor || !addQuestionButton || !(questionTemplate instanceof HTMLTemplateElement)
      || !(optionTemplate instanceof HTMLTemplateElement)) return;

  // Twig owns all structure. Only indices and ID references change after cloning or removal.
  function renumber() {
    const selected = [...editor.querySelectorAll('input[type="radio"]:checked')];
    editor.querySelectorAll('[data-question]').forEach((question, qi) => {
      question.dataset.questionIndex = String(qi);
      question.querySelector('[data-question-number]').textContent = String(qi + 1);
      question.querySelectorAll('[name]').forEach((control) => {
        control.name = control.name.replace(/\[[^\]]+\]/, `[${qi}]`);
      });
      question.querySelectorAll('[id], [for], [aria-describedby]').forEach((element) => {
        for (const attribute of ['id', 'for', 'aria-describedby']) {
          const value = element.getAttribute(attribute);
          if (value) element.setAttribute(attribute, value.replace(/question-[^-\s]+-/g, `question-${qi}-`));
        }
      });
      question.querySelectorAll('[data-option]').forEach((option, oi) => {
        option.dataset.optionIndex = String(oi);
        option.querySelector('input[type="radio"]').value = String(oi);
        option.querySelector('input[name^="option_html["]').name = `option_html[${qi}][${oi}]`;
        option.querySelectorAll('[id], [for], [aria-describedby]').forEach((element) => {
          for (const attribute of ['id', 'for', 'aria-describedby']) {
            const value = element.getAttribute(attribute);
            if (value) element.setAttribute(attribute, value.replace(/-option-[^-\s]+/g, `-option-${oi}`));
          }
        });
      });
    });
    // Changing radio group names must not discard the author's current answer selections.
    selected.forEach((radio) => { radio.checked = true; });
  }

  function addQuestion() {
    editor.append(questionTemplate.content.cloneNode(true));
    renumber();
    editor.lastElementChild?.scrollIntoView({ behavior: 'smooth', block: 'center' });
  }

  addQuestionButton.addEventListener('click', addQuestion);
  editor.addEventListener('click', (event) => {
    if (!(event.target instanceof Element)) return;
    const action = event.target.closest('button[name="question_action"]');
    const question = action?.closest('[data-question]');
    if (!action || !question) return;
    if (action.value === 'remove-question') {
      if (confirm('Remove this question?')) { question.remove(); renumber(); }
    } else if (action.value === 'add-option') {
      question.querySelector('[data-options]').append(optionTemplate.content.cloneNode(true));
      renumber();
    } else if (action.value === 'remove-option') {
      const options = question.querySelector('[data-options]');
      if (options.querySelectorAll('[data-option]').length <= 2) {
        alert('Every question requires at least two options.');
        return;
      }
      action.closest('[data-option]').remove();
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

  if (!editor.querySelector('[data-question]')) addQuestion();
  renumber();
})();
