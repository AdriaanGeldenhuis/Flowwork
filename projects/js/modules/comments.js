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
          <div class="fw-comment__avatar">${c.first_name.charAt(0)}${c.last_name.charAt(0)}</div>
          <div class="fw-comment__content">
            <div class="fw-comment__header">
              <span class="fw-comment__author">${c.first_name} ${c.last_name}</span>
              <span class="fw-comment__time">${c.time_ago}</span>
            </div>
            <div class="fw-comment__text">${formatCommentText(c.comment)}</div>
          </div>
        </div>
      `).join('');
      
      const modal = createModal(`💬 Comments - ${item.title}`, `
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
      
      // Auto-resize textarea
      const textarea = document.getElementById('commentTextarea');
      if (textarea) {
        textarea.addEventListener('input', function() {
          this.style.height = 'auto';
          this.style.height = this.scrollHeight + 'px';
        });
        textarea.focus();
      }
    })
    .catch(err => {
      alert('Failed to load comments: ' + err.message);
    });
  };

  // ===== FORMAT COMMENT TEXT (parse mentions) =====
  function formatCommentText(text) {
    // Convert @mentions to styled spans
    return text.replace(/@(\w+)/g, '<span class="fw-mention">@$1</span>');
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
      
      // Reload comments
      window.BoardApp.showComments(itemId);
      
      if (data.mentioned_users && data.mentioned_users.length > 0) {
        showToast(`✅ Comment posted • ${data.mentioned_users.length} user(s) mentioned`, 'success');
      } else {
        showToast('✅ Comment posted', 'success');
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

  // ===== HELPER: TOAST =====
  function showToast(message, type = 'info') {
    const toast = document.createElement('div');
    toast.className = `fw-toast fw-toast--${type}`;
    toast.style.cssText = `
      position: fixed;
      top: 80px;
      right: 20px;
      padding: 12px 20px;
      background: var(--modal-bg);
      border: 1px solid var(--modal-border);
      border-radius: 8px;
      color: var(--modal-text);
      font-size: 14px;
      z-index: 10000;
      backdrop-filter: blur(10px);
    `;
    toast.textContent = message;
    const container = document.querySelector('.fw-proj') || document.body;
    container.appendChild(toast);
    setTimeout(() => toast.remove(), 3000);
  }

  console.log('✅ Comments module loaded');

})();