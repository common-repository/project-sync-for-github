<?php

if (!class_exists('FSD_Utils')) {

    class FSD_Utils
    {

        /**
         * Tests whether an array is associative or not.
         *
         * @param array $arr : The array to test.
         * @return bool : True if the array is associative, false otherwise.
         */
        static function is_assoc_array(array $arr)
        {
            if (array() === $arr) return false;
            return array_keys($arr) !== range(0, count($arr) - 1);
        }

        /**
         * Function for basic field validation (present and neither empty nor only white space)
         *
         * @param string : String to validate.
         * @return bool : Whether or not this is a null or empty string.
         */
        static function IsNullOrEmptyString($question)
        {
            // TODO: Looks like sometimes we get arrays in here. Need to debug this.
            return (!isset($question) || trim($question) === '');
        }

        /**
         * Replace an incoming url with a standardized url following the format:
         *      https://google.com/rest-of-url
         *
         * Largely this function removes the www. prefix, and ensures that the url
         * uses HTTPS.
         *
         * @param $url : The url to sanitize.
         * @return string|WP_Error : The converted URL, or an error.
         */
        static function sanitize_url($url)
        {
            // If it's blank, just ignore it
            if (empty($url)) {
                return new WP_Error(FSD_ERROR::INVALID_PARAMETER, "Blank URL supplied to sanitize_url.");
            }

            $url = str_ireplace("www.", "", $url);
            $url = str_ireplace("http:", "https:", $url);
            if (!self::startsWith($url, "https://")) {
                $url = "https://" . $url;
            }

            return $url;
        }

        /**
         * Whether or not a string starts with a given substring.
         *
         * @param $haystack : The string to search.
         * @param $needle : The substring to find.
         * @return bool
         */
        static function startsWith($haystack, $needle)
        {
            $length = strlen($needle);
            return (substr($haystack, 0, $length) === $needle);
        }

        /**
         * Generates a suitable auth header for performing authenticated basic
         * auth requests.
         *
         * @param $settings_name : The name of the wordpress settings store to retrieve
         *                         the API credentials from.
         * @param $user_field : The name of the field in the settings store that contains
         *                      the API user name for authentication.
         * @param $key_field : The name of the field in the settings store that contains
         *                     the API key for authentication.
         *
         * @return array|WP_Error : The new auth header, or an error.
         */
        static function generate_auth_header($settings_name, $user_field, $key_field)
        {
            $auth_options = get_option($settings_name);
            if (!isset($auth_options[$user_field]) || !isset($auth_options[$key_field])) {
                return new WP_Error(FSD_ERROR::ARRAY_KEY_NOT_FOUND,
                    "Could not find stored settings for the supplied user_field and key_field.",
                    $auth_options);
            }
            $api_username = $auth_options[$user_field];
            $api_key = $auth_options[$key_field];
            $args = array();

            if ($api_username && $api_key) {
                $args = array(
                    'Authorization' => 'Basic ' . base64_encode($api_username . ':' . $api_key)
                );
            }

            return $args;
        }

        /**
         * Generates HTML for a checkbox field.
         *
         * @param $post_id : The post that data will be extracted from to populate
         *                   the checkbox state.
         * @param $field_name : The field name that corresponds to this HTML element.
         * @param $label : A label for the field.
         * @param $html_classes : Any extra classes to apply to this element.
         */
        static function make_checkbox_field($post_id, $field_name, $label, $html_classes)
        {
            ?>

            <span class="<?php echo $html_classes ?>">
            <input
                    type="checkbox"
                    name="<?php echo $field_name ?>"
                    value="1"
                <?php checked(1 == (get_post_meta($post_id, $field_name, true))); ?>
            >
                <?php echo $label ?>
            </span>

            <?php

        }

        /**
         * Generates HTML for an input field.
         *
         * @param $post_id : The post that data will be extracted from to populate
         *                   the inputbox initial state.
         * @param $field_name : The field name that corresponds to this HTML element.
         * @param $label : A label for the field.
         * @param $placeholder : Placeholder text for the field.
         * @param $html_classes : Any extra classes to apply to this element.
         */
        static function make_input_field($post_id, $field_name, $label, $placeholder, $html_classes)
        {
            ?>
            <label for="<?php echo $field_name ?>">
                <b><?php echo esc_attr($label) ?></b>
            </label>
            <input
                    class="<?php echo $html_classes ?>"
                    placeholder="<?php echo $placeholder ?>"
                    type="text"
                    name="<?php echo $field_name ?>"
                    id="<?php echo $field_name ?>"
                    value="<?php echo esc_attr(get_post_meta($post_id, $field_name, true)); ?>"
                    size="50"/>
            <?php
        }

        /**
         * Generates HTML for a textarea.
         *
         * @param $post_id : The post that data will be extracted from to populate
         *                   the textarea's initial state.
         * @param $field_name : The field name that corresponds to this HTML element.
         * @param $label : A label for the field.
         * @param $placeholder : Placeholder text for the textarea.
         * @param $html_classes : Any extra classes to apply to this element.
         */
        static function make_textarea_field($post_id, $field_name, $label, $placeholder, $html_classes)
        {
            ?>
            <label for="<?php echo $field_name ?>">
                <b><?php echo esc_attr($label) ?></b>
            </label>
            <textarea
                    class="<?php echo $html_classes ?>"
                    placeholder="<?php echo $placeholder ?>"
                    type="text"
                    name="<?php echo $field_name ?>"
                    id="<?php echo $field_name ?>"
                    size="50"><?php echo esc_attr(get_post_meta($post_id, $field_name, true)); ?></textarea>
            <?php
        }

        /**
         * Appends the specified taxonomy term to the incoming post object. If
         * the term doesn't already exist in the database, it will be created.
         *
         * Borrowed from https://gist.github.com/tommcfarlin/8d8c561973c4654d0eec#file-1-set-post-term-php
         *
         * @param string $post_id : The post id to which we're adding the taxonomy term.
         * @param string $value The : name of the taxonomy term
         * @param string $taxonomy : The name of the taxonomy.
         * @access static
         *
         * @return array|WP_Error : The result of the operation. Array if succeeded, else WP_Error.
         */
        static function set_post_term($post_id, $value, $taxonomy)
        {
            // No value was present, no point in setting anything.
            if (empty($value)) {
                return array();
            }

            // If the taxonomy doesn't exist, then we must create it first.
            $term = term_exists($value, $taxonomy);
            if (0 === $term || null === $term) {
                $term = wp_insert_term(
                    $value,
                    $taxonomy,
                    array(
                        'slug' => strtolower(str_ireplace(' ', '-', $value))
                    )
                );

                // Something went wrong inserting the term. Bail out.
                if (is_wp_error($term)) {
                    return $term;
                }
            }

            if ($term['term_id']) {
                $term = (int)$term['term_id'];
            }

            // Now we can set the taxonomy.
            return wp_set_object_terms($post_id, $term, $taxonomy, true);
        }

        /**
         * Updates a custom field for a post, or adds it if the field doesn't yet exist.
         *
         * @param integer : The post ID for the post we're updating
         * @param string : The field we're updating/adding/deleting
         * @param string [Optional] : The value to update/add for field_name. If left blank, data will be deleted.
         *
         * @return bool|WP_Error
         */
        static function upsert_custom_field($post_id, $field_name, $value)
        {
            // Try to retrieve the field data first.
            $data = get_post_meta($post_id, $field_name, true);

            // If we couldn't find data, the field probably doesn't exist.
            if (self::IsNullOrEmptyString($data)) {

                // Try to add the field to this custom post. If this works,
                // we're done.
                if (add_post_meta($post_id, $field_name, $value, true)) {
                    return true;
                }
            }

            // If we got here, either we found the field, or we didn't find
            // it and failed to add_post_meta (which could mean the field
            // exists and simply has no data). Either way, we now know the
            // field exists and we can update it.
            //
            // This function has useless return semantics because it can return false
            // if it's a no-op, or false if there was a legitimate failure.
            update_post_meta($post_id, $field_name, $value);
        }
    }

} // Import once protector.
?>
