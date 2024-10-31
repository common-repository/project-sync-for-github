<?php
/**
 * This module provides helpers for extracting untrusted user parameters.
 */

/**
 * Class FSD_PARAM.
 *
 * Responsible for extracting untrusted user data.
 */
class FSD_PARAM {

    /**
     * Safely extracts an integer from an untrusted user input.
     *
     * @param mixed $in : The input string.
     * @return int : The extracted int.
     */
    public static function get_int($in)
    {
        return (int)$in;
    }

    /**
     * Safely extracts a string from an untrusted user string.
     *
     * @param mixed $in : The input string.
     * @return string : The safely escaped/converted string.
     */
    public static function get_str($in)
    {
        return esc_attr($in);
    }

    /**
     * Converts an incoming string, number, or bool into a bool. Especially useful
     * in shortcodes where a user is being asked to supplye a true/false value, but
     * shortcodes don't natively have a bool type. True/true/0/yes are all valid.
     *
     * @param mixed $in : The type to convert to a bool.
     * @return bool : The converted boolean.
     */
    public static function get_bool($in)
    {
        return filter_var($in, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Sanitizes incoming shortcode attributes.
     *
     * @param $unpack_rules : The rules for unpacking them.
     * @param $atts : The attributes to unpack.
     *
     * @return array : The unpacked associative array.
     */
    public static function get_args($unpack_rules, $atts){

        // Sanitize the array keys to be lowercase, so that they can be accessed in
        // a standardized way.
        $atts = array_change_key_case((array)$atts, CASE_LOWER);
        return shortcode_atts($unpack_rules, $atts, 'fsd_github_get_project_field');
    }

    /**
     * Validates that an item exists in an associative array, and then returns it. This
     * is primarily used when trying to retrieve an item that may not exist in the array.
     *
     * Note: If the value itself is an array, this function returns the first item in it.
     *
     * @param array $assoc_array : The associative array to extract the value from.
     * @param mixed $key : The key to extract.
     * @param bool $log_error : Whether or not errors should be logged if the item isn't found.
     *
     * @return mixed|WP_Error : The extracted item, or an error.
     */
    public static function get_attr($assoc_array, $key, $log_error=False)
    {
        // Validate params.
        if (!is_array($assoc_array)) {
            return FSD_LOG::ERROR(new WP_Error(FSD_ERROR::INVALID_PARAMETER, "'assoc_array' argument was not an array.", $assoc_array));
        }

        // The item may not be present. Optionally log that we didn't find it.
        if (!isset($assoc_array[$key])) {
            return FSD_LOG::IF_ERROR(new WP_Error(FSD_ERROR::ARRAY_KEY_NOT_FOUND, "Could not find $key in the associative array.", $assoc_array));
        }

        // We found the item, it's safe to return it.
        $value = $assoc_array[$key];

        // If it's an array, grab the first item in it.
        if (is_array($value)) {
            $value = $value[0];
        }

        return $value;
    }

}