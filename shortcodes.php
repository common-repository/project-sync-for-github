<?php
/**
 * This module implements various shortcodes provided by the gitget plugin.
 * To activate these shortcodes, the shortcode object must be created by the main plugin.
 */

class FSD_GITGET_SHORTCODES
{
    //
    // Member variables.
    //
    private $CUSTOM_POST_NAME;
    private $GET_PROJECT_FIELD_REQUEST;

    /**
     * FSD_GITGET_SHORTCODES constructor.
     *
     * @param string $custom_post_name : The custom post type that this shortcode will query against.
     */
    public function __construct($custom_post_name)
    {
        $this->CUSTOM_POST_NAME = $custom_post_name;

        // Initialize the arguments that will be used to request a project field.
        $this->GET_PROJECT_FIELD_REQUEST = array(
            'field' => '',                  // ex: fields:first_name
            'project_name' => '',           // ex: "Windows Desktop Switcher"
            'project_id' => Null,           // ex: 35
            'github_id' => '',              // ex: "51282144"
            'join_string' => ',',           // ex: "</br>"
            'split_string' => '',           // ex: ","
            'default' => '',                // If nothing is found, return this text.
            'character_limit' => '',        // Truncates the output to the desired number of characters.
            'new_tab' => False,             // ex: True/False, requires strip_link = True
            'strip_link' => False,          // ex: True/False
            'convert_markdown' => False,    // Whether or not to translate the markdown to html.

            // This is not technically expected, but it could be used to select a different post type.
            'custom_post_name' => $this->CUSTOM_POST_NAME,
        );

        // Register our custom shortcodes.
        add_shortcode('fsd_github_get_project_field', array($this, 'shortcode_get_project_field'));
        add_shortcode('fsd_github_get_project_field_if_exists', array($this, 'shortcode_get_project_field_if_exists'));
        add_shortcode('fsd_github_if_field_not_exist', array($this, 'shortcode_if_field_not_exist'));
        add_shortcode('fsd_github_list_widget', array($this, 'shortcode_project_list_widget'));

        // Add filters for WPMarkdown.
        add_filter('meta_content', 'wptexturize');
        add_filter('meta_content', 'convert_smilies');
        add_filter('meta_content', 'convert_chars');
        add_filter('meta_content', 'wpautop');
        add_filter('meta_content', 'shortcode_unautop');
        add_filter('meta_content', 'prepend_attachment');

        // Add custom widget css and enqueue font awesome.
        add_action('wp_print_styles', array(__CLASS__, 'register_widget_stylesheets'));
    }

    /**
     * Responsible for adding resources required by the shortcodes.
     *
     * @return void
     */
    public static function register_widget_stylesheets()
    {
        // FontAwesome is used by the widgets.
        wp_enqueue_style('fontawesome', plugins_url('project-sync-for-github/lib/css/font-awesome/all.min.css', dirname(__FILE__)));

        // Custom widget styles.
        wp_enqueue_style('fsd_widget_style', plugins_url('project-sync-for-github/lib/css/shortcode-widgets.css', dirname(__FILE__)));
    }

    //
    // Private functions.
    //

    /**
     * Converts markdown text to HTML, and returns it.
     *
     * @param $markdown : Supplies the markdown text to convert.
     * @return string: The converted HTML.
     */
    private static function markdown_to_html($markdown)
    {
        $html = \Michelf\MarkdownExtra::defaultTransform($markdown);
        $html = apply_filters('meta_content', $html);
        return $html;
    }

