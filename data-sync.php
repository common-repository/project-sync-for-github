<?php

class WP_GitGetData extends FSD_DATA_SYNC
{
    /**
     * WP_GitGetData constructor.
     *
     * @param string $api_user_field : The field that contains the username for API requests.
     * @param string $api_key_field : The field that contains the password for API requests.
     * @param string $plugin_prefix : The prefix that the plugin uses for taxonomies, settings, etc.
     * @param string $settings_store_name : The name of the settings store for this plugin.
     * @param array $custom_post_fields : Associative array defining fields this plugin uses.
     */
    public function __construct($api_user_field, $api_key_field, $plugin_prefix, $settings_store_name, $custom_post_fields)
    {
        parent::__construct($api_user_field, $api_key_field, $plugin_prefix, $settings_store_name, $custom_post_fields);
    }

    //////////////////////////////////
    // Abstract function overrides ///
    //////////////////////////////////

    /**
     * Converts a github repository URL to a github API url.
     *
     * E.g:
     *   https://github.com/pmb6tz/windows-desktop-switcher
     * becomes:
     *   https://api.github.com/repos/pmb6tz/windows-desktop-switcher
     *
     * @param string $url : The URL to convert.
     * @return string|WP_Error
     */
    public function make_api_url($url = '')
    {
        // Validate parameters.
        if (empty($url)) {
            return new WP_Error(FSD_ERROR::INVALID_PARAMETER,
                "Empty url supplied to make_api_url.");
        }

        $git_domain = "github.com";
        $git_api_domain = "api.github.com/repos";

        // Standardize the url.
        $url = FSD_Utils::sanitize_url($url);
        if (is_wp_error($url)) {
            // No need to return a WP_Error here because the next check will fail as well.
            FSD_LOG::ERROR($url, "Failed to sanitize api url.");
        }

        // Validate the URL contains the github substring.
        if (strpos($url, $git_domain) === false) {
            return new WP_Error(FSD_ERROR::INVALID_PARAMETER,
                "Invalid url supplied to make_api_url. The url should be a valid github repository url.",
                $url);
        }

        // Convert the URL from a Github repository URL to a URL that we can
        // use to query the Github API.
        $new_url = str_ireplace($git_domain, $git_api_domain, $url);
        return $new_url;
    }

    /**
     * This is called when the cron is trying to determine how many items it can
     * request in a given run.
     *
     * @return WP_Error|FSD_DATA_SYNC_RATE_LIMIT
     */
    public function get_api_rate_limit()
    {
        $github_url = "https://api.github.com/rate_limit";
        $data = $this->make_get_request($github_url);
        if (is_wp_error($data)) {
            return $data;
        }

        $limit = $data['rate']['limit'];
        $remaining = $data['rate']['remaining'] - $this->RATE_PADDING;

        if ($remaining <= 0) {
            $remaining = 0;
        }

        return new FSD_DATA_SYNC_RATE_LIMIT($limit, $remaining);
    }

    /**
     * Performs extra work on any custom post fields marked with field_type=custom.
     * This allows the field to deviate from its normal data-sync behavior.
     *
     * @param $post_id : The ID of the post this field belongs to.
     * @param $custom_field : The name of the custom field we are performing work on.
     * @param $data : The data to be set on the field.
     * @param $context : Any context that the caller wants to retrieve from the calling function.
     *
     * @return bool|WP_Error : True if succeeded, WP_Error otherwise.
     */
    protected function extra_api_field_work($post_id, $custom_field, $data, $context)
    {
        // If override_description is set, we are not going to get the github description from
        // the API because the user wants to set the description contents in wordpress. This makes
        // sense if the user doesn't own the repository and wants a custom description.
        $override_description = get_post_meta($post_id, "override_description", true);
        if ($override_description) {
            return True;
        }

        // override_description is not set, go ahead and update the field with the
        // extracted data as we normally would.
        FSD_LOG::IF_ERROR(FSD_Utils::upsert_custom_field($post_id, $custom_field, $data));

        return True;
    }

