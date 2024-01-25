<?php
echo "hello";
/*
Plugin Name: Pinterest Connection
Description: Extract h2 headings and image URLs from a selected WordPress post and create pins in pinterest.
*/

// import table
define('PSY_BULK_IMPORT_TBL', 'pinterest_bulk_importer');
define('PSY_BOARD_PAGE_SIZE', 100);
define('PSY_CURRENT_TIME', current_time('mysql'));
define('PSY_PROCESSING_TIME', 180); // in seconds
define('PSY_PIN_SUCCESS_CODE', 201); // Successful pin creation.
define('PSY_DATE_FORMAT', date('d-M-Y H:i:s'));

// Include pinterest auth flow
require_once plugin_dir_path(__FILE__) . 'inc/pinterest-auth-flow.php';

// Enqueue JavaScript and CSS files
add_action('admin_enqueue_scripts', 'enqueue_extract_headings_scripts');
function enqueue_extract_headings_scripts($hook)
{
    if ($hook == 'toplevel_page_pinterest-connection-plugin') {
        wp_enqueue_script('extract-headings-script', plugin_dir_url(__FILE__) . 'js/extract-headings-script.js', array('jquery'), '1.32', true);
        wp_enqueue_style('extract-headings-style', plugin_dir_url(__FILE__) . 'extract-headings-style.css');
        wp_enqueue_style('bootsprap-style', 'https://maxcdn.bootstrapcdn.com/bootstrap/3.4.1/css/bootstrap.min.css');
        wp_enqueue_style('fontawesome', 'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/4.7.0/css/font-awesome.min.css');
        wp_enqueue_style('select2', 'https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/css/select2.min.css');
        wp_enqueue_script('select2', 'https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/js/select2.min.js', array('jquery'), '4.0.13', true);
        wp_enqueue_script('bootsprap-script', 'https://maxcdn.bootstrapcdn.com/bootstrap/3.4.1/js/bootstrap.min.js', array('jquery'), '1.10.25', true);
        wp_enqueue_script('dataTables', 'https://cdn.datatables.net/1.10.25/js/jquery.dataTables.min.js', array('jquery'), '1.10.25', true);
        wp_enqueue_style('dataTables-style', 'https://cdn.datatables.net/1.10.25/css/jquery.dataTables.min.css');
        wp_enqueue_script('sweetalert', 'https://cdn.jsdelivr.net/npm/sweetalert2@11', array('jquery'), '4.0.13', true);
        // Pass necessary data to the script
        wp_localize_script('extract-headings-script', 'extractHeadingsAjax', array(
            'ajaxurl' => admin_url('admin-ajax.php'),
            'security' => wp_create_nonce('extract_headings_nonce')
        ));
        // Localize the script with new data
        wp_localize_script('extract-headings-script', 'ajax_object', array('ajax_url' => admin_url('admin-ajax.php')));
    }
}

// Custom logging function
function psy_error_log($message)
{
    $date = PSY_DATE_FORMAT;
    $plugin_dir = plugin_dir_path(__FILE__);
    $log_directory = $plugin_dir . 'logs/';

    // Create the directory if it doesn't exist
    if (!is_dir($log_directory)) {
        mkdir($log_directory, 0755, true);
    }

    $log_file = $log_directory . 'log-' . date('d-M-Y') . '.log';

    // Log the message to the custom log file
    file_put_contents($log_file, '[' . $date . ']' . ' - ' . $message . PHP_EOL, FILE_APPEND);
}

// Add Pinterest Connection settings page
function pinterest_connection_plugin_menu()
{
    add_menu_page('Pinterest Connection Settings', 'Pinterest Connection', 'manage_options', 'pinterest-connection-plugin', 'extract_headings_plugin_settings_page' , 'dashicons-pinterest');

    // Add Pinterest Auth as a sub-menu under Pinterest Connection
    add_submenu_page('pinterest-connection-plugin', 'Pinterest Auth', 'Pinterest Auth', 'manage_options', 'pinterest-auth-settings', 'psy_display_integration_page');
}
add_action('admin_menu', 'pinterest_connection_plugin_menu');


// Add plugin settings page
/*function extract_headings_plugin_menu()
{
    add_options_page('Extract Headings Plugin Settings', 'Extract Headings Plugin', 'manage_options', 'extract-headings-plugin', 'extract_headings_plugin_settings_page');
}
add_action('admin_menu', 'extract_headings_plugin_menu');*/

// send request to pinterest
function sendCurlRequest($url, $method, $token, $data = null)
{
    $ch = curl_init($url);

    // Variable to capture response headers
    $responseHeaders = '';

    // Function to capture and log response headers
    $headerCallback = function ($ch, $header) use (&$responseHeaders) {
        $responseHeaders .= $header;
        return strlen($header);
    };

    // Set cURL options common to both POST and GET requests
    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
        ],
        CURLOPT_HEADERFUNCTION => $headerCallback
    ];

    curl_setopt_array($ch, $options);

    if ($method === 'POST') {
        // If it's a POST request, set additional cURL options
        curl_setopt($ch, CURLOPT_POST, 1);
        if ($data !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        }
    } elseif ($method === 'GET') {
        // If it's a GET request, no additional options needed
    } else {
        // Unsupported method
        return false;
    }

    // Execute the cURL request
    $response = curl_exec($ch);

    // Get the HTTP status code
    $httpStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    // Check for cURL errors
    if (curl_errno($ch)) {
        psy_error_log("Curl Error for [" . $data['title'] . "]: " . curl_error($ch));
    }

    // Close the cURL session
    curl_close($ch);

    // log request headers
    if (!empty($data)) {
        psy_error_log("API response header for [" . $data['title'] . "] : " . $responseHeaders);
        return [$httpStatus, json_decode($response)];
    }

    return json_decode($response);
}
// AJAX handler for the search
function search_handler()
{
    $postData = $_POST;
    if(empty($postData)) return;

    // Call the display_pinterest_data function with the search term
    display_pinterest_data($postData);

    // Always die in functions echoing AJAX content
    die();
}