    /**
     * Returns the post that matches the given id type. Only one id type can be supplied.
     *
     * @param $custom_post_name : The custom post type to query for attributes on.
     * @param $github_id : The id of the project on github.
     * @param $project_id : The id of the project in the wordpress installation.
     * @param $project_title : The name of the project in wordpress.
     *
     * @return WP_Post|WP_Error: Returns the post or an error if failed.
     */
    private static function get_github_project($custom_post_name, $github_id, $project_id, $project_title)
    {
        $argument_count = (int)($github_id != False) + (int)($project_id != False) + (int)($project_title != False);
        if ($argument_count > 1) {
            return new WP_Error(FSD_ERROR::INVALID_PARAMETER,
                "Too many criteria were provided, please only one, or none.", $argument_count);
        }

        $post = null;
        $result = null;

        // Retrieve the post that we're supposed to work on; this could be the current
        // post object, a supplied project id, or a github id.
        if ($github_id != '') {
            $args = array(
                'post_type' => $custom_post_name,
                'meta_query' => array(
                    array(
                        'key' => 'github_id',
                        'value' => $github_id,
                        'compare' => '=',
                        'posts_per_page' => 1,
                    )
                )
            );

            $query = new WP_Query($args);
            if (!$query->have_posts()) {
                return new WP_Error(FSD_ERROR::POST_NOT_FOUND,
                    "No post was found that matched the github_id.", $github_id);
            }

            $result = $query->posts;

        } else if ($project_id != '') {
            $result = get_post($project_id);
            if (!$result) {
                return new WP_Error(FSD_ERROR::POST_NOT_FOUND,
                    "No post was found that matched the project_id.", $project_id);
            }

        } else if ($project_title != '') {
            $result = get_page_by_title($project_title, null, $custom_post_name);
            if (!$result) {
                return new WP_Error(FSD_ERROR::POST_NOT_FOUND,
                    "No post was found that matched the project_title.", $project_title);
            }

        } else {
            // Try to get the current post if applicable.
            $result = get_post();
            if (!$result) {
                return new WP_Error(FSD_ERROR::POST_NOT_FOUND,
                    "No id's were supplied, and there was no current post global set.");
            }
        }

        // By this point, we have a result object that contains either an array of
        // posts or a single post. Make sure that we only have a single post.
        if (is_array($result)) {
            $post = $result[0];
        } else {
            $post = $result;
        }

        return $post;
    }

    /**
     * Extracts a field from a selected github project.
     *
     * @param $custom_post_name : The custom post type to retrieve.
     * @param $field : The field to extract from the project.
     * @param $project_name : The name of the project to retrieve the field from.
     * @param $project_id : The ID of the project to retrieve the field from.
     * @param $github_id : The github id of the project to retrieve a field from.
     * @param $join_string : The delimiter used to join an array into a string (ex: '<br>').
     * @param $split_string : The delimiter used to split a string into an array. Often used with join_string. (ex: ',')
     * @param $strip_link : Whether or not to remove the http and www portions of a URL, making it more human readable.
     * @param $default : The value to return if nothing is found.
     * @param $new_tab : Whether or not to generate HTML that will cause the link to open in a new window.
     * @param $character_limit : Truncates the output to the desired number of characters.
     * @param $convert_markdown : If set, the output will be converted from markdown to HTML.
     *
     * @return string : The requested field contents.
     */
    private static function get_project_field($field, $project_name, $project_id, $github_id, $join_string, $split_string, $strip_link, /** @noinspection PhpUnusedParameterInspection */
                                              $default, $new_tab, $character_limit, $convert_markdown, $custom_post_name)
    {
        // Sanitize parameters.
        $field = FSD_PARAM::get_str($field);
        $project_name = FSD_PARAM::get_str($project_name);
        $project_id = FSD_PARAM::get_int($project_id);
        $github_id = FSD_PARAM::get_int($github_id);
        $join_string = FSD_PARAM::get_str($join_string);
        $split_string = FSD_PARAM::get_str($split_string);
        $character_limit = FSD_PARAM::get_int($character_limit);
        $strip_link = FSD_PARAM::get_bool($strip_link);
        $new_tab = FSD_PARAM::get_bool($new_tab);
        $convert_markdown = FSD_PARAM::get_bool($convert_markdown);

        // The field must be specified to find an item.
        if (FSD_Utils::IsNullOrEmptyString($field)) {
            return new WP_Error(FSD_ERROR::INVALID_PARAMETER, "No 'field' was specified.");
        }

        // Retrieve the post that we're supposed to work on; this could be the current
        // post object, a supplied project id, or a github id.
        $post = self::get_github_project($custom_post_name, $github_id, $project_id, $project_name);
        if (is_wp_error($post)) {
            return $post;
        }

        // If we got this far, we have a valid post. Retrieve the requested meta field.
        $it = get_post_meta($post->ID, $field, true);
        if (!$it) {
            return new WP_Error(FSD_ERROR::POST_FIELD_NOT_FOUND, "Requested field not found.");
        }

        //
        // We've found the requested field. Apply any requested modifications/flags.
        //

        // Trim the output to the desired length. This is done before potentially converting
        // markdown because it could otherwise render the HTML invalid. It still could invalidate
        // the markdown, but this seems to be the lesser of the two evils.
        if ($character_limit) {
            $it = substr($it, 0, $character_limit);
        }

        // The caller has requested that we convert markdown to HTML.
        if ($convert_markdown) {
            $it = self::markdown_to_html($it);
        } else {
            // Escape any HTML found in the contents.
            $it = esc_html($it);
        }

        // Don't let the user use url functionality if we aren't operating on an url.
        $string_is_url = filter_var($it, FILTER_VALIDATE_URL);
        if (!$string_is_url && ($strip_link || $new_tab)) {
            return new WP_Error(FSD_ERROR::INVALID_PARAMETER, "User requested open_new or strip_link but content was not a URL.", array($strip_link, $new_tab));
        }

        // Might want to open in a new window.
        $open_new = '';
        if ($new_tab) {
            $open_new = "target='_blank'";
        }

        // Might want to strip http/www from a link.
        if ($strip_link) {
            $site_without_http = trim(str_replace(array('http://', 'https://'), '', $it), '/');
            $site_without_www = trim(str_replace(array('www.', 'www'), '', $site_without_http), '/');
            $it = "<a href='" . $it . "' " . $open_new . ">" . $site_without_www . "</a>";
        }

        // We might want to take a string like "hello, there, friend" and make it an array
        if ($split_string) {
            $it = explode($split_string, $it);
        }

        // If we have an array at this point, join it on the requested character
        if (is_array($it)) {
            $it = implode($join_string, $it);
        }

        return $it;
    }