    /**
     * This does some extra work at the end of an API sync for a post. It's responsible
     * for fetching the readme data from the github API, as well as the contributors count.
     *
     * @param $post_id : The ID of the post currently being sync'd with the API.
     * @param $data : An associative array representing the original API response.
     *
     * @return void
     */
    protected function extra_api_work($post_id, $data)
    {
        // The Github API doesn't provide a good way to retrieve a contributors count, so
        // we have to iterate through the contributors array (100 per page, max).
        $contributor_url = $data['contributors_url'] . "?per_page=100"; //&anon=1"; // &page=1
        $cont_data = $this->make_get_request($contributor_url);

        if (!is_wp_error($cont_data)) {
            // The Github API doesn't provide a good way to retrieve a contributors count, so
            // we have to iterate through the contributors array (100 per page, max).
            $contributors_count = count($cont_data);

            // Don't paginate to count the number of contributors--this could take a long time
            // and be error prone. Instead, if we've hit 100 contributors, just append a "+"
            // to indicate that there are probably more.
            if ($contributors_count == 100) {
                $contributors_count = "100+";
            }

            FSD_LOG::IF_ERROR(
                FSD_Utils::upsert_custom_field($post_id, 'contributors_count', $contributors_count)
            );
        } else {
            FSD_LOG::ERROR($cont_data, "Failed to retrieve contributors count.");
        }

        // Taxonomy settings.
        wp_set_object_terms($post_id, "", $this->PLUGIN_PREFIX . '_languages', false);
        FSD_LOG::IF_ERROR(FSD_Utils::set_post_term($post_id, $data['language'], $this->PLUGIN_PREFIX . '_languages'),
            "Failed to set post taxonomy for post: $post_id.");

        // If override_readme is set, we are not going to get the github readme contents from
        // the API because the user wants to set the readme contents in wordpress. This makes
        // sense if the user doesn't own the repository and wants a custom description.
        $override_readme = get_post_meta($post_id, "override_readme", true);
        if ($override_readme) {
            return;
        }

        // Get/set the readme info, if the get request succeeded.
        $readme_url = $data['url'] . '/readme';
        $new_data = $this->make_get_request($readme_url);
        if (!is_wp_error($new_data)) {
            $markdown_trimmed = base64_decode($new_data['content']);

            // Try to fix URLs in the repository.
            $readme_text = $this->fix_markdown_images($post_id, $data['url'], $markdown_trimmed);

            // Store the raw markdown in a custom field. This will be converted to HTML
            // later, when it's time to display it.
            FSD_LOG::IF_ERROR(
                FSD_Utils::upsert_custom_field($post_id, "readme", $readme_text)
            );
        } else {
            FSD_LOG::ERROR($new_data, "Failed to retrieve the readme.");
        }
    }

    /**
     * This function is called when the base library fails to update an item from
     * the API. This implementation emails the site admin to notify the failure.
     *
     * @param $post_id : The post that failed to update.
     * @return void : This function does not return a value that is handled or logged.
     */
    protected function handle_api_error($post_id)
    {
        // Get info to create our message to the admin. This is a best-effort.
        $api_url = FSD_LOG::IF_ERROR($this->make_api_url(get_post_meta($post_id, $this->api_url_field, true)));
        $edit_post_link = htmlspecialchars_decode(get_edit_post_link($post_id));
        $admin_email = get_option('admin_email');
        $failed_item_name = get_the_title($post_id);

        // Send and log a message about the failure.
        $subject = "Failed to update repository: $failed_item_name";
        $message = "Could not update '$failed_item_name'. The failing URL was $api_url. Please go to $edit_post_link to fix this.";

        // Only send an email if the user has configured it.
        $email_failures = get_option('email_failures');
        if (!$email_failures) {
            FSD_LOG::ERROR($message, "Failed to update repository. Not emailing admin, because emailing on failure is disabled.");
            wp_mail($admin_email, $subject, $message);
        } else {
            FSD_LOG::ERROR($message, "Failed to update repository. Emailing $admin_email with details.");
        }

        return;
    }

    ///////////////////////////////////////////////////
    // Functions not overridden from the base class. //
    ///////////////////////////////////////////////////
    /**
     * Attempts to fix relative image urls in markdown.
     *
     * @param $post_id : The ID of the post that's being fixed. This is used to retrieve its permalink.
     * @param $repo_url : The repository URL that the markdown was taken from. Used to generate the resource urls for images, etc.
     * @param $markdown : The markdown to convert.
     *
     * @return null|string|string[]
     */
    private function fix_markdown_images($post_id, $repo_url, $markdown)
    {
        $pattern = '/!\[(.*)\]\s?\((.*)(.png|.gif|.jpg|.jpeg)(.*)\)/';
        return preg_replace_callback(
            $pattern,
            function ($matches) use ($post_id, $repo_url) {
                // 0 - full match
                // 1 - link text
                // 2 - image link
                // 3 - image extension
                // 4 - image alt text

                // Start out with the original match, return it if nothing changes.
                $original_string = $matches[0];
                $return_string = $original_string;
                $modified = false;

                // Extract relevant parts of the markdown syntax.
                $link_text = $matches[1];
                $image_url = $matches[2] . $matches[3]; // Url + file ext
                $image_alt_text = $matches[4];

                // If it's a relative URL, prepend the repo url.
                if (substr($image_url, 0, 4) !== "http") {
                    // Convert back to a "normal" github url
                    $normal_repo_url = str_ireplace(".com/repos/", ".com/", $repo_url);
                    $normal_repo_url = str_ireplace("api.github", "github", $normal_repo_url);

                    $image_url = "$normal_repo_url/raw/master/$image_url";
                    // error_log("\nReplacing relative url in $return_string with $image_url");
                    $modified = true;
                }

                // Replace /blob/ with /raw/ in the whole string.
                $replaced = 0;
                $image_url = str_ireplace("/blob/", "/raw/", $image_url, $replaced);
                if ($replaced > 0) {
                    // error_log("\nReplaced blob->raw in $image_url");
                    $modified = true;
                }

                if ($modified) {
                    $return_string = "![$link_text]($image_url$image_alt_text)";
                    //error_log("Replaced: $original_string");
                    //error_log("With: $return_string");
                    error_log(htmlspecialchars_decode(get_permalink($post_id)));
                }
                //else {
                //  error_log("Not modifying $return_string");
                //}

                return $return_string;
            },
            $markdown
        );
    }

}