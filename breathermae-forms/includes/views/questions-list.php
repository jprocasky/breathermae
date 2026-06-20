<?php
$form_id    = absint( $_GET['form_id'] );
$section_id = absint($_GET['section_id']);
$questions  = BMF_Repository::get_questions_by_section( $section_id );

// Load form
$form = null;
foreach ( BMF_Repository::get_all_forms() as $f ) {
    if ( (int) $f->id === $form_id ) {
        $form = $f;
        break;
    }
}

if ( ! $form ) {
    echo '<div class="notice notice-error"><p>Invalid form selected.</p></div>';
    return;
}

// Load section
$sections = BMF_Repository::get_sections_by_form( $form_id );
$section  = null;

foreach ( $sections as $s ) {
    if ( (int) $s->id === $section_id ) {
        $section = $s;
        break;
    }
}

if ( ! $section ) {
    echo '<div class="notice notice-error"><p>Invalid section selected.</p></div>';
    return;
}

$editing = false;
$question = null;

if ( isset($_GET['edit']) ) {
    $editing = true;
    foreach ( $questions as $q ) {
        if ( (int)$q->id === absint($_GET['edit']) ) {
            $question = $q;
            break;
        }
    }
}

function bmf_split_options($string) {

    $result = [];
    $buffer = '';
    $depth  = 0;

    $len = strlen($string);

    for ($i = 0; $i < $len; $i++) {
        $char = $string[$i];

        if ($char === '{') {
            $depth++;
        }

        if ($char === '}') {
            $depth--;
        }

        // ✅ only split on commas OUTSIDE JSON
        if ($char === ',' && $depth === 0) {
            $result[] = $buffer;
            $buffer = '';
            continue;
        }

        $buffer .= $char;
    }

    if ($buffer !== '') {
        $result[] = $buffer;
    }

    return $result;
}

?>
<!-- Breadcrumb -->
<div class="bmf-breadcrumb" style="margin: 10px 0 20px;">
    <a href="<?php echo esc_url( admin_url('admin.php?page=bmf-forms') ); ?>">
        ← All Forms
    </a>
    &nbsp;›&nbsp;
    <a href="<?php echo esc_url( add_query_arg([
        'page'    => 'bmf-sections',
        'form_id' => $form_id,
    ], admin_url('admin.php')) ); ?>">
        <?php echo esc_html($form->title); ?>
    </a>
    &nbsp;›&nbsp;
    <strong><?php echo esc_html($section->title); ?></strong>
</div>

<?php if (isset($_GET['deleted'])) : ?>
    <div class="notice notice-success is-dismissible">
        <p>Question deleted successfully.</p>
    </div>
<?php endif; ?>

