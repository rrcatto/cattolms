/**
 * Catto Learning Inbox reply editor (owner, 2026-11-11: "Use Ckeditor to compose an html msg").
 *
 * CKEditor 5 on every textarea marked data-inbox-rich: an HTML reply gets headings, bold, italic, underline, lists, links,
 * quotes and tables, what InboxHtml::clean keeps; the HTML signature (data-inbox-rich="signature") gets bold, italic and
 * links. Every change is copied back to the textarea, so the form sends it as it is. Without JavaScript the textarea
 * stays and its HTML is sent as typed.
 *
 * Requires the locally installed ckeditor5.umd.js (window.CKEDITOR).
 */
(() => {
    'use strict';

    const CK = window.CKEDITOR;
    if (!CK) {
        console.error('CKEditor 5 did not load. The reply stays a plain text area.');
        return;
    }
    const { ClassicEditor, Essentials, Paragraph, Heading, Bold, Italic, Underline, Link, AutoLink, List, BlockQuote, Table, TableToolbar, Autoformat } = CK;

    const start = (area) => {
        if (area.dataset.inboxRichReady === '1') return;
        area.dataset.inboxRichReady = '1';
        const signature = area.dataset.inboxRich === 'signature';
        ClassicEditor.create(area, {
            licenseKey: 'GPL',
            plugins: signature ? [Essentials, Paragraph, Bold, Italic, Link, AutoLink] : [Essentials, Paragraph, Heading, Bold, Italic, Underline, Link, AutoLink, List, BlockQuote, Table, TableToolbar, Autoformat],
            toolbar: signature ? ['bold', 'italic', 'link', '|', 'undo', 'redo'] : ['heading', '|', 'bold', 'italic', 'underline', '|', 'bulletedList', 'numberedList', 'link', 'blockQuote', 'insertTable', '|', 'undo', 'redo'],
            heading: { options: [
                { model: 'paragraph', title: 'Paragraph', class: 'ck-heading_paragraph' },
                { model: 'heading2', view: 'h2', title: 'Heading', class: 'ck-heading_heading2' },
                { model: 'heading3', view: 'h3', title: 'Subheading', class: 'ck-heading_heading3' },
            ] },
            table: { contentToolbar: ['tableColumn', 'tableRow', 'mergeTableCells'] },
            link: { defaultProtocol: 'https://', allowedProtocols: ['https?', 'mailto'] },
        }).then((editor) => {
            editor.model.document.on('change:data', () => {
                area.value = editor.getData();
                area.dispatchEvent(new Event('input', { bubbles: true }));
            });
            area.required = false;
        }).catch((problem) => console.error('The reply editor could not start:', problem));
    };

    const scan = (root) => root.querySelectorAll('textarea[data-inbox-rich]').forEach(start);
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', () => scan(document));
    else scan(document);
    document.addEventListener('htmx:after:swap', (event) => scan(event.target instanceof Element ? event.target : document));
})();
