<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Small collection of shared helper functions used across the app.
 */

if (!function_exists('set_flash')) {
    /**
     * Store a one-time flash message in the session, read by get_flash().
     *
     * @param string $type    success|error|warning|info
     * @param string $message
     */
    function set_flash($type, $message)
    {
        $CI =& get_instance();
        $CI->session->set_flashdata('flash_type', $type);
        $CI->session->set_flashdata('flash_message', $message);
    }
}

if (!function_exists('get_flash')) {
    /**
     * @return array|null ['type' => ..., 'message' => ...] or null when none set
     */
    function get_flash()
    {
        $CI =& get_instance();
        $message = $CI->session->flashdata('flash_message');
        if ($message === null || $message === '') {
            return null;
        }

        return array(
            'type'    => $CI->session->flashdata('flash_type') ?: 'info',
            'message' => $message,
        );
    }
}

if (!function_exists('strip_trailing_dash00')) {
    /**
     * Derive part_number from a component code by stripping a trailing "-00".
     * Example: "77037-BZ080-00" -> "77037-BZ080"
     *
     * @param string $component
     * @return string
     */
    function strip_trailing_dash00($component)
    {
        $component = trim((string) $component);
        if (substr($component, -3) === '-00') {
            return substr($component, 0, -3);
        }

        return $component;
    }
}

if (!function_exists('badge_role')) {
    function badge_role($role)
    {
        $role = strtolower((string) $role);
        if ($role === 'admin') {
            return '<span class="badge text-bg-danger">Admin</span>';
        }

        return '<span class="badge text-bg-secondary">User</span>';
    }
}

if (!function_exists('badge_status')) {
    function badge_status($is_active)
    {
        return $is_active
            ? '<span class="badge text-bg-success">Active</span>'
            : '<span class="badge text-bg-secondary">Inactive</span>';
    }
}
