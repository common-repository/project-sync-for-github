<?php

/**
 * @package git_get
 * @version 0.3.8
 */
/*
Plugin Name: Project Sync for Github
Plugin URI: https://fullstackdigital.com/labs/github-project-sync-wordpress-plugin/
Bitbucket Plugin URI: https://bitbucket.org/fullstackdigital/gitget-wordpress
Description: Synchronizes GitHub repositories as custom posts and provides convenient shortcodes and widgets to access the data.
Version: 0.3.8
Author: Fullstack Digital
Author URI: http://fullstackdigital.com

    Copyright 2017 FullStack Digital

    This program is free software; you can redistribute it and/or modify
    it under the terms of the GNU General Public License, version 2, as
    published by the Free Software Foundation.

    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU General Public License
    along with this program; if not, write to the Free Software
    Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
*/

require __DIR__ . '/vendor/autoload.php';

class WP_GitGet extends WP_FSF
{
    // Static property to hold our singleton instance.
    protected static $instance = false;

    //
    // COMPOSITION CLASSES.
    //
    public $ApiFetcher;
    private $Shortcodes;

    /**
     * If an instance exists, this returns it.  If not, it creates one and
     * returns it.
     *
     * @return WP_GitGet
     * @throws ReflectionException
     */
    public static function get_singleton()
    {
        if (!self::$instance) {
            self::$instance = new self;
        }
        return self::$instance;
    }

