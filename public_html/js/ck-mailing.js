/**
 * Catto Learning mailing text editor (owner, 2026-10-28, decision 6A: each text block "a small word processor").
 *
 * CKEditor 5 on every textarea marked data-mailing-rich, with only paragraphs, bold, italic, links and bulleted and
 * numbered lists: what an email can show, and all the server keeps (EmailBlocks::richText). Every change is copied back
 * to the textarea, so the form sends it as it is. Without JavaScript the textarea stays: a blank line starts a new
 * paragraph and a web address becomes a link.
 *
 * Requires the locally installed ckeditor5.umd.js (window.CKEDITOR).
 */
(() => {
    'use strict';

    const CK = window.CKEDITOR;
    if (!CK) {
        console.error('CKEditor 5 did not load. The text stays a plain text area.');
        return;
    }
    const { ClassicEditor, Essentials, Paragraph, Bold, Italic, Link, AutoLink, List, Autoformat } = CK;

    const paragraphsOf = (text) => text.includes('<') ? text : text.split(/\n\s*\n/).map((p) => p.trim()).filter(Boolean)
        .map((p) => '<p>' + p.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/\n/g, '<br>') + '</p>').join('');

    const start = (area) => {
        if (area.dataset.mailingRichReady === '1') return;
        area.dataset.mailingRichReady = '1';
        area.value = paragraphsOf(area.value);
        ClassicEditor.create(area, {
            licenseKey: 'GPL',
            plugins: [Essentials, Paragraph, Bold, Italic, Link, AutoLink, List, Autoformat],
            toolbar: ['bold', 'italic', 'link', '|', 'bulletedList', 'numberedList', '|', 'undo', 'redo'],
            link: { defaultProtocol: 'https://', allowedProtocols: ['https?', 'mailto'] },
        }).then((editor) => {
            editor.model.document.on('change:data', () => {
                area.value = editor.getData();
                area.dispatchEvent(new Event('input', { bubbles: true }));
            });
            // A required textarea hidden by the editor would stop the form being sent: the server checks it instead.
            area.required = false;
        }).catch((problem) => console.error('The text editor could not start:', problem));
    };

    const scan = (root) => root.querySelectorAll('textarea[data-mailing-rich]').forEach(start);
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', () => scan(document));
    else scan(document);
    document.addEventListener('htmx:after:swap', (event) => scan(event.target instanceof Element ? event.target : document));
})();
