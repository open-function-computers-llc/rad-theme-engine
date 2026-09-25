<?php

namespace ofc;

class EmailLogger
{
    public static function register(): void
    {
        self::registerAdminMenu();
        self::hookIntoWpMail();
    }

    private static function registerAdminMenu(): void
    {
        add_action('admin_menu', function () {
            add_menu_page(
                'Email Log',
                'Email Log',
                'manage_options',
                'rad-email-log',
                [self::class, 'renderAdminPage'],
                'dashicons-email-alt',
                80
            );
        });
    }

    private static function hookIntoWpMail(): void
    {
        add_action('wp_mail', function ($args) {
            wp_insert_post([
                'post_type'    => 'rad-email-log',
                'post_status'  => 'publish',
                'post_title'   => $args['subject'],
                'post_content' => $args['message'],
                'meta_input'   => [
                    'to_address' => is_array($args['to']) ? implode(', ', $args['to']) : $args['to'],
                    'headers'    => is_array($args['headers']) ? implode("\n", $args['headers']) : $args['headers'],
                ],
            ]);
            return $args;
        });
    }

    public static function renderAdminPage(): void
    {
        $posts = get_posts([
            'post_type'   => 'rad-email-log',
            'numberposts' => 50,
            'post_status' => 'publish',
            'orderby'     => 'date',
            'order'       => 'DESC',
        ]);

        echo '<div class="wrap">';
        echo '<h1>Email Log</h1>';

        if (empty($posts)) {
            echo '<p>No emails logged yet.</p></div>';
            return;
        }

        echo '<table class="widefat striped">';
        echo '<thead><tr><th>Date</th><th>To</th><th>Subject</th><th>Preview</th></tr></thead>';
        echo '<tbody>';
        foreach ($posts as $post) {
            $to = get_post_meta($post->ID, 'to_address', true);
            echo '<tr>';
            echo '<td>' . esc_html($post->post_date) . '</td>';
            echo '<td>' . esc_html($to) . '</td>';
            echo '<td>' . esc_html($post->post_title) . '</td>';
            echo '<td>' . esc_html(wp_trim_words($post->post_content, 10)) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table></div>';
    }
}