    /**
     * WP_GitGet constructor.
     *
     * @throws ReflectionException
     */
    public function __construct()
    {
        //
        // Module settings.
        //
        $this->PLUGIN_FOLDER_NAME = "project-sync-for-github";
        $this->PLUGIN_PREFIX = "gitget";
        $this->SETTINGS_STORE_NAME = $this->PLUGIN_PREFIX . '_settings';
        $this->CUSTOM_POST_TYPE = $this->PLUGIN_PREFIX . '_project';

        // TODO: Remove these one glorious day..
        $API_USER_FIELD = "api_username";
        $API_KEY_FIELD = "api_key";
        $EMAIL_FAILURES_FIELD = "email_failures";

        //
        // Register an admin menu page for this app.
        //
        $this->admin_menu_page = array(
            "top_level" => false,
            "page_title" => "Project Sync for Github Settings",
            "menu_title" => "Project Sync for Github",
            "capabilities" => "manage_options",
            "menu_slug" => $this->PLUGIN_PREFIX,
            "render_function" => array($this, 'admin_page_html'),
            "icon_url" => 'dashicons-list-view',
            "position" => 20,
        );

        //
        // The admin page is composed of these tabs.
        //
        $this->admin_page_tabs = array(
            array(
                'name' => 'Manage Projects',
                'page_slug' => 'recent_activity',
                'render_function' => array($this, 'admin_manage_projects'),
            ),
            array(
                'name' => 'API Settings',
                'page_slug' => 'api_settings',
                'render_function' => function () {
                    ?>
                    <form action="options.php" method="post">
                        <?php
                        settings_fields($this->SETTINGS_STORE_NAME);
                        do_settings_sections($this->PLUGIN_PREFIX);
                        submit_button('Save Settings');
                        ?>
                    </form>
                    <?php

                },
            ),
            array(
                'name' => 'Support',
                'page_slug' => 'about_this_plugin',
                'render_function' => array($this, 'admin_about_this_plugin'),
            ),
        );

        //
        // Set up module settings/options.
        //
        $section_name = $this->PLUGIN_PREFIX . '_pluginPage_section';
        $this->custom_settings_fields = array(
            "option_group" => $this->SETTINGS_STORE_NAME,
            "option_name" => $this->SETTINGS_STORE_NAME,
            "sections" => array(
                array(
                    "id" => $section_name,
                    "title" => '',
                    "page" => $this->PLUGIN_PREFIX,
                    "callback" => function () {
                        echo '<p>Please provide a GitHub email/key pair to raise the rate limit (default 60 updates/hr).</p>';
                    },
                    "settings_fields" => array(
                        array(
                            "id" => $EMAIL_FAILURES_FIELD,
                            "title" => "Email when sync fails",
                            "page" => $this->PLUGIN_PREFIX,
                            "section" => $section_name,
                            "callback" => function () use ($EMAIL_FAILURES_FIELD) {
                                $options = get_option($this->SETTINGS_STORE_NAME);

                                if (isset($options[$EMAIL_FAILURES_FIELD])) {
                                    $checked = 'checked';
                                } else {
                                    $checked = '';
                                }

                                ?>
                                <input type='checkbox' title='Email about sync failures?'
                                       name='<?php echo $this->SETTINGS_STORE_NAME . "[" . $EMAIL_FAILURES_FIELD . "]"; ?>'
                                       <?php echo $checked; ?>
                                >
                                <?php
                            },
                        ),
                        array(
                            "id" => $API_USER_FIELD,
                            "title" => "Github username",
                            "page" => $this->PLUGIN_PREFIX,
                            "section" => $section_name,
                            "callback" => function () use ($API_USER_FIELD) {
                                $options = get_option($this->SETTINGS_STORE_NAME);

                                ?>
                                <input type='text' title='Api Username'
                                       name='<?php echo $this->SETTINGS_STORE_NAME . "[" . $API_USER_FIELD . "]"; ?>'
                                       value='<?php echo $options[$API_USER_FIELD]; ?>'>
                                <?php
                            },
                        ),
                        array(
                            "id" => $API_KEY_FIELD,
                            "title" => "Github API key",
                            "page" => $this->PLUGIN_PREFIX,
                            "section" => $section_name,
                            "callback" => function () use ($API_KEY_FIELD) {
                                $options = get_option($this->SETTINGS_STORE_NAME);
                                ?>
                                <input type='text' title='Api Key'
                                       name='<?php echo $this->SETTINGS_STORE_NAME . "[" . $API_KEY_FIELD . "]"; ?>'
                                       value='<?php echo $options[$API_KEY_FIELD]; ?>'>
                                <?php

                            }
                        ),
                    ),
                ),
            )
        );

        //
        // Custom post fields and form settings.
        //
        $this->custom_form_nonce = $this->PLUGIN_PREFIX . "_url_nonce";
        $this->custom_post_fields = array(

            //
            // These fields will automatically appear in the edit post page as a metabox.
            //
            array(
                // Name of the field in the custom post metadata.
                "field_name" => "github_url",
                // If no value is provided, use this one.
                "default_value" => "",
                // This is populated via a form in the custom post's edit view.
                "form" => array(
                    // Field label that's visible to users.
                    "label" => "Git Repo (Required)",
                    // Textbox placeholder.
                    "placeholder" => "Ex: https://github.com/facebook/react-native",
                    // This should be an html inputbox.
                    "type" => "input",
                    // Classes to set on the textbox.
                    "html_classes" => "pap_validate validate_github_url validate_oneof gitget_padded widefat"
                ),
                // Designates that this is the field that should be used for API requests to refresh this data.
                "API_URL_FIELD" => true,
            ),
            array(
                "field_name" => "website",
                "default_value" => "",
                "form" => array(
                    "label" => "Project Website",
                    "placeholder" => "Ex: https://www.react.io",
                    "type" => "input",
                    "html_classes" => "pap_validate validate_url validate_oneof gitget_padded widefat",
                )
            ),
            array(
                "form" => array(
                    "type" => "html",
                    "html" => "<div class='fsd_outline_div' style='padding-bottom:8px'>",
                )
            ),
            array(
                "field_name" => "gradient_start",
                "default_value" => "#ffffff",
                "form" => array(
                    "label" => "Gradient Start",
                    "placeholder" => "#ffffff",
                    "type" => "input",
                    "html_classes" => "color-picker gitget_padded fsd_hidden_input",
                )
            ),
            array(
                "field_name" => "gradient_end",
                "default_value" => "#ffffff",
                "form" => array(
                    "label" => "Gradient End",
                    "placeholder" => "#ffffff",
                    "type" => "input",
                    "html_classes" => "color-picker gitget_padded fsd_hidden_input",
                )
            ),
            array(
                "field_name" => "featured",
                "default_value" => 0,
                "form" => array(
                    "label" => "Featured",
                    "type" => "checkbox",
                    "html_classes" => "gitget_padded"
                )
            ),
            array(
                "field_name" => "archive",
                "default_value" => 0,
                "form" => array(
                    "label" => "Archive",
                    "type" => "checkbox",
                    "html_classes" => "gitget_padded"
                )
            ),
            array(
                "form" => array(
                    "type" => "html",
                    "html" => "</div>",
                )
            ),
            array(
                "form2" => array(
                    "type" => "html",
                    "html" => "<div class='fsd_outline_div'>",
                )
            ),
            array(
                "field_name" => "override_description",
                "default_value" => 0,
                "form2" => array(
                    "label" => "Override Description",
                    "type" => "checkbox",
                    "html_classes" => "gitget_padded"
                )
            ),
            array(
                "field_name" => "override_readme",
                "default_value" => 0,
                "form2" => array(
                    "label" => "Override Readme",
                    "type" => "checkbox",
                    "html_classes" => "gitget_padded"
                )
            ),
            array(
                "form2" => array(
                    "type" => "html",
                    "html" => "</div>",
                )
            ),
            array(
                "field_name" => "description",
                "default_value" => "",
                "form2" => array(
                    "label" => "Github Description",
                    "placeholder" => "Please save this post to sync the description, or select 'Override Description' to use your own.",
                    "type" => "textarea",
                    "html_classes" => "fsd_textarea_shadow wp-editor-area valid gitget_padded widefat",
                )
            ),
            array(
                "field_name" => "readme",
                "default_value" => "",
                "form2" => array(
                    "label" => "Github Readme",
                    "placeholder" => "Please save this post to sync the readme, or select 'Override Readme' to use your own.",
                    "type" => "textarea",
                    "html_classes" => "fsd_textarea_shadow wp-editor-area valid gitget_readme gitget_padded widefat",
                )
            ),

            //
            // API External Fields. When a request to the API url is made, each of these fields is
            // extracted if present. If they are not present, the default value is used.
            //
            // To extract a nested field, use this syntax:
            //   "api" => array(
            //     "field_name" => "statistics:subscribers:count"
            //   ),
            //
            array(
                "field_name" => "github_id",    // Metafield name
                "default_value" => 0,           // Default value
                "api" => array(                 // Get this value from an API response, not a form entry
                    "field_name" => "id"        // The value to extract from the JSON response
                ),
            ),
            array(
                "field_name" => "name",
                "default_value" => "",
                "api" => array(
                    "field_name" => "name"
                ),
            ),
            array(
                "field_name" => "full_name",
                "default_value" => "",
                "api" => array(
                    "field_name" => "full_name"
                ),
            ),
            //
            // Owner not included at the moment.
            //
            array(
                "field_name" => "private",
                "default_value" => 0,
                "api" => array(
                    "field_name" => "private"
                ),
            ),
            array(
                "field_name" => "html_url",
                "default_value" => "",
                "api" => array(
                    "field_name" => "html_url"
                ),
            ),
            array(
                "field_name" => "description",
                "field_type" => "custom",
                "default_value" => "",
                "api" => array(
                    "field_name" => "description"
                ),
            ),
            array(
                "field_name" => "fork",
                "default_value" => 0,
                "api" => array(
                    "field_name" => "fork"
                ),
            ),
            array(
                "field_name" => "url",
                "default_value" => "",
                "api" => array(
                    "field_name" => "url"
                ),
            ),
            //
            // Missing tons of the url's from the payload.
            //
            array(
                "field_name" => "created_at",
                "field_type" => "datetime",
                "default_value" => 0,
                "api" => array(
                    "field_name" => "created_at"
                ),
            ),
            array(
                "field_name" => "updated_at",
                "field_type" => "datetime",
                "default_value" => 0,
                "api" => array(
                    "field_name" => "updated_at"
                ),
            ),
            array(
                "field_name" => "pushed_at",
                "field_type" => "datetime",
                "default_value" => 0,
                "api" => array(
                    "field_name" => "pushed_at"
                ),
            ),
            array(
                "field_name" => "git_url",
                "default_value" => "",
                "api" => array(
                    "field_name" => "git_url"
                ),
            ),
            array(
                "field_name" => "ssh_url",
                "default_value" => "",
                "api" => array(
                    "field_name" => "ssh_url"
                ),
            ),
            //
            // Missing clone url, svn url.
            //
            array(
                "field_name" => "homepage",
                "default_value" => "",
                "api" => array(
                    "field_name" => "homepage"
                ),
            ),
            array(
                "field_name" => "size",
                "default_value" => 0,
                "api" => array(
                    "field_name" => "size"
                ),
            ),
            array(
                "field_name" => "stargazers_count",
                "default_value" => 0,
                "api" => array(
                    "field_name" => "stargazers_count"
                ),
            ),
            array(
                "field_name" => "watchers_count",
                "default_value" => 0,
                "api" => array(
                    "field_name" => "watchers_count"
                ),
            ),
            array(
                "field_name" => "language",
                "default_value" => 0,
                "api" => array(
                    "field_name" => "language"
                ),
            ),
            array(
                "field_name" => "has_issues",
                "default_value" => 0,
                "api" => array(
                    "field_name" => "has_issues"
                ),
            ),
            array(
                "field_name" => "has_projects",
                "default_value" => 0,
                "api" => array(
                    "field_name" => "has_projects"
                ),
            ),
            array(
                "field_name" => "has_downloads",
                "default_value" => 0,
                "api" => array(
                    "field_name" => "has_downloads"
                ),
            ),
            array(
                "field_name" => "has_wiki",
                "default_value" => 0,
                "api" => array(
                    "field_name" => "has_wiki"
                ),
            ),
            array(
                "field_name" => "has_pages",
                "default_value" => 0,
                "api" => array(
                    "field_name" => "has_pages"
                ),
            ),
            array(
                "field_name" => "forks_count",
                "default_value" => 0,
                "api" => array(
                    "field_name" => "forks_count"
                ),
            ),
            array(
                "field_name" => "mirror_url",
                "default_value" => "",
                "api" => array(
                    "field_name" => "mirror_url"
                ),
            ),
            array(
                "field_name" => "archived",
                "default_value" => 0,
                "api" => array(
                    "field_name" => "archived"
                ),
            ),
            array(
                "field_name" => "open_issues_count",
                "default_value" => 0,
                "api" => array(
                    "field_name" => "open_issues_count"
                ),
            ),
            /*
            Currently this generates an array, which breaks project sync. Need to investigate this a bit more.
            array(
                "field_name" => "license",
                "default_value" => "",
                "api" => array(
                    "field_name" => "license:name"
                ),
            ),
            */
            array(
                "field_name" => "forks",
                "default_value" => 0,
                "api" => array(
                    "field_name" => "forks"
                ),
            ),
            array(
                "field_name" => "open_issues",
                "default_value" => 0,
                "api" => array(
                    "field_name" => "open_issues"
                ),
            ),
            array(
                "field_name" => "watchers",
                "default_value" => 0,
                "api" => array(
                    "field_name" => "watchers"
                ),
            ),
            array(
                "field_name" => "default_branch",
                "default_value" => "master",
                "api" => array(
                    "field_name" => "default_branch"
                ),
            ),
            array(
                "field_name" => "network_count",
                "default_value" => 0,
                "api" => array(
                    "field_name" => "network_count"
                ),
            ),
            array(
                "field_name" => "subscribers_count",
                "default_value" => 0,
                "api" => array(
                    "field_name" => "subscribers_count"
                ),
            ),

            //
            // Other fields not populated from a form or API field.
            //
            array(
                "field_name" => "contributors_count",
                "default_value" => 0,
            ),
        );

        //
        // Register custom post type.
        //
        $post_type_name = "Project";
        $this->custom_post_types = array(
            array(
                $this->PLUGIN_PREFIX . '_' . lcfirst($post_type_name),
                array(
                    'labels' => array(
                        'name' => $post_type_name . 's',
                        'singular_name' => $post_type_name,
                        'add_new' => 'Add New ' . $post_type_name,
                        'add_new_item' => 'Add New ' . $post_type_name,
                        'edit_item' => 'Edit ' . $post_type_name,
                        'new_item' => 'New ' . $post_type_name,
                        'all_items' => 'All ' . $post_type_name . 's',
                        'view_item' => 'View ' . $post_type_name,
                        'search_items' => 'Search ' . $post_type_name,
                        'not_found' => $post_type_name . 'Not Found',
                        'not_found_in_trash' => $post_type_name . ' not found in Trash',
                        'parent_item_colon' => '',
                        'menu_name' => $post_type_name . 's',   // Plural
                    ),
                    'has_archive' => false,         // Disable list archive view. 
                    'publicly_queryable' => false,  // Disable the single page view. Users will be redirected to the home page.
                    'public' => true,
                    'supports' => array('title', 'editor', 'excerpt', 'thumbnail', 'wpcom-markdown'),
                    'taxonomies' => array($this->PLUGIN_PREFIX . '_tags', $this->PLUGIN_PREFIX . '_category'),
                    'exclude_from_search' => false,
                    'capability_type' => 'post',
                    'menu_icon' => plugin_dir_url(__FILE__) . 'lib/img/fa-github.png',
                    'rewrite' => array('slug' => 'projects'),
                )
            )
        );

        //
        // Custom Taxonomies
        //
        $this->taxonomies = array(
            array(
                $this->PLUGIN_PREFIX . '_categories',   // The name of the taxonomy. Name should be in slug form (must not contain capital letters or spaces).
                $this->CUSTOM_POST_TYPE,                // Post type name
                array(
                    'hierarchical' => true,
                    'label' => 'Project Categories',    // Display name
                    'query_var' => true,
                    'rewrite' => array(
                        'slug' => 'project_categories', // This controls the base slug that will display before each term
                        'with_front' => false           // Don't display the category base before
                    )
                )
            ),
            array(
                $this->PLUGIN_PREFIX . '_tags',
                $this->CUSTOM_POST_TYPE,
                array(
                    'hierarchical' => false,
                    'label' => 'Project Tags',
                    'query_var' => true,
                    'rewrite' => array(
                        'slug' => 'project_tags',
                        'with_front' => false
                    )
                ),
            ),
            array(
                $this->PLUGIN_PREFIX . '_languages',
                $this->CUSTOM_POST_TYPE,
                array(
                    'hierarchical' => false,
                    'label' => 'Languages',
                    'query_var' => true,
                    'rewrite' => array(
                        'slug' => 'project_languages',
                        'with_front' => false
                    )
                )
            )
        );

        //
        // Add a metabox to the custom post that we created. This is where custom fields
        // fields of type "form" are entered.
        //
        $custom_metabox_position = $this->PLUGIN_PREFIX . '_metabox';
        $this->meta_boxes = array(
            array(
                "id" => $this->PLUGIN_PREFIX . '_metabox_1',
                "title" => "Project Settings",
                "render_function" => array($this, 'post_class_meta_box'),
                "screens" => $this->CUSTOM_POST_TYPE,
                "priority" => "high",
                "position" => $custom_metabox_position,
                "custom_position_callback" => function () use ($custom_metabox_position) {

                    // Move the metabox below the title input box
                    global $post, $wp_meta_boxes;
                    do_meta_boxes(get_current_screen(), $custom_metabox_position, $post);
                    unset($wp_meta_boxes['post'][$custom_metabox_position]);
                }
            ),
            array(
                "id" => $this->PLUGIN_PREFIX . '_metabox_2',
                "title" => "Repository Content",
                "render_function" => array($this, 'post_class_meta_box_2'),
                "screens" => $this->CUSTOM_POST_TYPE,
                "priority" => "low",
                "position" => $custom_metabox_position,
                "custom_position_callback" => function () use ($custom_metabox_position) {

                    // Move the metabox below the title input box
                    global $post, $wp_meta_boxes;
                    do_meta_boxes(get_current_screen(), $custom_metabox_position, $post);
                    unset($wp_meta_boxes['post'][$custom_metabox_position]);
                }
            ),
            array(
                "id" => $this->PLUGIN_PREFIX . '_metabox_3',
                "title" => "Shortcode Generator",
                "render_function" => array($this, 'shortcode_generator'),
                "screens" => $this->CUSTOM_POST_TYPE,
                "priority" => "low",
                "position" => $custom_metabox_position,         // Custom metabox position
                "custom_position_callback" => function () use ($custom_metabox_position) {

                    // Move the metabox below the title input box
                    global $post, $wp_meta_boxes;
                    do_meta_boxes(get_current_screen(), $custom_metabox_position, $post);
                    unset($wp_meta_boxes['post'][$custom_metabox_position]);
                }
            )
        );

        // Initialize the API syncing object.
        $this->ApiFetcher = new WP_GitGetData($API_USER_FIELD, $API_KEY_FIELD, $this->PLUGIN_PREFIX, $this->SETTINGS_STORE_NAME, $this->custom_post_fields);

        // Initialize the shortcodes for this plugin.
        $this->Shortcodes = new FSD_GITGET_SHORTCODES($this->CUSTOM_POST_TYPE);

        // Cron setup. By default, this calls the run_cron method.
        //
        // NOTE: Cannot use "this" here because it's called statically. 
        self::$cron = array(
            "name" => 'github_project_sync_cron',
            "interval_name" => "30min",
            "custom_interval" => array(
                "name" => "30min",
                "seconds" => 60 * 30,
                "description" => "Thirty Minutes"
            )
        );

        // Need a non-static copy of the cron for the base class to use.
        $this->cron_settings = self::$cron;

        // Call the base class construct method.
        parent::__construct();
    }

