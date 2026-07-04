/**
 * Comments & Mentions Module
 */

(() => {
  'use strict';

  window.BoardApp = window.BoardApp || {};

  // ===== SHOW COMMENTS FOR ITEM =====
  window.BoardApp.showComments = function(itemId) {
    const item = window.BOARD_DATA.items.find(i => i.id == itemId);
    if (!item) return;
    
    fetch(`/projects/api/comment/list.php?item_id=${itemId}`, {
      headers: { 'X-CSRF-Token': window.BOARD_DATA.csrfToken }
    })
    .then(r => r.json())
    .then(data => {
      if (!data.ok) throw new Error(data.error);
      
      const comments = data.data.comments || [];

      const commentsHtml = comments.map(c => `
        <div class="fw-comment">
          <div class="fw-comment__avatar">${esc((c.first_name || '?').charAt(0) + (c.last_name || '?').charAt(0))}</div>
          <div class="fw-comment__content">
            <div class="fw-comment__header">
              <span class="fw-comment__author">${esc((c.first_name || '') + ' ' + (c.last_name || ''))}</span>
              <span class="fw-comment__time">${esc(c.time_ago)}</span>
            </div>
            <div class="fw-comment__text">${formatCommentText(c.comment)}</div>
          </div>
        </div>
      `).join('');

      const modal = createModal(`💬 Comments - ${esc(item.title)}`, `
        <div class="fw-comments-container">
          <div class="fw-comments-list">
            ${comments.length > 0 ? commentsHtml : '<div class="fw-empty-state"><div class="fw-empty-icon">💬</div><div class="fw-empty-title">No comments yet</div><div class="fw-empty-text">Be the first to comment!</div></div>'}
          </div>
          
          <div class="fw-comment-input">
            <textarea id="commentTextarea" class="fw-textarea" rows="3" placeholder="Add a comment... Use @name to mention someone"></textarea>
            <div style="display: flex; justify-content: space-between; align-items: center; margin-top: 12px;">
              <div class="fw-comment-hint">💡 Tip: Use @name to mention team members</div>
              <button class="fw-btn fw-btn--primary" onclick="BoardApp.addComment(${itemId})">Post Comment</button>
            </div>
          </div>
        </div>
      `);
      
      // Auto-resize textarea + @mention typeahead
      const textarea = document.getElementById('commentTextarea');
      if (textarea) {
        textarea.addEventListener('input', function() {
          this.style.height = 'auto';
          this.style.height = this.scrollHeight + 'px';
        });
        window.BoardApp.attachMentionTypeahead(textarea);
        textarea.focus();
      }
    })
    .catch(err => {
      alert('Failed to load comments: ' + err.message);
    });
  };

  // Escape helper — comments are user-generated content rendered via innerHTML
  function esc(text) {
    if (text === null || text === undefined) return '';
    return String(text)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  // ===== FORMAT COMMENT TEXT (parse mentions) =====
  // Escape FIRST, then wrap @mentions — never feed raw comment text to innerHTML
  function formatCommentText(text) {
    return esc(text).replace(/@(\w+)/g, '<span class="fw-mention">@$1</span>');
  }

  // ===== ADD COMMENT =====
  window.BoardApp.addComment = function(itemId) {
    const textarea = document.getElementById('commentTextarea');
    if (!textarea) return;
    
    const comment = textarea.value.trim();
    if (!comment) {
      alert('Comment cannot be empty');
      return;
    }
    
    textarea.disabled = true;
    
    window.BoardApp.apiCall('/projects/api/comment/add.php', {
      item_id: itemId,
      comment: comment
    })
    .then(data => {
      textarea.value = '';
      textarea.disabled = false;

      // Reload comments + bump the row badge
      window.BoardApp.showComments(itemId);
      window.BoardApp.bumpCommentBadge(itemId);

      if (data.mentioned_users && data.mentioned_users.length > 0) {
        showToast(`Comment posted • ${data.mentioned_users.length} user(s) notified`, 'success');
      } else {
        showToast('Comment posted', 'success');
      }
    })
    .catch(err => {
      textarea.disabled = false;
      alert('Failed to post comment: ' + err.message);
    });
  };

  // ===== HELPER: CREATE MODAL =====
  function createModal(title, content) {
    const modal = document.createElement('div');
    modal.className = 'fw-modal-overlay';
    modal.innerHTML = `
      <div class="fw-modal-content fw-slide-up" style="max-width: 700px; max-height: 85vh;">
        <div class="fw-modal-header">${title}</div>
        <div class="fw-modal-body" style="padding: 0;">
          ${content}
        </div>
      </div>
    `;
    
    modal.addEventListener('click', (e) => {
      if (e.target === modal) modal.remove();
    });
    
    // ✅ FIX: Append to .fw-proj
    const container = document.querySelector('.fw-proj') || document.body;
    container.appendChild(modal);
    return modal;
  }

  // ===== @MENTION TYPEAHEAD =====
  // Typing "@par" pops a picker of board users; picking one inserts the
  // FirstLast token that comment/add.php resolves server-side.
  window.BoardApp.attachMentionTypeahead = function(textarea) {
    if (!textarea || textarea.dataset.mentionsAttached) return;
    textarea.dataset.mentionsAttached = '1';

    let menu = null;

    const closeMenu = () => { menu?.remove(); menu = null; };

    const insertMention = (user) => {
      const caret = textarea.selectionStart;
      const before = textarea.value.slice(0, caret);
      const after = textarea.value.slice(caret);
      const token = '@' + (user.first_name + user.last_name).replace(/\s+/g, '');
      textarea.value = before.replace(/@(\w*)$/, token + ' ') + after;
      closeMenu();
      textarea.focus();
    };

    textarea.addEventListener('input', () => {
      const caret = textarea.selectionStart;
      const before = textarea.value.slice(0, caret);
      const match = before.match(/@(\w*)$/);

      if (!match) { closeMenu(); return; }

      const q = match[1].toLowerCase();
      const users = (window.BOARD_DATA.users || []).filter(u =>
        (u.first_name + u.last_name).toLowerCase().includes(q) || !q
      ).slice(0, 6);

      if (users.length === 0) { closeMenu(); return; }

      closeMenu();
      menu = document.createElement('div');
      menu.className = 'fw-dropdown fw-mention-menu';
      const rect = textarea.getBoundingClientRect();
      menu.style.cssText = `position:fixed;left:${rect.left}px;top:${rect.top - Math.min(users.length * 38 + 8, 200)}px;z-index:10002;max-height:200px;overflow-y:auto;`;

      users.forEach(u => {
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'fw-dropdown-item';
        btn.textContent = `${u.first_name} ${u.last_name}`;
        btn.addEventListener('mousedown', (e) => { e.preventDefault(); insertMention(u); });
        menu.appendChild(btn);
      });

      (document.querySelector('.fw-proj') || document.body).appendChild(menu);
    });

    textarea.addEventListener('blur', () => setTimeout(closeMenu, 150));
    textarea.addEventListener('keydown', (e) => {
      if (e.key === 'Escape' && menu) { e.stopPropagation(); closeMenu(); }
    });
  };

  // ===== ROW COMMENT BADGE =====
  // Increment (or set) the 💬 count bubble on the item's table row
  window.BoardApp.bumpCommentBadge = function(itemId, absoluteCount) {
    const counts = window.BOARD_DATA.commentCounts = window.BOARD_DATA.commentCounts || {};
    counts[itemId] = absoluteCount !== undefined ? absoluteCount : (parseInt(counts[itemId], 10) || 0) + 1;

    const btn = document.querySelector(`.fw-item-comments-btn[data-item-id="${itemId}"]`);
    if (btn) {
      btn.style.display = counts[itemId] > 0 ? '' : 'none';
      const countEl = btn.querySelector('.fw-item-comments-count');
      if (countEl) countEl.textContent = counts[itemId];
      btn.setAttribute('aria-label', `Comments (${counts[itemId]})`);
    }
  };

  // ===== HELPER: TOAST (delegates to the canonical toast in ui.js) =====
  function showToast(message, type = 'info') {
    if (typeof window.BoardApp.showToast === 'function') {
      window.BoardApp.showToast(message, type);
    }
  }

  console.log('✅ Comments module loaded');

})();