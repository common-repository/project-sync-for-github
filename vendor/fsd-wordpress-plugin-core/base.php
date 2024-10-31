<?php

// This base lib uses a class to avoid collisions, rather than prefixes.
// Wordpress Fullstack Framework (WP_FSF).
if (!class_exists('WP_FSF')) {

    /**
     * Class WP_FSF.
     *
     * This class is used as a base plugin class for Fullstack Digital plugins.
     *
     * It handles basic responsibilities such as:
     *  - Initializing custom post types
     *  - Registering custom options
     *  - Setting up an admin UI page
     *  - Setting up custom post Metaboxes (in the edit-post view)
     */
    abstract class WP_FSF
    {
        //
        // PRIVATES.
        //
        private $CORE_LIBRARY_FOLDER_NAME = "fsd-wordpress-plugin-core";

        //
        // USER SETTINGS
        //
        protected $PLUGIN_FOLDER_NAME = "";     // Folder name of the plugin
        protected $PLUGIN_PREFIX = "";          // Prefix for this module
        protected $SETTINGS_STORE_NAME = "";    // Name of the settings database for this app (options table)
        protected $CUSTOM_POST_TYPE = "";       // Name of the custom post type to create for this app

        //
        // INTERNAL STATE
        //
        protected $taxonomies = array();                // Custom taxonomies to register
        protected $custom_form_nonce = "";              // Admin form nonce
        protected $custom_post_types = array();         // Custom post types to register
        protected $custom_post_fields = array();        // Custom post fields
        protected $custom_settings_fields = array();    // Custom settings fields
        protected $admin_menu_page = array();           // Admin menu
        protected $cron_settings = array();             // Cron job setup
        protected $meta_boxes = array();                // Configure metaboxes
        protected $admin_page_tabs = array();           // Tabs & their contents to show on the admin page for the plugin
        public static $cron = array();                  // Cron object that will be called externally.

        // Composition class. Adds API syncing capability.
        protected $ApiFetcher;

        //
        // Abstract functions that must be overridden by child classes.
        //
        abstract protected function add_extra_admin_scripts($hook);
        abstract public function run_cron();

        /**
         * WP_FSF constructor.
         *
         * Does all of the setup for the plugin. Children are expected
         * to call parent::__construct() to make sure this is run.
         *
         * @throws ReflectionException
         */
        public function __construct()
        {
            // Require other classes to override some fields
            if (empty($this->PLUGIN_PREFIX)) {
                FSD_LOG::ERROR("Child class must set its own PLUGIN_PREFIX.");
                return;
            }

            // Admin view hooks.
            add_action('admin_menu', array($this, 'add_admin_menu_page'));
            add_action("admin_init", array($this, "settings_init"));
            add_action('admin_enqueue_scripts', array($this, 'add_admin_scripts'));

            // Metabox customization hooks.
            if (isset($this->meta_boxes)) {
                add_action('load-post.php', array($this, 'meta_boxes_setup'));
                add_action('load-post-new.php', array($this, 'meta_boxes_setup'));
                add_filter('is_protected_meta', array($this, 'hide_custom_fields'), 10, 2);
            }

            foreach ($this->meta_boxes as $mb) {
                if (isset($mb["custom_position_callback"])) {
                    // If any metaboxes have a position override, use that and break out.
                    add_action('edit_form_after_title', $mb["custom_position_callback"]);
                    break;
                }
            }

            // Custom post hooks.
            add_action('init', array($this, 'register_custom_post_types'));
            add_action('init', array($this, 'register_custom_taxonomies'));
            add_action('wp_insert_post', array($this, 'register_custom_fields'), 10, 3);

            // Cron job & custom cron times.
            if (isset($this->cron_settings)) {
                add_action($this->cron_settings["name"], array($this, 'run_cron'));

                if (isset($this->cron_settings["custom_interval"])) {
                    add_filter('cron_schedules', array($this, 'custom_cron_interval'));
                }
            }

            // The activation hook has to path the file path of the actual wordpress plugin.
            // Use static::class to figure out the class that's being created from this base class,
            // and find its file path via a ReflectionClass.
            //
            // This allows us to put this activation hook code in the base class.
            $child_class_file_path = (new ReflectionClass(static::class))->getFileName();
            register_activation_hook($child_class_file_path, array(static::class, 'cron_activate'));
            register_deactivation_hook($child_class_file_path, array(static::class, 'cron_deactivate'));

            // See the description of uninstall() for details on the special context this runs in.
            register_uninstall_hook($child_class_file_path, array(static::class, 'uninstall'));
        }

        /**
         * Filters out certain custom fields from the post view.
         *
         * @param $protected
         * @param $meta_key
         * @return bool
         */
        public function hide_custom_fields($protected, $meta_key)
        {
            return $meta_key == $this->CUSTOM_POST_TYPE ? true : $protected;
        }

        /**
         * Plugin activation. Registers cron job.
         *
         * @return void
         */
        public static function cron_activate()
        {
            $cs = static::$cron;

            if (isset($cs)) {
                $name = $cs['name'];

                if (!wp_next_scheduled($name)) {
                    wp_schedule_event(time(), $cs["interval_name"], $name);
                }
            }
        }

        /**
         * Plugin deactivation. Unregisters cron job.
         *
         * @return void
         */
        public static function cron_deactivate()
        {
            if (isset($cs)) {
                wp_clear_scheduled_hook($cs['name']);
            }
        }

        /**
         * Responsible for uninstalling the plugin. This function uses class reflection
         * to invoke the uninstall method in the context of the singleton child that
         * invoked it. This effectively allows it to access 'this', which means we don't
         * have to hard code the side effects to clean up, and this code can also live in
         * the base library rather than having to be implemented in each derived class.
         *
         * @return void
         */
        public static function uninstall()
        {
            // Get the singleton instance of the child class using reflection. "static::"
            // allows us to get the static class that is actually being invoked at this time
            // (a child class since ours is abstract) and get its singleton. Then, we can call
            // all of its functions.
            //
            // So, conceptually, the child_class is "$this" now.
            $child_class = static::get_singleton();

            // Delete any extra taxonomies created by the plugin.
            $child_class->unregister_custom_taxonomies();

            // TODO: Remove sidebar menu page for taxonomy on uninstall?
            // remove_menu_page('edit-tags.php?taxonomy=post_tag');

            // We can't do anything if this is a failure, so just log it.
            FSD_LOG::IF_ERROR($child_class->settings_cleanup());

            // Clean up custom post type.
            FSD_LOG::IF_ERROR($child_class->delete_all_custom_posts());
            // FSD_LOG::IF_ERROR($child_class->unregister_custom_post_types());

            // We're removing our custom post type, so we should regenerate the url rewrite rules.
            flush_rewrite_rules();
        }

        /**
         * Responsible for configuring a custom cron interval.
         *
         * @param array $schedules : The existing cron schedules associative array.
         * @return array : The (modified) cron schedule array.
         */
        public function custom_cron_interval($schedules)
        {
            // Maybe there are no intervals being requested.
            if (!isset($this->cron_settings["custom_interval"])) {
                return $schedules;
            }

            $cs = $this->cron_settings["custom_interval"];

            $schedules[$cs['name']] = array(
                'interval' => $cs["seconds"],
                'display' => esc_html__($cs["description"]),
            );

            return $schedules;
        }

        /**
         * Responsible for registering configured taxonomies.
         *
         * @return void
         */
        public function register_custom_taxonomies()
        {
            foreach ($this->taxonomies as $new_taxonomy) {
                register_taxonomy(...$new_taxonomy);
            }
        }

        /**
         * Responsible for unregistering configured taxonomies. It does this by clearing
         * them on the given custom post type.
         *
         * @return void
         */
        public function unregister_custom_taxonomies()
        {
            foreach ($this->taxonomies as $new_taxonomy) {
                $post_tag = $new_taxonomy[0];
                register_taxonomy($post_tag, array());
            }
        }

        /**
         * Creates custom post types on behalf of this class.
         *
         * @return void
         */
        public function register_custom_post_types()
        {
            foreach ($this->custom_post_types as $new_custom_post_type) {
                FSD_LOG::IF_ERROR(register_post_type(...$new_custom_post_type),
                    "Failed to register custom post type.");
            }
        }

        /**
         * Deletes all custom post types registered by this class.
         *
         * @return bool|WP_Error : Returns True if succeeded, else WP_Error.
         */
        public function unregister_custom_post_types()
        {
            $succeeded = True;
            foreach ($this->custom_post_types as $new_custom_post_type) {
                $post_type_name = $new_custom_post_type[0];
                FSD_LOG::IF_ERROR(unregister_post_type($post_type_name),
                    "Failed to unregister post type: $post_type_name");
            }

            if (!$succeeded) {
                return new WP_Error(FSD_ERROR::UNREGISTER_CUSTOM_POST_FAILED, "Unregistering one or more custom posts failed");
            }

            return $succeeded;
        }

        /**
         * Deletes all project posts.
         *
         * @param bool $quiet : If true, no messages will be echo'd.
         * @return void
         */
        public function delete_all_custom_posts($quiet = true)
        {
            $args = array(
                'posts_per_page' => -1,
                'orderby' => 'post_date',
                'order' => 'DESC',
                'post_type' => $this->CUSTOM_POST_TYPE,
                'post_status' => Array("any")
            );

            // Get all existing posts.
            $posts = get_posts($args);
            foreach ($posts as $post) {
                if (!$quiet) {
                    echo "<br> Deleting project: " . $post->post_title;
                }

                FSD_LOG::IF_FALSE(wp_delete_post($post->ID),
                    "Failed to delete post $post->ID");
            }
        }

        /**
         * Render the Admin menu options.
         *
         * @return void
         */
        public function add_admin_menu_page()
        {
            $mp = $this->admin_menu_page;
            if (!isset($mp)) {
                return;
            }

            // Add a top level menu if specified. Otherwise, add a new menu to the
            // settings panel.
            if ($mp['top_level']) {
                add_menu_page(
                    $mp["page_title"],
                    $mp["menu_title"],
                    $mp["capabilities"],
                    $mp["menu_slug"],
                    $mp["render_function"],
                    $mp["icon_url"],
                    $mp["position"]
                );
            } else {
                add_options_page(
                    $mp["page_title"],
                    $mp["menu_title"],
                    $mp["capabilities"],
                    $mp["menu_slug"],
                    $mp["render_function"]
                );
            }
        }

        /**
         * Render the admin options page HTML, and any additional admin views that
         * a child class overrides.
         *
         * @return void
         */
        public function admin_page_html()
        {
            // Check user permissions
            if (!current_user_can('manage_options')) {
                return;
            }

            $active_tab = isset($_GET['tab']) ? $_GET['tab'] : $this->admin_page_tabs[0]['page_slug'];
            ?>

            <!-- Create a header in the default WordPress 'wrap' container -->
            <div class="wrap">

                <div id="icon-themes" class="icon32"></div>
                <h1><?= esc_html(get_admin_page_title()); ?></h1>
                <?php settings_errors(); ?>

                <h2 class="nav-tab-wrapper">
                    <?php foreach ($this->admin_page_tabs as $tab) { ?>
                        <a href="?page=<?php echo $this->PLUGIN_PREFIX ?>&tab=<?php echo $tab["page_slug"] ?>"
                           class="nav-tab <?php echo($active_tab == $tab["page_slug"] ? "nav-tab-active" : ''); ?>"><?php echo $tab["name"] ?></a>
                        <?php
                    } ?>
                </h2>

                <?php
                foreach ($this->admin_page_tabs as $tab) {
                    if (($active_tab == $tab["page_slug"]) && isset($tab['render_function'])) {
                        call_user_func($tab["render_function"]);
                    }
                }

                ?>
            </div><!-- /.wrap -->
            <?php
        }

        /**
         * Initializes the plugin settings object.
         *
         * @return void
         */
        public function settings_init()
        {
            register_setting($this->custom_settings_fields['option_group'], $this->custom_settings_fields['option_name']);

            $sections = $this->custom_settings_fields['sections'];
            foreach ($sections as $css) {
                add_settings_section($css['id'], $css['title'], $css['callback'], $css['page']);

                $settings_fields = $css["settings_fields"];
                foreach ($settings_fields as $sf) {
                    add_settings_field($sf['id'], $sf['title'], $sf['callback'], $sf['page'], $sf['section']);
                }
            }
        }

        /**
         * Destroys the plugin settings object.
         *
         * @return bool|WP_Error
         */
        public function settings_cleanup()
        {
            // Delete any database entries created for these options/settings.
            if (!delete_option($this->custom_settings_fields['option_group'])) {
                return new WP_Error(FSD_ERROR::OPTIONS_FIELD_NOT_FOUND,
                    "Failed to delete option during settings cleanup.",
                    array('group'=>$this->custom_settings_fields['option_group']));
            }

            unregister_setting($this->custom_settings_fields['option_group'], $this->custom_settings_fields['option_name']);

            return True;
        }

        /**
         * Includes admin page scripts and styles.
         *
         * @param string $hook : The current page being accessed in the admin panel.
         * @return void
         */
        public function add_admin_scripts($hook)
        {
            // Retrieve the current post.
            global $post;

            // Only load these resources on our custom post type's new/edit view.
            // There may not be any posts yet, be sure to only do this if there is a post.
            if ($post &&
                ($hook == 'post-new.php' || $hook == 'post.php' || $hook == 'edit.php') &&
                $this->CUSTOM_POST_TYPE === $post->post_type) {

                wp_enqueue_script($this->PLUGIN_PREFIX . '-jquery-validate', plugins_url("$this->CORE_LIBRARY_FOLDER_NAME/lib/js/jquery.validate.min.js", dirname(__FILE__)), array('jquery'));
                wp_enqueue_script($this->PLUGIN_PREFIX . '-post-validate', plugins_url("$this->CORE_LIBRARY_FOLDER_NAME/lib/js/post_validate.js", dirname(__FILE__)), array('jquery'));
                wp_enqueue_style($this->PLUGIN_PREFIX . '-post-style', plugins_url("$this->CORE_LIBRARY_FOLDER_NAME/lib/css/edit-custom-post.css", dirname(__FILE__)));
            }

            $this->add_extra_admin_scripts($hook);
        }

        /**
         * Sets up meta box functions.
         *
         * @return void
         */
        public function meta_boxes_setup()
        {
            if (isset($this->meta_boxes)) {
                add_action('add_meta_boxes', array($this, 'add_post_meta_boxes'));
                add_action('save_post', array($this, 'save_post_class_meta'), 10, 3);
            }
        }

        /**
         * Creates one or more meta boxes to be displayed on the post editor screen.
         *
         * @return void
         */
        public function add_post_meta_boxes()
        {
            foreach ($this->meta_boxes as $mb) {
                add_meta_box(
                    $mb['id'],
                    $mb['title'],
                    $mb['render_function'],
                    $mb['screens'],         // What post_type or admin pages to put this metabox on
                    $mb['position'],        // Custom location or well known position (normal, side, advanced, custom)
                    $mb['priority']
                );
            }
        }

        /**
         * Save the meta box's post metadata.
         *
         * @param $post_id : The ID of the post being operated on.
         * @param $post : The full post object being operated on.
         * @param $update : Whether or not the post is new, or simply being updated.
         *
         * @return void
         */
        public function save_post_class_meta($post_id, $post, $update)
        {
            // Make sure this is our custom post type.
            if ($post->post_type !== $this->CUSTOM_POST_TYPE) {
                return;
            }

            // Don't operate on posts that are not being updated or created.
            if ($post->post_status == 'trash' or $post->post_status == 'auto-draft') {
                return;
            }

            // Make sure it's not an auto save.
            if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE)
                return;

            // Check if the current user has permission to edit the post.
            $post_type = get_post_type_object($post->post_type);
            if (!current_user_can($post_type->cap->edit_post, $post_id)) {
                return;
            }

            // Verify the nonce before proceeding.
            if (!isset($_POST[$this->custom_form_nonce]) || !wp_verify_nonce($_POST[$this->custom_form_nonce], basename(__FILE__))) {
                FSD_LOG::ERROR(FSD_ERROR::INVALID_PARAMETER, "(Updated? $update) An invalid nonce was found: " . $_POST[$this->custom_form_nonce]);
                return;
            }

            // Extract known form arguments and set them on the custom post.
            foreach ($this->custom_post_fields as $cpf) {

                // There may not be a field name on some fields.
                if (!isset($cpf['field_name'])) {
                    continue;
                }

                $default_value = isset($cpf['default_value']) ? $cpf['default_value'] : "";
                $form_field = (isset($_POST[$cpf['field_name']]) ? $_POST[$cpf['field_name']] : $default_value);
                FSD_Utils::upsert_custom_field($post_id, $cpf['field_name'], $form_field);
            }

            remove_action('save_post', array($this, 'save_post_class_meta'), 10);

            // Update fields from the API. In practice, at runtime, the ApiFetcher will be present
            // on objects that implement this class.
            $this->ApiFetcher->sync_post_with_api($post_id);

            add_action('save_post', array($this, 'save_post_class_meta'), 10, 3);

            return;
        }

        /**
         * Adds default custom fields (and default values) to a new post for a custom
         * post type. This runs when the user creates a new post of our custom post type.
         *
         * This runs under the same hook as save_post_class_meta, but the difference is
         * that this function does setup when the post is being loaded, whereas the
         * save_post_class_meta function handles post submission/update.
         *
         * @param $post_id : The post ID for the post we're modifying.
         * @param $post : The post object that is being operated on.
         * @param $update : Whether or not the post is being updated at this time.
         *
         * @return void
         */
        public function register_custom_fields($post_id, $post, $update)
        {
            // Ignore this hook if it's an existing post.
            if ($update) {
                return;
            }

            // Ignore hook if it's not our custom post type.
            if ($post->post_type != $this->CUSTOM_POST_TYPE) {
                return;
            }

            // Create default fields/values for a new custom post.
            foreach ($this->custom_post_fields as $cpf) {
                // TODO: The only fields tha that have no field name are the type=html
                //       fields. Need to come up with a better solution for that, and remove
                //       this check.
                //
                // There may not be a field name on some fields.
                if (!isset($cpf['field_name'])) {
                    continue;
                }

                $default_value = isset($cpf['default_value']) ? $cpf['default_value'] : "";
                FSD_Utils::upsert_custom_field($post_id, $cpf["field_name"], $default_value);
            }

            return;
        }

        /**
         * Generate the custom metabox HTML for the topmost metabox.
         *
         * @param $post
         */
        function post_class_meta_box($post)
        {
            ?>
            <?php
            wp_nonce_field(basename(__FILE__), $this->custom_form_nonce);
            ?>
            <p>
                <?php
                foreach ($this->custom_post_fields as $cpf) {

                    if (!isset($cpf["form"])) {
                        continue;
                    }

                    $form = $cpf["form"];

                    if ($form["type"] == "html") {
                        echo $form['html'];
                    } elseif ($form["type"] == "checkbox") {
                        FSD_Utils::make_checkbox_field(
                            $post->ID,
                            $cpf["field_name"],
                            $form["label"],
                            $form["html_classes"]
                        );
                    } elseif ($form["type"] == "textarea") {
                        FSD_Utils::make_textarea_field(
                            $post->ID,
                            $cpf["field_name"],
                            $form["label"],
                            $form["placeholder"],
                            $form["html_classes"]
                        );
                    } else {
                        FSD_Utils::make_input_field(
                            $post->ID,
                            $cpf["field_name"],
                            $form["label"],
                            $form["placeholder"],
                            $form["html_classes"]
                        );
                    }
                }
                ?>
            </p>
            <?php
        }
    }
} // Include once protection.
?>