<?php

defined('ABSPATH') or die("you do not have acces to this page!");

if (!class_exists("cmplz_document")) {
    class cmplz_document
    {
        private static $_this;

        function __construct()
        {
            if (isset(self::$_this))
                wp_die(sprintf(__('%s is a singleton class and you cannot create a second instance.', 'complianz'), get_class($this)));

            self::$_this = $this;
            foreach (COMPLIANZ()->config->pages as $type => $page) {
                add_shortcode('cmplz-document', array($this, 'load_document'));
            }

            add_shortcode('cmplz-revoke-link', array($this, 'revoke_link'));

            //clear shortcode transients after post update
            add_action('save_post', array($this, 'clear_shortcode_transients'), 10, 1);
            //add_action('publish_post', array($this, 'post_published_notification'), 10, 2);
            add_action('admin_init', array($this, 'add_privacy_policy_to_wp_page'));
            add_filter('wp_get_default_privacy_policy_content', array($this, 'remove_default_wp_content'));

            // Update the cached policy info when the policy page is updated.
            //add_action( 'cmplz_wp_privacy_policies', array( 'WP_Privacy_Policy_Content', '_policy_page_updated' ) );
            add_action('cmplz_wp_privacy_policies', array($this, 'wp_privacy_policies'));
            add_action('admin_head', array($this, 'hide_privacy_policy_intro'));

            add_action('cmplz_wizard_add_pages_to_menu',array($this, 'wizard_add_pages_to_menu'), 10, 1);
            add_action('admin_init', array($this, 'assign_documents_to_menu'));
        }

        static function this()
        {
            return self::$_this;
        }

        public function wizard_add_pages_to_menu(){
            //get list of menus
            $locations = get_theme_mod('nav_menu_locations');

            $link = '<a href="'.admin_url('nav-menus.php').'">';
            if (!$locations) {
                cmplz_notice(sprintf(__("No menus were found. Skip this step, or %screate a menu%s first."), $link, '</a>'));
                return;
            }

            if (!$this->all_pages_in_menu()){
                cmplz_notice(__("Not all your generated documents have been assigned to a menu yet, you can do this now, or skip this step and do it later.", 'copmlianz'));
            } else{
                _e("Great! All your generated documents have been assigned to a menu, so you can skip this step.", 'copmlianz');
            }
            $menus = array();
            //search in menus for the current post
            foreach($locations as $location => $menu_id) {
                if(has_nav_menu($location) ){
                    $menus[$location] = wp_get_nav_menu_name($location);
                }
            }
            $pages = $this->get_required_pages();
            echo '<table>';
            foreach ($pages as $page_id){
                echo "<tr><td>";
                echo get_the_title($page_id);
                echo "</td><td>";
                ?>

                <select name="cmplz_assigned_menu[<?php echo $page_id?>]">
                    <option value=""><?php _e("Select a menu", "complianz");?></option>
                    <?php foreach($menus as $location => $menu){
                        $selected = ($this->is_assigned_this_menu($page_id, $location)) ? "selected" : "";
                       echo '<option '.$selected.' value="'.$location.'">'.$menu.'</option>';
                    }?>

                </select>
                <?php
                echo "</td></tr>";
            }
            echo "</table>";


        }

        public function assign_documents_to_menu(){
            if (isset($_POST['cmplz_assigned_menu'])){
                foreach($_POST['cmplz_assigned_menu'] as $page_id => $location){
                    if (empty($location)) continue;
                    if ($this->is_assigned_this_menu($page_id, $location)) continue;

                    $page = get_post($page_id);
                    $menu_id = $this->get_menu_id_by_location($location);
                    wp_update_nav_menu_item($menu_id, 0, array(
                        'menu-item-title' => get_the_title($page),
                        'menu-item-object-id' => $page->ID,
                        'menu-item-object' => get_post_type($page),
                        'menu-item-status' => 'publish',
                        'menu-item-type' => 'post_type',
                    ));
                }
            }
        }

        /*
         * Check if all pages are located in a menu.
         *
         *
         * */

        public function all_pages_in_menu(){
            $locations = get_theme_mod('nav_menu_locations');

            if (!$locations) return false;

            //search in menus for the current post

            $pages = $this->get_required_pages();
            $pages_in_menu = array();


            foreach($locations as $location => $menu_id) {
                if(has_nav_menu($location) ){
                    $menu_items = wp_get_nav_menu_items($menu_id);
                    foreach ($menu_items as $post){
                        if (in_array($post->object_id, $pages)){
                            $pages_in_menu[] = $post->object_id;
                        }
                    }
                }
            }
            if (count($pages)>count($pages_in_menu)) return false;
            return true;
        }

        public function get_menu_id_by_location($location){
            $theme_locations = get_nav_menu_locations();
            $menu_obj = get_term( $theme_locations[$location], 'nav_menu' );
            if (!$menu_obj) return false;
            return $menu_obj->term_id;
        }

        public function is_assigned_this_menu($page_id, $location){
            $locations = get_theme_mod('nav_menu_locations');

            if (!$locations) return false;

            foreach($locations as $location_key => $menu_id) {
                if ($location_key!==$location) continue;
                if (has_nav_menu($location_key)) {

                    if (has_nav_menu($location_key)) {
                        $menu_items = wp_get_nav_menu_items($menu_id);

                        foreach ($menu_items as $post) {
                            if ($post->object_id == $page_id) return true;
                        }
                    }
                }
            }

            return false;
        }

        public function get_required_pages(){
            $required_pages = COMPLIANZ()->config->pages;
            $pages = array();

            foreach ($required_pages as $type => $page) {
                if (!$page['public']) continue;

                if (COMPLIANZ()->document->page_required($page)) {
                    $pages[] = $this->get_shortcode_page_id($type);
                }
            }
            return $pages;
        }

        public function revoke_link($atts = [], $content = null, $tag = '')
        {

            // normalize attribute keys, lowercase
            $atts = array_change_key_case((array)$atts, CASE_LOWER);

            ob_start();

            // override default attributes with user attributes
            $atts = shortcode_atts(['text' => false,], $atts, $tag);

            echo cmplz_revoke_link($atts['text']);

            return ob_get_clean();

        }

        public function wp_privacy_policies()
        {
            global $wp_version;
            if (version_compare($wp_version, '4.9.6', '<')) {
                echo '<p>' . __('As of WordPress 4.9.6, plugins and themes can add their own suggested statements about cookie usage and privacy here. To use this functionality, please upgrade to the latest WordPress version.', 'complianz') . '</p>';
                return;
            }

            //to be able to load the privacy statements from plugins, we need to have a page selected.
            if ($this->page_required("privacy-statement")) {
                $this->create_page('privacy-statement');
            }

            if ( ! class_exists( 'WP_Privacy_Policy_Content' ) ) {
                require_once( ABSPATH . 'wp-admin/includes/misc.php' );
            }

            $data = $this->get_wp_privacy_policy_data();

            $found_policy = false;
            foreach ($data as $policy) {
                if (isset($policy['removed'])) continue;
                if ($policy['plugin_name'] == 'Complianz') continue;
                $found_policy = true;
                $s_plugin_name = sanitize_title($policy['plugin_name']);
                ?>
                <div class="cmplz-custom-privacy-text-container">
                    <h2 class="cmplz-custom-privacy-header"><?php echo $s_plugin_name ?></h2>
                    <p class="cmplz-custom-privacy-text"><?php echo $policy['policy_text'] ?></p>
                    <button class="button cmplz-add-to-policy" type="button"><?php printf(__('Add "%s" text to privacy policy', 'complianz'), $s_plugin_name) ?></button>
                </div>
                <?php
            }


            if (!$this->page_required('privacy-statement')){
                echo '<p>' . __("You did not choose to create a privacy statement through Complianz GDPR, so you can skip this step.", 'complianz') . '</p>';
            }

            if (!$found_policy) {
                echo '<p>' . __('No plugins with suggested statements found.', 'complianz') . '</p>';
            }

        }

        public function hide_privacy_policy_intro()
        {
            ?>
            <script>
                jQuery(document).ready(function ($) {
                    if ($('.privacy-text-box-head').length) {
                        $('.privacy-text-box-head').html('<h2><?php _e('Introduction', 'complianz')?></h2><p><?php _e('Adjusted intro', 'complianz')?></p>');
                    }

                });

            </script>
            <?php

        }

        public function remove_default_wp_content($content)
        {
            $update_policy_data = false;
            $policy_page_id = (int)get_option('wp_page_for_privacy_policy');
            $checked = get_post_meta($policy_page_id, '_wp_suggested_privacy_policy_content');
            if ($checked) {
                foreach ($checked as $key => $data) {
                    if ($data['plugin_name'] == 'WordPress') {
                        $update_policy_data = true;
                        unset($checked[$key]);
                    }
                }
            }

            if ($update_policy_data) {
                delete_post_meta($policy_page_id, '_wp_suggested_privacy_policy_content');
                foreach ($checked as $data) {
                    add_post_meta($policy_page_id, '_wp_suggested_privacy_policy_content', $data);
                }
            }

            return '';
        }

        public function get_wp_privacy_policy_data()
        {
            if ( ! class_exists( 'WP_Privacy_Policy_Content' ) ) {
                require_once( ABSPATH . 'wp-admin/includes/misc.php' );
            }
            //if there's no privacy policy page yet, create one.  We can always delete it later on.
            $this->create_page('privacy-statement');

//            $policy_page_id = (int)get_option('wp_page_for_privacy_policy');
//            $data = get_post_meta($policy_page_id, '_wp_suggested_privacy_policy_content');
            $data = WP_Privacy_Policy_Content::get_suggested_policy_text();

            return $data;
        }


        public function add_privacy_policy_to_wp_page()
        {

            if (!function_exists('wp_add_privacy_policy_content')) {
                return;
            }

            $html = $this->get_document_html('privacy-statement');

            wp_add_privacy_policy_content(
                __('Complianz', 'complianz'),
                wp_kses_post(wpautop($html, false))
            );
        }

        /*
         * Check if a page is required. If no condition is set, return true.
         *
         * */

        public function page_required($page)
        {
            if (!isset($page['condition'])) return true;

            if (isset($page['condition'])) {
                $conditions = $page['condition'];
                $condition_answer = reset($conditions);
                $condition_question = key($conditions);
                if (COMPLIANZ()->field->get_value($condition_question) == $condition_answer) {
                    return true;
                }
            }

            return false;

        }


        public function create_page($type)
        {
            $pages = $page_titles = COMPLIANZ()->config->pages;

            if (!isset($pages[$type])) return false;

            //only insert if there is no shortcode page of this type yet.
            $page_id = $this->get_shortcode_page_id($type);
            if (!$page_id) {

                $page = $pages[$type];

                $page = array(
                    'post_title' => $page['title'],
                    'post_type' => "page",
                    'post_content' => '[' . $this->get_shortcode($type) . ']',
                    'post_status' => 'publish',
                );

                // Insert the post into the database
                $page_id = wp_insert_post($page);
            }

            /*
             * Set default privacy page for WP
             *
             * */

            if ($type == 'privacy-statement') {
                update_option('wp_page_for_privacy_policy', $page_id);
            }

        }

        public function delete_page($type)
        {
            $page_id = $this->get_shortcode_page_id($type);
            if ($page_id) {
                wp_delete_post($page_id, false);
            }
        }

        public function page_exists($type)
        {
            if ($this->get_shortcode_page_id($type)) return true;

            return false;
        }


        /*

          Genereate the editor html on a page with the shortcode.

        */

        public function get_shortcode($type)
        {
            return 'cmplz-document type="' . $type . '"';
        }

        public function insert_element($element, $post_id)
        {

            if (isset($element['condition'])) {
                $fields = COMPLIANZ()->config->fields();

                foreach ($element['condition'] as $question => $condition_answer) {
                    if ($condition_answer == 'loop') continue;
                    if (!isset($fields[$question]['type'])) return false;

                    $type = $fields[$question]['type'];
                    $value = cmplz_get_value($question, $post_id);

                    if ($type == 'multicheckbox') {
                        if (!isset($value[$condition_answer]) || !$value[$condition_answer]) {
                            return false;
                        }
                    } else {
                        if ($value != $condition_answer) {
                            return false;
                        }
                    }

                }

            }

            if (isset($element['callback_condition'])) {
                $func = $element['callback_condition'];
                $show_field = $func();
                if (!$show_field) return false;
            }
            return true;
        }


        /*
         * Check if this element should loop through dynamic multiple values
         *
         *
         * */

        public function is_loop_element($element)
        {
            if (isset($element['condition'])) {
                foreach ($element['condition'] as $question => $condition_answer) {
                    if ($condition_answer == 'loop') return true;
                }
            }

            return false;
        }

        public function load_document($atts = [], $content = null, $tag = '')
        {

            // normalize attribute keys, lowercase
            $atts = array_change_key_case((array)$atts, CASE_LOWER);

            ob_start();

            // override default attributes with user attributes
            $atts = shortcode_atts(['type' => false,], $atts, $tag);
            $type = $atts['type'];
            if ($type) {

                echo $this->get_document_html($type);
            }

            return ob_get_clean();
        }


        public function get_document_html($type, $post_id = false)
        {
            $html = get_transient("complianz_document_$type");
            if (defined('WP_DEBUG') && WP_DEBUG) $html = false;

            //do not cache for these types
            if (($type === 'processing') || ($type === 'dataleak')) $html = false;

            if (!$html) {
                if (!isset(COMPLIANZ()->config->document_elements[$type])) return "";

                $elements = COMPLIANZ()->config->document_elements[$type];

                $html = "";
                $paragraph = 0;
                $sub_paragraph = 0;
                $annex = 0;
                $annex_arr = array();
                $paragraph_id_arr = array();
                foreach ($elements as $id => $element) {
                    //count paragraphs
                    if ($this->insert_element($element, $post_id) || $this->is_loop_element($element)) {

                        if (isset($element['title']) && (!isset($element['numbering']) || $element['numbering'])) {
                            $sub_paragraph = 0;
                            $paragraph++;
                            $paragraph_id_arr[$id]['main'] = $paragraph;
                        }

                        //count subparagraphs
                        if (isset($element['subtitle']) && $paragraph > 0 && (!isset($element['numbering']) || $element['numbering'])) {
                            $sub_paragraph++;
                            $paragraph_id_arr[$id]['main'] = $paragraph;
                            $paragraph_id_arr[$id]['sub'] = $sub_paragraph;
                        }

                        //count annexes
                        if (isset($element['annex'])) {
                            $annex++;
                            $annex_arr[$id] = $annex;
                        }
                    }

                    if ($this->is_loop_element($element) && $this->insert_element($element, $post_id)) {
                        $fieldname = key($element['condition']);
                        $values = cmplz_get_value($fieldname, $post_id);
                        $loop_content = '';
                        if (!empty($values)) {
                            foreach ($values as $value) {
                                //line specific for cookies, to hide or show conditionally
                                if (isset($value['show']) && $value['show'] !== 'on') continue;

                                if (!is_array($value)) $value = array($value);
                                $fieldnames = array_keys($value);
                                if (count($fieldnames) == 1 && $fieldnames[0] == 'key') continue;

                                $loop_section = $element['content'];
                                foreach ($fieldnames as $fieldname) {

                                    $field_value = (isset($value[$fieldname])) ? $value[$fieldname] : '';

                                    if (!empty($field_value) && is_array($field_value)) $field_value = implode(', ', $field_value);
                                    $loop_section = str_replace('[' . $fieldname . ']', $field_value, $loop_section);
                                }

                                $loop_content .= $loop_section;

                            }
                            $html .= $this->wrap_header($element, $paragraph, $sub_paragraph, $annex);
                            $html .= $this->wrap_content($loop_content);
                        }
                    } elseif ($id === 'wp_privacy_policies') {

                        $policies = $this->get_wp_privacy_policy_data();
                        $stored_policies = cmplz_get_value('wp_privacy_policies');
                        $policy_html = "";
                        $added_by_user = false;
                        foreach ($policies as $policy) {
                            if (isset($policy['removed'])) continue;
                            $s_plugin_name = sanitize_title($policy['plugin_name']);
                            $added_by_user = (isset($stored_policies[$s_plugin_name]) && $stored_policies[$s_plugin_name] == 'on') ? true : false;
                            if ($added_by_user) {
                                $policy_html .= $this->wrap_sub_header($policy['plugin_name'], $paragraph, $sub_paragraph);
                                $policy_html .= $this->wrap_content($policy['policy_text']);
                            }
                        }

                        //if at least one wp policy is added, added by user is true, so add the header as well.
                        if ($added_by_user) {
                            $html .= $this->wrap_header($element, $paragraph, $sub_paragraph, $annex);
                            $html .= $this->wrap_content($element['content']);
                            $html .= $policy_html;
                        }

                    } elseif ($this->insert_element($element, $post_id)) {
                        $html .= $this->wrap_header($element, $paragraph, $sub_paragraph, $annex);
                        if (isset($element['content'])) {
                            $html .= $this->wrap_content($element['content']);
                        }
                    }
                }

                $html = $this->replace_fields($html, $paragraph_id_arr, $annex_arr, $post_id);

                //do not cache for these types
                if (($type !== 'processing') && ($type !== 'dataleak')) {
                    set_transient("complianz_document_$type", $html, WEEK_IN_SECONDS);
                }
            }

            return $html;
        }

        public function wrap_header($element, $paragraph, $sub_paragraph, $annex)
        {
            $nr = "";

            if (isset($element['annex'])) {
                $nr = __("Annex", "complianz") . " " . $annex . ": ";
                if (isset($element['title'])) {
                    return '<h3><b>' . $nr . $element['title'] . '</b></h3>';
                }
                if (isset($element['subtitle'])) {
                    return '<h4><b>' . $nr . $element['subtitle'] . '</b></h4>';
                }
            }

            if (isset($element['title'])) {
                if (empty($element['title'])) return "";
                if ($paragraph > 0 && $this->is_numbered_element($element)) $nr = $paragraph;
                return '<h3>' . $nr . ' ' . $element['title'] . '</h3>';
            }

            if (isset($element['subtitle'])) {
                if ($paragraph > 0 && $sub_paragraph > 0 && $this->is_numbered_element($element)) $nr = $paragraph . "." . $sub_paragraph . " ";
                return '<h4><b>' . $nr . $element['subtitle'] . '</b></h4>';
            }


        }

        public function is_numbered_element($element)
        {

            if (!isset($element['numbering'])) return true;

            return $element['numbering'];
        }

        public function wrap_sub_header($header, $paragraph, $subparagraph)
        {
            if (empty($header)) return "";
            return '<h4>' . $header . '</h4>';
        }

        public function wrap_content($content)
        {
            if (empty($content)) return "";
            return '<p>' . $content . '</p>';
        }


        /*
         * Replace all fields in the resulting output
         *
         *
         * */


        private function replace_fields($html, $paragraph_id_arr, $annex_arr, $post_id)
        {
            //replace references
            foreach ($paragraph_id_arr as $id => $paragraph) {
                $html = str_replace("[article-$id]", sprintf(__('(See paragraph %s)', 'complianz'), $paragraph['main']), $html);
            }

            foreach ($annex_arr as $id => $annex) {
                $html = str_replace("[annex-$id]", sprintf(__('(See annex %s)', 'complianz'), $annex), $html);
            }

            //some custom elements
            $html = str_replace("[domain]", get_site_url(), $html);
            $html = str_replace("[cookie_policy_url]", COMPLIANZ()->cookie->get_cookie_statement_page(), $html);

            $date = $post_id ? get_the_date('', $post_id) : date(get_option('date_format'), time());
            $date = cmplz_localize_date($date);
            $html = str_replace("[publish_date]", $date, $html);

            //replace all fields.
            foreach (COMPLIANZ()->config->fields() as $fieldname => $field) {
                if (strpos($html, "[$fieldname]") !== FALSE) {
                    $html = str_replace("[$fieldname]", $this->get_plain_text_value($fieldname, $post_id), $html);
                    //when there's a closing shortcode it's always a link
                    $html = str_replace("[/$fieldname]", "</a>", $html);
                }

                if (strpos($html, "[comma_$fieldname]") !== FALSE) {
                    $html = str_replace("[comma_$fieldname]", $this->get_plain_text_value($fieldname, $post_id, false), $html);
                }
            }

            return $html;

        }



        private function get_plain_text_value($fieldname, $post_id, $list_style = true)
        {
            $value = cmplz_get_value($fieldname, $post_id);
            if (COMPLIANZ()->config->fields[$fieldname]['type'] == 'url') {
                $value = '<a href="' . $value . '" target="_blank">';
            } elseif (COMPLIANZ()->config->fields[$fieldname]['type'] == 'radio') {
                $options = COMPLIANZ()->config->fields[$fieldname]['options'];
                $value = isset($options[$value]) ? $options[$value] : '';
            } elseif (is_array($value)) {
                $options = COMPLIANZ()->config->fields[$fieldname]['options'];
                //array('3' => 1 );
                $value = array_filter($value, function ($item) {
                    return $item == 1;
                });
                $value = array_keys($value);
                //array (1, 4, 6)
                $labels = "";
                foreach ($value as $index) {
                    if ($list_style)
                        $labels .= "<li>" . $options[$index] . '</li>';
                    else
                        $labels .= $options[$index] . ', ';
                }

                if ($list_style) {
                    $labels = "<ul>" . $labels . "</ul>";
                } else {
                    $labels = rtrim($labels, ', ');
                    $labels = strrev(implode(strrev(', ' . __('and', 'complianz')), explode(strrev(','), strrev($labels), 2)));
                }

                $value = $labels;
            } else {
                if (isset(COMPLIANZ()->config->fields[$fieldname]['options'])) {
                    $options = COMPLIANZ()->config->fields[$fieldname]['options'];
                    if (isset($options[$value])) $value = $options[$value];
                }
            }

            return $value;
        }


        /*
          checks if the current page contains the shortcode.
        */

        public function is_shortcode_page($shortcode)
        {
            global $post;
            if ($post) {
                if (has_shortcode($post->post_content, $shortcode)) return true;
            }

            return false;
        }

        /*
          gets the  page that contains the shortcode.
        */

        public function get_shortcode_page_id($type)
        {
            $shortcode = 'cmplz-document';

            delete_transient('cmplz_shortcode_' . $type);
            $page_id = get_transient('cmplz_shortcode_' . $type);

            if (!$page_id) {
                $pages = get_pages();
                foreach ($pages as $page) {
                    if (has_shortcode($page->post_content, $shortcode) && strpos($page->post_content, 'type="' . $type)) {
                        set_transient('cmplz_shortcode_' . $type, $page->ID, DAY_IN_SECONDS);
                        return $page->ID;
                    }
                }
            } else {
                return $page_id;
            }
            return false;
        }


        /*
         *
         *
         * clear shortcode transients after page update */

        public function clear_shortcode_transients($post_id, $post = false)
        {
            $pages = COMPLIANZ()->config->pages;
            foreach ($pages as $type => $page) {
                delete_transient('cmplz_shortcode_' . $type);
            }
        }


    }
} //class closure