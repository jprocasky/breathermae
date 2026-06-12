
jQuery(function ($) {

  // =========================================================
  // RUNTIME STATE (form-level, non-persistent)
  // =========================================================
  var bmfState = {
    answers: {},   // question_id -> value
    branches: {},  // section_order -> matched branch rule
    flags: {
      is_logged_in: false,
      email_exists: null
    }
  };

  var isTouch = ('ontouchstart' in window) || (navigator.maxTouchPoints > 0);  
  window.bmfState = bmfState;

    // =========================================================
    // RADIO CHANGE — autosave + branch decision (NO navigation)
    // =========================================================
    $(document).on('change', '.bmf-q-choices input[type=radio]', function () {
      var $input = $(this);
      var value  = $input.val();
      var $form  = $input.closest('.bmf-form');
      var $panel = $input.closest('.bmf-section-panel');

      // -------------------------------------------------------
      // 1) AUTOSAVE (unchanged)
      // -------------------------------------------------------



      $.post(bmfAjax.url, {
        action: 'bmf_save_answer',
        _ajax_nonce: bmfAjax.nonce,
        response_id: $input.data('response-id'),
        question_id: $input.data('question-id'),
        value: value
      });

      // -------------------------------------------------------
      // 2) RECORD ANSWER (optional but useful)
      // -------------------------------------------------------
      bmfState.answers[$input.data('question-id')] = value;

      // -------------------------------------------------------
      // 3) RESET previous branch state (NO UI navigation)
      // -------------------------------------------------------
      var sectionOrder = $panel.attr('data-section-order');

      // Remove prior branch mapping for this decision section
      delete bmfState.branches[sectionOrder];

      // Clear branch exclusions WITHOUT revealing panels
      $form.find('.bmf-section-panel.bmf-branch-hidden')
        .removeClass('bmf-branch-hidden');

      // Ensure only the CURRENT panel is visible (pager owns visibility)
      var curIndex = Number($form.data('bmfCurrent') || 0);
      var $panels = $form.find('.bmf-section-panel');
      $panels.hide();
      $panels.eq(curIndex).show().addClass('active');


      // Clear any previous branch state for this section
      var sectionOrder = $panel.attr('data-section-order');
      delete bmfState.branches[sectionOrder];

      // -------------------------------------------------------
      // 4) RESOLVE BRANCH (DO NOT navigate)
      // -------------------------------------------------------
      var metaRaw = $panel.attr('data-section-meta');
      if (!metaRaw) return;

      var meta;
      try {
        meta = JSON.parse(metaRaw);
      } catch (e) {
        console.warn('Invalid branching JSON', e);
        return;
      }

      if (!meta.branching) return;

      var rule = bmfResolveBranch(meta.branching, value);
      if (rule) {
        bmfState.branches[sectionOrder] = rule;
      }
    });


  // =========================================================
  // AUTOSAVE — text / email / password inputs
  // =========================================================
  $(document).on('blur', '.bmf-q-choices input[type=text], .bmf-q-choices input[type=email], .bmf-q-choices input[type=password]', function () {
    var $el = $(this);
    var payload = {
      action: 'bmf_save_answer',
      _ajax_nonce: bmfAjax.nonce,
      response_id: $el.data('response-id'),
      question_id: $el.data('question-id'),
      value: $el.val()
    };
    $.post(bmfAjax.url, payload);

    // Track in runtime state
    bmfState.answers[$el.data('question-id')] = $el.val();
  });

  // =========================================================
  // AUTOSAVE — rank (hidden input change)
  // =========================================================
  $(document).on('change', '.bmf-rank-output', function () {

console.log('RANK AUTOSAVE TRIGGERED');

      var $el = $(this);

      var payload = {
          action: 'bmf_save_answer',
          _ajax_nonce: bmfAjax.nonce,
          response_id: $el.data('response-id'),
          question_id: $el.data('question-id'),
          value: $el.val()
      };

      $.post(bmfAjax.url, payload);

      // Track in runtime state
      bmfState.answers[$el.data('question-id')] = $el.val();
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

    var hasAnswer = false;

    // ✅ Radio
    if ($q.find('input[type=radio]').length) {
      hasAnswer = $q.find('input[type=radio]:checked').length > 0;

    // ✅ Checkbox (future-proof, even if not required now)
    } else if ($q.find('input[type=checkbox]').length) {
      hasAnswer = $q.find('input[type=checkbox]:checked').length > 0;

    // ✅ Select (NEW)
    } else if ($q.find('select').length) {
      hasAnswer = String($q.find('select').val()).trim() !== '';

    }
    // ✅ RANK (NEW)
    else if ($q.find('.bmf-rank-output').length) {

      var val = $q.find('.bmf-rank-output').val();
      hasAnswer = String(val).trim() !== '';

    } 
    // ✅ TEXT-LIKE
    else {
      var $input = $q.find(
        'input[type=text], input[type=email], input[type=password], textarea'
      );
      hasAnswer = $input.length && String($input.val()).trim() !== '';
    }

    if (!hasAnswer) {
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

    // Hide all, then show current
    $panels.removeClass('active').hide();
    var $current = $panels.eq(idx).addClass('active').show();

    // -------------------------------------------------------
    // Progress (unchanged logic)
    // -------------------------------------------------------
    var total = Number($form.data('total-sections') || $panels.length || 1);
    var $progText = $form.find('.bmf-progress-current');
    var $progBar  = $form.find('.bmf-progress-bar');
    var $progFill = $form.find('.bmf-progress-fill');
    var $progPct  = $form.find('.bmf-progress-percent');

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

    // -------------------------------------------------------
    // BRANCH-AWARE FOOTER LOGIC
    // -------------------------------------------------------
    var $submitWrap = $form.find('.bmf-submit-wrap');
    var $nextBtn    = $current.find('.bmf-next-section');

    // All panels that are part of the active path
    var $pathPanels = $panels.not('.bmf-branch-hidden');

    // Index of last panel in the active path
    var lastPathIndex = $panels.index($pathPanels.last());

    var isLastStep = (idx === lastPathIndex);

    if (isLastStep) {
      // We are at the final step in the chosen branch
      $submitWrap.show();
      if ($nextBtn.length) $nextBtn.hide();
    } else {
      // Not final step yet
      $submitWrap.hide();
      if ($nextBtn.length) $nextBtn.show();
    }

    // -------------------------------------------------------
    // BRANCH-AWARE PREVIOUS BUTTON VISIBILITY
    // -------------------------------------------------------
    var $prevBtn = $current.find('.bmf-prev-section');

    // Panels that are part of the active path
    var $pathPanels = $panels.not('.bmf-branch-hidden');

    // Index of first panel in active path
    var firstPathIndex = $panels.index($pathPanels.first());

    var isFirstStep = (idx === firstPathIndex);

    if ($prevBtn.length) {
      if (isFirstStep) {
        $prevBtn.hide();
      } else {
        $prevBtn.show();
      }
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
    // SECTION VISIBILITY HELPERS (by section_order)
    // =========================================================
    function bmfShowSectionByOrder($form, order) {
      $form
        .find('.bmf-section-panel[data-section-order="' + order + '"]')
        .removeClass('bmf-branch-hidden')
        .show();
    }


    function bmfHideSectionByOrder($form, order) {
      $form
        .find('.bmf-section-panel[data-section-order="' + order + '"]')
        .addClass('bmf-branch-hidden')
        .hide()
        .removeClass('active')
        .find('.bmf-q')
        .removeClass('bmf-missing')
        .find('.bmf-q-error')
        .hide();
    }
    
    // Check if a section panel has auth requirements based on its data-section-meta
    function bmfIsAuthSection($panel) {
      var metaRaw = $panel.attr('data-section-meta');
      if (!metaRaw) return false;

      try {
        var meta = JSON.parse(metaRaw);
        return meta.auth === true;
      } catch (e) {
        return false;
      }
    }

    function showAuthError($panel, message) {
      // Look for a generic message area first
      var $msg = $panel.find('.bmf-submit-msg');

      if ($msg.length) {
        $msg.css('color', '#b00020').text(message);
        return;
      }

      // Otherwise show it under the first question
      var $firstError = $panel.find('.bmf-q-error').first();
      if ($firstError.length) {
        $firstError.text(message).show();
      } else {
        alert(message); // ultimate fallback
      }
    }

    // =========================================================
    // BRANCH RESOLUTION — PURE LOGIC (no UI changes)
    // =========================================================
      function bmfResolveBranch(branchingConfig, answerValue) {
      if (!branchingConfig || !Array.isArray(branchingConfig.rules)) return null;

      // ✅ Normalize value (strip JSON after pipe)
      var cleanValue = String(answerValue).split('|')[0];

      for (var i = 0; i < branchingConfig.rules.length; i++) {
        var rule = branchingConfig.rules[i];

        if (Array.isArray(rule.when) && rule.when.indexOf(cleanValue) !== -1) {
        return rule;
        }
      }

      return null;
      }

    // =========================================================
    // INIT — establish baseline eligibility and paging
    // =========================================================
      $('.bmf-form').each(function () {

        // ✅ ✅ FIX: normalize login state (handles "1", 1, true)
        bmfState.flags.is_logged_in = (
          window.bmfVars &&
          (bmfVars.is_logged_in === true || bmfVars.is_logged_in === 1 || bmfVars.is_logged_in === "1")
        );

        var $form   = $(this);
        var $panels = $form.find('.bmf-section-panel');

        if (!$panels.length) return;

        // -------------------------------------------------------
        // 1) Apply section-level eligibility rules
        // -------------------------------------------------------
        $panels.each(function () {
          var $panel = $(this);
          var metaRaw = $panel.attr('data-section-meta');
          if (!metaRaw) return;

          try {
            var meta = JSON.parse(metaRaw);

            // Exclude section when user is logged in
            if (
              meta.visibility &&
              meta.visibility.exclude_when_logged_in &&
              bmfState.flags.is_logged_in
            ) {
              $panel.addClass('bmf-branch-hidden').hide();
            }

            // ✅ Auth section skip
            if (
              meta.auth === true &&
              bmfState.flags.is_logged_in
            ) {
              $panel.addClass('bmf-branch-hidden').hide();
            }

          } catch (e) {
            console.warn('Invalid section meta JSON', e);
          }
        });

      // -------------------------------------------------------
      // 2) Determine starting panel:
      //    first section NOT excluded from the path
      // -------------------------------------------------------
      var $pathPanels = $panels.not('.bmf-branch-hidden');
      var $startPanel = $pathPanels.first();

      // Defensive fallback
      if (!$startPanel.length) {
        console.warn('No eligible start panel found; falling back to first panel');
        $startPanel = $panels.first()
          .removeClass('bmf-branch-hidden')
          .show();
      }

      // -------------------------------------------------------
      // 3) Initialize pager and show only the start panel
      //    (pager owns visibility from this point forward)
      // -------------------------------------------------------
      var startIndex = $panels.index($startPanel);
      $form.data('bmfCurrent', startIndex);

      $panels.hide();
      bmfShowPanel($form, startIndex);
    });


  // =========================================================
  // PREVIOUS / NEXT
  // =========================================================
  $(document).on('click', '.bmf-prev-section', function () {
    var $form   = $(this).closest('.bmf-form');
    var cur     = Number($form.data('bmfCurrent') || 0);
    var $panels = $form.find('.bmf-section-panel');

    if (cur <= 0) return;

    var $currentPanel = $panels.eq(cur);
    var currentOrder  = $currentPanel.attr('data-section-order');

    var prevIndex = cur - 1;

    // -------------------------------------------------------
    // Branch-aware reverse navigation
    // -------------------------------------------------------
    Object.keys(bmfState.branches).forEach(function (sourceOrder) {
      var rule = bmfState.branches[sourceOrder];

      if (
        rule &&
        Array.isArray(rule.show_sections) &&
        rule.show_sections.indexOf(Number(currentOrder)) !== -1
      ) {
        // This section was reached via a branch
        var $sourcePanel = $panels.filter(
          '[data-section-order="' + sourceOrder + '"]'
        );

        if ($sourcePanel.length) {
          prevIndex = $panels.index($sourcePanel);
        }
      }
    });

    // -------------------------------------------------------
    // Navigate back
    // -------------------------------------------------------
    if (prevIndex >= 0) {
      $form.data('bmfCurrent', prevIndex);
      bmfShowPanel($form, prevIndex);
      $form.find('.bmf-submit-msg').text('').css('color', '#555');

      var topForm = $form.offset().top - 40;
      window.scrollTo({ top: topForm, behavior: 'smooth' });
    }
  });

  $(document).on('click', '.bmf-next-section', function () {

    var $form   = $(this).closest('.bmf-form');
    var cur     = Number($form.data('bmfCurrent') || 0);
    var $panels = $form.find('.bmf-section-panel');
    var $currentPanel = $panels.eq(cur);


    
    // ✅ ALWAYS SYNC WITH REAL WP COOKIE
    bmfState.flags.is_logged_in = (document.cookie.indexOf('wordpress_logged_in_') !== -1);

    var isAuthSection = bmfIsAuthSection($currentPanel);
console.log('AUTH CHECK:', {
    isAuthSection: isAuthSection,
    isLoggedInFlag: bmfState.flags.is_logged_in
});

    if (isAuthSection && !bmfState.flags.is_logged_in) {
      bmfAuthenticateAndContinue($form, $currentPanel, function () {
        // ✅ this is the "continue" part
        proceedToNextPanel($form);
      });
      return; // stop default NEXT until auth resolves
    }


    // -------------------------------------------------------
    // 1) Validate required questions in current section
    // -------------------------------------------------------
    var missing = bmfValidateRequiredSection($currentPanel);
    if (missing.length > 0) {
      var top = missing[0].offset().top - 80;
      window.scrollTo({ top: top, behavior: 'smooth' });
      $form.find('.bmf-submit-msg')
        .css('color', '#b00020')
        .text('Please complete required questions in this section.');
      return;
    }

    // -------------------------------------------------------
    // 2) Determine branch-aware next index (no auto jumps)
    // -------------------------------------------------------
    var nextIndex = cur + 1;
    var currentOrder = $currentPanel.attr('data-section-order');
    var branchRule   = bmfState.branches[currentOrder];

    // If a branch was chosen for this section, align path
    if (branchRule && Array.isArray(branchRule.show_sections) && branchRule.show_sections.length) {
      var targetOrder = branchRule.show_sections[0];
      var $target = $panels.filter(
        '[data-section-order="' + targetOrder + '"]'
      );

      if ($target.length) {
        nextIndex = $panels.index($target);
      }

      // Hide non-selected branch sections permanently
      if (Array.isArray(branchRule.hide_sections)) {
        branchRule.hide_sections.forEach(function (order) {
          bmfHideSectionByOrder($form, order);
        });
      }
    }

    // -------------------------------------------------------
    // 3) Skip any hidden panels when advancing
    // -------------------------------------------------------
    while (
      nextIndex < $panels.length &&
      $panels.eq(nextIndex).hasClass('bmf-branch-hidden')
    ) {
      nextIndex++;
    }

    // -------------------------------------------------------
    // 4) Advance if a next visible panel exists
    // -------------------------------------------------------
    if (nextIndex < $panels.length) {
      $form.data('bmfCurrent', nextIndex);
      bmfShowPanel($form, nextIndex);
      $form.find('.bmf-submit-msg').text('').css('color', '#555');

      var topForm = $form.offset().top - 40;
      window.scrollTo({ top: topForm, behavior: 'smooth' });
    }
  });


  function bmfAuthenticateAndContinue($form, $panel, proceed) {

    var $emailInput = $panel.find('input[type="email"]');
    var $passInput  = $panel.find('input[type="password"]');

    if (!$emailInput.length || !$passInput.length) {
      
      showAuthError($panel, 'Authentication form is misconfigured.');
      return;
    }

    var email = String($emailInput.val() || '').trim();
    var password = String($passInput.val() || '').trim();

    if (!email || !password) {
      showAuthError($panel, 'Email and password are required.');
      return;
    }
    // ✅ SHOW WAIT CURSOR
    $('body').css('cursor', 'wait');
    $form.css('pointer-events', 'none');

    $.post(bmfAjax.url, {
      action: 'bmf_auth',
      _ajax_nonce: bmfAjax.nonce,
      email: email,
      password: password
    })
    .done(function (res) {
      //console.log('AUTH RESPONSE:', res);
      if (res.success) {
        // ✅ set flag (optional now)
        bmfState.flags.is_logged_in = true;

        // ✅ RELOAD PAGE so WP session + UI sync
        window.location.reload();
        proceed();
      } else {

        // ✅ restore cursor if login fails
        $('body').css('cursor', '');
        $form.css('pointer-events', '');

        showAuthError($panel, res.data?.message || 'Authentication failed.');
      }
    })
    .fail(function (xhr) {

      // ✅ restore cursor on failure
      $('body').css('cursor', '');
      $form.css('pointer-events', '');

      showAuthError($panel, 'Authentication error. Please refresh and try again.');
    });
  }


  function bmfHideQuestion(qid) {
  $('.bmf-q[data-question-id="' + qid + '"]')
    .hide()
    .removeClass('bmf-missing')
    .find('.bmf-q-error').hide();
  }

  function bmfShowQuestion(qid) {
    $('.bmf-q[data-question-id="' + qid + '"]').show();
  }

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

  function updateRankValue($list) {

      var order = [];

      $list.find('.bmf-rank-item').each(function () {
          var raw = $(this).data('value');
          var clean = String(raw).split('|')[0];
          order.push(clean);
      });

      var val = order.join(',');

      var $input = $list.closest('.bmf-q').find('.bmf-rank-output');

      $input.val(val);

      // ✅ SAVE (same logic you already use)
      $.post(bmfAjax.url, {
          action: 'bmf_save_answer',
          _ajax_nonce: bmfAjax.nonce,
          response_id: $input.data('response-id'),
          question_id: $input.data('question-id'),
          value: encodeURIComponent(val)
      });

      bmfState.answers[$input.data('question-id')] = val;
  }  

  // =========================================================
  // DRAG & DROP RANK HANDLER
  // =========================================================
  jQuery(function ($) {

      if (!$.fn.sortable) return;

        $('.bmf-rank-list').each(function () {

            var $list = $(this);

            if (isTouch) {

                // ✅ MOBILE: add up/down buttons
                $list.find('.bmf-rank-item').each(function () {

                    var $item = $(this);

                    // prevent duplicate buttons
                    if ($item.find('.bmf-rank-controls').length) return;

                    var controls = `
                        <span class="bmf-rank-controls" style="float:right;">
                            <button type="button" class="bmf-up">▲</button>
                            <button type="button" class="bmf-down">▼</button>
                        </span>
                    `;

                    $item.append(controls);
                });

            } else {

                // ✅ DESKTOP: use sortable
                if (!$.fn.sortable) return;

                $list.sortable({
                    update: function () {
                        updateRankValue($list);
                    }
                });

            }
        });

  });
  // =========================================================
  // MOBILE RANK CONTROLS (up/down buttons)
  // =========================================================
  $(document).on('click', '.bmf-up, .bmf-down', function () {

      var $btn = $(this);
      var $item = $btn.closest('.bmf-rank-item');
      var $list = $btn.closest('.bmf-rank-list');

      if ($btn.hasClass('bmf-up')) {
          $item.prev().before($item);
      } else {
          $item.next().after($item);
      }

      updateRankValue($list);
  });


});