    /**
     * Builds the HTML for a project list widget.
     *
     * @param $thumbnail : The thumbnail to use. If none is supplied, the github icon will be used.
     * @param $title : The title of the post in the widget.
     * @param $description : The description of the post in the widget. This will be truncated to fit.
     * @param string $language : The programming language used in the project.
     * @param int $stars : The number of stars the project has received.
     * @param int $forks : The number of forks the project has received.
     * @param string $gradient_start : The color to use for the top of the gradient.
     * @param string $gradient_end : The color to use for the bottom of the gradient.
     * @param string $style : The visual style for the widget. Can be list, image_list, or image_gradient_list.
     * @param string $link : Where to link the user to when an item is clicked. Could be empty (no link), github_repository, github_website, wordpress_post, or website_override.
     *
     * @return string : The generated HTML.
     */
    private static function build_list_widget_html($thumbnail, $title, $description, $language = '', $stars = 0, $forks = 0, $gradient_start = "0xffffffff", $gradient_end = "0xffffffff", $style = "list", $link = "")
    {
        ob_start();
        ?>

        <div class="fsd_gitget_list-item">
            <div class="fsd_gitget_list-item-wrapper">
                <div class="fsd_gitget_list-item-container">

                    <!-- Optional image component -->
                    <?php if (strcasecmp(substr($style, 0, 6), "image_") == 0) { ?>

                        <div class="fsd_gitget_list-image-top"
                            <?php if (strcasecmp("image_list_gradient", $style) == 0) { ?>
                                style="background: <?php echo $gradient_start ?>;
                                        background: -webkit-linear-gradient(<?php echo $gradient_end ?>,<?php echo $gradient_start ?>);
                                        background: -o-linear-gradient(<?php echo $gradient_end ?>,<?php echo $gradient_start ?>);
                                        background: -moz-linear-gradient(<?php echo $gradient_end ?>,<?php echo $gradient_start ?>);
                                        background: linear-gradient(<?php echo $gradient_end ?>,<?php echo $gradient_start ?>);"
                            <?php } ?>
                        >

                            <span class="centered project-image-bg"
                                  style="background-image: url(
                                  <?php
                                  if (FSD_Utils::IsNullOrEmptyString($thumbnail)) {
                                      echo plugin_dir_url(__FILE__) . 'lib/img/default-project-image.png';
                                  } else {
                                      echo $thumbnail;
                                  }
                                  ?>
                                          );">
                            </span>

                        </div>

                    <?php } ?>

