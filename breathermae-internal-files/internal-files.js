/**
 * Breathermae Internal Files - Frontend JS
 * Handles dynamic table rendering, form interactions, AJAX CRUD, modal, edit mode
 */
(function($) {
    'use strict';

    const BMIF = window.BMIF || {};

    function initList() {
        $('.bmif-list-container').each(function() {
            const $container = $(this);
            const context = $container.data('context');
            const adminTag = $container.data('admin-tag') || '';
            const isAdmin = $container.data('is-admin') == '1';

            loadFiles($container, context, adminTag, isAdmin);
        });
    }

    function loadFiles($container, context, adminTag, isAdmin) {
        const $tbody = $container.find('.bmif-tbody');
        const $loading = $container.find('.bmif-loading');
        const $noResults = $container.find('.bmif-no-results');

        $tbody.empty();
        $loading.show();
        $noResults.hide();

        $.post(BMIF.ajaxurl, {
            action: BMIF.listAction,
            nonce: BMIF.nonce,
            context: context
        }, function(resp) {
            $loading.hide();

            if (!resp.success || !resp.data.files || resp.data.files.length === 0) {
                $noResults.show();
                return;
            }

            resp.data.files.forEach(function(row) {
                const $tr = buildRow(row, isAdmin, adminTag, context);
                $tbody.append($tr);
            });

            // Attach handlers
            attachRowHandlers($container, adminTag, context);
        }).fail(function() {
            $loading.hide();
            $tbody.html('<tr><td colspan="7" style="color:#ef4444;">Error loading files. Please refresh.</td></tr>');
        });
    }

    function buildRow(row, isAdmin, adminTag, context) {
        const shortDesc = $('<div/>').text(row.short_desc || '').html();
        const hasGraphic = row.graphic_url ? `<img src="${row.graphic_url}" class="bmif-graphic" alt="Graphic">` : '<span class="bmif-icon bmif-empty">—</span>';
        
        const internalFileLink = row.internal_file_url || row.internal_file_attachment_url;
        const internalFile = internalFileLink 
            ? `<a href="${internalFileLink}" target="_blank" class="bmif-file-link" data-download-id="${row.id}" data-type="internal_file">${BMIF.getFileIconHTML(row.internal_file_ext, false)}</a>`
            : '<span class="bmif-icon bmif-empty">—</span>';
        
        const internalVideo = row.internal_video_url 
            ? `<a href="${row.internal_video_url}" target="_blank" class="bmif-file-link">${BMIF.getFileIconHTML('', true)}</a>`
            : '<span class="bmif-icon bmif-empty">—</span>';
        
        const sharableFileLink = row.sharable_file_url || row.sharable_file_attachment_url;
        const sharableFile = sharableFileLink 
            ? `<a href="${sharableFileLink}" target="_blank" class="bmif-file-link" data-download-id="${row.id}" data-type="sharable_file">${BMIF.getFileIconHTML(row.sharable_file_ext, false)}</a>`
            : '<span class="bmif-icon bmif-empty">—</span>';
        
        const sharableVideo = row.sharable_video_url 
            ? `<a href="${row.sharable_video_url}" target="_blank" class="bmif-file-link">${BMIF.getFileIconHTML('', true)}</a>`
            : '<span class="bmif-icon bmif-empty">—</span>';

        let actions = '';
        if (isAdmin) {
            actions = `
                <button type="button" class="button button-small bmif-edit-btn" data-id="${row.id}">Edit</button>
                <button type="button" class="button button-small bmif-delete-btn" data-id="${row.id}" style="color:#b91c1c; border-color:#fecaca;">Delete</button>
            `;
        }

        const $tr = $(`
            <tr data-id="${row.id}">
                <td><span class="bmif-desc" data-id="${row.id}" data-long="${escapeHtml(row.long_desc || '')}">${shortDesc}</span></td>
                <td>${hasGraphic}</td>
                <td class="bmif-col-file">${internalFile}</td>
                <td class="bmif-col-video">${internalVideo}</td>
                <td class="bmif-col-file">${sharableFile}</td>
                <td class="bmif-col-video">${sharableVideo}</td>
                ${isAdmin ? `<td class="bmif-actions-col">${actions}</td>` : ''}
            </tr>
        `);

        return $tr;
    }

    // Helper exposed for icon rendering
    window.BMIF.getFileIconHTML = function(ext, isVideo) {
        if (isVideo) {
            return '<span class="bmif-icon bmif-video-icon" title="Watch Video">🎥</span>';
        }
        const icons = {
            'pdf': '📕', 'doc': '📘', 'docx': '📘',
            'xls': '📗', 'xlsx': '📗',
            'ppt': '📙', 'pptx': '📙',
            'jpg': '🖼️', 'jpeg': '🖼️', 'png': '🖼️', 'webp': '🖼️'
        };
        const icon = icons[ext] || '📄';
        return `<span class="bmif-icon bmif-file-icon" data-ext="${ext || ''}" title="${(ext || 'file').toUpperCase()}">${icon}</span>`;
    };

    function escapeHtml(str) {
        return str.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    function attachRowHandlers($container, adminTag, context) {
        // Modal on description click
        $container.on('click', '.bmif-desc', function(e) {
            e.preventDefault();
            const $this = $(this);
            const title = $this.text();
            const long = $this.data('long') || 'No additional details provided.';
            showModal(title, long);
        });

        // Controlled download (optional - uses AJAX for tracking/security)
        $container.on('click', '.bmif-file-link[data-download-id]', function(e) {
            // For now allow direct link (media library). Uncomment below for AJAX download if desired.
            // e.preventDefault();
            // const id = $(this).data('download-id');
            // const type = $(this).data('type');
            // window.open(BMIF.ajaxurl + '?action=' + BMIF.downloadAction + '&nonce=' + BMIF.nonce + '&id=' + id + '&type=' + type, '_blank');
        });

        // Edit button
        $container.on('click', '.bmif-edit-btn', function() {
            const id = $(this).data('id');
            populateFormForEdit(id, adminTag, context, $container);
        });

        // Delete button
        $container.on('click', '.bmif-delete-btn', function() {
            if (!confirm('Delete this entry permanently? Attachments will also be removed.')) return;
            const id = $(this).data('id');
            deleteEntry(id, adminTag, context, $container);
        });
    }

    function showModal(title, longDesc) {
        const $modal = $('#bmif-modal');
        if ($modal.length === 0) {
            // Create if not present (in case multiple lists)
            $('body').append(`
                <div id="bmif-modal" class="bmif-modal">
                    <div class="bmif-modal-content">
                        <span class="bmif-modal-close">&times;</span>
                        <h4 id="bmif-modal-title"></h4>
                        <div id="bmif-modal-body" class="bmif-modal-body"></div>
                    </div>
                </div>
            `);
        }
        $('#bmif-modal-title').text(title);
        $('#bmif-modal-body').html(longDesc.replace(/\n/g, '<br>'));
        $('#bmif-modal').fadeIn(120);

        // Close handlers
        $(document).off('click.bmifmodal').on('click.bmifmodal', '.bmif-modal-close, #bmif-modal', function(ev) {
            if (ev.target.id === 'bmif-modal' || $(ev.target).hasClass('bmif-modal-close')) {
                $('#bmif-modal').fadeOut(120);
            }
        });
    }

    // Form handling
    function initForms() {
        $('.bmif-form-container').each(function() {
            const $formContainer = $(this);
            const $form = $formContainer.find('#bmif-upload-form');
            const context = $formContainer.data('context');
            const adminTag = $formContainer.data('admin-tag') || '';

            // Cancel edit
            $formContainer.find('#bmif-cancel-edit').on('click', function() {
                resetForm($formContainer, $form);
            });

            // Submit (create or update)
            $form.on('submit', function(e) {
                e.preventDefault();
                const editId = $form.find('#bmif-edit-id').val();
                if (editId) {
                    updateEntry($formContainer, $form, editId, adminTag, context);
                } else {
                    uploadNewEntry($formContainer, $form, adminTag, context);
                }
            });

            // Simple client-side preview for graphic
            $form.find('#bmif-graphic').on('change', function() {
                const file = this.files[0];
                const $preview = $formContainer.find('#bmif-graphic-preview');
                if (file && file.type.startsWith('image/')) {
                    const reader = new FileReader();
                    reader.onload = function(ev) {
                        $preview.html(`<img src="${ev.target.result}" style="max-width:80px; max-height:60px; border-radius:4px; border:1px solid #e5e7eb;">`);
                    };
                    reader.readAsDataURL(file);
                } else {
                    $preview.empty();
                }
            });
        });
    }

    function uploadNewEntry($container, $form, adminTag, context) {
        const $btn = $form.find('#bmif-submit-btn');
        const $status = $container.find('.bmif-status');
        $btn.prop('disabled', true).text('Uploading...');
        $status.text('').removeClass('error');

        const formData = new FormData($form[0]);
        formData.append('action', BMIF.uploadAction);
        formData.append('nonce', BMIF.nonce);
        formData.append('admin_tag', adminTag);
        formData.append('context', context);

        $.ajax({
            url: BMIF.ajaxurl,
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            success: function(resp) {
                if (resp.success) {
                    $status.text('✅ Entry created successfully!').css('color', '#16a34a');
                    resetForm($container, $form);
                    // Refresh any list on page
                    $('.bmif-list-container[data-context="' + context + '"]').each(function() {
                        const isAdmin = $(this).data('is-admin') == '1';
                        loadFiles($(this), context, adminTag, isAdmin);
                    });
                    setTimeout(() => $status.text(''), 2500);
                } else {
                    $status.text('❌ ' + (resp.data.message || 'Upload failed')).css('color', '#dc2626');
                }
            },
            error: function(xhr) {
                $status.text('❌ Server error: ' + (xhr.responseJSON?.data?.message || xhr.statusText)).css('color', '#dc2626');
            },
            complete: function() {
                $btn.prop('disabled', false).text('Upload Entry');
            }
        });
    }

    function updateEntry($container, $form, editId, adminTag, context) {
        const $btn = $form.find('#bmif-submit-btn');
        const $status = $container.find('.bmif-status');
        $btn.prop('disabled', true).text('Saving...');
        $status.text('');

        const formData = new FormData($form[0]);
        formData.append('action', BMIF.updateAction);
        formData.append('nonce', BMIF.nonce);
        formData.append('id', editId);
        formData.append('admin_tag', adminTag);
        formData.append('context', context);

        $.ajax({
            url: BMIF.ajaxurl,
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            success: function(resp) {
                if (resp.success) {
                    $status.text('✅ Updated successfully!').css('color', '#16a34a');
                    resetForm($container, $form);
                    // Refresh lists
                    $('.bmif-list-container[data-context="' + context + '"]').each(function() {
                        const isAdmin = $(this).data('is-admin') == '1';
                        loadFiles($(this), context, adminTag, isAdmin);
                    });
                    setTimeout(() => $status.text(''), 2200);
                } else {
                    $status.text('❌ ' + (resp.data.message || 'Update failed')).css('color', '#dc2626');
                }
            },
            error: function() {
                $status.text('❌ Update error').css('color', '#dc2626');
            },
            complete: function() {
                $btn.prop('disabled', false).text('Update Entry');
            }
        });
    }

    function populateFormForEdit(id, adminTag, context, $listContainer) {
        // Find the form on page (assumes one form)
        const $formContainer = $('.bmif-form-container').first();
        if (!$formContainer.length) {
            alert('Upload/Edit form not found on this page. Add the [breathermae_internal_file_form] shortcode.');
            return;
        }
        const $form = $formContainer.find('#bmif-upload-form');

        $.post(BMIF.ajaxurl, {
            action: BMIF.getRowAction,
            nonce: BMIF.nonce,
            id: id,
            context: context,
            admin_tag: adminTag
        }, function(resp) {
            if (!resp.success) {
                alert(resp.data.message || 'Could not load entry');
                return;
            }
            const data = resp.data;

            // Populate
            $form.find('#bmif-edit-id').val(data.id);
            $form.find('#bmif-short-desc').val(data.short_desc || '');
            $form.find('#bmif-long-desc').val(data.long_desc || '');
            $form.find('#bmif-internal-video').val(data.internal_video_url || '');
            $form.find('#bmif-sharable-video').val(data.sharable_video_url || '');

            // Populate the new manual URL fields
            $form.find('input[name="internal_file_url"]').val(data.internal_file_url || '');
            $form.find('input[name="sharable_file_url"]').val(data.sharable_file_url || '');

            // Previews / notes for existing files
            const $graphicPrev = $formContainer.find('#bmif-graphic-preview');
            if (data.graphic_attachment_id) {
                // Use a simple note instead of broken link for now
                $graphicPrev.html(`<small>Current graphic present (ID: ${data.graphic_attachment_id}) — upload new file to replace</small>`);
            } else {
                $graphicPrev.empty();
            }

            // Similar notes for other files (simplified)
            const $intPrev = $formContainer.find('#bmif-internal-file-preview');
            if (data.internal_file_url) {
                $intPrev.html(`<small>Using manual URL — <a href="${data.internal_file_url}" target="_blank">View current</a></small>`);
            } else if (data.internal_file_attachment_id) {
                $intPrev.html('<small>Current internal file present — upload new to replace</small>');
            } else {
                $intPrev.empty();
            }

            const $shaPrev = $formContainer.find('#bmif-sharable-file-preview');
            if (data.sharable_file_url) {
                $shaPrev.html(`<small>Using manual URL — <a href="${data.sharable_file_url}" target="_blank">View current</a></small>`);
            } else if (data.sharable_file_attachment_id) {
                $shaPrev.html('<small>Current sharable file present — upload new to replace</small>');
            } else {
                $shaPrev.empty();
            }

            // Switch to edit mode UI
            $formContainer.addClass('editing');
            $formContainer.find('#bmif-form-title').text('Edit File Entry #' + data.id);
            $form.find('#bmif-submit-btn').text('Save Changes');
            $formContainer.find('#bmif-cancel-edit').show();

            // Scroll to form
            $('html, body').animate({ scrollTop: $formContainer.offset().top - 80 }, 300);
        }).fail(function() {
            alert('Failed to fetch entry details');
        });
    }

    function wp_get_attachment_url_fallback(id) {
        // Fallback if wp not in scope; in practice use direct or AJAX but simple return #
        return '#';
    }

    function deleteEntry(id, adminTag, context, $listContainer) {
        $.post(BMIF.ajaxurl, {
            action: BMIF.deleteAction,
            nonce: BMIF.nonce,
            id: id,
            context: context,
            admin_tag: adminTag
        }, function(resp) {
            if (resp.success) {
                // Refresh list
                loadFiles($listContainer, context, adminTag, $listContainer.data('is-admin') == '1');
            } else {
                alert(resp.data.message || 'Delete failed');
            }
        }).fail(function() {
            alert('Delete request failed');
        });
    }

    function resetForm($container, $form) {
        $form[0].reset();
        $form.find('#bmif-edit-id').val('');
        $container.removeClass('editing');
        $container.find('#bmif-form-title').text('Add New Internal File Entry');
        $container.find('#bmif-submit-btn').text('Upload Entry');
        $container.find('#bmif-cancel-edit').hide();
        $container.find('.bmif-preview').empty();
        $container.find('.bmif-status').text('');
    }

    // Init everything on ready
    $(document).ready(function() {
        initList();
        initForms();

        // Expose refresh for external use if needed
        window.BMIF.refreshLists = function(ctx) {
            $('.bmif-list-container' + (ctx ? '[data-context="' + ctx + '"]' : '')).each(function() {
                const $c = $(this);
                loadFiles($c, $c.data('context'), $c.data('admin-tag'), $c.data('is-admin') == '1');
            });
        };
    });

})(jQuery);
