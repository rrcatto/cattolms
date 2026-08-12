/**
 * Catto Learning CKEditor 5 configuration.
 *
 * Based on Richard Catto's working ctnlist CKEditor Builder configuration.
 * The locally installed ckeditor5.umd.js and ckeditor5.css must come from the
 * same CKEditor build so that every listed plugin is available.
 */
(() => {
    'use strict';

    const CK = window.CKEDITOR;
    if (!CK) {
        console.error('CKEditor 5 did not load. Rich-text fields remain as plain textareas.');
        return;
    }

    const {
        ClassicEditor,
        Autosave,
        Essentials,
        Paragraph,
        Autoformat,
        TextTransformation,
        Bold,
        Table,
        TableToolbar,
        Emoji,
        Mention,
        FontBackgroundColor,
        FontColor,
        FontFamily,
        FontSize,
        GeneralHtmlSupport,
        Heading,
        ImageInline,
        ImageToolbar,
        CloudServices,
        Link,
        ImageUpload,
        ImageInsertViaUrl,
        AutoImage,
        ImageTextAlternative,
        ImageStyle,
        Indent,
        IndentBlock,
        Italic,
        AutoLink,
        List,
        ImageUtils,
        ImageEditing,
        PlainTableOutput,
        Strikethrough,
        Style,
        TableCaption,
        Alignment,
        Underline,
        Fullscreen,
        MediaEmbed,
        MediaEmbedStyle,
        MediaEmbedToolbar,
        Code,
        Subscript,
        Superscript,
        Highlight,
        BlockQuote,
        HorizontalLine,
        CodeBlock,
        ImageBlock,
        LinkImage,
        ImageCaption,
        ShowBlocks,
        SourceEditing,
        HtmlComment,
        TodoList,
        BalloonToolbar,
        BlockToolbar
    } = CK;

    const requiredPlugins = {
        ClassicEditor,
        Autosave,
        Essentials,
        Paragraph,
        Autoformat,
        TextTransformation,
        Bold,
        Table,
        TableToolbar,
        Emoji,
        Mention,
        FontBackgroundColor,
        FontColor,
        FontFamily,
        FontSize,
        GeneralHtmlSupport,
        Heading,
        ImageInline,
        ImageToolbar,
        CloudServices,
        Link,
        ImageUpload,
        ImageInsertViaUrl,
        AutoImage,
        ImageTextAlternative,
        ImageStyle,
        Indent,
        IndentBlock,
        Italic,
        AutoLink,
        List,
        ImageUtils,
        ImageEditing,
        PlainTableOutput,
        Strikethrough,
        Style,
        TableCaption,
        Alignment,
        Underline,
        Fullscreen,
        MediaEmbed,
        MediaEmbedStyle,
        MediaEmbedToolbar,
        Code,
        Subscript,
        Superscript,
        Highlight,
        BlockQuote,
        HorizontalLine,
        CodeBlock,
        ImageBlock,
        LinkImage,
        ImageCaption,
        ShowBlocks,
        SourceEditing,
        HtmlComment,
        TodoList,
        BalloonToolbar,
        BlockToolbar
    };

    const missingPlugins = Object.entries(requiredPlugins)
        .filter(([, plugin]) => typeof plugin === 'undefined')
        .map(([name]) => name);

    if (missingPlugins.length > 0) {
        console.error(
            'The local CKEditor build does not contain the plugins required by ck-main.js:',
            missingPlugins.join(', ')
        );
        return;
    }

    const instances = new Map();

    const editorConfig = {
        licenseKey: 'GPL',
        plugins: [
            Alignment,
            Autoformat,
            AutoImage,
            AutoLink,
            Autosave,
            BalloonToolbar,
            BlockQuote,
            BlockToolbar,
            Bold,
            CloudServices,
            Code,
            CodeBlock,
            Emoji,
            Essentials,
            FontBackgroundColor,
            FontColor,
            FontFamily,
            FontSize,
            Fullscreen,
            GeneralHtmlSupport,
            Heading,
            Highlight,
            HorizontalLine,
            HtmlComment,
            ImageBlock,
            ImageCaption,
            ImageEditing,
            ImageInline,
            ImageInsertViaUrl,
            ImageStyle,
            ImageTextAlternative,
            ImageToolbar,
            ImageUpload,
            ImageUtils,
            Indent,
            IndentBlock,
            Italic,
            Link,
            LinkImage,
            List,
            MediaEmbed,
            MediaEmbedStyle,
            MediaEmbedToolbar,
            Mention,
            Paragraph,
            PlainTableOutput,
            ShowBlocks,
            SourceEditing,
            Strikethrough,
            Style,
            Subscript,
            Superscript,
            Table,
            TableCaption,
            TableToolbar,
            TextTransformation,
            TodoList,
            Underline
        ],
        toolbar: {
            items: [
                'undo',
                'redo',
                '|',
                'sourceEditing',
                'showBlocks',
                'fullscreen',
                '|',
                'heading',
                'style',
                '|',
                'fontSize',
                'fontFamily',
                'fontColor',
                'fontBackgroundColor',
                '|',
                'bold',
                'italic',
                'underline',
                'strikethrough',
                'subscript',
                'superscript',
                'code',
                '|',
                'emoji',
                'horizontalLine',
                'link',
                'insertImageViaUrl',
                'mediaEmbed',
                'insertTable',
                'highlight',
                'blockQuote',
                'codeBlock',
                '|',
                'alignment',
                '|',
                'bulletedList',
                'numberedList',
                'todoList',
                'outdent',
                'indent'
            ],
            shouldNotGroupWhenFull: true
        },
        autosave: {
            save: editor => {
                editor.updateSourceElement();
                return Promise.resolve();
            }
        },
        balloonToolbar: [
            'bold',
            'italic',
            '|',
            'link',
            '|',
            'bulletedList',
            'numberedList'
        ],
        blockToolbar: [
            'fontSize',
            'fontColor',
            'fontBackgroundColor',
            '|',
            'bold',
            'italic',
            '|',
            'link',
            'insertTable',
            '|',
            'bulletedList',
            'numberedList',
            'outdent',
            'indent'
        ],
        fontFamily: {
            supportAllValues: true
        },
        fontSize: {
            options: [10, 12, 14, 'default', 18, 20, 22],
            supportAllValues: true
        },
        fullscreen: {
            onEnterCallback: container => {
                container.classList.add(
                    'editor-container',
                    'editor-container_classic-editor',
                    'editor-container_include-style',
                    'editor-container_include-block-toolbar',
                    'editor-container_include-fullscreen',
                    'main-container'
                );
            }
        },
        heading: {
            options: [
                {
                    model: 'paragraph',
                    title: 'Paragraph',
                    class: 'ck-heading_paragraph'
                },
                {
                    model: 'heading1',
                    view: 'h1',
                    title: 'Heading 1',
                    class: 'ck-heading_heading1'
                },
                {
                    model: 'heading2',
                    view: 'h2',
                    title: 'Heading 2',
                    class: 'ck-heading_heading2'
                },
                {
                    model: 'heading3',
                    view: 'h3',
                    title: 'Heading 3',
                    class: 'ck-heading_heading3'
                },
                {
                    model: 'heading4',
                    view: 'h4',
                    title: 'Heading 4',
                    class: 'ck-heading_heading4'
                },
                {
                    model: 'heading5',
                    view: 'h5',
                    title: 'Heading 5',
                    class: 'ck-heading_heading5'
                },
                {
                    model: 'heading6',
                    view: 'h6',
                    title: 'Heading 6',
                    class: 'ck-heading_heading6'
                }
            ]
        },
        htmlSupport: {
            allow: [
                {
                    name: /^.*$/,
                    styles: true,
                    attributes: true,
                    classes: true
                }
            ]
        },
        image: {
            toolbar: [
                'toggleImageCaption',
                'imageTextAlternative',
                '|',
                'imageStyle:inline',
                'imageStyle:wrapText',
                'imageStyle:breakText'
            ]
        },
        link: {
            addTargetToExternalLinks: true,
            defaultProtocol: 'https://',
            decorators: {
                toggleDownloadable: {
                    mode: 'manual',
                    label: 'Downloadable',
                    attributes: {
                        download: 'file'
                    }
                }
            }
        },
        mediaEmbed: {
            toolbar: ['mediaEmbed:breakText', 'mediaEmbed:wrapText']
        },
        mention: {
            feeds: [
                {
                    marker: '@',
                    feed: []
                }
            ]
        },
        menuBar: {
            isVisible: true
        },
        placeholder: '',
        style: {
            definitions: [
                {
                    name: 'Article category',
                    element: 'h3',
                    classes: ['category']
                },
                {
                    name: 'Title',
                    element: 'h2',
                    classes: ['document-title']
                },
                {
                    name: 'Subtitle',
                    element: 'h3',
                    classes: ['document-subtitle']
                },
                {
                    name: 'Info box',
                    element: 'p',
                    classes: ['info-box']
                },
                {
                    name: 'CTA Link Primary',
                    element: 'a',
                    classes: ['button', 'button--green']
                },
                {
                    name: 'CTA Link Secondary',
                    element: 'a',
                    classes: ['button', 'button--black']
                },
                {
                    name: 'Marker',
                    element: 'span',
                    classes: ['marker']
                },
                {
                    name: 'Spoiler',
                    element: 'span',
                    classes: ['spoiler']
                }
            ]
        },
        table: {
            contentToolbar: ['tableColumn', 'tableRow', 'mergeTableCells']
        }
    };

    function editorElements(root = document) {
        const elements = [];
        if (root instanceof HTMLTextAreaElement && root.matches('textarea.cl-rich-editor, textarea#mt_html, textarea[data-ckeditor]')) {
            elements.push(root);
        }
        if (typeof root.querySelectorAll === 'function') {
            elements.push(...root.querySelectorAll('textarea.cl-rich-editor, textarea#mt_html, textarea[data-ckeditor]'));
        }
        return [...new Set(elements)];
    }

    function initialiseEditors(root = document) {
        editorElements(root).forEach(element => {
            if (instances.has(element) || element.dataset.ckeditorInitialising === '1') {
                return;
            }

            element.dataset.ckeditorInitialising = '1';
            const config = {
                ...editorConfig,
                placeholder: element.dataset.placeholder || editorConfig.placeholder
            };

            ClassicEditor.create(element, config)
                .then(editor => {
                    instances.set(element, editor);
                    delete element.dataset.ckeditorInitialising;

                    const minimumHeight = element.dataset.editorMinHeight;
                    if (minimumHeight) {
                        editor.ui.view.editable.element.style.minHeight = minimumHeight;
                    }
                })
                .catch(error => {
                    delete element.dataset.ckeditorInitialising;
                    console.error('Unable to initialise CKEditor for', element.name || element.id, error);
                });
        });
    }

    function syncFormEditors(form) {
        instances.forEach((editor, element) => {
            if (element.form === form) {
                editor.updateSourceElement();
            }
        });
    }

    function destroyEditors(root) {
        const tasks = [];
        instances.forEach((editor, element) => {
            if (root === element || (root instanceof Element && root.contains(element))) {
                tasks.push(
                    editor.destroy()
                        .catch(error => console.error('Unable to destroy CKEditor cleanly.', error))
                        .finally(() => instances.delete(element))
                );
            }
        });
        return Promise.all(tasks);
    }

    document.addEventListener('submit', event => {
        if (event.target instanceof HTMLFormElement) {
            syncFormEditors(event.target);
        }
    }, true);

    document.addEventListener('DOMContentLoaded', () => initialiseEditors(document));

    window.CattoLearningEditors = {
        initialise: initialiseEditors,
        syncForm: syncFormEditors,
        destroy: destroyEditors,
        instances
    };
})();