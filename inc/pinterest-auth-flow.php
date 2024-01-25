<?php
/****************************
 * Pinterest Auth Flow Start
 * *************************/
define('PSY_APP_REDIRECT_URI', admin_url());
define('PSY_APP_ENV_LOCAL', false);
define('PSY_APP_CLIENT_ID', PSY_APP_ENV_LOCAL ? '1491986' : '1492104');
define('PSY_APP_CLIENT_SECRET', PSY_APP_ENV_LOCAL ? '35513c7f3f40289aba2f07965737e7a97b5df243' : 'c22bb9dfe54c843f891169a2448648c590206b76');
define('PSY_OAUTH_OPTION_NAME', 'psy_oauth_data');
define('PSY_SANDBOX_ENABLE', false);
define('PSY_APP_SCOPE','boards:read,boards:write,boards:write_secret,boards:read_secret
,pins:read,pins:write,pins:write_secret');

// admin option page
add_action('admin_menu', 'psy_create_auth_page');
function psy_create_auth_page()
{
    add_menu_page('Pinterest Integration', 'Pinterest Auth', 'manage_options', 'pinterest-integration', 'psy_display_integration_page', 'dashicons-pinterest');
}

// get option data
function psy_get_option()
{
    $pin_oauth_data = get_option(PSY_OAUTH_OPTION_NAME);
    if (!empty($pin_oauth_data) && isset($pin_oauth_data->access_token) && !empty($pin_oauth_data->access_token)) {
        return $pin_oauth_data;
    }
    return;
}

// show pinterest auth button
function psy_display_integration_page()
{
    $auth_data = psy_get_option();
    if (!empty($auth_data)) {
        $remove_link = esc_url(
            wp_nonce_url(admin_url('admin-post.php?action=remove_pinterest_auth'), 'remove_pinterest_auth_nonce')
        );
        echo '<div id="setting-error-tgmpa" class="notice notice-success settings-error"> 
		<p><strong>Pinterest Authorization is completed. If you want to authorize account again please <a href="' . $remove_link . '">Click Here</a></strong></p></div>';
    } else {
        print_r(psy_get_option());
        // Display your Pinterest integration content here, including the authorization button.
        $psy_form_nonce = wp_create_nonce('psy_pinterest_auth_form_nonce');
        echo '<div class="wrap">';
        echo '<h2>Pinterest Integration</h2>';
        echo '<div class="update-nag notice notice-warning inline">';
        echo '<p>Click the button below to authorize your app with Pinterest</p>';
        echo '</div>';
        echo '<form action="' . admin_url('admin-post.php') . '" method="post">';
        echo '<input type="hidden" name="action" value="authorize_pinterest">';
        echo '<input type="hidden" name="psy_form_nonce_field" value="' . $psy_form_nonce . '">';
        echo '<input type="submit" class="button button-primary" value="Authorize with Pinterest">';
        echo '</form>';
        echo '</div>';
    }
}

// removing token option from table
add_action('admin_post_remove_pinterest_auth', 'psy_remove_pinterest_auth_option');
add_action('admin_post_nopriv_remove_pinterest_auth', 'psy_remove_pinterest_auth_option');
function psy_remove_pinterest_auth_option()
{
    // Check the nonce
    if (!isset($_GET['_wpnonce']) || !check_admin_referer('remove_pinterest_auth_nonce', '_wpnonce')) {
        // Nonce verification failed
        wp_die('Nonce verification failed');
    }
    // Remove the option from the database
    $deleted = delete_option(PSY_OAUTH_OPTION_NAME);

    if ($deleted) {
        wp_safe_redirect(admin_url('admin.php?page=pinterest-integration'));
        exit;
    }
}


// after button click submit and redirect for authorization
add_action('admin_post_authorize_pinterest', 'psy_handle_pinterest_authorization');
function psy_handle_pinterest_authorization()
{
    //check nonce and process further
    if (isset($_POST['psy_form_nonce_field']) && wp_verify_nonce($_POST['psy_form_nonce_field'], 'psy_pinterest_auth_form_nonce')) {
        $client_id = PSY_APP_CLIENT_ID;
        $redirect_uri = PSY_APP_REDIRECT_URI;
        $auth_url = "https://www.pinterest.com/oauth/?client_id={$client_id}&redirect_uri={$redirect_uri}&response_type=code&scope=" . PSY_APP_SCOPE;
        wp_redirect($auth_url);
        exit;
    }
}

// verify pinterest auth code and save access token in options for later use
add_action('admin_init', 'psy_handle_auth_code');
function psy_handle_auth_code()
{
    if (isset($_GET['code'])) {
        // Redirect to your admin page.
        $authorization_code = sanitize_text_field($_GET['code']);
        // Exchange the code for an access token using Pinterest API.
        $oauth_data = psy_exchange_authorization_code($authorization_code);
        if (!empty($oauth_data)) {
            // Save the access token in options.
            if (update_option(PSY_OAUTH_OPTION_NAME, $oauth_data)) {
                //wp_safe_redirect(admin_url('admin.php?page=pinterest-integration&token=' . $oauth_data->access_token . ''));
                wp_safe_redirect(admin_url('options-general.php?page=extract-headings-plugin'));
                exit;
            }
        } else {
            error_log('Authorization failed please try again');
            return null;
        }
    }
}

// send request for authorization code with help of oauth code from 1st request
function psy_exchange_authorization_code($token, $scope = '')
{
    // Set your Pinterest API endpoint URL
    $endpoint = PSY_SANDBOX_ENABLE ? 'https://api-sandbox.pinterest.com/v5/oauth/token' : 'https://api.pinterest.com/v5/oauth/token';

    // Create an array of headers
    $headers = array(
        'Authorization' => 'Basic ' . base64_encode(PSY_APP_CLIENT_ID . ':' . PSY_APP_CLIENT_SECRET),
        'Content-Type' => 'application/x-www-form-urlencoded',
    );

    // Create an array of data
    if (empty($scope)) {
        $data = array(
            'grant_type' => 'authorization_code',
            'code' => $token,
            'redirect_uri' => PSY_APP_REDIRECT_URI,
        );
    } else {
        $data = array(
            'grant_type' => 'refresh_token',
            'refresh_token' => $token,
            'scope' => $scope
        );
    }

    // Build the request
    $request = array(
        'headers' => $headers,
        'body' => $data,
    );

    // Send the POST request using wp_safe_remote_post
    $response = wp_safe_remote_post($endpoint, $request);

    // Check for a successful response
    if (!is_wp_error($response)) {
        // Process the response as needed
        $body = wp_remote_retrieve_body($response);
        $oauth_api_data = json_decode($body);
        if (isset($oauth_api_data->access_token)) {
            if (isset($data['scope'])) {
                $oauth_api_data->refresh_token = $token;
                return $oauth_api_data;
            } else {
                return $oauth_api_data;
            }
        }
        return null;
    } else {
        // Error handling
        $error_message = $response->get_error_message();
        error_log('Pinterest API Authorization Error: ' . $error_message);
        return null;
    }
}

// get refresh token from pinterest
add_action('psy_refresh_token', 'psy_exchange_refresh_code');
function psy_exchange_refresh_code()
{
    $auth_data = psy_get_option();
    if (!empty($auth_data)) {
        $refresh_token_response = psy_exchange_authorization_code($auth_data->refresh_token, PSY_APP_SCOPE);
        if (!empty($refresh_token_response)) {
            update_option(PSY_OAUTH_OPTION_NAME, $refresh_token_response);
        } else {
            error_log('Pinterest API Response Error');
        }
    } else {
        error_log('Pinterest API Error Retrieving Option data');
    }
}