add_action('wp_ajax_search_handler', 'search_handler');
add_action('wp_ajax_nopriv_search_handler', 'search_handler');

//functions for display data
function display_pinterest_data($req_post)
{
    global $wpdb;
    $table_name = $wpdb->prefix . PSY_BULK_IMPORT_TBL;
    //pdie($req_post);
    // Reading value
    $draw = $req_post['draw'];
    $row = $req_post['start'];
    $rowperpage = $req_post['length']; // Rows display per page
    $columnIndex = $req_post['order'][0]['column']; // Column index
    $columnName = $req_post['columns'][$columnIndex]['data']; // Column name
    $columnSortOrder = $req_post['order'][0]['dir']; // asc or desc
    $searchValue = $req_post['search']['value']; // Search value

    // Add a WHERE clause for searching if a term is provided
    $searchQuery = " ";
    if (!empty($searchValue)) {
        $searchQuery = " and (post_title like '%" . $searchValue . "%' or 
        pinterest_board_name like '%" . $searchValue . "%')";
    }

    //$searchQuery .= " and (status IN (0,1))";

    // Total number of records without filtering (distinct post_id)
    $totalRecords = $wpdb->get_var("SELECT COUNT(DISTINCT post_id) FROM $table_name");

    // Total number of records with filtering (distinct post_id)
    $totalRecordwithFilter = $wpdb->get_var("SELECT COUNT(DISTINCT post_id) FROM $table_name WHERE 1 " . $searchQuery);

    // Query the database to retrieve data from the table.
    $results = $wpdb->get_results(
        "
        SELECT 
            id,
            post_id, 
            post_title, 
            pinterest_board_name,
            DATE(created_at) AS date_created,
            COUNT(success_pins) AS success_count, 
            COUNT(failed_pins) AS failed_count,
            COUNT(*) AS total_count
        FROM $table_name 
        WHERE 1 $searchQuery
        GROUP BY post_id, pinterest_board_name
        ORDER BY $columnName $columnSortOrder
        LIMIT $row, $rowperpage;
        ",
        ARRAY_A
    );
    // echo $wpdb->last_query;
    // pdie($results);

    $data = array();
    if (!empty($results)) {
        $serialNumber = 1;
        foreach ($results as $row) {
            $dt = $row['date_created'];
            $date = strtotime($dt);
            $rDate = date('M j, Y ', $date);
            $queue = $row['total_count'] - $row['failed_count'] - $row['success_count'];

            // create data to send in response
            $data[] = array(
                'serial_no' => $serialNumber,
                'post_title' => get_the_title($row['post_id']),
                'pin_board' => $row['pinterest_board_name'],
                'success_pins' => '<button class="btn btn-primary success-pins-button" data-success-id="' . $row['post_id'] . '" data-title="' . get_the_title($row['post_id']) . '" data-permalink="' . get_permalink($row['post_id']) . '">'.$row['success_count'].' <i class="fa fa-eye" aria-hidden="true"></i></button></td>',
                'failed_pins' => '<button class="btn btn-danger failed-pins-button" data-failed-id="' . $row['post_id'] . '" data-title="' . get_the_title($row['post_id']) . '" data-permalink="' . get_permalink($row['post_id']) . '">'.$row['failed_count'].' <i class="fa fa-eye" aria-hidden="true"></i></button>',
                'pins_in_queue' => $queue,
                'total_pins' => $row['total_count'],
                'created_at' => $rDate
            );
            $serialNumber++;
        }
    }

    // Send back response
    $response = array(
        "draw" => intval($draw),
        "iTotalRecords" => $totalRecords,
        "iTotalDisplayRecords" => $totalRecordwithFilter,
        "aaData" => $data
    );

    echo json_encode($response);
    wp_die();
}

// fetch access token
function psy_access_token()
{
    // get access token from options
    $pin_access_token = psy_get_option();
    if (empty($pin_access_token)) {
        psy_error_log('API execution failed due to access token missing');
        return;
    }
    return $pin_access_token;
}