                    <!-- List content -->
                    <div class="fsd_gitget_list-item-content">
                        <span class="fsd_gitget_item-title">
                            <?php
                            if (!FSD_Utils::IsNullOrEmptyString($link)) {
                                echo "<a href='$link'> $title </a>";
                            } else {
                                echo $title;
                            }
                            ?>
                        </span>
                        <ul class="fsd_gitget_inline-list">
                            <li class="fsd_gitget_inline-list-item"><span class="fsd_gitget_item-category"><i
                                            class="fas fa-folder"></i> <?php echo $language; ?></span></li>
                            <li class="fsd_gitget_inline-list-item"><span class="item-stars"><i
                                            class="fas fa-star"></i> <?php echo $stars; ?> Stars</span></li>
                            <li class="fsd_gitget_inline-list-item"><span class="item-forks"><i
                                            class="fas fa-code-branch"></i> <?php echo $forks; ?> Forks</span></li>
                        </ul>
                        <span class="fsd_gitget_item-excerpt"><?php echo $description; ?></span>
                    </div>
                </div>
            </div>
        </div>

        <?php
        return ob_get_clean();
    }

    /**
     * Builds the query used to generate the list widget.
     *
     * @param string $type : The type of post to retrieve. Can be "featured" or "recent".
     * @param int $count : The number of posts to retrieve.
     *
     * @return array : An array describing the generated query.
     */
    private static function build_list_widget_query($post_type, $type, $count = 5)
    {
        // If no type is specified, this is the default argument.
        $args = array(
            "post_type" => $post_type,
            "post_status" => "publish",
            "orderby" => "date",
            "posts_per_page" => $count,
        );

        if ($type == "featured") {
            $args["meta_query"] = array(
                array(
                    "key" => "archive",
                    "value" => "0"
                ),
                array(
                    "key" => "featured",
                    "value" => "1"
                )
            );
        } else if ($type == "recent") {
            $args['orderby'] = "date";
        }

        return $args;
    }

    //
    // Public shortcode definitions.
    //

    /**
     * Displays a list-based widget showcasing a number of github projects.
     *
     * Note: For a complete argument list, see the documentation for build_list_widget_html.
     *
     * @param array $atts : An associative array containing parameters for build_list_widget_html.
     * @return string : The HTML used to render the widget.
     */
    public function shortcode_project_list_widget($atts)
    {
        $args = FSD_PARAM::get_args(array(
            'style' => 'list',      // Can be list, image_list, and image_gradient_list.
            'link_to' => '',        // Could be github_repository, github_website, wordpress_post, or website_override.
            'type' => 'recent',     // Can filter by featured, or most recent.
            'count' => 5,           // Number of results to return.
            'default' => '',        // If nothing is found, return this text.
        ), $atts);

        $style = FSD_PARAM::get_str($args['style']);
        $link_to = FSD_PARAM::get_str($args['link_to']);
        $type = FSD_PARAM::get_str($args['type']);
        $count = FSD_PARAM::get_int($args['count']);
        $default = FSD_PARAM::get_str($args['default']);

        $query = new WP_Query(self::build_list_widget_query($this->CUSTOM_POST_NAME, $type, $count));
        if (!$query->have_posts()) {
            return $default;
        }

        ob_start();
        ?>

        <div class="fsd_gitget_grid-row">

            <?php
            while ($query->have_posts()) {

                // Set the query post object. This isn't global so doesn't need to be reset.
                $query->the_post();

                // Note that $query->post holds normal fields.
                // Get the metadata we care about for this post. We don't expect get_post_meta
                // to fail since we are not supplying a specific key.
                $post_meta = get_post_meta(get_the_ID());
                $description = trim(esc_html(FSD_PARAM::get_attr($post_meta, 'description')));
                $description_length = strlen($description);
                $description_last_char = substr($description, -1);

                // Add a period to the end of the description if it's short enough. Otherwise,
                // truncate the description and add '...'.
                if ($description_length > 120) {
                    $description = substr($description, 0, 120) . '...';

                } else if ($description_last_char !== "." &&
                    $description_last_char !== "!" &&
                    $description_last_char !== "?") {

                    // If there's no punctuation at the end, add it.
                    $description = $description . ".";
                }

                if (strcasecmp($link_to, "github_repository") == 0) {
                    // Extract the github repository link.
                    $link = FSD_PARAM::get_attr($post_meta, 'html_url');

                } else if (strcasecmp($link_to, "github_homepage") == 0) {
                    // Extract the github website field.
                    $link = FSD_PARAM::get_attr($post_meta, 'homepage');

                } else if (strcasecmp($link_to, "wordpress_post") == 0) {
                    // Extract the wordpress post url.
                    $link = get_post_permalink(get_the_ID());

                } else if (strcasecmp($link_to, "website_override") == 0) {
                    // Extract the wordpress website override field.
                    $link = FSD_PARAM::get_attr($post_meta, 'website');

                } else {
                    // No link parameter provided.
                    $link = null;
                }

                // Extract the other fields we care about.
                $language = FSD_PARAM::get_attr($post_meta, 'language');
                $stars = FSD_PARAM::get_attr($post_meta, 'stargazers_count');
                $forks = FSD_PARAM::get_attr($post_meta, 'forks_count');
                $gradient_start = FSD_PARAM::get_attr($post_meta, 'gradient_start');
                $gradient_end = FSD_PARAM::get_attr($post_meta, 'gradient_end');
                $thumbnail = get_the_post_thumbnail_url(get_the_ID());

                // Print the HTML itself. This cannot fail.
                echo self::build_list_widget_html(
                    $thumbnail,
                    $query->post->post_title,
                    $description,
                    $language,
                    $stars,
                    $forks,
                    $gradient_start,
                    $gradient_end,
                    $style,
                    $link);
            }
            ?>

        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Extracts a field from a project.
     *
     * @param array $atts : An associative array containing parameters for get_github_project.
     * @return string : The field, or an empty/default string indicating no field was found.
     */
    public function shortcode_get_project_field($atts = [])
    {
        $args = FSD_PARAM::get_args($this->GET_PROJECT_FIELD_REQUEST, $atts);
        $default = FSD_PARAM::get_str($args['default']);

        // Call_user_func is being used because here because we have an associative array,
        // and we'd like to support lower PHP versions.
        $field = call_user_func_array(array('self', 'get_project_field'), $args);
        if (is_wp_error($field)) {
            FSD_LOG::ERROR($field);
            return $default;
        }

        // Otherwise, we must have found the field.
        return $field;
    }

    /**
     * Displays the shortcode contents if a field does not exist or is empty/null/false.
     *
     * Example:
     *   [fsd_github_if_field_not_exist field="stars"]
     *      There were no stargazers.
     *   [/fsd_github_if_field_not_exist]
     *
     * @param array $atts : An associative array containing parameters for get_github_project.
     * @param string contents : The text that will be shown if the field is not found.
     *
     * @return string : The field, or an empty/default string indicating no field was found.
     */
    function shortcode_if_field_not_exist($atts, $contents = '')
    {
        $args = FSD_PARAM::get_args($this->GET_PROJECT_FIELD_REQUEST, $atts);
        $default = FSD_PARAM::get_str($args['default']);

        // In this case, we just check to see if we got anything back at all.
        // The reasoning is that if we get the empty string, it's identical to not
        // finding the field.
        //
        // Call_user_func is being used because here because we have an associative array,
        // and we'd like to support lower PHP versions.
        $field = call_user_func_array('get_project_field', $args);

        // If we failed to find the attribute, return the contents.
        if (is_wp_error($field) || FSD_Utils::IsNullOrEmptyString($field)) {
            FSD_LOG::ERROR($field);
            return $contents;
        }

        // If we found the attribute, return the default.
        return $default;
    }

    /**
     * Extracts a field from a project and shows the shortcode content if the field exists.
     * The user could optionally specify the {%PROJECT_FIELD%} tag within the contents, in
     * order to display the field inside of the content.
     *
     * Example:
     *   [fsd_github_get_project_field_if_exists field="stars"]
     *      There are {%PROJECT_FIELD%} stargazers in my repository.
     *   [/fsd_github_get_project_field_if_exists]
     *
     * @param array $atts : An associative array containing parameters for get_github_project.
     * @param string contents : The text that will be shown if the field is found.
     *
     * @return string : The field, or an empty/default string indicating no field was found.
     */
    public function shortcode_get_project_field_if_exists($atts = [], $contents = '')
    {
        $args = FSD_PARAM::get_args($this->GET_PROJECT_FIELD_REQUEST, $atts);
        $default = FSD_PARAM::get_str($args['default']);

        // Call_user_func is being used because here because we have an associative array,
        // and we'd like to support lower PHP versions.
        $field = call_user_func_array('get_project_field', $args);

        // If we failed to find the field, return the old default return value.
        if (is_wp_error($field)) {
            FSD_LOG::ERROR($field);
            return $default;
        }

        // Otherwise, we found it: replace the PROJECT_FIELD placeholder in the shortcode
        // text with the actual returned value.
        return str_replace("{%PROJECT_FIELD%}", $field, $contents);
    }


}