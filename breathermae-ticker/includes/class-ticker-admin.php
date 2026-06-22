<?php
/**
 * Simple Admin interface for managing ticker tips
 * (You can also manage directly from Excel VBA)
 */

if (!defined('ABSPATH')) {
    exit;
}

class BM_Ticker_Admin {

    public static function render_page() {
        global $wpdb;
        $table = BM_Ticker_DB::get_table_name();

        // Handle form submissions (add new tip)
        if (isset($_POST['bm_add_tip']) && check_admin_referer('bm_ticker_add')) {
            $message = sanitize_textarea_field($_POST['message'] ?? '');
            $type    = sanitize_text_field($_POST['type'] ?? 'general');
            $active  = isset($_POST['is_active']) ? 1 : 0;

            if (!empty($message)) {
                $wpdb->insert($table, [
                    'message'   => $message,
                    'type'      => $type,
                    'is_active' => $active,
                ]);
                echo '<div class="notice notice-success"><p>Tip added successfully.</p></div>';
            }
        }

        // Handle quick actions (activate/deactivate/delete)
        if (isset($_GET['action']) && isset($_GET['id']) && check_admin_referer('bm_ticker_action')) {
            $id = intval($_GET['id']);
            if ($_GET['action'] === 'toggle') {
                $current = $wpdb->get_var($wpdb->prepare("SELECT is_active FROM $table WHERE id = %d", $id));
                $new_status = $current ? 0 : 1;
                $wpdb->update($table, ['is_active' => $new_status], ['id' => $id]);
            } elseif ($_GET['action'] === 'delete') {
                $wpdb->delete($table, ['id' => $id]);
            }
            // Refresh
            wp_redirect(admin_url('admin.php?page=breathermae-ticker'));
            exit;
        }

        // Get all tips
        $tips = $wpdb->get_results("SELECT * FROM $table ORDER BY is_active DESC, priority DESC, id DESC", ARRAY_A);

        ?>
        <div class="wrap">
            <h1>BreatherMae Tips <span style="font-size:0.6em; color:#666;">(Ticker Data)</span></h1>
            <p>Manage the tips that appear in the BreatherMae Ticker widget. You can also edit this table directly from Excel VBA.</p>

            <!-- Add New Tip Form -->
            <div class="postbox" style="padding:20px; margin-top:20px; max-width:700px;">
                <h2 style="margin-top:0;">Add New Tip</h2>
                <form method="post">
                    <?php wp_nonce_field('bm_ticker_add'); ?>
                    <table class="form-table">
                        <tr>
                            <th><label for="message">Tip Message</label></th>
                            <td>
                                <textarea name="message" id="message" rows="3" class="large-text" required></textarea>
                                <p class="description">Keep it concise — this will scroll in the ticker.</p>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="type">Type / Category</label></th>
                            <td>
                                <input type="text" name="type" id="type" value="general" class="regular-text">
                                <p class="description">Examples: general, pro, nutrition, recovery, mindset</p>
                            </td>
                        </tr>
                        <tr>
                            <th>Active</th>
                            <td>
                                <label><input type="checkbox" name="is_active" checked> Show this tip</label>
                            </td>
                        </tr>
                    </table>
                    <button type="submit" name="bm_add_tip" class="button button-primary">Add Tip</button>
                </form>
            </div>

            <!-- Existing Tips Table -->
            <h2 style="margin-top:40px;">Existing Tips</h2>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th style="width:60px;">ID</th>
                        <th>Message</th>
                        <th style="width:100px;">Type</th>
                        <th style="width:80px;">Active</th>
                        <th style="width:120px;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($tips)) : ?>
                        <tr><td colspan="5">No tips found. Add your first one above.</td></tr>
                    <?php else : ?>
                        <?php foreach ($tips as $tip) : ?>
                            <tr>
                                <td><?php echo esc_html($tip['id']); ?></td>
                                <td><?php echo esc_html(wp_trim_words($tip['message'], 20)); ?></td>
                                <td><code><?php echo esc_html($tip['type']); ?></code></td>
                                <td>
                                    <?php if ($tip['is_active']) : ?>
                                        <span style="color:#16a34a; font-weight:600;">Yes</span>
                                    <?php else : ?>
                                        <span style="color:#dc2626;">No</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=breathermae-ticker&action=toggle&id=' . $tip['id']), 'bm_ticker_action'); ?>" class="button button-small">
                                        <?php echo $tip['is_active'] ? 'Deactivate' : 'Activate'; ?>
                                    </a>
                                    <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=breathermae-ticker&action=delete&id=' . $tip['id']), 'bm_ticker_action'); ?>" class="button button-small button-link-delete" onclick="return confirm('Delete this tip?');">Delete</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>

            <p style="margin-top:30px; color:#666; font-size:0.9em;">
                <strong>Tip for Excel VBA:</strong> You can read/write directly to the <code><?php echo esc_html($table); ?></code> table from your VBA code using standard MySQL/ODBC connection.
            </p>
        </div>
        <?php
    }
}