// Display plugin settings page
function extract_headings_plugin_settings_page()
{
?>

    <?php
    // Initialize an array to store all boards
    $allBoards = [];
    $board_token = psy_access_token();
    // Send a GET request to fetch boards
    $access_token = empty($board_token->access_token) ? '' : $board_token->access_token;
    $boardsEndpoint = 'https://api.pinterest.com/v5/boards/?privacy=all&page_size=' . PSY_BOARD_PAGE_SIZE;
    $boardGetResponse = sendCurlRequest($boardsEndpoint, 'GET', $access_token);
    if (!empty($boardGetResponse) && isset($boardGetResponse->code)) {
        echo '<div class="notice notice-error"><p><strong>API execution failed to fetch list of boards : ' . json_encode($boardGetResponse) . '</strong></p></div>';
        return;
    }
    ?>

    <div class="wrap">
        <div class="container">
            <h1>Pinterest Plugin</h1>
            <div class="row">
                <div id="pinterest-card-1" class="card">
                    <div class="card-body">
                        <div class="row">
                            <h4 style="text-align:center; margin-bottom: 20px;">Create pins on pinterest</h4>
                        </div>
                        <form id="extract-headings-form">

                            <div class="col-md-6">
                                <div class="form-group">
                                    <label for="post-id">Select Post:</label>
                                    <select class="form-control" name="post-id" id="post-id"></select>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label for="post-id">Select Pinterest Board:</label>
                                    <select class="form-control" name="pinterest-board" id="pinterest-board">
                                        <?php foreach ($boardGetResponse->items as $board) : ?>
                                            <option data-name="<?php echo $board->name; ?>" value="<?php echo $board->id; ?>"><?php echo $board->name; ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            <div style="text-align: center;" class="row">
                                <input class="btn btn-info" id="submit-button" type="submit" value="Submit">
                            </div>
                            <div style="display: none;" id="extract-headings-loader">
                                <img src="http://cdnjs.cloudflare.com/ajax/libs/semantic-ui/0.16.1/images/loader-large.gif" alt="processing..." />
                            </div>
                            <?php wp_nonce_field('extract_headings_nonce', 'extract_headings_nonce'); ?>
                        </form>
                    </div>
                </div>
                <div id="extract-headings-container">
                    <h4 style="text-align:center;">Imported post on Pinterest</h4>
                    <button onclick="location.reload();" id="refresh_btn" class="pull-right btn btn-primary" style="margin-bottom: 10px;">
                        <span class="glyphicon glyphicon-refresh"></span> Refresh
                    </button>
                    <table id="pinterest-table" class="display" style="width:100%">
                        <thead>
                            <tr>
                                <th data-sorting="false">Serial Number</th>
                                <th data-sorting="false">Post Title</th>
                                <th data-sorting="false">Pinterest Board</th>
                                <th data-sorting="false">Success pins</th>
                                <th data-sorting="false">Failed pins</th>
                                <th data-sorting="false">Pins in queue</th>
                                <th data-sorting="false">Total pins</th>
                                <th>Created Date</th>
                            </tr>
                        </thead>
                    </table>
                </div>

            </div>
        </div>
    </div>
    <!-- Modal HTML here -->
    <div class="container">
        <!-- Modal -->
        <div class="modal fade" id="pinDetailsModal" role="dialog">
            <div class="modal-dialog modal-lg">

                <!-- Modal content-->
                <div class="modal-content">
                    <div class="modal-header">
                        <button type="button" class="close" data-dismiss="modal">&times;</button>
                        <h4 class="modal-title">success Pins</h4>
                    </div>
                    <div id="result-container" class="modal-body">
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-default" data-dismiss="modal">Close</button>
                    </div>
                </div>

            </div>
        </div>

    </div>
    <script>
        jQuery(document).ready(function($) {
            $('#post-id').select2({
                ajax: {
                    url: ajaxurl,
                    dataType: 'json',
                    delay: 250,
                    data: function(params) {
                        return {
                            action: 'extract_headings_search_posts',
                            search: params.term,
                            security: '<?php echo wp_create_nonce('extract_headings_search_posts_nonce'); ?>'
                        };
                    },
                    processResults: function(data) {
                        return {
                            results: data
                        };
                    },
                    cache: true
                },
                minimumInputLength: 3
            });
            $('#pinterest-board').select2();
        });
    </script>

<?php
}

// insert bulk pins
function psy_insert_batch($data_array, $pid = null)
{
    global $wpdb;

    $table_name = $wpdb->prefix . PSY_BULK_IMPORT_TBL;

    // Check if there is data to insert
    if (empty($data_array)) {
        return false;
    }

    // Create placeholders for the data
    $placeholders = array_fill(0, count($data_array), '(%d, %s, %s, %s, %s, %d, %s, %s, %d, %s)');
    $placeholders = implode(', ', $placeholders);

    // Prepare the data for the query
    $data = array();
    foreach ($data_array as $item) {
        $data[] = $item['post_id'];
        $data[] = $item['post_title'];
        $data[] = $item['heading'];
        $data[] = $item['slug'];
        $data[] = $item['imageURL'];
        $data[] = $item['pinterestBoard'];
        $data[] = $item['pinterest_board_name'];
        $data[] = $item['text'];
        $data[] = $item['processing_unixTime'];
        $data[] = $item['processing_time'];
    }

    // Generate the insert query
    $query = $wpdb->prepare(
        "INSERT IGNORE INTO $table_name (post_id, post_title, heading, slug, imageURL, pinterestBoard, pinterest_board_name, text, processing_unixTime, processing_time) VALUES $placeholders",
        $data
    );

    // Execute the insert query and check the affected row count
    $affected_rows = $wpdb->query($query);

    if (false === $affected_rows) {
        // An error occurred during the insertion
        return [false, 'Error occurred during the insertion. Please refresh and try again.'];
    } else if ($affected_rows > 0) {
        // At least one row was inserted
        return [true, sprintf('%u Pins created successfully to process.', $affected_rows)];
    } else {
        // No rows were inserted (query was ignored)
        return [true, sprintf('Pins already exist for - %s', get_the_title($pid))];
    }
}

