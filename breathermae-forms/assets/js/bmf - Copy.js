
jQuery(function ($) {
  // =========================================================
  // AUTOSAVE — on any radio change
  // =========================================================
  $(document).on('change', '.bmf-q-choices input[type=radio]', function () {
    var $el = $(this);
    var payload = {
      action: 'bmf_save_answer',
      _ajax_nonce: bmfAjax.nonce,
      response_id: $el.data('response-id'),
      question_id: $el.data('question-id'),
      value: $el.val()
    };
    $.post(bmfAjax.url, payload);
  });

  // =========================================================
  // HELPERS
  // =========================================================
  // Validate only the questions within the provided section panel.
  // Returns an array of jQuery elements for missing questions.
  function bmfValidateRequiredSection($panel) {
    var missing = [];
    // Clear prior markers
    $panel.find('.bmf-q').removeClass('bmf-missing');
    $panel.find('.bmf-q-error').hide();

    $panel.find('.bmf-q').each(function () {
      var $q = $(this);
      var isReq = String($q.data('required')) === '1';
      if (!isReq) return;
      var anyChecked = $q.find('input[type=radio]:checked').length > 0;
      if (!anyChecked) {
        missing.push($q);
      }
    });

    // Mark missing
    missing.forEach(function ($q) {
      $q.addClass('bmf-missing');
      $q.find('.bmf-q-error').show();
    });
    return missing;
  }

  // Show a given panel (by index) and update progress
  // Progress shows COMPLETED sections (before the current one),
  // so: idx=0→0%, idx=1→33%, idx=2→66% (for 3 sections)
  function bmfShowPanel($form, idx) {
    var $panels = $form.find('.bmf-section-panel');
    $panels.removeClass('active').hide();
    var $current = $panels.eq(idx).addClass('active').show();

    // Progress elements
    var total = Number($form.data('total-sections') || $panels.length || 1);
    var $progText = $form.find('.bmf-progress-current');
    var $progBar = $form.find('.bmf-progress-bar');
    var $progFill = $form.find('.bmf-progress-fill');
    var $progPct = $form.find('.bmf-progress-percent');

    if ($progText.length) {
      $progText.text(idx + 1);
    }
    var completed = Math.max(0, idx);
    var pct = Math.round((completed / total) * 100);
    if ($progBar.length && $progFill.length) {
      $progBar.attr('aria-valuenow', pct);
      $progFill.css('width', pct + '%');
      if ($progPct.length) $progPct.text(pct + '%');
    }

    // Submit visibility: only last panel (if multi-section)
    var $submitWrap = $form.find('.bmf-submit-wrap');
    if ($panels.length > 1) {
      if (idx === $panels.length - 1) {
        $submitWrap.show();
      } else {
        $submitWrap.hide();
      }
    } else {
      $submitWrap.show(); // single-section view
    }
  }

  // Inject missing highlight CSS once (in case theme CSS is cached)
  (function bmfInjectMissingStyle() {
    if (document.getElementById('bmf-missing-style')) return;
    var css = '.bmf-q.bmf-missing{outline:2px solid #b00020; outline-offset:4px; border-radius:6px; padding:4px;}';
    var style = document.createElement('style');
    style.id = 'bmf-missing-style';
    style.innerHTML = css;
    document.head.appendChild(style);
  })();

  // =========================================================
  // INIT — set up paging if multiple section panels exist
  // =========================================================
  $('.bmf-form').each(function () {
    var $form = $(this);
    var $panels = $form.find('.bmf-section-panel');
    if ($panels.length <= 1) {
      // Single-section view – no paging, just show Submit
      $form.find('.bmf-submit-wrap').show();
      return;
    }
    // Multi-section: start at first panel
    $form.data('bmfCurrent', 0);
    bmfShowPanel($form, 0);
  });

  // =========================================================
  // PREVIOUS / NEXT
  // =========================================================
  $(document).on('click', '.bmf-prev-section', function () {
    var $form = $(this).closest('.bmf-form');
    var cur = Number($form.data('bmfCurrent') || 0);
    if (cur > 0) {
      $form.data('bmfCurrent', cur - 1);
      bmfShowPanel($form, cur - 1);
      $form.find('.bmf-submit-msg').text('').css('color', '#555');
      // Scroll to top of form for a clean UX
      var topForm = $form.offset().top - 40;
      window.scrollTo({ top: topForm, behavior: 'smooth' });
    }
  });

  $(document).on('click', '.bmf-next-section', function () {
    var $form = $(this).closest('.bmf-form');
    var cur = Number($form.data('bmfCurrent') || 0);
    var $panels = $form.find('.bmf-section-panel');
    var $currentPanel = $panels.eq(cur);

    // Validate required within current panel only
    var missing = bmfValidateRequiredSection($currentPanel);
    if (missing.length > 0) {
      // Scroll to first missing
      var top = missing[0].offset().top - 80;
      window.scrollTo({ top: top, behavior: 'smooth' });
      $form.find('.bmf-submit-msg')
        .css('color', '#b00020')
        .text('Please complete required questions in this section.');
      return; // Block advancing
    }

    // Advance
    if (cur < $panels.length - 1) {
      $form.data('bmfCurrent', cur + 1);
      bmfShowPanel($form, cur + 1);
      $form.find('.bmf-submit-msg').text('').css('color', '#555');
      var topForm = $form.offset().top - 40;
      window.scrollTo({ top: topForm, behavior: 'smooth' });
    }
  });

  // =========================================================
  // SUBMIT — compute section scores & mark submitted
  // - Keeps progress where it is (NO 100% jump on last panel)
  // - Validates the current (last) panel's required items for visual cues
  // - NEW: redirect after success (data-redirect > server fallback > '/')
  // =========================================================
  $(document).on('click', '.bmf-submit', function () {
    var $btn  = $(this),
        $form = $btn.closest('.bmf-form'),
        $msg  = $btn.siblings('.bmf-submit-msg');

    // Validate the currently visible (last) panel to show highlights if needed
    var cur = Number($form.data('bmfCurrent') || 0);
    var $panels = $form.find('.bmf-section-panel');
    var $currentPanel = ($panels.length > 0) ? $panels.eq(cur) : $form;
    var missing = bmfValidateRequiredSection($currentPanel);
/*     if (missing.length > 0) {
      var top = missing[0].offset().top - 80;
      window.scrollTo({ top: top, behavior: 'smooth' });
      $msg.css('color', '#b00020').text('Please answer all Required questions marked with * before submitting.');
      return; // Block submit if current section incomplete
    } */

    // Proceed to server submit
    $btn.prop('disabled', true);
    $msg.css('color', '#555').text('Saving your responses...');

    $.post(bmfAjax.url, {
      action: 'bmf_submit',
      _ajax_nonce: bmfAjax.nonce,
      response_id: $btn.data('response-id'),
      form_id: $btn.data('form-id')
    })
    .done(function (res) {
      if (res && res.success) {
        $msg.css('color', '#006400').text('Your answers have been submitted. Thank you!');

        var fromDataAttr = $form.data('redirect');
        var fromServer   = res.data && res.data.redirect;
        var target       = fromDataAttr || fromServer || '/';

        setTimeout(function () {
          window.location.href = target;
        }, 800);
      } else {
        $msg.css('color', '#b00020').text(
          (res && res.data && res.data.message) ? res.data.message : 'Error'
        );
      }
    })
    .fail(function (xhr) {
      console.log('Submit failed:', xhr);

      let message = 'Submission failed. Please try again.';
      if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
        message = xhr.responseJSON.data.message;
      }

      $msg.css('color', '#b00020').text(message);
    })
    .always(function () {
      $btn.prop('disabled', false);
    });
  });
});
