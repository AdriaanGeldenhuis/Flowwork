// /projects/js/modules/guests.js

(function() {
  'use strict';

  const BoardGuests = {
    
    // ===== OPEN MODAL =====
    showModal() {
      console.log('👥 Opening guest modal...');
      
      const modal = document.getElementById('modalGuests');
      if (!modal) {
        console.error('❌ Guest modal not found!');
        return;
      }
      
      // Show modal
      modal.setAttribute('aria-hidden', 'false');
      modal.style.display = 'flex';
      document.body.style.overflow = 'hidden';
      
      console.log('✅ Guest modal opened');
      
      // Load guests immediately
      this.loadGuests();
      
      // Setup close handlers
      this.setupCloseHandlers();
    },
    
    // ===== CLOSE MODAL =====
    close() {
      console.log('👥 Closing guest modal...');
      
      const modal = document.getElementById('modalGuests');
      if (!modal) return;
      
      modal.setAttribute('aria-hidden', 'true');
      modal.style.display = 'none';
      document.body.style.overflow = '';
      
      console.log('✅ Guest modal closed');
    },
    
    // ===== SETUP CLOSE HANDLERS =====
    setupCloseHandlers() {
      const modal = document.getElementById('modalGuests');
      if (!modal) return;
      
      // Close button
      const closeBtn = modal.querySelector('.fw-picker-close');
      if (closeBtn) {
        closeBtn.onclick = () => this.close();
      }
      
      // Backdrop click
      const backdrop = modal.querySelector('.fw-cell-picker-overlay');
      if (backdrop) {
        backdrop.onclick = (e) => {
          if (e.target === backdrop) {
            this.close();
          }
        };
      }
      
      // Escape key
      const escapeHandler = (e) => {
        if (e.key === 'Escape' && modal.getAttribute('aria-hidden') === 'false') {
          this.close();
          document.removeEventListener('keydown', escapeHandler);
        }
      };
      document.addEventListener('keydown', escapeHandler);
    },
    
    // ===== SEND INVITE =====
    async invite() {
      const email = document.getElementById('guestEmail').value.trim();
      const expiry = document.getElementById('guestExpiry').value;
      
      if (!email) {
        alert('⚠️ Please enter an email address');
        return;
      }
      
      if (!email.includes('@')) {
        alert('⚠️ Please enter a valid email address');
        return;
      }
      
      console.log('📧 Sending invite to:', email);
      
      try {
        const res = await fetch('/projects/api/guests.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body: new URLSearchParams({
            action: 'invite_guest',
            board_id: window.BOARD_DATA.boardId,
            email: email,
            expiry_days: expiry
          })
        });
        
        const data = await res.json();
        
        if (data.success) {
          alert(`✅ Invitation sent to ${email}`);
          document.getElementById('guestEmail').value = '';
          this.loadGuests();
        } else {
          alert('❌ ' + (data.error || 'Failed to send invitation'));
        }
      } catch (err) {
        console.error('Network error:', err);
        alert('❌ Network error. Please try again.');
      }
    },
    
    // ===== LOAD GUESTS LIST =====
    async loadGuests() {
      const container = document.getElementById('guestsList');
      if (!container) {
        console.error('❌ Guest list container not found!');
        return;
      }
      
      container.innerHTML = '<div style="text-align:center;padding:40px;color:var(--fw-text-tertiary);">Loading guests...</div>';
      
      console.log('📋 Loading guests for board:', window.BOARD_DATA.boardId);
      
      try {
        const res = await fetch(`/projects/api/guests.php?action=list_guests&board_id=${window.BOARD_DATA.boardId}`);
        const data = await res.json();
        
        console.log('✅ Guests loaded:', data);
        
        if (!data.success || !data.guests || data.guests.length === 0) {
          container.innerHTML = `
            <div style="text-align:center;padding:60px 20px;color:var(--fw-text-tertiary);">
              <svg width="64" height="64" fill="none" stroke="currentColor" stroke-width="1.5" style="opacity:0.3;margin-bottom:16px;">
                <path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/>
                <circle cx="8.5" cy="7" r="4"/>
                <path d="M20 8v6M23 11h-6"/>
              </svg>
              <p style="font-size:16px;font-weight:600;margin:0 0 8px 0;color:var(--fw-text-secondary);">No guests yet</p>
              <p style="font-size:13px;margin:0;">Invite someone using the form above</p>
            </div>
          `;
          return;
        }
        
        this.renderGuestsTable(data.guests, container);
        
      } catch (err) {
        console.error('Failed to load guests:', err);
        container.innerHTML = '<div style="text-align:center;padding:40px;color:#ef4444;">⚠️ Failed to load guests</div>';
      }
    },
    
    // ===== RENDER GUESTS TABLE =====
    renderGuestsTable(guests, container) {
      let html = '<div style="overflow-x:auto;">';
      html += '<table style="width:100%;border-collapse:collapse;">';
      
      // Header
      html += '<thead><tr style="border-bottom:2px solid var(--fw-border);">';
      html += '<th style="padding:12px;text-align:left;font-size:12px;font-weight:700;color:var(--fw-text-secondary);text-transform:uppercase;">Email</th>';
      html += '<th style="padding:12px;text-align:left;font-size:12px;font-weight:700;color:var(--fw-text-secondary);text-transform:uppercase;">Status</th>';
      html += '<th style="padding:12px;text-align:left;font-size:12px;font-weight:700;color:var(--fw-text-secondary);text-transform:uppercase;">Invited</th>';
      html += '<th style="padding:12px;text-align:left;font-size:12px;font-weight:700;color:var(--fw-text-secondary);text-transform:uppercase;">Last Access</th>';
      html += '<th style="padding:12px;text-align:center;font-size:12px;font-weight:700;color:var(--fw-text-secondary);text-transform:uppercase;">Views</th>';
      html += '<th style="padding:12px;text-align:center;font-size:12px;font-weight:700;color:var(--fw-text-secondary);text-transform:uppercase;">Actions</th>';
      html += '</tr></thead>';
      
      // Body
      html += '<tbody>';
      
      guests.forEach(g => {
        const statusClass = g.status === 'active' ? 'active' : 
                           g.status === 'pending' ? 'pending' : 'expired';
        
        const statusColor = g.status === 'active' ? '#10b981' : 
                           g.status === 'pending' ? '#f59e0b' : '#6b7280';
        
        html += '<tr style="border-bottom:1px solid var(--fw-border-subtle);">';
        
        // Email
        html += `<td style="padding:12px;color:var(--fw-text-primary);font-size:14px;">${this.escapeHtml(g.email)}</td>`;
        
        // Status badge
        html += `<td style="padding:12px;">`;
        html += `<span style="display:inline-block;padding:4px 10px;background:${statusColor}20;color:${statusColor};border-radius:4px;font-size:11px;font-weight:600;text-transform:uppercase;">`;
        html += g.status;
        html += `</span>`;
        html += `</td>`;
        
        // Invited date
        const invitedDate = new Date(g.invited_at);
        html += `<td style="padding:12px;font-size:13px;color:var(--fw-text-tertiary);">`;
        html += invitedDate.toLocaleDateString('en-ZA', { year: 'numeric', month: 'short', day: 'numeric' });
        html += `</td>`;
        
        // Last access
        html += `<td style="padding:12px;font-size:13px;color:var(--fw-text-tertiary);">`;
        if (g.last_access_at) {
          const lastAccess = new Date(g.last_access_at);
          html += lastAccess.toLocaleDateString('en-ZA', { year: 'numeric', month: 'short', day: 'numeric' });
        } else {
          html += '—';
        }
        html += `</td>`;
        
        // View count
        html += `<td style="padding:12px;text-align:center;color:var(--fw-text-primary);font-weight:600;">${g.access_count || 0}</td>`;
        
        // Actions
        html += '<td style="padding:12px;"><div style="display:flex;gap:4px;justify-content:center;">';
        
        if (g.status === 'pending') {
          html += `<button onclick="BoardGuests.resend(${g.id})" class="fw-icon-btn" title="Resend Email" style="color:var(--fw-text-primary);">`;
          html += '<svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>';
          html += '</button>';
        }
        
        html += `<button onclick="BoardGuests.revoke(${g.id})" class="fw-icon-btn" style="color:#ef4444;" title="Revoke Access">`;
        html += '<svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>';
        html += '</button>';
        
        html += '</div></td>';
        html += '</tr>';
      });
      
      html += '</tbody></table></div>';
      
      container.innerHTML = html;
    },
    
    // ===== RESEND INVITE =====
    async resend(guestId) {
      if (!confirm('📧 Resend invitation email to this guest?')) return;
      
      console.log('📧 Resending invite to guest:', guestId);
      
      try {
        const res = await fetch('/projects/api/guests.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body: new URLSearchParams({
            action: 'resend_invite',
            board_id: window.BOARD_DATA.boardId,
            guest_id: guestId
          })
        });
        
        const data = await res.json();
        
        if (data.success) {
          alert('✅ Invitation resent successfully');
        } else {
          alert('❌ ' + (data.error || 'Failed to resend invitation'));
        }
      } catch (err) {
        console.error('Network error:', err);
        alert('❌ Network error. Please try again.');
      }
    },
    
    // ===== REVOKE ACCESS =====
    async revoke(guestId) {
      if (!confirm('🗑️ Revoke guest access? This will immediately block their access to the board.')) return;
      
      console.log('🗑️ Revoking guest:', guestId);
      
      try {
        const res = await fetch('/projects/api/guests.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body: new URLSearchParams({
            action: 'revoke_guest',
            board_id: window.BOARD_DATA.boardId,
            guest_id: guestId
          })
        });
        
        const data = await res.json();
        
        if (data.success) {
          alert('✅ Guest access revoked');
          this.loadGuests();
        } else {
          alert('❌ ' + (data.error || 'Failed to revoke access'));
        }
      } catch (err) {
        console.error('Network error:', err);
        alert('❌ Network error. Please try again.');
      }
    },
    
    // ===== HTML ESCAPE =====
    escapeHtml(text) {
      const div = document.createElement('div');
      div.textContent = text;
      return div.innerHTML;
    }
    
  };

  // ===== ATTACH TO WINDOW =====
  window.BoardGuests = BoardGuests;
  
  console.log('✅ BoardGuests module loaded');
  
})();