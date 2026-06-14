(function ($, W) {
  'use strict';
  if (!W || !W.ajaxurl) { console.error('[uls-files] missing ajaxurl'); return; }


  var SEL = {
    target: '.uls-files-target',
    panel: '.uls-files-panel',
    list: '.uls-files-list',
    status: '.uls-files-status',
    uploadInput: '.uls-file-input',
    uploadBtn: '.uls-file-upload',
    toggleVis: '.uls-file-toggle-vis',
    deleteBtn: '.uls-file-delete'
  };

  function h(s){ return $('<div>').text(String(s==null?'':s)).html(); }
  function bytes(n){ n=parseInt(n||0,10); if (n<1024) return n+' B'; if(n<1048576) return (n/1024).toFixed(1)+' KB'; return (n/1048576).toFixed(1)+' MB'; }

  function ensurePanels(){
    
    
    var $targets = $(SEL.target);
    if (!$targets.length){
      var $after = $('.uls-orders-target'); // fall back to orders anchor if present
      if ($after.length){
        $targets = $('<div class="uls-files-target"></div>').insertAfter($after);
      } else {
        $targets = $('<div class="uls-files-target"></div>').appendTo('body');
      }
    }
    $targets.each(function(){
      var $t=$(this);
      if ($t.find(SEL.panel).length) return;
      var note = String($t.data('noteName')||'').trim();
      var all  = $t.data('all') ? 1 : 0;

      var html = [
        '<div class="uls-files-panel" ',
          'data-note-name="', h(note), '" ',
          'data-all="', all, '" ',
          '>',
          '<div class="uls-files-head"><strong>Files</strong>',
            all
              ? ' <span class="u-sub">(all categories)</span>'
              : (note ? ' <span class="u-sub">(' + h(note) + ')</span>' : ''),
          '</div>',
          '<div class="uls-files-body">',
            '<div class="uls-files-controls" style="margin:6px 0; ', (W.canEdit ? '' : 'display:none;'), '">',
              '<label class="uls-doc-type-label" style="margin-right:8px;">',
                'Document Type ',
                '<select class="uls-doc-type-select"></select>',
              '</label>',
              '<input type="file" class="uls-file-input" /> ',
              '<label><input type="checkbox" class="uls-file-visible" /> Visible to member</label> ',
              '<button class="uls-file-upload">Upload</button>',
            '</div>',
            '<div class="uls-files-status" style="min-height:18px;color:#555;"></div>',
            '<div class="uls-files-list"></div>',
          '</div>',
        '</div>'
      ].join('');

      var $panel = $(html).appendTo($t);   // ✅ REQUIRED

        if (Array.isArray(W.documentTypes)) {
            var $select = $panel.find('.uls-doc-type-select');
            W.documentTypes.forEach(function (dt) {
                $('<option>')
                    .val(dt.id)
                    .text(dt.label)
                    .appendTo($select);
            });
        }      

      var ctx = String($t.attr('data-context') || '');
      $panel
        .attr('data-context', ctx)
        .data('context', ctx);

    });
    return $(SEL.panel);
  }

/*   // ✅ New function to show AI summary modal
  function showAISummaryModal(data) {
    const html = `
      <div class="uls-ai-summary-modal">
        <h3>AI Summary (${data.context})</h3>
        <pre>${data.summary_text}</pre>
        <button class="uls-ai-close">Close</button>
      </div>
    `;
    $('body').append(html);
  } */

  
  function setStatus($p, msg, ok){
    $p.find(SEL.status).text(msg||'')[0].style.color = ok ? '#237804' : '#c00';
  }

  function renderList($p, files) {
    var $list = $p.find(SEL.list);

    if (!Array.isArray(files) || !files.length) {
      $list.html('<div class="uls-empty">No files yet.</div>');
      return;
    }
    

    var rows = [];

    files.forEach(function (f) {

      // ✅ determine private state
      var isPrivate = (f.visibility_scope === 'private');
      var isOwner = (parseInt(f.uploaded_by, 10) === parseInt(W.currentUserId, 10));

      

      var currentTypeId = f.ai_document_type_id || '';
      var typeSelect =
          '<select class="uls-ai-prompt-type" ' +
          'data-file-id="' + f.id + '" ' +
          'data-current="' + (currentTypeId || '') + '">' +
          '</select>';


      // ✅ determine AI summary status (if applicable)      
      let aiCell = '';

      if (!f.has_ai_summary) {
        aiCell = '<button class="uls-ai-generate" data-id="' + f.id + '">Generate</button>';
      } else {
        aiCell = '<button class="uls-ai-view" data-id="' + f.id + '">View</button>';
        if (f.ai_summary_stale) {
          aiCell += ' <button class="uls-ai-update" data-id="' + f.id + '">Update</button>';
        }
      }      
      
      
      // ✅ build Private column cell

      var privateCell = '<td class="c6">';
      if (isOwner) {
        privateCell +=
          '<input type="checkbox" class="uls-file-private-toggle" ' +
          (f.visibility_scope === 'private' ? 'checked ' : '') +
          ' title="Private to me">';
      } else {
        privateCell +=
          '<span class="uls-private-disabled" title="Only the uploader can change privacy">—</span>';
      }
      privateCell += '</td>';

      var viewIcon = '';
      if (f.is_viewable) {
        viewIcon =
          '<button type="button" class="uls-file-view" data-id="' + f.id + '" title="View File" ' +
          'style="background:none;border:0;padding:0;cursor:pointer;">' +
          '👁️' +
          '</button> ';
      }

      var downloadIcon =
          '<a href="' + signedFileUrl(f.id) + '" title="Download">⬇️</a> ';

      var fileNameLink =
          '<a href="' + signedFileUrl(f.id) + '">' +
          h(f.original_name || f.file_name) +
          '</a>';

      var fileCell =
        '<td>' +
          '<span class="uls-file-actions">' +
            viewIcon +
            '<a href="' + signedFileUrl(f.id) + '" class="uls-file-download" title="Download">⬇️</a>' +
          '</span>' +
          '<span class="uls-file-name-wrap">' +
            '<a href="' + signedFileUrl(f.id) + '" class="uls-file-name">' +
              h(f.original_name || f.file_name) +
            '</a>' +
          '</span>' +
        '</td>';       

      rows.push([
        '<tr data-id="', f.id, '">',

          // File
          fileCell,


          // Type
          '<td class="c2">', h(f.mime_type || ''), '</td>',

          // Size
          '<td class="c3">', bytes(f.file_size), '</td>',

          // Uploaded By
          '<td class="c4">', h(f.uploaded_by_name || ''), '</td>',

          // Uploaded At
          '<td class="c5">', h(f.uploaded_at || ''), '</td>',

          // ✅ Private
          privateCell,

          // ✅ AI Prompt Type
          '<td>' + typeSelect + '</td>' +

          // ✅ AI Summary
          '<td class="c-ai">',  aiCell,'</td>',

          // Visible to member + Delete (editors only)
          (W.canEdit && isOwner ? [
            '<td class="c7">',
              '<label title="Toggle member visibility">',
                '<input type="checkbox" class="uls-file-toggle-vis" ',
                  (f.is_member_visible ? 'checked' : ''),
                '> ',
              '</label>',
            '</td>',
            '<td class="c8">',
              '<button class="uls-file-delete">Delete</button>',
            '</td>'
          ].join('') : '<td>—</td><td>—</td>'),

        '</tr>'
      ].join(''));
    });

    // ✅ final table render
    $list.html([
      '<table class="uls-files-table">',
        '<thead>',
          '<tr>',
            '<th>File</th>',
            '<th>Type</th>',
            '<th>Size</th>',
            '<th>Uploaded By</th>',
            '<th>Uploaded</th>',
            '<th>Private</th>',
            '<th>Document Type</th>',
            '<th>AI Summary</th>',
            (W.canEdit ? '<th>Member Visible</th><th></th>' : '<th></th><th></th>'),
          '</tr>',
        '</thead>',
        '<tbody>',
          rows.join(''),
        '</tbody>',
      '</table>'
    ].join(''));

    // ✅ Populate AI Prompt Type dropdowns
    if (Array.isArray(W.documentTypes)) {
      $list.find('.uls-ai-prompt-type').each(function () {
        var $sel = $(this);
        var current = $sel.data('current') || '';

        W.documentTypes.forEach(function (dt) {
          $('<option>')
            .val(dt.id)
            .text(dt.label)
            .appendTo($sel);
        });

        // ✅ Default selection
        if (current) {
          $sel.val(String(current));
        } else {
          // default to General
          var general = W.documentTypes.find(d => d.label === 'General Document');
          if (general) {
            $sel.val(String(general.id));
          }
        }
      });
    }


  }
  
  function load($p, email){
    var note = String($p.data('noteName')||'').trim();
    var all  = $p.data('all') ? 1 : 0;
    setStatus($p, 'Loading…');
    $.post(W.ajaxurl, {
      action: all ? W.listAllAction : W.listAction,
      nonce: W.nonce,
      email: email,
      note_name: note,
      all: all,
      context: String($p.data('context') || '')
    }).done(function(resp){
      if (!resp || !resp.success){ setStatus($p, 'Failed to load.', false); return; }
      renderList($p, resp.data.files || []);
      setStatus($p, '');
    }).fail(function(){ setStatus($p, 'Network error.', false); });
  }

  function bind($p, email){
    $p.off('click.ulsf change.ulsf');

    $p.on('click.ulsf', '.uls-file-upload', function(e){
      e.preventDefault();
      var file = $p.find('.uls-file-input')[0].files[0];
      if (!file){ setStatus($p,'Choose a file.', false); return; }
      if (file.size > (W.maxBytes||10485760)){ setStatus($p, 'File too large (max 10MB).', false); return; }
      var ext = (file.name.split('.').pop()||'').toLowerCase();
      if (Array.isArray(W.allowedExts) && W.allowedExts.indexOf(ext) === -1){ setStatus($p, 'Type not allowed.', false); return; }

      var fd = new FormData();
      fd.append('action', W.uploadAction);
      fd.append('nonce', W.nonce);
      fd.append('email', email);
      fd.append('note_name', String($p.data('noteName')||''));
      fd.append('is_member_visible', $p.find('.uls-file-visible').is(':checked') ? 1 : 0);
      fd.append('overwrite', 1); // replace file with same original name within this category
      fd.append('file', file);

      setStatus($p, 'Uploading…');
      $.ajax({ url: W.ajaxurl, method:'POST', data: fd, processData:false, contentType:false })
        .done(function(resp){
          if (!resp || !resp.success){
            setStatus($p, (resp && resp.data && resp.data.message) || 'Upload failed.', false);
            return;
          }
          setStatus($p, 'Uploaded.', true);
          $p.find('.uls-file-input').val('');
          $p.find('.uls-file-visible').prop('checked', false);
          load($p, email);
        })
        .fail(function(){ setStatus($p, 'Upload failed (network).', false); });
    });

    $p.on('change.ulsf', '.uls-file-toggle-vis', function(){
      var $row = $(this).closest('tr');
      var id   = parseInt($row.data('id'),10)||0;
      $.post(W.ajaxurl, {
        action: W.toggleVisAction,
        nonce: W.nonce,
        id: id,
        email: email,
        note_name: String($p.data('noteName')||''),
        is_member_visible: this.checked?1:0
      }).fail(function(){ alert('Failed to update visibility.'); });
    });

    $p.on('change.ulsf', '.uls-file-private-toggle', function () {

      var $row = $(this).closest('tr');
      var id = parseInt($row.data('id'), 10) || 0;

      if (!id) {
        alert('Missing file ID.');
        return;
      }

      $.post(W.ajaxurl, {
        action: W.toggleVisAction, // reuse existing action
        nonce: W.nonce,
        id: id,
        email: email,
        note_name: String($p.data('noteName') || ''),
        visibility_scope: this.checked ? 'private' : 'shared',
        context: String($p.data('context') || '')
      });

    });


    $p.on('click.ulsf', '.uls-file-delete', function(){
      var $row = $(this).closest('tr');
      var id   = parseInt($row.data('id'),10)||0;
      if (!confirm('Delete this file?')) return;
      $.post(W.ajaxurl, {
        action: W.deleteAction,
        nonce: W.nonce,
        id: id,
        email: email,
        note_name: String($p.data('noteName')||'')
      }).done(function(resp){
        if (resp && resp.success){ $row.remove(); }
        else { alert('Delete failed.'); }
      }).fail(function(){ alert('Network error.'); });
    });

    $p.on('click.ulsf', '.uls-ai-view', function (e) {
      e.preventDefault();

      const fileId = $(this).data('id');
      const context = String($p.data('context') || '');

      $.post(W.ajaxurl, {
        action: 'uls_get_ai_file_summary',
        nonce: W.nonce,
        file_id: fileId,
        context: context
      }).done(function (resp) {
        if (!resp || !resp.success) {
          alert(resp?.data?.message || 'Failed to load summary.');
          return;
        }

        showAISummaryModal(resp.data);
      }).fail(function () {
        alert('Network error....');
      });
    });

  $p.on('click.ulsf', '.uls-ai-generate, .uls-ai-update', function (e) {
    e.preventDefault();

    var $btn = $(this);                      // ✅ capture button
    var originalText = $btn.text();          // ✅ remember label
    var fileId = $btn.data('id');
    var context = String($p.data('context') || '');
    var documentTypeId = $btn
      .closest('tr')
      .find('.uls-ai-prompt-type')
      .val();


    // ✅ Immediate feedback
    $btn.prop('disabled', true).text('Generating…');

    $.post(W.ajaxurl, {
      action: 'uls_generate_ai_file_summary',
      nonce: W.nonce,
      file_id: fileId,
      context: context,
      document_type_id: documentTypeId

    })
    .done(function (resp) {
      if (!resp || !resp.success) {
        alert(resp?.data?.message || 'Generation failed.');
        $btn.prop('disabled', false).text(originalText); // rollback
        return;
      }

      // ✅ Reload table → Generate becomes View
      load($p, email);
    })
    .fail(function (xhr) {
      alert(
        xhr?.responseJSON?.data?.message ||
        'Unexpected server error.'
      );
      $btn.prop('disabled', false).text(originalText); // rollback
    });
  }); 

  $p.on('click.ulsf', '.uls-file-view', function (e) {
      e.preventDefault();
      e.stopPropagation();

      const fileId = $(this).data('id');
      if (!fileId) {
          console.warn('[uls-files] Missing file ID for viewer');
          return;
      }

      const url = viewFileUrl(fileId);

      showFileViewerModal({
          title: 'File Viewer',
          body: `<iframe src="${url}" style="width:100%;height:70vh;border:0"></iframe>`,
          downloadUrl: `<a class="button button-primary" href="${url}">`
      });
  });  

  $p.on('change', '.uls-ai-prompt-type', function () {
    var $sel = $(this);
    var selected = String($sel.val());
    var current = String($sel.data('current') || '');
    var $row = $sel.closest('tr');

    var $btn = $row.find('.uls-ai-generate, .uls-ai-view');

    if (current && selected === current) {
      $btn.removeClass('uls-ai-generate')
          .addClass('uls-ai-view')
          .text('View');
    } else {
      $btn.removeClass('uls-ai-view')
          .addClass('uls-ai-generate')
          .text('Generate');
    }
  });

  }

  // Hook into the same selected-member event your stack already emits.
  document.addEventListener('uls:selected-member', function (e) {
    var email = e && e.detail && e.detail.email ? String(e.detail.email) : '';
    if (!email) return;
    var $panels = ensurePanels();
    $panels.each(function(){ var $p=$(this); bind($p, email); load($p, email); });
  });

// --------------------------------------------------
// Global View handler (works for AJAX + shortcodes)
// --------------------------------------------------
jQuery(document).on('click.ulsf', '.uls-file-view', function (e) {
    e.preventDefault();
    e.stopPropagation();

    if (!window.ULS_FILES) {
        console.warn('[uls-files] ULS_FILES not available');
        return;
    }

    const fileId = parseInt(jQuery(this).data('id'), 10);
    if (!fileId) {
        console.warn('[uls-files] Missing file ID');
        return;
    }

    const url =
        window.ULS_FILES.ajaxurl +
        '?action=uls_view_file' +
        '&nonce=' + window.ULS_FILES.nonce +
        '&id=' + fileId;

    // ✅ Reuse your existing viewer modal helper if present
    if (typeof showFileViewerModal === 'function') {
        showFileViewerModal({
            title: 'File Viewer',
            body: `<iframe src="${url}" style="width:100%;height:80vh;border:0"></iframe>`,
            downloadUrl: `<a href="${url}" target="_blank">Download</a>`
        });
        return;
    }

    // ✅ Fallback modal (if helper not loaded)
    jQuery('.uls-file-viewer-modal').remove();

    jQuery('body').append(
        `<div class="uls-file-viewer-modal">
            <div class="uls-ai-modal-overlay"></div>
            <div class="uls-file-viewer">
                <a href="#" class="uls-file-viewer-close">×</a>
                <iframe src="${url}" style="width:100%;height:80vh;border:0"></iframe>
                <p><a href="${url}" target="_blank">Download</a></p>
            </div>
        </div>`
    );
});

    // --------------------------------------------------
    // Member-only upload handler (own-account uploads)
    // --------------------------------------------------
    $(document).on('click.ulsMemberUpload', '.uls-member-upload .uls-file-upload', function (e) {
        e.preventDefault();

        var $wrap = $(this).closest('.uls-member-upload');
        var input = $wrap.find('.uls-file-input')[0];
        var $status = $wrap.find('.uls-files-status');

        if (!input || !input.files || !input.files.length) {
            $status.text('Please choose a file.').css('color', '#c00');
            return;
        }

        var file = input.files[0];

        if (file.size > (W.maxBytes || 10485760)) {
            $status.text('File is too large.').css('color', '#c00');
            return;
        }

        var fd = new FormData();
        fd.append('action', W.uploadAction);
        fd.append('nonce', W.nonce);
        fd.append('email', $wrap.data('email'));
        fd.append('note_name', $wrap.data('noteName') || '');
        fd.append('is_member_visible', 0); // forced private
        fd.append('overwrite', 0);
        fd.append('file', file);

        $status.text('Uploading…').css('color', '#333');

        $.ajax({
            url: W.ajaxurl,
            method: 'POST',
            data: fd,
            processData: false,
            contentType: false
        })
        .done(function (resp) {
            if (!resp || !resp.success) {
                $status.text(
                    (resp && resp.data && resp.data.message) || 'Upload failed.'
                ).css('color', '#c00');
                return;
            }

            $status.text('Uploaded successfully.').css('color', '#237804');
            input.value = '';
        })
        .fail(function () {
            $status.text('Upload failed (network).').css('color', '#c00');
        });
    });

    function showFileViewerModal(opts) {
      const html = `
      <div class="uls-ai-modal uls-file-viewer-modal">
        <div class="uls-ai-modal-overlay"></div>
        <div class="uls-ai-modal-content uls-file-viewer">
          <div class="uls-ai-modal-header">
            <strong>${opts.title}</strong>
            <button type="button" class="uls-file-viewer-close">×</button>
          </div>

          <div class="uls-ai-modal-body">
            ${opts.body}
          </div>


        </div>
      </div>`;

      $('body').append(html);
    }

    $(document).on('keydown', function (e) {
      if (e.key === 'Escape') {
        $('.uls-file-viewer-modal').remove();
      }
    });

    // ✅ Close ONLY the file viewer modal
    $('body').on(
      'click',
      '.uls-file-viewer-close, .uls-file-viewer-modal .uls-ai-modal-overlay',
      function (e) {
        e.preventDefault();
        $('.uls-file-viewer-modal').remove();
      }
    );

    function signedFileUrl(id) {
        return `${W.ajaxurl}?action=${W.downloadAction}&nonce=${W.nonce}&id=${id}`;
    }

    function viewFileUrl(id) {
        return W.ajaxurl +
            '?action=uls_view_file' +
            '&nonce=' + W.nonce +
            '&id=' + id;
    }    

  })(jQuery, window.ULS_FILES || {});

