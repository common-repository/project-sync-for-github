<?php
/**
 * This module defines constants that define errors which are standardized across
 * this plugin.
 */

/**
 * Class FSD_ERROR. Describes the possible error types that this plugin
 * uses in the WP_Error class.
 */
class FSD_ERROR
{
    const POST_NOT_FOUND = "ERROR_POST_NOT_FOUND";
    const POST_FIELD_NOT_FOUND = "ERROR_POST_FIELD_NOT_FOUND";
    const CLASS_FIELD_NOT_FOUND = "ERROR_CLASS_FIELD_NOT_FOUND";
    const ARRAY_KEY_NOT_FOUND = "ERROR_ARRAY_KEY_NOT_FOUND";
    const INVALID_PARAMETER = "ERROR_INVALID_PARAMETER";
    const OPTIONS_FIELD_NOT_FOUND = "ERROR_OPTIONS_FIELD_NOT_FOUND";
    const UNREGISTER_CUSTOM_POST_FAILED = "ERROR_UNREGISTER_CUSTOM_POST_FAILED";
    const HTTP_REQUEST_FAILED = "ERROR_HTTP_REQUEST_FAILED";
    const INVALID_JSON = "ERROR_INVALID_JSON";
}