    /**
     * Generate custom metabox HTML.
     * TODO: Move metaboxes to their own class/object.
     *
     * @param $post : The post that the metabox is being shown on.
     */
    function post_class_meta_box_2($post)
    {
        ?>
        <?php
            // TODO: Currently this nonce is ignored because the nonce for the first metabox
            //       provides the same security guarantee. Need to move nonce's to some shared
            //       location so they can be generalized.
            wp_nonce_field(basename(__FILE__), $this->custom_form_nonce.'2');
        ?>
        <p>
            <?php
            foreach ($this->custom_post_fields as $cpf) {

                if (!isset($cpf["form2"])) {
                    continue;
                }

                $form = $cpf["form2"];
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

    /**
     * Enqueues scripts to the admin view.
     *
     * @param string $hook : The current page being accessed in the admin panel.
     */
    protected function add_extra_admin_scripts($hook)
    {
        // Retrieve the current post.
        global $post;

        // Only load these resources on our custom post type's new/edit view.
        // There may not be any posts yet, be sure to only do this if there is a post.
        if ($post &&
            ($hook == 'post-new.php' || $hook == 'post.php' || $hook == 'edit.php') &&
            $this->CUSTOM_POST_TYPE === $post->post_type) {

            // SpectrumJS color picker.
            wp_enqueue_script($this->PLUGIN_PREFIX . '-spectrum', plugins_url($this->PLUGIN_FOLDER_NAME . '/lib/js/spectrum.js', dirname(__FILE__)),  array('jquery'));
            wp_enqueue_style($this->PLUGIN_PREFIX . '-spectrum', plugins_url($this->PLUGIN_FOLDER_NAME . '/lib/css/spectrum.css', dirname(__FILE__)));

            // CSS for the shortcode generator widget.
            wp_enqueue_style($this->PLUGIN_PREFIX . '-shortcode-generator', plugins_url($this->PLUGIN_FOLDER_NAME . '/lib/css/shortcode-generator.css', dirname(__FILE__)));
        }

        // Admin settings page assets.
        if ($hook == "settings_page_gitget") {
            wp_enqueue_style($this->PLUGIN_PREFIX . '-admin', plugins_url($this->PLUGIN_FOLDER_NAME . '/lib/css/admin-page.css', dirname(__FILE__)));
        }
    }

    /**
     * Implements an algorithm to walk a nested JSON dict:
     *  - Iterates the topmost list.
     *  - If the item being iterated has keys (sub-items) then recursively calls itself.
     *  - Otherwise, print the term (base case).
     *
     * @param $dict : The dictionary to be printed.
     * @param $indent : The indent level. In addition to being a visual configuration, this also indicates the current recursion depth.
     */
    protected function pretty_print_object($dict, $indent)
    {
        foreach ($dict as $k => $v) {

            $group_field_text = "";

            // If this is the top level, we might find some groups.
            if ($indent == 1) {
                $group_field_text = "class='is_gitget_group'";
                foreach ($this->custom_post_fields as $cpf) {
                    // If we explicitly requested this field to be synced from the API, then
                    // it must not be a custom group.
                    if (isset($cpf['field_name']) && ($cpf['field_name'] == $k)) {
                        $group_field_text = "";
                    }
                }
            }

            // The the current item is a single item array, convert it to a string.
            if (is_array($v) && !FSD_Utils::is_assoc_array($v)) {
                $v = $v[0];
            }

            // If the string is JSON, parse it into an associative array.
            if (!is_array($v)) {
                $json_attempt = json_decode($v, true);
                if ($json_attempt) {
                    $v = $json_attempt;
                }
            }

            // By this point, the item is either an associative array or a string value
            // (but it will not be a JSON string). 

            // Recursive case. It was a nested array; print the key name of the array and recurse.
            if (is_array($v) && FSD_Utils::is_assoc_array($v)) {
                echo "<li $group_field_text><b>$k</b></li>";
                echo "<ul>";
                $this->pretty_print_object($v, $indent + 1);
                echo "</ul>";
                continue;
            }

            // Base case. It was a string; print it.
            if ($k == "github_id") {

                // Special case: tag the github ID so that it can be easily found by the frontend JS.
                echo "<li><b>$k</b>: <span id='github_id'>$v</span></li>";
            } else if ($k == "readme") {

                // Special case: If this is the readme, escape html and truncate it.
                $readme_short = esc_html(substr($v, 0, 1000));
                echo "<li><b>$k</b>: $readme_short ...[Content truncated--edit this content via the readme textbox above!]</span></li>";

            } else {

                // Simple case. Print the key/value pair.
                echo "<li><b>$k</b>: $v</li>";
            }

        } // Loop.
    }

    /**
     * Generates a shortcode widget in the custom post edit page.
     */
    public function shortcode_generator()
    {
        // Get current post, pull out the json data.
        global $post;
        $custom_fields = get_post_meta($post->ID);

        // There may be no custom fields; log this unlikely event.
        if (!$custom_fields) {
            echo "<p>No custom fields were found in this post.</p>";
            FSD_LOG::ERROR($custom_fields, "No custom fields found for shortcode generator.");
            return;
        }

        // Create a TextArea which will hold the generated shortcode.
        echo "<textarea class='center-textarea' id='gitget_shortcode'>Click an item to generate its shortcode!</textarea>";
        echo "<button class='center-textarea' id='gitget_shortcode_copy'>Copy</button>";

        // Create a click-able list, used to select the item for which a shortcode
        // will be generated.
        echo "<div class='gitget-clear-list'><li class='is_gitget_list_top'></li><ul>";
        $this->pretty_print_object($custom_fields, 1);
        echo "</ul></div>";
    }

    /////////////////////////////
    // Custom helper functions //
    /////////////////////////////

    /**
     * Admin tab. Displays HTML describing the plugin.
     */
    protected function admin_about_this_plugin()
    {
        echo "
        <div id='admin-about-container'>
          <img class='fsd-gitget-banner' src='" . plugin_dir_url(__FILE__) . "lib/img/gitget-wordpress.jpg' /> 

          <h2>About</h2>
          <p>Thank you for using Project Sync for Github! We hope this plugin helps you easily showcase GitHub projects on your Wordpress site.</p>

          <p>You can use custom shortcodes to display repository information anywhere on the site, and that information will stay up to date as well.</p>

          <h2>Support</h2>   
          <p>If you encounter bugs or issues, feel free to reach out to us at support@fullstackdigital.com and we will address them promptly.</p>

          <p>You can view up-to-date documentation for this plugin <a href='https://fullstackdigital.com/labs/github-project-sync-wordpress-plugin/'>here</a>.</p>

          <p>If you'd like Fullstack Digital to perform <a href='https://blog.fullstackdigital.com/how-we-automatically-synchronize-100-projects-on-dells-open-source-website-8da0c54669d4'>custom UI integrations</a>, such as custom project pages, reach out to us at hello@fullstackdigital.com.</p>

          <div id='admin-about' class='fsd-gitget-about'>
            <img src='" . plugin_dir_url(__FILE__) . "lib/img/fsd-logo.png' />
            <p>A plugin produced by your friends at Fullstack Digital. <span>© 2018 Fullstack Digital, LLC. All Rights Reserved.</span>
          </div>
        </div>";
    }

    /**
     * An admin tab that allows the user to update or delete projects.
     */
    protected function admin_manage_projects()
    {
        // Use a nonce to validate this request came from our site.
        echo '<h2>Sync Projects</h2>';
        echo '<p>Click this button to force all projects to update via GitHub.com. Note that this should happen automatically if wp_cron is correctly configured.</p>';
        echo '<form action="options-general.php?page=gitget&tab=recent_activity" method="post">';
        wp_nonce_field('update_projects_clicked');
        echo '<input type="hidden" value="true" name="update_projects" />';
        submit_button('Update Projects');
        echo '</form>';

        // Check whether the button has been pressed AND also check the nonce.
        if (isset($_POST['update_projects']) && check_admin_referer('update_projects_clicked')) {
            // The button has been pressed AND we've passed the security check.
            echo '<h3>Logging</h3>';
            $this->run_cron();
        }

        // Use a nonce to validate this request came from our site.
        echo '<h2>Delete Projects</h2>';
        echo '<p>Click this button to delete all projects locally. This cannot be undone--you will have to re-import each project.</p>';
        echo '<form action="options-general.php?page=gitget&tab=recent_activity" method="post">';
        wp_nonce_field('delete_projects_clicked');
        echo '<input type="hidden" value="true" name="delete_projects" />';
        submit_button('Delete Projects');
        echo '</form>';

        if (isset($_POST['delete_projects']) && check_admin_referer('delete_projects_clicked')) {
            // The button has been pressed AND we've passed the security check.
            echo '<h3>Logging</h3>';
            $this->delete_all_custom_posts(false);
        }

        //
        // Settings boxes already exist here, before we're called.
        //

        ?>

        <h2>Recently updated projects. (Rate limit: <?php
            $rate = $this->ApiFetcher->get_api_rate_limit();
            if (is_wp_error($rate)) {
                FSD_LOG::ERROR($rate, "Error retrieving rate limit.");
                echo "[Error!]";
            } else {
                echo $rate->getRemaining() . "/" . $rate->getTotal();
            }
            ?> )
        </h2>

        <table id="gitget-manage-projects-table">
            <tr>
                <th>ID</th>
                <th>Project Name</th>
                <th>Last Updated</th>
            </tr>

            <?php
            // Show all project posts
            $args = [
                'post_type' => $this->CUSTOM_POST_TYPE,
                'order' => 'DESC',
                'orderby' => 'post_modified',
                'posts_per_page' => 30,
            ];

            $loop = new WP_Query($args);

            // Members found. Display a table of most recently updated.
            while ($loop->have_posts()) {
                $loop->the_post(); ?>

                <tr>
                    <?php
                    echo '<td class="fsd-gitget-updated-projects-id">';
                    the_id();
                    echo '</td>';
                    echo '<td class="fsd-gitget-updated-projects-title" >';
                    the_title();
                    echo '</td>';
                    echo '<td>';
                    the_modified_date();
                    echo " at ";
                    the_modified_time();
                    echo '</td>';
                    ?>
                </tr>
                <?php
            }

            ?>
        </table>
        <?php
    }

    /**
     * Called by the WP Cron.
     *
     * @return void
     */
    public function run_cron()
    {
        // If there's no API url field designated, then we have no work.
        if (!$this->ApiFetcher->get_api_url_field()) {
            return;
        }

        // Figure out how many repositories we can afford to update.
        $rate = $this->ApiFetcher->get_api_rate_limit();
        if (is_wp_error($rate)) {
            FSD_LOG::ERROR($rate, "Error retrieving rate limit.");
            return;
        }

        if ($rate->getRemaining() == 0) {
            return;
        }

        // Update the projects that haven't been updated.
        $args = array(
            'post_type' => $this->CUSTOM_POST_TYPE,
            'order' => 'ASC',
            'orderby' => 'post_modified',
            'fields' => 'ids',
            'posts_per_page' => 30
        );

        // Go request updated information from the API Url field on each post.
        $latest = new WP_Query($args);
        $posts = $latest->posts;
        $limit = 0;

        // We are going to be calling sync_post_with_api which will trigger the save hook
        // if we don't disable it.
        remove_action('save_post', array($this, 'save_post_class_meta'), 10);

        foreach ($posts as $post_id) {
            $post = get_post($post_id);
            $post_title = $post->post_title;

            // If there is no api url there's nothing to fetch, so skip this.
            // Most likely the person did not supply a URL because they wanted
            // to manually supply data--so we're not supposed to sync this.
            $url = get_post_meta($post_id, $this->ApiFetcher->get_api_url_field(), true);
            if (FSD_Utils::IsNullOrEmptyString($url)) {
                echo "[SKIPPING, no URL given] " . $post_title . ", ID = " . $post_id . "</br>";
            }

            $api_url = $this->ApiFetcher->make_api_url($url);
            if (is_wp_error($api_url)) {
                FSD_LOG::ERROR($api_url, "Could not convert API url.");
                echo "[SKIPPING, invalid URL] " . $post_title . ", ID = " . $post_id . ", URL = " . $url . "</br>";
                continue;
            }

            // Artificially limit the number we update for now.
            $limit++;
            if ($limit > 30) {
                echo "Limit reached.";
                break;
            }

            // We've already extracted the API URL, so supply it and fetch the
            // data from the endpoint.
            if ($this->ApiFetcher->sync_post_with_api($post_id)) {
                echo "Updated " . $post_title . ", ID = " . $post_id . " <br/>";
            } else {
                echo "<b>FAILED TO REFRESH </b>" . $post_title . ", ID = " . $post_id . "</br>";
            }
        }

        // Restore the save_post action.
        add_action('save_post', array($this, 'save_post_class_meta'), 10, 3);
    }
}

//////////
// Main //
//////////

// Create a singleton instance of the plugin class. This loads the plugin and its
// associated classes as well.
try {
    $WP_GitGet = WP_GitGet::get_singleton();
} catch (Exception $e) {
    FSD_LOG::ERROR($e, "Failed to create GitGet singleton!");
}