<div class="wrap">
    
    <h1>Questions</h1>

    <div style="margin: 10px 0 20px; padding: 10px 12px; background: #f6f7f7; border-left: 4px solid #2271b1;">
        <p style="margin:0;">
            <strong>Form:</strong>
            <?php echo esc_html( $form->title ); ?>
            <br>
            <strong>Section:</strong>
            <?php echo esc_html( $section->order_index . '. ' . $section->title ); ?>
            |
            <a href="<?php echo esc_url(
                add_query_arg( [ 'page' => 'bmf-questions', 'form_id' => $form_id ], admin_url('admin.php') )
            ); ?>">Change: Sections</a>
        </p>
    </div>

    <div style="display:flex; gap:30px; margin-top:20px;">

        
        <!-- LEFT: question list -->
        <div style="flex:2;">
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Code</th>
                        <th>Prompt</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ( $questions as $q ) : ?>
                    <tr>
                        <td><?php echo esc_html($q->order_index); ?></td>
                        <td><?php echo esc_html($q->code); ?></td>
                        <td><?php echo esc_html($q->prompt); ?></td>
                        <td>
                            <a href="<?php 
                                echo esc_url( add_query_arg([
                                    'page'       => 'bmf-questions',
                                    'form_id'    => $form_id,
                                    'section_id' => $section_id,
                                    'edit'       => $q->id,
                                ], admin_url('admin.php')) ); 
                            ?>">Edit</a>

                            &nbsp;|&nbsp;

                            <a href="<?php 
                                echo esc_url( wp_nonce_url( add_query_arg([
                                    'page'       => 'bmf-questions',
                                    'form_id'    => $form_id,
                                    'section_id' => $section_id,
                                    'delete'     => $q->id,
                                ], admin_url('admin.php')), 'bmf_delete_question_' . $q->id ) ); 
                            ?>" 
                            onclick="return confirm('Are you sure you want to delete this question?');"
                            style="color:#b32d2e;">
                                Delete
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- RIGHT: editor -->
        <div style="flex:1; background:#fff; padding:20px; border:1px solid #ccd0d4;">
            <p>
                <a href="<?php echo esc_url( remove_query_arg('edit') ); ?>" class="button button-primary">
                    + Add New Question
                </a>
            </p>               
            <h2><?php echo $editing ? 'Edit Question' : 'Add Question'; ?></h2>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('bmf-questions_save'); ?>
                <input type="hidden" name="action" value="bmf-questions_save">
                <input type="hidden" name="form_id" value="<?php echo esc_attr( $form_id ); ?>">
                <input type="hidden" name="section_id" value="<?php echo esc_attr($section_id); ?>">

                <?php if ($editing): ?>
                    <input type="hidden" name="id" value="<?php echo esc_attr($question->id); ?>">
                <?php endif; ?>

                <p>
                    <label><strong>Question Code</strong></label><br>
                    <input name="question_code" class="regular-text"
                        value="<?php echo esc_attr($question->code ?? ''); ?>">
                </p>

                <p>
                    <label><strong>Prompt</strong></label><br>
                    <textarea name="prompt" rows="3" class="large-text"><?php
                        echo esc_textarea($question->prompt ?? '');
                    ?></textarea>
                </p>

                <p>
                    <label><strong>Type</strong></label><br>
                    <select name="question_type">
                        <?php foreach ( ['radio','checkbox','select','text','number','rank'] as $type ) : ?>
                            <option value="<?php echo esc_attr($type); ?>"
                                <?php selected($question->type ?? 'radio', $type); ?>>
                                <?php echo esc_html(ucfirst($type)); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </p>

                <p>
                    <label>
                        <input type="checkbox" name="required" value="1"
                            <?php checked($question->required ?? 0); ?>>
                        Required
                    </label>
                </p>

                <p>
                    <label><strong>Order</strong></label><br>
                    <input type="number" name="order_index"
                        value="<?php echo esc_attr($question->order_index ?? 0); ?>">
                </p>

                <h3>Question Choices</h3>

                <p style="color:#666;">
                    Label | Value | Meta JSON (weights, etc.)
                </p>

                <div id="bmf-question-choices">
                <?php
                $q_choices = [];

                if ( ! empty( $question->options_string ) ) {

                    $pairs = bmf_split_options($question->options_string);

                    foreach ( $pairs as $pair ) {

                        $parts = explode( '|', $pair, 3 );

                        $q_choices[] = [
                            'label' => $parts[0] ?? '',
                            'value' => $parts[1] ?? '',
                            'meta'  => $parts[2] ?? '',
                        ];
                    }
                }

                foreach ( $q_choices ?: [ [ 'label' => '', 'value' => '', 'meta' => '' ] ] as $row ) :
                ?>
                    <div class="bmf-question-choice-row" style="display:flex; gap:8px; margin-bottom:6px;">
                        
                        <input name="choice_label[]"
                            value="<?php echo esc_attr( $row['label'] ); ?>"
                            placeholder="Label"
                            style="flex:2;">

                        <input name="choice_value[]"
                            value="<?php echo esc_attr( $row['value'] ); ?>"
                            placeholder="Value"
                            style="flex:1;">

                        <input name="choice_meta[]"
                            value="<?php echo esc_attr( $row['meta'] ); ?>"
                            placeholder='{"weights":{"rsi":2}}'
                            style="flex:3; font-family:monospace;">
                        <button type="button"
                                class="bmf-delete-choice button-link"
                                style="color:#b32d2e; font-size:18px; line-height:1; padding:0 4px; cursor:pointer; border:none; background:none;"
                                title="Delete this choice">
                            ×
                        </button>
                    </div>
                <?php endforeach; ?>
                </div>

                <p style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
                    <button type="button" class="button" id="bmf-add-question-choice">
                        + Add choice
                    </button>

                    <button type="button" class="button button-secondary" id="bmf-reverse-values">
                        Reverse Values
                    </button>
                    <span id="bmf-reverse-notice" style="font-size: 12px; color: #2271b1; display: none;"></span>
                </p>

                <p>
                    <button class="button button-primary">Save Question</button>
                </p>

            </form>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {

    const container = document.getElementById('bmf-question-choices');
    if (!container) return;

    // === Add new choice row ===
    const addBtn = document.getElementById('bmf-add-question-choice');
    if (addBtn) {
        addBtn.addEventListener('click', function () {
            const rows = container.querySelectorAll('.bmf-question-choice-row');
            if (!rows.length) return;

            const clone = rows[rows.length - 1].cloneNode(true);

            // Clear all input values in the new row
            clone.querySelectorAll('input').forEach(input => {
                input.value = '';
            });

            container.appendChild(clone);
        });
    }

    // === Delete choice row (using event delegation) ===
    container.addEventListener('click', function (e) {
        if (e.target.classList.contains('bmf-delete-choice')) {
            const rows = container.querySelectorAll('.bmf-question-choice-row');

            // Prevent deleting the last remaining choice
            if (rows.length <= 1) {
                alert('At least one choice is required.');
                return;
            }

            // Remove the row
            const row = e.target.closest('.bmf-question-choice-row');
            if (row) {
                row.remove();
            }
        }
    });

});
</script>

<script>
document.getElementById('bmf-reverse-values')?.addEventListener('click', function () {
    const container = document.getElementById('bmf-question-choices');
    if (!container) return;

    const valueInputs = container.querySelectorAll('input[name="choice_value[]"]');
    if (!valueInputs.length) return;

    // Collect numeric values
    const values = Array.from(valueInputs).map(input => {
        const num = parseFloat(input.value);
        return isNaN(num) ? null : num;
    }).filter(v => v !== null);

    if (values.length === 0) {
        alert('No numeric values found to reverse.');
        return;
    }

    const min = Math.min(...values);
    const max = Math.max(...values);

    // Reverse each value
    valueInputs.forEach(input => {
        const num = parseFloat(input.value);
        if (!isNaN(num)) {
            input.value = (min + max - num);
        }
    });

    // Show temporary notice
    const notice = document.getElementById('bmf-reverse-notice');
    if (notice) {
        notice.textContent = 'Values reversed. Review and Save.';
        notice.style.display = 'inline';
        setTimeout(() => {
            notice.style.display = 'none';
        }, 2500);
    }
});
</script>