// Ajax request handler
// Ajax request handler
function extract_headings_ajax_request_handler()
{
    global $wpdb;

    $data_table_name = $wpdb->prefix . 'pinterest_bulk_importer';

    // Select only processing_unixTime from the last row
    $query = "SELECT processing_unixTime FROM $data_table_name ORDER BY id DESC LIMIT 1;";
    $last_pin_time = $wpdb->get_var($query) + rand(120, 180);

    check_ajax_referer('extract_headings_nonce', 'security');

    $post_id = $_POST['post_id'];
    $pinterest_board = $_POST['pinterest_board']; // Retrieve the selected Pinterest board
    $pinterestBoardName = $_POST['pinterestBoardName']; // Retrieve the selected Pinterest board name

    // Retrieve the post content
    $post = get_post($post_id);
    $post_content = $post->post_content;

    // Extract h2 headings and image URLs
    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    $dom->loadHTML($post_content);
    $nextProcessingTime = max(strtotime(PSY_CURRENT_TIME), $last_pin_time); // Set $nextProcessingTime to the maximum of current time and last_pin_time
    //$nextProcessingTime = strtotime(PSY_CURRENT_TIME);
    $resultArray = [];

    $headings = $dom->getElementsByTagName('h2');
    $totalHeadings = $headings->length;

    for ($i = 0; $i < $totalHeadings; $i++) {
    $heading = $headings->item($i);

    // Decode HTML entities in the heading
    $decodedHeading = html_entity_decode($heading->nodeValue, ENT_QUOTES | ENT_HTML5, 'UTF-8');

    $imageURL = '';
    $nextSibling = $heading->nextSibling;

    // Extract and store the text content of <p> elements after <h2>
    $pText = '';
    $nextSibling = $nextSibling->nextSibling;
    while ($nextSibling) {
        if ($nextSibling->nodeName === 'p') {
            $pText .= $nextSibling->textContent . ' ';
        } elseif ($nextSibling->nodeType === XML_ELEMENT_NODE) {
            // Skip elements other than <p>
            break;
        }
        $nextSibling = $nextSibling->nextSibling;
    }

    // Traverse the next siblings to find the first <figure> element with an <img>
    while ($nextSibling) {
        if ($nextSibling->nodeName === 'figure') {
            $image = $nextSibling->getElementsByTagName('img')->item(0);
            if ($image) {
                $imageURL = $image->getAttribute('src');
            }
        } elseif ($nextSibling->nodeName === 'h2') {
            // Stop processing if the next heading is found
            break;
        }

        $nextSibling = $nextSibling->nextSibling;
    }

    // Exclude heading with text "Frequently Asked Questions"
    if (trim($heading->nodeValue) === 'Frequently Asked Questions') {
        continue;
    }

    // Decode HTML entities in the post title
    $decodedPostTitle = html_entity_decode(get_the_title($post_id), ENT_QUOTES | ENT_HTML5, 'UTF-8');

    // Only add to resultArray if an image is found
    if (!empty($imageURL)) {
        // Calculate the processing time for each record in both formats
        $processing_time = date('Y-m-d H:i:s', $nextProcessingTime);
        $processing_unixTime = $nextProcessingTime;

        $resultArray[] = [
            'post_id' => $post_id,
            'post_title' => $decodedPostTitle,
            'heading' => $decodedHeading,
            'slug' => psy_heading_slug($decodedHeading),
            'imageURL' => $imageURL,
            'pinterestBoard' => $pinterest_board,
            'pinterest_board_name' => $pinterestBoardName,
            'text' => trim($pText),
            'processing_unixTime' => $processing_unixTime,
            'processing_time' => $processing_time
        ];

                // Add a random time between 2 to 3 minutes (120 to 180 seconds)
        $randomTime = rand(120, 180);
        $nextProcessingTime += $randomTime;
    }
}

        //echo "<pre>";
        //print_r($resultArray);
        //die();
    // Send a POST request to create Pinterest pins for each entry in $resultArray
    if (count($resultArray) > 0) {

        // Insert data into the custom table
        list($status, $message) = psy_insert_batch($resultArray, $post_id);

        if ($status) {
            // Data inserted successfully
            wp_send_json_success(array('message' => $message));
        } else {
            // Error occurred during insertion
            wp_send_json_error(array('message' => $message));
        }
    } else {
        wp_send_json_error(array('message' => 'No pins found to process.'));
    }
}





// print data
function pdie($data, $terminate = true)
{
    echo '<pre>';
    print_r($data);
    echo '</pre>';
    if ($terminate) {
        die('die end - ' . rand());
    }
}

