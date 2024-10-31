<?php
/**
 * This module provides functionality for syncing data with an external API.
 */

/**
 * Class FSD_DATA_SYNC_RATE_LIMIT.
 *
 * Contains information about a data source's rate limit.
 */
class FSD_DATA_SYNC_RATE_LIMIT {

    /**
     * @return int
     */
    public function getTotal()
    {
        return $this->total;
    }

    /**
     * @return int
     */
    public function getRemaining()
    {
        return $this->remaining;
    }

    private $total = 0;
    private $remaining = 0;

    /**
     * FSD_DATA_SYNC_RATE_LIMIT constructor. Contains information about
     * a data source's rate limit.
     *
     * @param $total : The total rate limit (e.g: 1000 requests)
     * @param $remaining : The portion of the total rate that is remaining.
     */
    public function __construct($total, $remaining)
    {
        $this->total = $total;
        $this->remaining = $remaining;
    }
}

/**
 * Class FSD_DATA_SYNC. This class is an abstract base for syncing data with
 * a REST API (which may use basic auth).
 */
abstract class FSD_DATA_SYNC
{
    //
    // USER SETTINGS
    //
    protected $RATE_PADDING = 0;                  // How far under the rate limit to stay
    protected $API_USER_FIELD = "";               // The settings field that stores the API_USER_FIELD
    protected $API_KEY_FIELD = "";                // The settings field that stores the API_KEY_FIELD
    protected $PLUGIN_PREFIX = "";                // The prefix that the plugin uses (e.g. gitget_)
    protected $SETTINGS_STORE_NAME = "";          // Name of the settings database for this app (options table)

    //
    // INTERNAL STATE
    //
    protected $custom_post_fields = array();        // Custom post fields
    protected $api_url_field = "";                  // Holds the field_name of the field that holds the API_URL

    //
    // Abstract functions that must be overridden by child classes.
    //
    abstract public function make_api_url($url);

    abstract public function get_api_rate_limit();

    abstract protected function extra_api_work($post_id, $data);

    abstract protected function extra_api_field_work($post_id, $custom_field, $data, $context);

    abstract protected function handle_api_error($post_id);

    /**
     * FSD_DATA_SYNC constructor.
     *
     * @param string $api_user_field : The field that contains the username for API requests.
     * @param string $api_key_field : The field that contains the password for API requests.
     * @param string $plugin_prefix : The prefix that the plugin uses for taxonomies, settings, etc.
     * @param string $settings_store_name : The name of the settings store for this plugin.
     * @param array $custom_post_fields : Associative array defining fields this plugin uses.
     */
    public function __construct($api_user_field, $api_key_field, $plugin_prefix, $settings_store_name, $custom_post_fields)
    {
        $this->API_USER_FIELD = $api_user_field;
        $this->API_KEY_FIELD = $api_key_field;
        $this->PLUGIN_PREFIX = $plugin_prefix;
        $this->SETTINGS_STORE_NAME = $settings_store_name;

        $this->custom_post_fields = $custom_post_fields;

        // The API url field is stored in the custom post fields dictionary.
        // Find and store it so that we can index to this field easily.
        foreach ($this->custom_post_fields as $cpf) {
            if (isset($cpf["API_URL_FIELD"])) {
                $this->api_url_field = $cpf["field_name"];
            }
        }

        // If field was not found, log the error.
        FSD_LOG::IF_(empty($this->api_url_field), "Could not find API url field. Incorrect custom fields object.");
    }

    /**
     * Getter for the api_url_field property.
     *
     * @return string
     */
    public function get_api_url_field() {
        return $this->api_url_field;
    }

    /**
     * Performs a GET request against the supplied URL using an auth header.
     *
     * @param string $url : The URL to perform the request against.
     * @return array|WP_Error
     */
    protected function make_get_request($url)
    {
        // Validate parameters.
        if (FSD_Utils::IsNullOrEmptyString($url)) {
            return new WP_Error(FSD_ERROR::INVALID_PARAMETER, "Invalid URL supplied for GET request.", $url);
        }

        // Build up the options/headers for the request.
        $request_args['headers'] = FSD_Utils::generate_auth_header($this->SETTINGS_STORE_NAME, $this->API_USER_FIELD, $this->API_KEY_FIELD);
        if (is_wp_error($request_args['headers'])) {
            // If we failed to generate an auth header, we'll try anyway with no header. Some services have
            // default rate limits if you're not authenticated (such as github) and will let this slide.
            // FSD_LOG::IF_ERROR($request_args['headers'], "Failed to generate auth header.");

            $request_args['headers'] = "";
        }


        $request_args['timeout'] = 10;
        $request_args['reject_unsafe_urls'] = true;

        // Perform the actual request.
        $response = wp_remote_get($url, $request_args);
        if (is_wp_error($response)) {
            return $response;
        }

        // Maybe we got the response but the API failed us for some reason.
        $http_code = wp_remote_retrieve_response_code($response);
        if ($http_code !== 200) {
            return new WP_Error(FSD_ERROR::HTTP_REQUEST_FAILED,
                "HTTP response code was not 200.",
                array($response, $http_code));
        }

        // Decode the JSON response as an associative array.
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        if (!$data) {
            return new WP_Error(FSD_ERROR::INVALID_JSON, "Invalid JSON supplied to json_decode.");
        }

        // Success. Return the associative array.
        return $data;
    }

