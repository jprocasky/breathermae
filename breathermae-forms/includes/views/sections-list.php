<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$form_id = absint($_GET['form_id']);
$form = null;

foreach ( BMF_Repository::get_all_forms() as $f ) {
    if ( (int) $f->id === (int) $form_id ) {
        $form = $f;
        break;
    }
}

if ( ! $form ) {
    echo '<div class="notice notice-error"><p>Invalid form selected.</p></div>';
    return;
}

// ✅ use repo for sections
$sections = $repo->get_sections_by_form( $form_id );

$editing = false;
$section = null;

if ( isset($_GET['edit']) ) {
    $edit_id = absint( $_GET['edit'] );

    foreach ( $sections as $s ) {
        if ( (int) $s->id === $edit_id ) {
            $section = $s;
            break;
        }
    }

    if ( ! $section ) {
        echo '<div class="notice notice-error"><p>Section not found.</p></div>';
        return;
    }

    $editing = true;
}

// ✅ formula_meta (branching stays here)
$formula_meta = [];
$branching    = null;

if ( ! empty( $section->formula_meta ) ) {
    $formula_meta = json_decode( $section->formula_meta, true );
    $branching    = $formula_meta['branching'] ?? null;
}

// ✅ meta_json (redirects live here)
$meta = [];

if ( ! empty( $section->meta_json ) ) {
    $meta = json_decode( $section->meta_json, true );
}

$redirects = $meta['path_redirects'] ?? [];

?>