// prepare data to post in pinterest
function psy_prepare_pinterest_request($pins)
{
    $pinData = [
        "link" => get_permalink($pins->post_id), // Add post link
        //"raw_title" => trim(preg_replace('/[0-9.]/', '', $pins->heading)).': '.wp_trim_words($pins->post_title, 10, '...'),
        "title" => mb_substr(trim(preg_replace('/[0-9.]/', '', $pins->heading)).': '.wp_trim_words($pins->post_title, 10, '...'), 0, 100),
        //"title" => trim(preg_replace('/[0-9.]/', '', $pins->heading)).': '.wp_trim_words($pins->post_title, 10, '...'),
        "description" => trim(preg_replace('/[0-9.]/', '', $pins->heading)).': '.wp_trim_words($pins->text, 40, '...'),
        "board_id" => $pins->pinterestBoard,
        "alt_text"      => trim(preg_replace('/[0-9.]/', '', $pins->heading)),
        "media_source" => [
            "source_type" => "image_url",
            "url" => $pins->imageURL,
        ],
    ];

    // get access token from options
    $pin_access_token = psy_access_token();
    if (empty($pin_access_token)) {
        psy_error_log('API execution failed due to access token missing');
        return;
    }

    // based on env change api endpoint
    $endpoint = PSY_SANDBOX_ENABLE ? 'https://api-sandbox.pinterest.com/v5/pins' : 'https://api.pinterest.com/v5/pins';

    // Add a 5 second delay before hitting the Pinterest API
    //sleep(10);

    // Send a POST request to create the Pinterest pin for the current entry
    list($http_code, $pinPostResponse) = sendCurlRequest($endpoint, 'POST', empty($pin_access_token->access_token) ? '' : $pin_access_token->access_token, $pinData);

    // log api response
    $title = $pinData['title'];
    $pinApiResponse = empty($pinPostResponse) ? json_encode(['message' => 'Pin created successfully but api response empty']) : $pinPostResponse;
    psy_error_log("API Post Data for [" . $title . "] : " . print_r($pinData, true));
    psy_error_log("API Response for [" . $title . "] : " . print_r($pinApiResponse, true));

    // Initialize arrays to store success and failed pins
    $successPins = [];
    $failedPins = [];

    // Check if the response code is 201
    if (!empty($http_code) && $http_code == PSY_PIN_SUCCESS_CODE) {
        // Pin was created successfully
        $successPins[] = empty($pinPostResponse) ? $pinData : $pinPostResponse;
    } else {
        // Pin creation failed
        $failedPins[] = $pinPostResponse;
    }

    // log success and failure data
    psy_error_log("API Success Data for [" . $title . "] : " . print_r($successPins, true));
    psy_error_log("API Failed Data for [" . $title . "] : " . print_r($failedPins, true));

    return [$successPins, $failedPins];
}

// cron to process pins
add_action('psy_bulk_create_pins', 'psy_bulk_create_pinterest_pins');
function psy_bulk_create_pinterest_pins()
{
    global $wpdb;
    $import_tbl = $wpdb->prefix . PSY_BULK_IMPORT_TBL;

    // Fetch records that are ready to be processed (processing_time <= current time)
    $records = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT * FROM $import_tbl WHERE status <> 1 AND processing_unixTime <= %d",
            strtotime(PSY_CURRENT_TIME)
        )
    );

    // process if record exist
    if (!empty($records)) {
        foreach ($records as $pins) {
            //$pin_post_id = $pins->post_id;
            list($successPins, $failedPins) = psy_prepare_pinterest_request($pins);

            // Update status and response from API
            $update_pin = $wpdb->update(
                $import_tbl,
                array(
                    'status' => 1,
                    'success_pins' => (count($successPins) > 0 ? json_encode($successPins) : null),
                    'failed_pins' => (count($failedPins) > 0 ? json_encode($failedPins) : null),
                ),
                array('id' => $pins->id)
            );

            if (false === $update_pin) {
                $last_error = $wpdb->last_error;
                psy_error_log(sprintf('There is some error - "%s" saving pin for record ID: "%u" & post ID: "%u" & post title: "%s" and heading: "%s"', $last_error, $pins->id, $pins->post_id, get_the_title($pins->post_id), $pins->heading));
            }
        }
    }
}

// cron to process failed pins
add_action('psy_bulk_failed_pins', 'psy_bulk_failed_pinterest_pins');
function psy_bulk_failed_pinterest_pins()
{
    global $wpdb;
    $import_tbl = $wpdb->prefix . PSY_BULK_IMPORT_TBL;

    $batch_size = 1; // Number of rows to process in each batch

    // Fetch up to $batch_size rows where 'status' is not equal to 0 and 'failed_pins' is not NULL
    $sql = $wpdb->prepare("SELECT * FROM $import_tbl WHERE status <> 0 AND failed_pins IS NOT NULL LIMIT %d", $batch_size);
    $pins = $wpdb->get_results($sql);

     foreach ($pins as $pin) {
    $failed_unsearlize = json_decode($pin->failed_pins);

    // Check if the unserialization was successful and if 'code' is not equal to 1
    if ($failed_unsearlize !== null && $failed_unsearlize[0]->code === 1) {
        // Exclude rows where code is 1 from the output
        continue;
    }
        //$pin_post_id = $pin->post_id;
        list($successPins, $failedPins) = psy_prepare_pinterest_request($pin);

        // Update status and response from API
        $update_pin = $wpdb->update(
            $import_tbl,
            array(
                'status' => (count($successPins) > 0 ? 1 : 0),
                'success_pins' => (count($successPins) > 0 ? json_encode($successPins) : null),
                'failed_pins' => (count($failedPins) > 0 ? json_encode($failedPins) : null),
            ),
            array('id' => $pin->id)
        );

        // If there is any error logging
        if (false === $update_pin) {
            // Get the last MySQL error message
            $last_error = $wpdb->last_error;
            psy_error_log(sprintf('There is some error - "%s" saving failed pin again for record ID : "%u" & post ID - "%u" & post title - "%s" and heading is "%s"', $last_error, $pin->id, $pin->post_id, get_the_title($pin->post_id), $pin->heading));
        }
    }
}


