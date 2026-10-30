(function ($, W) {
  'use strict';

  if (!W || !W.ajaxurl) {
    console.error('[uls-notes] missing ajaxurl/localized config');
  }

  var SEL = {
    panel: '.uls-notes-panel',
    list: '.uls-notes-list',
    editor: '.uls-notes-editor',
    textarea: '.uls-notes-text',
    saveBtn: '.uls-notes-save',
    status: '.uls-notes-status'
  };

  function escHtml(s) {
    return $('<div/>').text(String(s == null ? '' : s)).html();
  }

  function ensurePanel() {
    return $(SEL.panel);
  }

  function initNotesEditor() {
    if (!window.tinymce) return;
    if (tinymce.get('uls-notes-text-editor')) return;
    tinymce.init({
      selector: '#uls-notes-text-editor',
      menubar: false,
      height: 180,
      plugins: 'lists link',
      toolbar: 'bold italic underline | bullist numlist | undo redo',
      branding: false,
      statusbar: false,
      setup: function (editor) {
        editor.on('change keyup', function () { editor.save(); });
      }
    });
  }

  function destroyNotesEditor() {
    if (window.tinymce) {
      var ed = tinymce.get('uls-notes-text-editor');
      if (ed) ed.remove();
    }
  }

  function getEditorContent($panel) {
    var ed = window.tinymce ? tinymce.get('uls-notes-text-editor') : null;
    return ed ? ed.getContent() : $panel.find(SEL.textarea).val();
  }

  function setEditorContent($panel, content) {
    var ed = window.tinymce ? tinymce.get('uls-notes-text-editor') : null;
    if (ed) ed.setContent(content || '');
    else $panel.find(SEL.textarea).val(content || '');
  }

  function readNoteName($panel) {
    return ($panel.attr('data-note-name') || '').toString().trim();
  }

  function renderList($panel, notes) {
    var $list = $panel.find(SEL.list);
    if (!Array.isArray(notes) || !notes.length) {
      $list.html('No notes yet.');
      return;
    }

    var out = [];
    notes.forEach(function (n) {
      var isStub = (parseInt(n.is_stub, 10) === 1 || n.access === 'stub');
      var isOwner = parseInt(n.created_by, 10) === parseInt(W.currentUserId, 10);
      var canTogglePrivate = (typeof n.can_toggle_private !== 'undefined')
        ? (parseInt(n.can_toggle_private, 10) === 1)
        : isOwner;

      var visCtl = '';
      if (W && W.canEdit && !isStub) {
        var checked = (parseInt(n.is_member_visible, 10) === 1) ? ' checked' : '';
        visCtl =
          '<label class="uls-note-vis" title="Toggle member visibility">' +
            '<input type="checkbox" class="uls-note-toggle" data-id="' + String(n.id) + '"' + checked + '> Member visible' +
          '</label>';
      }

      var privateCtl = '';
      if (isStub) {
        privateCtl = '<span title="Private – assigned provider only">🔒 Private</span>';
      } else if (canTogglePrivate) {
        var isPrivate = (n.visibility_scope === 'private');
        privateCtl =
          '<label class="uls-note-private" title="Private to assigned provider">' +
            '<input type="checkbox" class="uls-note-private-toggle" data-id="' +
              String(n.id) + '"' + (isPrivate ? ' checked' : '') + '> Private' +
          '</label>';
      }

      var deleteCtl = '';
      if (W.canEdit && isOwner && !isStub) {
        deleteCtl = '<button class="uls-note-delete" title="Delete note">Delete</button>';
      }

      var body = isStub
        ? '<em>Private – assigned provider only</em>'
        : (n.note_text || '');

      out.push(
        '<div class="uls-note" data-id="' + String(n.id) + '" data-created-by="' + String(n.created_by) + '"' +
            (isStub ? ' data-stub="1"' : '') + '>' +
          '<table class="uls-note-table">' +
            '<tr class="uls-note-header">' +
              '<td class="uls-note-author">' + escHtml(n.created_by_name || '') + '</td>' +
              '<td class="uls-note-date">' + escHtml(n.created_at || '') + '</td>' +
              '<td class="uls-note-action">' + (visCtl || '') + '</td>' +
              '<td class="uls-note-action">' + (privateCtl || '') + '</td>' +
              '<td class="uls-note-action">' + (deleteCtl || '') + '</td>' +
            '</tr>' +
            '<tr class="uls-note-body">' +
              '<td colspan="5">' + body + '</td>' +
            '</tr>' +
          '</table>' +
        '</div>'
      );
    });

    $list.html(out.join(''));
  }

  function canMemberAdd($panel) {
    return String($panel.attr('data-allow-add') || '') === '1';
  }

  function setStatus($panel, msg, good) {
    $panel.find(SEL.status).text(String(msg || '')).css('color', good ? '#3b7e23' : '#c00');
  }

  function toggleEditor($panel, enabled) {
    $panel.find(SEL.textarea).prop('disabled', !enabled);
    $panel.find(SEL.saveBtn).prop('disabled', !enabled);
  }

  function initDictation($panel) {
    var SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;
    if (!SpeechRecognition) return;
    var $textarea = $panel.find('.uls-notes-text').first();
    if (!$textarea.length) return;
    var $btn = $panel.find('.uls-notes-dictate');
    if (!$btn.length) {
      $btn = $('<button type="button" class="uls-notes-dictate" title="Dictate note">🎤</button>');
      $textarea.after($btn);
    }
    var recognition = new SpeechRecognition();
    recognition.continuous = true;
    recognition.interimResults = false;
    recognition.lang = 'en-US';
    var listening = false;
    recognition.onresult = function (event) {
      var last = event.results[event.results.length - 1];
      if (!last || !last[0]) return;
      $textarea.val($textarea.val() + last[0].transcript + ' ');
    };
    recognition.onend = function () { listening = false; $btn.text('🎤'); };
    $btn.off('click').on('click', function () {
      if (listening) { recognition.stop(); return; }
      recognition.start();
      listening = true;
      $btn.text('🛑');
    });
  }

  function loadNotes(email) {
    var $panel = ensurePanel();
    destroyNotesEditor();
    initNotesEditor();
    initDictation($panel);
    var noteName = readNoteName($panel);
    var allowAdd = canMemberAdd($panel);

    if (W && W.canEdit) $panel.find(SEL.editor).show();
    else if (allowAdd) $panel.find(SEL.editor).show();
    else $panel.find(SEL.editor).hide();

    setStatus($panel, 'Loading notes…');

    $.post(W.ajaxurl, {
      action: W.getAction,
      nonce: W.nonce,
      email: email,
      note_name: noteName,
      context: String($panel.data('context') || $panel.attr('data-context') || '')
    })
    .done(function (resp) {
      if (!resp || !resp.success || !resp.data) {
        setStatus($panel, 'Failed to load notes', false);
        return;
      }

      var notes = resp.data.notes || [];
      renderList($panel, notes);
      setStatus($panel, '');

      $panel.off('click.uls', SEL.saveBtn).on('click.uls', SEL.saveBtn, function () {
        var txt = String(getEditorContent($panel) || '').trim();
        if (!txt) { setStatus($panel, 'Please enter a note.', false); return; }
        toggleEditor($panel, false);
        setStatus($panel, 'Saving…');
        var editingId = $panel.data('editing-id') || 0;

        $.post(W.ajaxurl, {
          action: W.saveAction,
          nonce: W.nonce,
          email: (String($panel.data('context')) === 'member') ? '' : email,
          note_name: noteName,
          note_text: txt,
          id: editingId,
          context: String($panel.data('context') || $panel.attr('data-context') || '')
        })
        .done(function (resp2) {
          if (!resp2 || !resp2.success || !resp2.data || !resp2.data.note) {
            setEditorContent($panel, '');
            setStatus($panel, 'Your note was saved successfully.', true);
            return;
          }
          var saved = resp2.data.note;
          var eid = $panel.data('editing-id') || 0;
          if (eid) {
            for (var i = 0; i < notes.length; i++) {
              if (parseInt(notes[i].id, 10) === parseInt(eid, 10)) { notes[i] = saved; break; }
            }
          } else {
            notes.unshift(saved);
          }
          renderList($panel, notes);
          setEditorContent($panel, '');
          $panel.removeData('editing-id');
          $panel.find(SEL.saveBtn).text('Save Note');
          setStatus($panel, 'Saved.', true);
        })
        .fail(function () { setStatus($panel, 'Save failed (network).', false); })
        .always(function () { toggleEditor($panel, true); });
      });

      $panel.on('click.uls', '.uls-note-delete', function () {
        var $note = $(this).closest('.uls-note');
        var id = parseInt($note.data('id'), 10) || 0;
        if (!id) return;
        if (!confirm('Delete this note?')) return;
        $.post(W.ajaxurl, { action: 'uls_delete_member_note', nonce: W.nonce, id: id })
          .done(function () { $note.remove(); })
          .fail(function () { alert('Failed to delete note.'); });
      });

      $panel.off('change.uls', '.uls-note-toggle').on('change.uls', '.uls-note-toggle', function () {
        var $cb = $(this);
        var id = parseInt($cb.closest('.uls-note').attr('data-id'), 10) || parseInt($cb.data('id'), 10) || 0;
        var vis = $cb.is(':checked') ? 1 : 0;
        if (!id) return;
        $.post(W.ajaxurl, {
          action: W.toggleVisAction, nonce: W.nonce, id: id, email: email, is_member_visible: vis
        }).fail(function () {
          $cb.prop('checked', !vis);
          setStatus($panel, 'Visibility update failed.', false);
        });
      });

      $panel.off('change.uls', '.uls-note-private-toggle')
        .on('change.uls', '.uls-note-private-toggle', function () {
          var $cb = $(this);
          var $note = $cb.closest('.uls-note');
          var id = parseInt($note.attr('data-id'), 10) || 0;
          if (!id) return;
          $.post(W.ajaxurl, {
            action: W.toggleVisAction,
            nonce: W.nonce,
            id: id,
            email: email,
            note_name: readNoteName($panel),
            visibility_scope: $cb.is(':checked') ? 'private' : 'shared',
            context: String($panel.data('context') || $panel.attr('data-context') || '')
          }).fail(function () {
            $cb.prop('checked', !$cb.is(':checked'));
            setStatus($panel, 'Failed to update note privacy.', false);
          });
        });

      $panel.on('dblclick.uls', '.uls-note-body td', function () {
        var $note = $(this).closest('.uls-note');
        if (!$note.length || $note.data('stub')) return;
        var id = parseInt($note.data('id'), 10);
        var authorId = parseInt($note.data('created-by'), 10);
        if (authorId !== parseInt(W.currentUserId, 10)) return;
        setEditorContent($panel, $(this).html());
        $panel.data('editing-id', id);
        $panel.find(SEL.saveBtn).text('Update Note');
        if (window.tinymce && tinymce.get('uls-notes-text-editor')) {
          tinymce.get('uls-notes-text-editor').focus();
        } else {
          $panel.find(SEL.textarea).focus();
        }
      });
    })
    .fail(function () {
      setStatus($panel, 'Failed to load notes (network).', false);
    });
  }

  document.addEventListener('uls:selected-member', function (e) {
    var email = e && e.detail && e.detail.email ? String(e.detail.email) : '';
    if (email) loadNotes(email);
  });

  jQuery(function ($) {
    $('.uls-notes-panel[data-context="member"]').each(function () {
      var $panel = $(this);
      if ($panel.data('member-save-bound')) return;
      $panel.data('member-save-bound', 1);
      initNotesEditor();
      $panel.on('click.uls', '.uls-notes-save', function () {
        var txt = String(getEditorContent($panel) || '').trim();
        if (!txt) { setStatus($panel, 'Please enter a note.', false); return; }
        toggleEditor($panel, false);
        setStatus($panel, 'Saving…');
        $.post(ULS_NOTES.ajaxurl, {
          action: ULS_NOTES.saveAction,
          nonce: ULS_NOTES.nonce,
          email: W.currentUserEmail,
          note_name: readNoteName($panel),
          note_text: txt
        })
        .done(function (resp) {
          if (!resp || !resp.success) { setStatus($panel, 'Save failed.', false); return; }
          setEditorContent($panel, '');
          setStatus($panel, 'Your note was saved successfully.', true);
        })
        .fail(function () { setStatus($panel, 'Save failed (network).', false); })
        .always(function () { toggleEditor($panel, true); });
      });
    });
  });

})(jQuery, window.ULS_NOTES || {});