<div class="wrap">
    <h1>Sections for "<?php echo esc_html($form->title); ?>"</h1>

    <hr>

    <div style="display:flex; gap:30px;">

        <!-- ================= LEFT ================= -->
        <div style="flex:2;">
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th style="width: 60px;">Section</th>
                        <th>Title</th>
                        <th>Formula</th>
                        <th style="width: 80px;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ( $sections as $s ) : ?>
                    <?php
                        $formula = trim( (string) ( $s->formula ?? '' ) );
                        if ( $formula ) {
                            $formula_display = strlen( $formula ) > 65 
                                ? substr( $formula, 0, 62 ) . '…' 
                                : $formula;
                        } else {
                            $formula_display = '—';
                        }
                    ?>
                    <tr>
                        <td><?php echo esc_html( $s->order_index ); ?></td>
                        <td><?php echo esc_html( $s->title ); ?></td>
                        <td>
                            <?php if ( $formula ) : ?>
                                <code 
                                    style="font-size: 12px; background: #f6f7f7; padding: 2px 6px; border-radius: 3px; display: inline-block; max-width: 320px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; vertical-align: middle;"
                                    title="<?php echo esc_attr( $formula ); ?>"
                                >
                                    <?php echo esc_html( $formula_display ); ?>
                                </code>
                            <?php else : ?>
                                <span style="color: #8c8f94;">—</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <a href="<?php echo esc_url(
                                add_query_arg(
                                    [
                                        'page'    => 'bmf-sections',
                                        'form_id' => $form_id,
                                        'edit'    => $s->id,
                                    ],
                                    admin_url( 'admin.php' )
                                )
                            ); ?>">Edit</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

        </div>

        <!-- ================= RIGHT ================= -->
        <div style="flex:1; background:#fff; padding:20px; border:1px solid #ccd0d4;">
            <h2><?php echo $editing ? 'Edit Section' : 'Add Section'; ?></h2>

            <form method="post" action="<?php echo esc_url( admin_url('admin-post.php') ); ?>">
                <?php wp_nonce_field('bmf-sections_save'); ?>
                <input type="hidden" name="action" value="bmf-sections_save">
                <input type="hidden" name="form_id" value="<?php echo esc_attr($form_id); ?>">

                <?php if ($editing): ?>
                    <input type="hidden" name="id" value="<?php echo esc_attr($section->id); ?>">
                <?php endif; ?>

                <p>
                    <label><strong>Title</strong></label><br>
                    <input class="regular-text" name="title"
                        value="<?php echo esc_attr($section->title ?? ''); ?>" required>
                </p>

                <p>
                    <label><strong>Prompt</strong></label><br>
                    <textarea name="prompt" rows="3" class="large-text"><?php
                        echo esc_textarea($section->prompt ?? '');
                    ?></textarea>
                </p>

                <p>
                    <label><strong>Explanation</strong></label><br>
                    <textarea name="explanation" rows="3" class="large-text"><?php
                        echo esc_textarea($section->explanation ?? '');
                    ?></textarea>
                </p>

                <p>
                    <label><strong>Order</strong></label><br>
                    <input type="number" name="order_index"
                        value="<?php echo esc_attr($section->order_index ?? 0); ?>">
                </p>

                <!-- ===================================================== -->
                <!-- ✅ NEW: REDIRECT MAPPING EDITOR -->
                <!-- ===================================================== -->
                <h3>Redirect Mapping</h3>
                <p style="color:#666; margin-top:-5px;">
                    Define where users are sent based on computed path keys.
                </p>

                <div id="bmf-redirect-rows">

                    <?php if (!empty($redirects)) : ?>
                        <?php foreach ($redirects as $key => $url): ?>
                            <div class="bmf-redirect-row" style="display:flex; gap:8px; margin-bottom:6px;">
                                <input name="redirect_key[]"
                                    value="<?php echo esc_attr($key); ?>"
                                    placeholder="path key (e.g. performance)"
                                    style="flex:1;">

                                <input name="redirect_url[]"
                                    value="<?php echo esc_attr($url); ?>"
                                    placeholder="/path/"
                                    style="flex:2;">
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="bmf-redirect-row" style="display:flex; gap:8px; margin-bottom:6px;">
                            <input name="redirect_key[]" placeholder="path key">
                            <input name="redirect_url[]" placeholder="redirect url">
                        </div>
                    <?php endif; ?>

                </div>

                <p>
                    <button type="button" id="bmf-add-redirect" class="button">
                        + Add Path
                    </button>
                </p>

                <!-- ===================================================== -->

                <h3>Branching Logic</h3>

                <p>
                    <label><strong>Trigger Question Code</strong></label><br>
                    <input type="text"
                        name="branch_question_code"
                        class="regular-text"
                        value="<?php echo esc_attr( $branching['question_code'] ?? '' ); ?>">
                </p>

                <?php
                $rules = $branching['rules'] ?? [ [
                    'when' => [],
                    'show_sections' => [],
                    'hide_sections' => [],
                ] ];
                ?>

                <div id="bmf-branch-rules">
                    <?php foreach ( $rules as $rule ) : ?>
                        <div class="bmf-branch-rule" style="padding:10px; border:1px solid #ccd0d4; margin-bottom:10px;">

                            <p>
                                <label>When value(s)</label><br>
                                <input type="text"
                                    name="branch_when[]"
                                    class="regular-text"
                                    value="<?php echo esc_attr( implode(',', $rule['when'] ?? []) ); ?>">
                            </p>

                            <p>
                                <label>Show sections</label><br>
                                <input type="text"
                                    name="branch_show[]"
                                    class="regular-text"
                                    value="<?php echo esc_attr( implode(',', $rule['show_sections'] ?? []) ); ?>">
                            </p>

                            <p>
                                <label>Hide sections</label><br>
                                <input type="text"
                                    name="branch_hide[]"
                                    class="regular-text"
                                    value="<?php echo esc_attr( implode(',', $rule['hide_sections'] ?? []) ); ?>">
                            </p>

                        </div>
                    <?php endforeach; ?>

                    <p>
                        <button type="button" class="button" id="bmf-add-branch-rule">
                            + Add rule
                        </button>
                    </p>
                </div>

                <!-- Scoring Formula -->
                <h3>Scoring Formula</h3>
                <p>
                    <label><strong>Formula</strong> <small>(used by section scorer)</small></label><br>
                    <textarea 
                        name="formula" 
                        rows="4" 
                        class="large-text code" 
                        placeholder="avg(Q1:Q5) or (Q1 + Q2 * 0.5) / 2"
                    ><?php echo esc_textarea( $section->formula ?? '' ); ?></textarea>
                    <br>
                    <small>
                        Examples: <code>avg(Q1:Q3)</code>, <code>avg(Q1:Q10)</code>, 
                        <code>sum(Q1,Q2,Q3)/3</code>, <code>(Q1*0.4 + Q2*0.6)</code>, 
                        <code>Total / 20</code><br>
                        Variables: Q1, Q2, ... (per section). Supports <code>avg()</code>, <code>sum()</code>, <code>Total</code>, and basic arithmetic.
                    </small>
                </p>

                <h3>Advanced JSON (Formula Meta)</h3>
                <textarea name="formula_meta_raw" rows="10" style="width:100%; font-family: monospace;">
                <?php echo esc_textarea(json_encode($formula_meta ?? [], JSON_PRETTY_PRINT)); ?>
                </textarea>

                <h3>Advanced JSON (Meta JSON / Redirects)</h3>
                <textarea name="meta_json_raw" rows="10" style="width:100%; font-family: monospace;">
                <?php echo esc_textarea(json_encode($meta ?? [], JSON_PRETTY_PRINT)); ?>
                </textarea>




                <p>
                    <button class="button button-primary">Save Section</button>
                </p>

            </form>

        </div>

    </div>
</div>

<script>
// ✅ Add redirect row
document.getElementById('bmf-add-redirect')?.addEventListener('click', function () {
    const container = document.getElementById('bmf-redirect-rows');

    const row = document.createElement('div');
    row.className = 'bmf-redirect-row';
    row.style.display = 'flex';
    row.style.gap = '8px';
    row.style.marginBottom = '6px';

    row.innerHTML = `
        <input name="redirect_key[]" placeholder="path key" style="flex:1;">
        <input name="redirect_url[]" placeholder="redirect url" style="flex:2;">
    `;

    container.appendChild(row);
});

// existing branching add
document.getElementById('bmf-add-branch-rule')?.addEventListener('click', function () {
    const container = document.getElementById('bmf-branch-rules');
    const rules = container.querySelectorAll('.bmf-branch-rule');

    const clone = rules[rules.length - 1].cloneNode(true);
    clone.querySelectorAll('input').forEach(input => input.value = '');

    container.appendChild(clone);
});
</script>