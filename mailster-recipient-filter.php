<?php
/*
Plugin Name: Mailster Lists Column
Plugin Description: Adds a lists column to the campaign overview with filtering options
Plugin URI: https://unkonvenitonell.at
Version: 1.0
Author: Fabian Wolf
Author URI: https://unkonvenitonell.at
*/

class MailsterListsColumn {

    public function __construct() {
        add_filter('manage_edit-newsletter_columns', array($this, 'add_lists_column'), 11);
        add_action('manage_newsletter_posts_custom_column', array($this, 'lists_column_content'), 10, 2);
        add_filter('manage_edit-newsletter_sortable_columns', array($this, 'make_lists_column_sortable'));
        add_action('pre_get_posts', array($this, 'lists_orderby'));
        add_action('admin_head', array($this, 'column_width_css'));
        
        // Add filter dropdown
        add_action('restrict_manage_posts', array($this, 'add_list_filter'));
        add_filter('parse_query', array($this, 'filter_campaigns_by_list'));
    }

    /**
     * Add CSS for column width and filter styling
     */
    public function column_width_css() {
        echo '<style>
            .fixed .column-newsletter_lists {
                width: 15%;
            }
            @media screen and (max-width: 1100px) {
                .fixed .column-newsletter_lists {
                    width: 12%;
                }
            }
            .mailster-list-filter {
                float: left;
                margin-right: 6px;
            }
            /* Fix double sorting indicators */
            .fixed .column-newsletter_lists a + span.sorting-indicators {
                display: none;
            }
        </style>';
    }

    /**
     * Add list filter dropdown
     */
    public function add_list_filter() {
        global $typenow, $wpdb;
        
        if($typenow != 'newsletter') return;
        
        $selected_list = isset($_GET['mailster_list']) ? intval($_GET['mailster_list']) : 0;
        $lists = mailster('lists')->get();
        
        // Get count of newsletters per list
        $nl_counts = array();
        foreach($lists as $list) {
            $count = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(DISTINCT post_id) 
                FROM {$wpdb->postmeta} 
                WHERE meta_key = '_mailster_lists' 
                AND meta_value LIKE %s",
                '%:"' . $list->ID . '";%'
            ));
            $nl_counts[$list->ID] = $count;
        }
        
        echo '<select name="mailster_list" class="mailster-list-filter">';
        echo '<option value="0">' . esc_html__('All Lists', 'mailster') . '</option>';
        echo '<option value="-1"' . selected($selected_list, -1, false) . '>' . esc_html__('No List', 'mailster') . '</option>';
        
        foreach($lists as $list) {
            $subscriber_count = mailster('lists')->get_member_count($list->ID);
            $newsletter_count = $nl_counts[$list->ID] ?? 0;
            echo '<option value="' . esc_attr($list->ID) . '" ' . selected($selected_list, $list->ID, false) . '>' 
                . esc_html($list->name) . sprintf(' (%d✉️ | %d👥)', $newsletter_count, $subscriber_count) . '</option>';
        }
        
        echo '</select>';
    }

    /**
     * Filter the campaigns based on selected list
     */
    public function filter_campaigns_by_list($query) {
        global $typenow;
        
        if(!is_admin() || $typenow != 'newsletter' || !$query->is_main_query()) {
            return $query;
        }
        
        if(!isset($_GET['mailster_list']) || !$_GET['mailster_list']) {
            return $query;
        }

        $list_id = intval($_GET['mailster_list']);
        
        // Handle "No List" filter
        if($list_id === -1) {
            $meta_query = array(
                'relation' => 'OR',
                array(
                    'key' => '_mailster_lists',
                    'value' => '',
                    'compare' => '='
                ),
                array(
                    'key' => '_mailster_lists',
                    'compare' => 'NOT EXISTS'
                )
            );
        } else {
            $meta_query = array(
                array(
                    'key' => '_mailster_lists',
                    'value' => sprintf(':"%d";', $list_id),
                    'compare' => 'LIKE'
                )
            );
        }
        
        $query->set('meta_query', $meta_query);
        
        return $query;
    }

    /**
     * Add new lists column to newsletter overview
     */
    public function add_lists_column($columns) {
        $new_columns = array();
        
        foreach($columns as $key => $value) {
            if($key == 'title') {
                $new_columns[$key] = $value;
                $new_columns['newsletter_lists'] = '<a href="' . admin_url('edit.php?post_type=newsletter&orderby=newsletter_lists&order=asc') . '">
                    <span>' . esc_html__('Lists', 'mailster') . '</span>
                    <span class="sorting-indicators">
                        <span class="sorting-indicator asc" aria-hidden="true"></span>
                        <span class="sorting-indicator desc" aria-hidden="true"></span>
                    </span>
                    <span class="screen-reader-text">' . esc_html__('Order Lists', 'mailster') . '</span>
                </a>';
            } else {
                $new_columns[$key] = $value; 
            }
        }
        
        return $new_columns;
    }

    /**
     * Make the lists column sortable
     */
    public function make_lists_column_sortable($columns) {
        $columns['newsletter_lists'] = array('newsletter_lists', true);
        return $columns;
    }

    /**
     * Handle sorting of lists column
     */
    public function lists_orderby($query) {
        if(!is_admin() || !$query->is_main_query() || $query->get('post_type') !== 'newsletter') {
            return;
        }

        $orderby = $query->get('orderby');

        if('newsletter_lists' == $orderby) {
            $query->set('meta_key', '_mailster_lists');
            $query->set('orderby', 'meta_value');
        }
    }

    /**
     * Display list content in the column
     */
    public function lists_column_content($column, $post_id) {
        if($column != 'newsletter_lists') return;

        if(!mailster('campaigns')->meta($post_id, 'ignore_lists')) {
            $lists = mailster('campaigns')->get_lists($post_id);
            
            if(empty($lists)) {
                echo '<em>' . esc_html__('No lists selected', 'mailster') . '</em>';
                return;
            }
            
            $list_names = array();
            foreach($lists as $list) {
                $list_names[] = '<a href="edit.php?post_type=newsletter&page=mailster_lists&ID=' . (int)$list->ID . '">' . 
                    esc_html($list->name) . '</a>';
            }
            
            echo implode(', ', $list_names);

        } else {
            echo '<em>' . esc_html__('All Lists', 'mailster') . '</em>';
        }
        
        // Add additional info for sent campaigns
        $post = get_post($post_id);
        if($post && in_array($post->post_status, array('finished', 'active'))) {
            $sent = mailster('campaigns')->get_sent($post_id);
            if($sent) {
                echo '<br><small>' . sprintf(
                    esc_html__('Sent to %s subscribers', 'mailster'),
                    number_format_i18n($sent)
                ) . '</small>';
            }
        }
    }

}

// Initialize the plugin
new MailsterListsColumn();