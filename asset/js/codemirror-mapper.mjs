/**
 * CodeMirror 6 bundle for Mapper module.
 *
 * This is the source file that gets bundled by esbuild into codemirror-mapper.js.
 * Do not load this file directly in the browser.
 *
 * Build: npm run build (from module root)
 */

import {EditorView, basicSetup} from 'codemirror';
import {xml} from '@codemirror/lang-xml';
import {json} from '@codemirror/lang-json';
import {EditorState, Compartment} from '@codemirror/state';
import {placeholder} from '@codemirror/view';
import {history} from '@codemirror/commands';

/**
 * XML schema for Mapper mapping files.
 */
const xmlElements = [
    {
        name: 'mapping',
        top: true,
        children: ['include', 'info', 'params', 'param', 'maps', 'map', 'tables', 'table'],
    },
    {
        name: 'include',
        attributes: [{name: 'mapping'}],
    },
    {
        name: 'info',
        children: ['label', 'from', 'to', 'querier', 'mapper', 'preprocess', 'example'],
    },
    {
        name: 'params',
        children: ['param'],
    },
    {
        name: 'param',
        attributes: [{name: 'name'}],
    },
    {
        name: 'maps',
        children: ['map'],
    },
    {
        name: 'map',
        children: ['from', 'to', 'mod'],
    },
    {
        name: 'from',
        attributes: [
            {name: 'jsdot'},
            {name: 'jmespath'},
            {name: 'jsonpath'},
            {name: 'xpath'},
            {name: 'index'},
        ],
    },
    {
        name: 'to',
        attributes: [
            {name: 'field'},
            {name: 'datatype'},
            {name: 'language'},
            {name: 'visibility'},
        ],
    },
    {
        name: 'mod',
        attributes: [
            {name: 'raw'},
            {name: 'pattern'},
            {name: 'prepend'},
            {name: 'append'},
        ],
    },
    {
        name: 'tables',
        children: ['table'],
    },
    {
        name: 'table',
        children: ['label', 'entry', 'list'],
        attributes: [
            {name: 'name'},
            {name: 'code'},
            {name: 'lang'},
        ],
    },
    {
        name: 'entry',
        attributes: [{name: 'key'}],
    },
    {
        name: 'label',
        attributes: [{name: 'lang'}],
    },
    {
        name: 'list',
        children: ['term'],
    },
    {
        name: 'term',
        attributes: [{name: 'code'}],
    },
    {name: 'querier'},
    {name: 'mapper'},
    {name: 'preprocess'},
    {name: 'example'},
];

/**
 * Detect the format of a mapping content.
 *
 * @param {string} content
 * @returns {string} 'xml', 'json', or 'ini'
 */
function detectFormat(content) {
    const trimmed = content.trimStart();
    if (trimmed.startsWith('<') || trimmed.startsWith('<?xml')) {
        return 'xml';
    }
    if (trimmed.startsWith('{') || trimmed.startsWith('[')) {
        return 'json';
    }
    return 'ini';
}

/**
 * Get the language extension for a given format.
 *
 * @param {string} format
 * @returns {import('@codemirror/state').Extension}
 */
function getLanguageExtension(format) {
    switch (format) {
        case 'xml':
            return xml({elements: xmlElements});
        case 'json':
            return json();
        default:
            // INI: plain text, no language support for now.
            return [];
    }
}

/**
 * Initialize the Mapper CodeMirror editor.
 */
function initMapperEditor() {
    const textareaId = 'o-mapper-mapping';
    const textarea = document.getElementById(textareaId);
    if (!textarea) {
        return;
    }

    const isReadOnly = !window.location.href.includes('/edit');
    const content = textarea.value;
    const format = detectFormat(content);
    const language = new Compartment();

    const extensions = [
        basicSetup,
        history({minDepth: 10000}),
        language.of(getLanguageExtension(format)),
        EditorView.lineWrapping,
        EditorState.readOnly.of(isReadOnly),
        placeholder(textarea.getAttribute('placeholder') || ''),
        EditorState.tabSize.of(4),
    ];

    const view = new EditorView({
        doc: content,
        extensions: extensions,
        parent: textarea.parentNode,
    });

    // Hide the original textarea.
    textarea.style.display = 'none';

    // Sync editor content back to textarea on form submit.
    const form = textarea.closest('form');
    if (form) {
        form.addEventListener('submit', function () {
            textarea.value = view.state.doc.toString();
        });
    }
}

// Initialize when DOM is ready.
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initMapperEditor);
} else {
    initMapperEditor();
}
