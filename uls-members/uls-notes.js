(function ($, W) {
  'use strict';

  // Defensive guards for the localized object
  if (!W || !W.ajaxurl) {
    console.error('[uls-notes] missing ajaxurl/localized config');
  }

  // CSS selectors used by the panel
  var SEL = {
    panel: '.uls-notes-panel',
    list: '.uls-notes-list',
    editor: '.uls-notes-editor',
    textarea: '.uls-notes-text',
    saveBtn: '.uls-notes-save',
    status: '.uls-notes-status'
  };

  // --- small helpers ---
  function escHtml(s) {
    return $('<div/>').text(String(s == null ? '' : s)).html();
  }
  function nl2br(s) {
    return String(s == null ? '' : s).replace(/\n/g, '<br>');
  }

  function ensurePanel() {
    return $(SEL.panel);
  }

  function initNotesEditor() {
    if (!window.tinymce) return;

    // Avoid double-init
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
        editor.on('change keyup', function () {
          editor.save(); // keeps <textarea> in sync
        });
      }
    });
  }

  function destroyNotesEditor() {
    if (window.tinymce) {
      var ed = tinymce.get('uls-notes-text-editor');
      if (ed) {
        ed.remove();
      }
    }
  }

  function getEditorContent($panel) {
    var ed = window.tinymce
      ? tinymce.get('uls-notes-text-editor')
      : null;
    return ed ? ed.getContent() : $panel.find(SEL.textarea).val();
  }

  function setEditorContent($panel, content) {
    var ed = window.tinymce
      ? tinymce.get('uls-notes-text-editor')
      : null;

    if (ed) {
      ed.setContent(content || '');
    } else {
      $panel.find(SEL.textarea).val(content || '');
    }
  }

  // The note "category" (note_name) is read from a data attribute of the panel
  function readNoteName($panel) {
    var name = ($panel.attr('data-note-name') || '').toString().trim();
    return name;
  }

  // Render the list of notes with an (optional) "Member visible" checkbox
  function renderList($panel, notes) {
    var $list = $panel.find(SEL.list);

    if (!Array.isArray(notes) || !notes.length) {
      $list.html('No notes yet.');
      return;
    }

    var out = [];

    notes.forEach(function (n) {

      // -------- meta text --------
      var metaBits = [];
      if (n.created_by_name || n.created_at) {
        metaBits.push(escHtml(n.created_by_name || ''));
        metaBits.push(escHtml(n.created_at || ''));
      }
      if (n.updated_by_name || n.updated_at) {
        metaBits.push(
          'updated by ' +
          escHtml(n.updated_by_name || '') +
          ' ' +
          escHtml(n.updated_at || '')
        );
      }

      // -------- member visibility toggle (existing) --------
      var visCtl = '';
      if (W && W.canEdit) {
        var checked = (parseInt(n.is_member_visible, 10) === 1) ? ' checked' : '';
        visCtl =
          '<label class="uls-note-vis" title="Toggle member visibility">' +
            '<input type="checkbox" class="uls-note-toggle" data-id="' +
              String(n.id) + '"' + checked + '> Member visible' +
          '</label>';
      }

      // -------- provider privacy toggle (NEW, owner-only) --------
      var privateCtl = '';
      var isOwner =
        parseInt(n.created_by, 10) === parseInt(W.currentUserId, 10);

      if (W && W.canEdit && isOwner) {
        var isPrivate = (n.visibility_scope === 'private');
        privateCtl =
          '<label class="uls-note-private" title="Private to me">' +
            '<input type="checkbox" class="uls-note-private-toggle" data-id="' +
              String(n.id) + '"' + (isPrivate ? ' checked' : '') + '> Private' +
          '</label>';
      }

      var deleteCtl = '';
      if (W.canEdit && isOwner) {
        deleteCtl =
          '<button class="uls-note-delete" title="Delete note">Delete</button>';
      }

      // -------- render note --------
      out.push(
        '<div class="uls-note" ' +
            'data-id="' + String(n.id) + '" ' +
            'data-created-by="' + String(n.created_by) + '"' +
            '>' +

          '<table class="uls-note-table">' +

            // --- Row 1: meta / controls ---
            '<tr class="uls-note-header">' +
              '<td class="uls-note-author">' + escHtml(n.created_by_name || '') + '</td>' +
              '<td class="uls-note-date">' + escHtml(n.created_at || '') + '</td>' +

              '<td class="uls-note-action">' +
                (visCtl || '') +
              '</td>' +

              '<td class="uls-note-action">' +
                (privateCtl || '') +
              '</td>' +

              '<td class="uls-note-action">' +
                (deleteCtl || '') +
              '</td>' +
            '</tr>' +

            // --- Row 2: content ---
            '<tr class="uls-note-body">' +
              '<td colspan="5">' +
                n.note_text +
              '</td>' +
            '</tr>' +

          '</table>' +
        '</div>'
      );
    });

    $list.html(out.join(''));
  }

  // Read member add-mode flag from panel attribute (default: false)
  function canMemberAdd($panel) {
      return String($panel.attr('data-allow-add') || '') === '1';
  }

  function setStatus($panel, msg, good) {
    $panel.find(SEL.status)
      .text(String(msg || ''))
      .css('color', good ? '#3b7e23' : '#c00');
  }

  function toggleEditor($panel, enabled) {
    $panel.find(SEL.textarea).prop('disabled', !enabled);
    $panel.find(SEL.saveBtn).prop('disabled', !enabled);
  }

  function initDictation($panel) {
    const SpeechRecognition =
      window.SpeechRecognition || window.webkitSpeechRecognition;

    if (!SpeechRecognition) return;

    const $textarea = $panel.find('.uls-notes-text').first();
    if (!$textarea.length) return;

    // ✅ Ensure mic button exists (THIS is where it belongs)
    let $btn = $panel.find('.uls-notes-dictate');
    if (!$btn.length) {
      $btn = $('<button type="button" class="uls-notes-dictate" title="Dictate note">🎤</button>');
      $textarea.after($btn);
    }

    const recognition = new SpeechRecognition();
    recognition.continuous = true;
    recognition.interimResults = false;
    recognition.lang = 'en-US';

    let listening = false;

    recognition.onresult = function (event) {
      const last = event.results[event.results.length - 1];
      if (!last || !last[0]) return;
      $textarea.val($textarea.val() + last[0].transcript + ' ');
    };

    recognition.onend = function () {
      listening = false;
      $btn.text('🎤');
    };

    $btn.off('click').on('click', function () {
      if (listening) {
        recognition.stop();
        return;
      }
      recognition.start();
      listening = true;
      $btn.text('🛑');
    });
  }
  // Main loader: fetch notes for a given email + current note_name category
  function loadNotes(email) {
    var $panel = ensurePanel();
    destroyNotesEditor();  // clean slate (member switch safe)
    initNotesEditor();
    initDictation($panel);
    var noteName = readNoteName($panel);

    // Show/hide editor based on permission (page gating is handled via WPF; this is just UI)
    // ✅ New member add-mode allows editor to show even without W.canEdit, but note that saving will still fail without proper server-side permissions (handled in your PHP callback)
    var allowAdd = canMemberAdd($panel);

    // Provider behavior unchanged
    if (W && W.canEdit) {
        $panel.find(SEL.editor).show();
    }
    // Member add-mode (not functional yet)
    else if (allowAdd) {
        $panel.find(SEL.editor).show();
    }
    // Default (current behavior)
    else {
        $panel.find(SEL.editor).hide();
    }

    setStatus($panel, 'Loading notes…');

    $.post(W.ajaxurl, {
      action: W.getAction,
      nonce: W.nonce,
      email: email,
      note_name: noteName
    })
      .done(function (resp) {
        if (!resp || !resp.success || !resp.data) {
          setStatus($panel, 'Failed to load notes', false);
          return;
        }

        var notes = resp.data.notes || [];
        renderList($panel, notes);
        setStatus($panel, '');

        // SAVE
        $panel.off('click.uls', SEL.saveBtn).on('click.uls', SEL.saveBtn, function () {
          var txt = String(getEditorContent($panel) || '').trim();
          if (!txt) { setStatus($panel, 'Please enter a note.', false); return; }

          toggleEditor($panel, false);
          setStatus($panel, 'Saving…');

          var editingId = $panel.data('editing-id') || 0;

          $.post(W.ajaxurl, {
            action: W.saveAction,
            nonce: W.nonce,
            email: (String($panel.data('context')) === 'member')
              ? ''
              : email,
            note_name: noteName,
            note_text: txt,
            id: editingId
            // If you want to default visibility on create, add:
            // is_member_visible: 0 or 1
          })
          .done(function (resp2) {

            if (!resp2 || !resp2.success || !resp2.data || !resp2.data.note) {
              setEditorContent($panel, '');
              setStatus($panel, 'Your note was saved successfully.', true);
              return;
            }

            var saved = resp2.data.note;                 // ✅ FIX
            var editingId = $panel.data('editing-id') || 0;

            if (editingId) {
              for (var i = 0; i < notes.length; i++) {
                if (parseInt(notes[i].id, 10) === parseInt(editingId, 10)) {
                  notes[i] = saved;
                  break;
                }
              }
            } else {
              notes.unshift(saved);
            }

            renderList($panel, notes);

            // ✅ Clear editor content (TinyMCE-safe)
            setEditorContent($panel, '');

            // ✅ Exit edit mode
            $panel.removeData('editing-id');

            // ✅ Reset button label back to default
            $panel.find(SEL.saveBtn).text('Save Note');

            setStatus($panel, 'Saved.', true);
          })

            .fail(function () {
              setStatus($panel, 'Save failed (network).', false);
            })
            .always(function () {
              toggleEditor($panel, true);
            });
        });

        $panel.on('click.uls', '.uls-note-delete', function () {
          var $note = $(this).closest('.uls-note');
          var id = parseInt($note.data('id'), 10) || 0;
          if (!id) return;

          if (!confirm('Delete this note?')) return;

          $.post(W.ajaxurl, {
            action: 'uls_delete_member_note',
            nonce: W.nonce,
            id: id
          }).done(function () {
            $note.remove();
          }).fail(function () {
            alert('Failed to delete note.');
          });
        });        

        // VISIBILITY TOGGLE (delegate)
        $panel.off('change.uls', '.uls-note-toggle').on('change.uls', '.uls-note-toggle', function () {
          var $cb = $(this);
          var id = parseInt($cb.closest('.uls-note').attr('data-id'), 10) || parseInt($cb.data('id'), 10) || 0;
          var vis = $cb.is(':checked') ? 1 : 0;
          if (!id) return;

          $.post(W.ajaxurl, {
            action: W.toggleVisAction,
            nonce: W.nonce,
            id: id,
            email: email,
            is_member_visible: vis
          })
            .fail(function () {
              // Revert UI on failure
              $cb.prop('checked', !vis);
              setStatus($panel, 'Visibility update failed.', false);
            });
        });

        // PROVIDER PRIVATE TOGGLE (author-only)
        $panel.off('change.uls', '.uls-note-private-toggle')
          .on('change.uls', '.uls-note-private-toggle', function () {

            var $cb = $(this);
            var $note = $cb.closest('.uls-note');
            var id = parseInt($note.attr('data-id'), 10) || 0;

            if (!id) return;

            $.post(W.ajaxurl, {
              action: W.toggleVisAction,          // ✅ reuse existing action
              nonce: W.nonce,
              id: id,
              email: email,
              note_name: readNoteName($panel),
              visibility_scope: $cb.is(':checked') ? 'private' : 'shared',
              context: String($panel.data('context') || '')
            })
            .fail(function () {
              // revert checkbox on failure
              $cb.prop('checked', !$cb.is(':checked'));
              setStatus($panel, 'Failed to update note privacy.', false);
            });

        });        
        // EDIT ON DOUBLE-CLICK (author only)
        $panel.on('dblclick.uls', '.uls-note-body td', function () {
          var $note = $(this).closest('.uls-note');
          if (!$note.length) return;

          var id = parseInt($note.data('id'), 10);
          var authorId = parseInt($note.data('created-by'), 10);
          var currentUserId = parseInt(W.currentUserId, 10);

          // ✅ Only author may enter edit mode
          if (authorId !== currentUserId) {
            return;
          }

          // Pull plain text from DOM (HTML already sanitized on render)
          var html = $(this).html();

          // ✅ Load text into editor (TinyMCE OR textarea)
          setEditorContent($panel, html);

          // Track editing state
          $panel.data('editing-id', id);

          // Optional but recommended UX polish
          $panel.find(SEL.saveBtn).text('Update Note');

          // Focus editor
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

  // Listen for member selection broadcast by your uls-members.js
  document.addEventListener('uls:selected-member', function (e) {
    var email = e && e.detail && e.detail.email ? String(e.detail.email) : '';
    if (email) loadNotes(email);
  });


  // ✅ Bind SAVE for member add-only panels (no list loading)
  jQuery(function ($) {
    $('.uls-notes-panel[data-context="member"]').each(function () {
      var $panel = $(this);
      

      // Prevent double binding
      if ($panel.data('member-save-bound')) return;
      $panel.data('member-save-bound', 1);

      initNotesEditor();

      $panel.on('click.uls', '.uls-notes-save', function () {
        var txt = String(getEditorContent($panel) || '').trim();
        if (!txt) {
          setStatus($panel, 'Please enter a note.', false);
          return;
        }

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
          if (!resp || !resp.success) {
            setStatus($panel, 'Save failed.', false);
            return;
          }

          setEditorContent($panel, '');
          setStatus($panel, 'Your note was saved successfully.', true);
        })
        .fail(function () {
          setStatus($panel, 'Save failed (network).', false);
        })
        .always(function () {
          toggleEditor($panel, true);
        });
      });
    });
  });




  })(jQuery, window.ULS_NOTES || {});