add_action('wp_ajax_extract_headings_ajax_request', 'extract_headings_ajax_request_handler');
add_action('wp_ajax_nopriv_extract_headings_ajax_request', 'extract_headings_ajax_request_handler');
function extract_headings_search_posts_ajax_handler()
{
    check_ajax_referer('extract_headings_search_posts_nonce', 'security');

    $search = $_GET['search'];

    $args = array(
        'posts_per_page' => -1,
        'post_type' => 'post',
        'post_status' => 'publish',
        's' => $search,
        'sentence' => true,
        'fields' => 'ids'
    );

    $query = new WP_Query($args);

    $results = array();

    if ($query->have_posts()) {
        while ($query->have_posts()) {
            $query->the_post();
            $results[] = array(
                'id' => get_the_ID(),
                'text' => get_the_title(),
            );
        }

        wp_reset_postdata();
    }

    wp_send_json($results);
}
add_action('wp_ajax_extract_headings_search_posts', 'extract_headings_search_posts_ajax_handler');
add_action('wp_ajax_nopriv_extract_headings_search_posts', 'extract_headings_search_posts_ajax_handler');

function get_pinterest_data()
{
    global $wpdb;
    $table_name = $wpdb->prefix . 'pinterest_data';

    $results = $wpdb->get_results("SELECT * FROM $table_name", ARRAY_A);

    wp_send_json($results);
}

add_action('wp_ajax_get_pinterest_data', 'get_pinterest_data');
add_action('wp_ajax_nopriv_get_pinterest_data', 'get_pinterest_data');

// create heading slug for checking
function psy_heading_slug($custom_data)
{
    // Remove any special characters and punctuation
    $custom_data = preg_replace('/[^\p{L}\s]/u', '', $custom_data);

    // Convert to lowercase
    $slug = strtolower($custom_data);

    // Replace spaces with hyphens (you can use underscores instead if you prefer)
    $slug = str_replace(' ', '-', $slug);

    $slug = trim($slug, '- ');

    return $slug;
}

function custom_unserialize_endpoint()
{
    global $wpdb;

    // Ensure you have the ID from your AJAX request
     $id = absint($_POST['id']); // Replace this with how you get the ID from your AJAX request
    if(empty($id)) return;

    // Construct the SQL query using $wpdb->prepare to prevent SQL injection
    $table_name = $wpdb->prefix . PSY_BULK_IMPORT_TBL;
    $query = $wpdb->prepare("SELECT heading,success_pins FROM $table_name WHERE post_id = %d AND success_pins IS NOT NULL", $id);

    // Execute the query and get the results
    $results = $wpdb->get_results($query, ARRAY_A);
    //pdie($results);
    //echo "<pre>";
    //print_r($results); 
    //die();
    if (!empty($results)) {

        // Convert the unserialized data to HTML
        $html = '<div class="container">';
        for ($i = 0; $i < count($results); $i++) {
            if ($i % 3 === 0) {
                // Start a new row for every 3 items
                $html .= '<div class="row">';
            }

            $sucess_decode = json_decode($results[$i]['success_pins']);
            $success_pins_data = json_decode($results[$i]['success_pins'], true); // true for associative array

            //pdie($sucess_decode,false);

            if (!empty($success_pins_data)) {
                if (isset($success_pins_data[0]['media_source']['url'])) {
                    $image_url = esc_url($success_pins_data[0]['media_source']['url']);
                } else {
                    // Handle the case when the image URL is not available
                    $image_url = esc_url($success_pins_data[0]['media']['images']['1200x']['url']); // You can provide a default image URL or an appropriate message
                }
            } else {
                // Handle the case when the JSON data is empty
                $image_url = 'URL_NOT_FOUND.jpg'; // You can provide a default image URL or an appropriate message
            }

            $html .= '<div class="col-lg-4 col-md-4 col-sm-6 col-12">';
            $html .= '<div class="card">
                <img class="card-img-top" style="width: 200px; height: 200px;" src="' . $image_url . '" alt="'.(empty($image_url) ? 'No Image Found' : '').'" style="width:100%">
                <div class="card-body">
                    <h4 class="card-title">' . $results[$i]['heading'] . '</h4>
                </div>
            </div>';
            $html .= '</div>';

            if (($i + 1) % 3 === 0 || $i === count($results) - 1) {
                // Close the row after every 3 items or at the end of the loop
                $html .= '</div>';
            }
        }
        $html .= '</div>';

        // Output the HTML
        echo $html;
    } else {
        // Handle the case when no data is found
        echo 'No data found.';
    }

    // Make sure to exit after sending the response
    wp_die();
}

// Hook the AJAX callback to both logged in and non-logged in users
add_action('wp_ajax_custom_unserialize', 'custom_unserialize_endpoint');
add_action('wp_ajax_nopriv_custom_unserialize', 'custom_unserialize_endpoint');

