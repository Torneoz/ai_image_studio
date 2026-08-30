(function (Drupal, once) {
  'use strict';

  /**
   * Inserts Token browser selections into the AI prompt MDX editor.
   */
  Drupal.behaviors.aiImageStudioVboPromptTokens = {
    attach(context) {
      once('ai-image-studio-vbo-prompt-tokens', 'html', context).forEach(() => {
        document.addEventListener('click', (event) => {
          const link = event.target.closest(
            '.token-tree-dialog .token-click-insert .token-key a',
          );
          if (!link) {
            return;
          }
          const textarea = document.querySelector('textarea[data-mdxeditor]');
          const token = link.textContent.trim();
          if (!textarea || !/^\[[^\]]+\]$/.test(token)) {
            return;
          }

          // Token's normal click handler inserts an HTML link into the hidden
          // textarea. Stop it before MDX converts that link into Markdown.
          event.preventDefault();
          event.stopImmediatePropagation();
          const separator = textarea.value === '' || /\s$/.test(textarea.value)
            ? ''
            : ' ';
          const content = `${textarea.value}${separator}${token}`;
          textarea.dispatchEvent(new CustomEvent('drupal:mdx-fill', {
            detail: {content},
          }));
        }, true);

        document.addEventListener('copy', (event) => {
          const selection = window.getSelection();
          const anchor = selection?.anchorNode?.parentElement;
          if (!anchor?.closest('.token-tree-dialog')) {
            return;
          }
          const text = selection.toString();
          if (text === '') {
            return;
          }
          event.preventDefault();
          event.clipboardData.setData('text/plain', text);
        });
      });
    },
  };
})(Drupal, once);
