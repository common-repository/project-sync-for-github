<?php
/**
 * This module defines functions used to log or handle errors.
 */

/**
 * Class FSD_LOG.
 *
 * Functions used to log or handle errors.
 */
class FSD_LOG
{
    /**
     * Logs a message to error_log if WP_DEBUG is enabled. Also formats the
     * log if the error is an object or array.
     *
     * @param mixed $result : The possible error object or message to log.
     * @param string $message : An extra message to log before the error.
     *
     * @return mixed : The original message for future usage. This allows callers
     *                 to log and return a WP_Error in the same line.
     */
    public static function ERROR($result, $message = "")
    {
        if (WP_DEBUG === true) {

            // Print an optional message before the actual object is logged.
            if ($message) {
                error_log("[FSD_LOG] " . $message);
            }

            // Print the actual object we were asked to log.
            if (is_array($result) || is_object($result)) {
                error_log(print_r($result, true));
            } else {
                error_log($result);
            }
        }

        return $result;
    }

    /**
     * Logs a failure to error_log if WP_DEBUG is enabled and the given
     * result is a WP_Error. Also formats the log if the error is an object
     * or array.
     *
     * This also logs an optional message with the error logged.
     *
     * @param mixed $result : The possible error object to log.
     * @param string $message : An extra message to log.
     *
     * @return mixed : The originally passed in option. This allows callers
     *                 to log and return a WP_Error in the same line.
     */
    public static function IF_ERROR($result, $message = "")
    {
        if (is_wp_error($result) && WP_DEBUG === true) {
            $log_output = Null;

            // Stringify the error object.
            if (is_array($result) || is_object($result)) {
                $log_output = (print_r($result, true));
            } else {
                $log_output = $result;
            }

            // Print an optional message before the actual object is logged.
            if ($message) {
                error_log("[FSD_LOG] " . $message);
            }

            // Print the actual error object.
            if (isset($log_output)) {
                error_log($log_output);
            }
        }

        return $result;
    }

    /**
     * Logs a failure to error_log if WP_DEBUG is enabled and the given
     * result evaluates to false. Also formats the log if the error is an
     * object or array.
     *
     * This also logs an optional message with the error logged.
     *
     * @param bool $result : The boolean to test.
     * @param string $message : An extra message to log.
     *
     * @return bool : The originally passed in option. This allows callers
     *                to log and return a WP_Error in the same line.
     */
    public static function IF_FALSE($result, $message = "")
    {
        if (($result === false) && WP_DEBUG === true) {
            $log_output = Null;

            // Stringify the error object.
            if (is_array($message) || is_object($message)) {
                error_log(print_r($message, true));
            } else {
                error_log($message);
            }
        }

        return $result;
    }

    /**
     * Logs a failure to error_log if WP_DEBUG is enabled and the given
     * result evaluates to true. Also formats the log if the error is an
     * object or array.
     *
     * This also logs an optional message with the error logged.
     *
     * @param bool $result : The boolean to test.
     * @param string $message : An extra message to log.
     *
     * @return bool : The originally passed in option. This allows callers
     *                to log and return a WP_Error in the same line.
     */
    public static function IF_($result, $message = "")
    {
        if ($result && WP_DEBUG === true) {
            $log_output = Null;

            // Stringify the error object.
            if (is_array($message) || is_object($message)) {
                error_log(print_r($message, true));
            } else {
                error_log($message);
            }
        }

        return $result;

    }
}