function failed_unserialize_endpoint()
{
    global $wpdb;

    // Ensure you have the ID from your AJAX request
    $id = absint($_POST['id']); // Replace this with how you get the ID from your AJAX request
    if(empty($id)) return;

    // Construct the SQL query using $wpdb->prepare to prevent SQL injection
    $table_name = $wpdb->prefix . PSY_BULK_IMPORT_TBL;
    //$query = $wpdb->prepare("SELECT heading,failed_pins FROM $table_name WHERE post_id = %d", $id);
    $query = $wpdb->prepare("SELECT heading, failed_pins FROM $table_name WHERE post_id = %d AND failed_pins IS NOT NULL", $id);
    // Execute the query and get the results
    $results = $wpdb->get_results($query, ARRAY_A);
    //echo "<pre>";
    //print_r($results);
    //die;
    if (!empty($results)) {
        // Assuming that 'failed_pins' contains serialized data
        // $serializedData = $results[0]['failed_pins'];
        // $unserializedData = json_decode($serializedData);
        // echo "<pre>";
        //print_r($unserializedData);
        //die();
        // Do any further processing or manipulation with the unserialized data if needed

        // Convert the unserialized data to HTML
        $html = '<table class="table">';
        if (['failed_pins'] != "") {
            for ($i = 0; $i < count($results); $i++) {
                $html .= '<tr>';
                $html .= '<td><strong>' . $results[$i]['heading'] . '</strong> - ' . esc_html($results[$i]['failed_pins']) . '</td>';
                // You can add more HTML elements to display other data fields as needed
                $html .= '</tr>';
            }
            // code...
        }
        $html .= '</tbale';

        // Output the HTML
        echo $html;
    } else {
        // Handle the case when no data is found
        echo 'No data found.';
    }

    // Make sure to exit after sending the response
    wp_die();
}

// Hook the AJAX callback to both logged in and non-logged in users
add_action('wp_ajax_failed_unserialize', 'failed_unserialize_endpoint');
add_action('wp_ajax_nopriv_failed_unserialize', 'failed_unserialize_endpoint');
// on adding and updating post get pintrest board 
function wporg_add_custom_box()
{
    $screens = ['post', 'wporg_cpt'];
    foreach ($screens as $screen) {
        add_meta_box(
            'wporg_box_id',                 // Unique ID
            'Select Pinterest Board',      // Box title
            'wporg_custom_box_html',  // Content callback, must be of type callable
            $screen
        );
    }
}
add_action('add_meta_boxes', 'wporg_add_custom_box');
function wporg_custom_box_html($post)
{
    // Initialize an array to store all boards
    $allBoards = [];
    $board_token = psy_access_token();
    // Send a GET request to fetch boards
    $access_token = empty($board_token->access_token) ? '' : $board_token->access_token;
    $boardsEndpoint = 'https://api.pinterest.com/v5/boards/?privacy=all&page_size=' . PSY_BOARD_PAGE_SIZE;
    $boardGetResponse = sendCurlRequest($boardsEndpoint, 'GET', $access_token);
    if (!empty($boardGetResponse) && isset($boardGetResponse->code)) {
        echo '<div class="notice notice-error"><p><strong>API execution failed to fetch list of boards : ' . json_encode($boardGetResponse) . '</strong></p></div>';
        return;
    }

    // check post data
    if ($post) {
        $boardID = get_post_meta($post->ID, 'pinterest_board', true);
        $boardName = get_post_meta($post->ID, 'pinterestBoardName', true);
    }
?>

    <form method="post">
        <select name="pinterest_board" id="pinterest-board" required>
            <option value="">--Select Board--</option>
            <?php foreach ($boardGetResponse->items as $board) : ?>
                <option <?= (!empty($boardID) && $board->id == $boardID) ? 'selected=selected' : ''; ?> data-name="<?php echo $board->name; ?>" value="<?php echo $board->id; ?>"><?php echo $board->name; ?></option>
            <?php endforeach; ?>
        </select>

        <input type="hidden" name="pinterestBoardName" id="pinterest-board-name-hidden" value="<?= (!empty($boardName)) ? $boardName : ''; ?>">
    </form>

    <script>
        document.getElementById("pinterest-board").addEventListener("change", function() {
            var selectedValue = this.value;
            var selectedName = this.options[this.selectedIndex].getAttribute('data-name');
            //document.getElementById("pinterest-board-hidden").value = selectedValue;
            document.getElementById("pinterest-board-name-hidden").value = selectedName;
        });
        //         document.addEventListener("DOMContentLoaded", function () {
        //     // Get the meta value you want to set in the hidden field
        //     var metaValue = ""; // Replace this with the actual meta value

        //     // Set the value of the hidden field
        //     document.getElementById("pinterest-board-hidden").value = metaValue;
        // });
    </script>

<?php
} ?>

