/**
 * Catto Learning certificate wording editor.
 *
 * CKEditor 5 for a certificate design's wording and small print: paragraphs in the certificate's
 * text styles, bold, italic, alignment and fields. A field is an inline widget showing its label
 * ("Learner name") and stored as <span class="cl-field" data-field="learner.name">Learner name</span>,
 * so an author never sees template syntax; Insert field lists the fields the server offers
 * (data-fields on the textarea). Alignment is written as classes, not style attributes, and
 * nothing else is allowed in, so the stored wording is exactly what a certificate can show.
 *
 * Every change is copied back to the textarea and announced with an input event, which the
 * certificate-preview controller uses to redraw the preview. Without JavaScript the textarea stays
 * and fields can be typed as [Learner name].
 *
 * Requires the locally installed ckeditor5.umd.js (window.CKEDITOR).
 */
(() => {
    'use strict';

    const CK = window.CKEDITOR;
    if (!CK) {
        console.error('CKEditor 5 did not load. The certificate wording stays a plain text area.');
        return;
    }
    const { ClassicEditor, Essentials, Paragraph, Bold, Italic, Alignment, Style, GeneralHtmlSupport, Plugin, Command, Widget, toWidget,
        viewToModelPositionOutsideModelElement, createDropdown, addListToDropdown, Collection } = CK;
    const UIModel = CK.UIModel || CK.ViewModel;

    class InsertFieldCommand extends Command {
        execute(field) {
            const model = this.editor.model;
            model.change((writer) => {
                const element = writer.createElement('certificateField', { path: field.path, label: field.label });
                model.insertObject(element, null, null, { setSelection: 'after' });
            });
        }

        refresh() {
            const selection = this.editor.model.document.selection;
            this.isEnabled = this.editor.model.schema.checkChild(selection.focus.parent, 'certificateField');
        }
    }

    class CertificateFields extends Plugin {
        static get requires() {
            return [Widget];
        }

        init() {
            const editor = this.editor;
            const fields = editor.config.get('certificateFields') || [];
            const labels = new Map(fields.map((field) => [field.path, field.label]));

            editor.model.schema.register('certificateField', {
                inheritAllFrom: '$inlineObject',
                allowAttributes: ['path', 'label']
            });
            editor.conversion.for('upcast').elementToElement({
                view: { name: 'span', classes: ['cl-field'] },
                model: (view, { writer }) => {
                    const path = view.getAttribute('data-field') || '';
                    return labels.has(path) ? writer.createElement('certificateField', { path, label: labels.get(path) }) : null;
                },
                converterPriority: 'high'
            });
            const render = (item, writer) => {
                const span = writer.createContainerElement('span', { class: 'cl-field', 'data-field': item.getAttribute('path') });
                writer.insert(writer.createPositionAt(span, 0), writer.createText(item.getAttribute('label')));
                return span;
            };
            editor.conversion.for('editingDowncast').elementToElement({
                model: 'certificateField',
                view: (item, { writer }) => toWidget(render(item, writer), writer, { label: item.getAttribute('label') + ' field' })
            });
            editor.conversion.for('dataDowncast').elementToElement({ model: 'certificateField', view: (item, { writer }) => render(item, writer) });
            editor.editing.mapper.on('viewToModelPosition', viewToModelPositionOutsideModelElement(editor.model, (view) => view.hasClass('cl-field')));
            editor.commands.add('insertCertificateField', new InsertFieldCommand(editor));

            editor.ui.componentFactory.add('insertField', (locale) => {
                const dropdown = createDropdown(locale);
                dropdown.buttonView.set({ label: 'Insert field', withText: true, tooltip: 'Insert something that changes for each learner' });
                const items = new Collection();
                fields.forEach((field) => {
                    items.add({ type: 'button', model: new UIModel({ withText: true, label: field.label, field }) });
                });
                addListToDropdown(dropdown, items);
                dropdown.bind('isEnabled').to(editor.commands.get('insertCertificateField'));
                this.listenTo(dropdown, 'execute', (event) => {
                    editor.execute('insertCertificateField', event.source.field);
                    editor.editing.view.focus();
                });
                return dropdown;
            });
        }
    }

    const parse = (value, fallback) => {
        try {
            return JSON.parse(value || '');
        } catch (error) {
            return fallback;
        }
    };

    document.querySelectorAll('textarea[data-certificate-editor]').forEach((textarea) => {
        const fields = parse(textarea.dataset.fields, []);
        const styles = parse(textarea.dataset.styles, {});
        const styleClasses = Object.keys(styles);
        const wording = textarea.dataset.certificateEditor === 'wording';
        const toolbar = wording
            ? ['style', '|', 'bold', 'italic', '|', 'alignment', '|', 'insertField', '|', 'undo', 'redo']
            : ['bold', 'italic', '|', 'insertField', '|', 'undo', 'redo'];
        const plugins = [Essentials, Paragraph, Bold, Italic, CertificateFields];
        if (wording) {
            plugins.push(Alignment, GeneralHtmlSupport, Style);
        }
        ClassicEditor.create(textarea, {
            licenseKey: 'GPL',
            plugins,
            toolbar: { items: toolbar, shouldNotGroupWhenFull: true },
            certificateFields: fields,
            alignment: { options: [{ name: 'left', className: 'align-left' }, { name: 'center', className: 'align-center' }, { name: 'right', className: 'align-right' }] },
            htmlSupport: { allow: [{ name: 'p', classes: styleClasses }] },
            style: { definitions: styleClasses.map((name) => ({ name: styles[name], element: 'p', classes: [name] })) }
        }).then((editor) => {
            let timer = null;
            editor.model.document.on('change:data', () => {
                clearTimeout(timer);
                timer = setTimeout(() => {
                    editor.updateSourceElement();
                    textarea.dispatchEvent(new Event('input', { bubbles: true }));
                }, 250);
            });
        }).catch((error) => {
            console.error('The certificate wording editor could not start; the plain text area stays.', error);
        });
    });
})();
