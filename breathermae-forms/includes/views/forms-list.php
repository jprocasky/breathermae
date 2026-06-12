<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$repo = new BMF_Repository();
$table = new BMF_Forms_Table( $repo );
$table->prepare_items();

$editing = false;
$form    = null;

    if ( isset( $_GET['edit'] ) ) {
        $editing = true;
        $form = null;
        foreach ( BMF_Repository::get_all_forms() as $f ) {
            if ( (int) $f->id === absint( $_GET['edit'] ) ) {
                $form = $f;
                break;
            }
        }
    }
?>

<div class="wrap">
    <h1 class="wp-heading-inline">Breathermae Forms</h1>

    <hr class="wp-header-end">

    <div style="display:flex; gap:30px; align-items:flex-start;">

        <div style="flex:2;">
            <form method="get">
                <input type="hidden" name="page" value="bmf_forms">
                <?php $table->display(); ?>
            </form>
        </div>

        <div style="flex:1; background:#fff; padding:20px; border:1px solid #ccd0d4;">
            <div style="display:flex; justify-content:space-between; align-items:center;">
                <h2><?php echo $editing ? 'Edit Form' : 'Add New Form'; ?></h2>

                <?php if ( $editing ) : ?>
                    <a href="<?php echo esc_url(
                        add_query_arg(
                            [ 'page' => 'bmf-forms' ],
                            admin_url( 'admin.php' )
                        )
                    ); ?>" class="page-title-action">
                        Add New
                    </a>
                <?php endif; ?>
            </div>

            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <?php wp_nonce_field( 'bmf_forms_save' ); ?>
                <input type="hidden" name="action" value="bmf_forms_save">

                <?php if ( $editing ) : ?>
                    <input type="hidden" name="id" value="<?php echo esc_attr( $form->id ); ?>">
                <?php endif; ?>

                <p>
                    <label><strong>Title</strong></label><br>
                    <input type="text" name="title" class="regular-text"
                        value="<?php echo esc_attr( $form->title ?? '' ); ?>" required>
                </p>

                <?php if ( ! $editing ) : ?>
                    <p>
                        <label><strong>Slug</strong></label><br>
                        <input type="text"
                            name="slug"
                            class="regular-text"
                            required
                            placeholder="Unique identifier (e.g. rsi-intake)">
                    </p>
                <?php else : ?>
                    <input type="hidden" name="slug"
                        value="<?php echo esc_attr( $form->slug ); ?>">
                <?php endif; ?>

                <p>
                    <label><strong>Form Tag</strong></label><br>
                    <input type="text" name="form_tag" class="regular-text"
                        value="<?php echo esc_attr( $form->form_tag ?? '' ); ?>">
                </p>

                <p>
                    <label><strong>Status</strong></label><br>
                    <?php
                    $statuses = [
                        'draft'     => 'Draft',
                        'published' => 'Published',
                        'archived'  => 'Archived',
                    ];
                    ?>

                    <select name="status">
                    <?php foreach ( $statuses as $value => $label ) : ?>
                        <option value="<?php echo esc_attr( $value ); ?>"
                            <?php selected( $form->status ?? 'draft', $value ); ?>>
                            <?php echo esc_html( $label ); ?>
                        </option>
                    <?php endforeach; ?>
                    </select>
                </p>

                <p>
                    <label><strong>Description</strong></label><br>
                    <textarea name="description" rows="4" class="large-text"><?php
                        echo esc_textarea( $form->description ?? '' );
                    ?></textarea>
                </p>

                <p>
                    <button type="submit" class="button button-primary">
                        Save Form
                    </button>
                </p>

            </form>
        </div>

    </div>
</div>