<?php
function extract_post_pins($post_id, $req_post)
{
    global $wpdb;

    $data_table_name = $wpdb->prefix . 'pinterest_bulk_importer';

    // Select only processing_unixTime from the last row
    $query = "SELECT processing_unixTime FROM $data_table_name ORDER BY id DESC LIMIT 1;";
    $last_pin_time = $wpdb->get_var($query);
    $pinArray = [];
    if (!empty($req_post)) {
        psy_error_log("post inside Data : " . print_r($req_post, true));
        $pinterest_board = $req_post['pinterest_board']; // Retrieve the selected Pinterest board
        $pinterestBoardName = $req_post['pinterestBoardName']; // Retrieve the selected Pinterest board name
        // Retrieve the post content
        $post = get_post($post_id);
        $post_content = $post->post_content;

        // Extract h2 headings and image URLs
        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML($post_content);
        $nextProcessingTime = max(strtotime(PSY_CURRENT_TIME), $last_pin_time); // Set $nextProcessingTime to the maximum of current time and last_pin_time
        //$nextProcessingTime = strtotime(PSY_CURRENT_TIME);

        $headings = $dom->getElementsByTagName('h2');
        $totalHeadings = $headings->length;

        for ($i = 0; $i < $totalHeadings; $i++) {
            $heading = $headings->item($i);
            $imageURL = '';
            $nextSibling = $heading->nextSibling;
            // Extract and store the text content of <p> elements after <h2>
            $pText = '';
            $nextSibling = $nextSibling->nextSibling;
            while ($nextSibling) {
                if ($nextSibling->nodeName === 'p') {
                    $pText .= $nextSibling->textContent . ' ';
                } elseif ($nextSibling->nodeType === XML_ELEMENT_NODE) {
                    // Skip elements other than <p>
                    break;
                }
                $nextSibling = $nextSibling->nextSibling;
            }

            // Traverse the next siblings to find the first <figure> element with an <img>
            while ($nextSibling) {
                if ($nextSibling->nodeName === 'figure') {
                    $image = $nextSibling->getElementsByTagName('img')->item(0);
                    if ($image) {
                        $imageURL = $image->getAttribute('src');
                    }
                } elseif ($nextSibling->nodeName === 'h2') {
                    // Stop processing if the next heading is found
                    break;
                }

                $nextSibling = $nextSibling->nextSibling;
            }

            // Exclude heading with text "Frequently Asked Questions"
            if (trim($heading->nodeValue) === 'Frequently Asked Questions') {
                continue;
            }

            // Only add to pinArray if an image is found
            if (!empty($imageURL)) {
                // Calculate the processing time for each record in both formats
                $processing_time = date('Y-m-d H:i:s', $nextProcessingTime);
                $processing_unixTime = $nextProcessingTime;

                $pinArray[] = [
                    'post_id' => $post_id,
                    'post_title' => get_the_title($post_id),
                    'heading' => $heading->nodeValue,
                    'slug' => psy_heading_slug($heading->nodeValue),
                    'imageURL' => $imageURL,
                    'pinterestBoard' => $pinterest_board,
                    'pinterest_board_name' => $pinterestBoardName,
                    'text' => trim($pText),
                    'processing_unixTime' => $processing_unixTime,
                    'processing_time' => $processing_time
                ];

            // Add a random time between 3 to 4 minutes (180 to 240 seconds)
            $randomTime = rand(180, 240);
            $nextProcessingTime += $randomTime;
            }
        }
    }
    return $pinArray;
}


function extract_headings_text_img($post_id)
{
    $post_id = absint($_POST['post_id']);
    if(empty($post_id)) return;

    $resultArray = extract_post_pins($post_id, $_POST);

    // Send a POST request to create Pinterest pins for each entry in $resultArray
    if (count($resultArray) > 0) {

        // Insert data into the custom table
        list($status, $message) = psy_insert_batch($resultArray, $post_id);

        if ($status) {
            // Data inserted successfully
            wp_send_json_success(array('message' => $message));
        } else {
            // Error occurred during insertion
            wp_send_json_error(array('message' => $message));
        }
    } else {
        wp_send_json_error(array('message' => 'No pins found to process.'));
    }
}

// save post data on publish and update
add_action('save_post', 'psy_save_post_info', 10, 3);
function psy_save_post_info($post_id)
{
    // Check if the post is being created for the first time
    if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id))
        return; // Skip revisions and autosaves

    $post = get_post($post_id);
    if ($post) {
        if ($post->post_type == 'post' && $post->post_status === 'publish') {
            if (isset($_POST['pinterest_board']) && isset($_POST['pinterestBoardName'])) {
                $pinterest_board = sanitize_text_field($_POST['pinterest_board']);
                $pinterest_board_name = sanitize_text_field($_POST['pinterestBoardName']);
                // Call the function to extract headings and send the AJAX request
                //psy_error_log("Extracted Data : " . print_r(extract_post_pins($post_id, $_POST), true));

                // execute if board ID and name exist
                if (!empty($pinterest_board) && !empty($pinterest_board_name)) {
                    // Extract the headings and other data
                    $resultArray = extract_post_pins($post_id, $_POST);

                    psy_error_log("Result pin array for post : " . print_r($resultArray, true));

                    // execute if any row found
                    if (count($resultArray) > 0) {
                        // Insert data into the custom table
                        list($status, $message) = psy_insert_batch($resultArray, $post_id);
                        if (!$status) {
                            // Error occurred during insertion
                            psy_error_log("There is some error while insert/ update post : " . print_r($post, true));
                        }
                        // Update the post meta with the new values
                        update_post_meta($post_id, 'pinterest_board', $pinterest_board);
                        update_post_meta($post_id, 'pinterestBoardName', $pinterest_board_name);
                        return true;
                    } else {
                        psy_error_log("Post insert/ update no pin found for post : " . print_r($post, true));
                    }
                }
            }
        }
    }
}