    /**
     * Fetches the info from an API and sets it on a particular post. If this fails,
     * it makes a callout for child classes to handle errors.
     *
     * NOTE: Callers are expected to disable the save_post action before calling this function.
     *
     * @param $post_id : The post to update.
     * @param string $context : A context to attach to this operation.
     *
     * @return bool|WP_Error
     */
    public function sync_post_with_api($post_id, $context = '')
    {
        // If post data isn't provided, get it from the API.
        $api_url = $this->make_api_url(get_post_meta($post_id, $this->api_url_field, true));

        // If we can't find the API url field, silently continue. This may be expected,
        // because not all posts require API syncing, so we will not signal the callout.
        if (is_wp_error($api_url)) {
            FSD_LOG::ERROR($api_url, "Failed to find api_url for post: $post_id. This may mean that the post does not require API syncing.");
            return true;
        }

        // Fetch the data for this post.
        $data = $this->make_get_request($api_url);
        if (is_wp_error($data)) {

            // Before returning, make a callout to let subclasses handle an API sync
            // failure however they want.
            $this->handle_api_error($post_id);
            return $data;
        }

        // Update fields from API response.
        foreach ($this->custom_post_fields as $cpf) {

            // This loop will only operate on API fields.
            if (!isset($cpf["api"])) {
                continue;
            }

            $data_iter = $data;
            $default_value = $cpf["default_value"];

            // Extract the field name. This could be a top level name (e.g "username") or
            // a string indicating a nested field like "fields:country".
            $api_field = $cpf["api"]["field_name"];

            // If it's a nested field, try to travel that path to get the attribute.
            $tokens = explode(":", $api_field);
            foreach ($tokens as $token) {
                // Only travel to the attribute if it exists. If not, pop out.
                if (isset($data_iter[$token])) {
                    $data_iter = $data_iter[$token];
                } else {
                    $data_iter = $default_value;
                    break;
                }
            }

            // We've extracted the attribute value successfully.
            // If this is a title field, update the post title as well.
            if (isset($cpf["TITLE_FIELD"]) && ($data_iter != $default_value)) {
                FSD_LOG::IF_ERROR($this->update_attr_during_save($post_id, "post_title", $data_iter));
            }

            // If it's a datetime field, sanitize it.
            if (isset($cpf["field_type"]) && $cpf['field_type'] == "datetime") {
                $data_iter = date('Y-m-d H:i:s', strtotime($data_iter));
            }

            // Allow a custom callout for certain fields with field_type=custom.
            if (isset($cpf["field_type"]) && $cpf['field_type'] == "custom") {
                $this->extra_api_field_work($post_id, $cpf["field_name"], $data_iter, $context);
            } else {
                FSD_LOG::IF_ERROR(FSD_Utils::upsert_custom_field($post_id, $cpf["field_name"], $data_iter));
            }
        }

        // Set the posts's last write time.
        FSD_LOG::IF_ERROR($this->update_attr_during_save($post_id, "post_modified_gmt", time()));
        FSD_LOG::IF_ERROR($this->update_attr_during_save($post_id, "post_modified", time()));

        // Provide a hook for work the child class wants to do.
        $this->extra_api_work($post_id, $data);

        return true;
    }

    /**
     * Updates a value during a wordpress save call. This implies that the "save_post"
     * action has been removed before calling this function, so that the save filter doesn't
     * run on it.
     *
     * @param $post_id : The ID of the post to update an attribute on.
     * @param $field_name : The field name to update.
     * @param string $field_value : The field value to update.
     *
     * @return int|WP_Error
     */
    function update_attr_during_save($post_id, $field_name, $field_value = '')
    {
        return wp_update_post(array('ID' => $post_id, $field_name => $field_value), true);
    }
}