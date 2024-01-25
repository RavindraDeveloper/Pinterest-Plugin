const psy_alert = (txt, desc, type) => {
  Swal.fire(txt, desc, type);
};

jQuery(document).ready(function ($) {
  // fetch pins
  $("#pinterest-table").dataTable({
    order: [[9, "desc"]],
    //pageLength : 1,
    processing: true,
    serverSide: true,
    serverMethod: "post",
    ajax: {
      url: ajax_object.ajax_url,
      data: { action: "search_handler" },
    },
    columns: [
      { data: "serial_no" },
      { data: "post_title" },
      { data: "pin_board" },
      { data: "success_pins" },
      { data: "success_pins_count" },
      { data: "failed_pins" },
      { data: "failed_pins_count" },
      { data: "pins_in_queue" },
      { data: "total_pins" },
      { data: "created_at" }
    ],
  });


  $("#extract-headings-form").on("submit", function (event) {
    event.preventDefault();
    var postId = $("#post-id").val();
    var pinterestBoard = $("#pinterest-board").val();
    var selectedOption = jQuery("#pinterest-board").find("option:selected");
    var pinterestBoardName = selectedOption.data("name");
    //console.log("Data Name: " + pinterestBoardName);
    // Display a confirmation dialog
    var isConfirmed = confirm("Are you sure you want to submit the form?");
    
    // Check if the user confirmed the dialog
    if (isConfirmed) {
        // Disable the submit button
        $("#submit-button").prop("disabled", true);

        // Show a loader
        $("#extract-headings-loader").show();

        // Make an AJAX request to the server
        jQuery.ajax({
            type: "POST",
            url: extractHeadingsAjax.ajaxurl,
            data: {
                action: "extract_headings_ajax_request",
                post_id: postId,
                pinterest_board: pinterestBoard,
                pinterestBoardName: pinterestBoardName,
                security: extractHeadingsAjax.security,
            },
            success: function (response) {
                if (response.success) {
                    psy_alert("Success", response.data.message, "success");
                } else {
                    // Handle the case where the server returned an error
                    psy_alert("Error", response.data.message, "warning");
                    const delay = 2000;
                    setTimeout(function () {
                        location.reload();
                    }, delay);
                }
                // Enable the submit button
                $("#submit-button").prop("disabled", false);

                // Hide the loader
                $("#extract-headings-loader").hide();
            },
            error: function (error) {
                psy_alert("Error", "Unable to make the request.", "warning");
                // Hide the loader
                $("#extract-headings-loader").hide();
                // Enable the submit button
                $("#submit-button").prop("disabled", false);
            },
        });
    }

    // Return true to submit the form or false to cancel
    return isConfirmed;
});



  jQuery(document).on("click", ".success-pins-button", function () {
    var id = $(this).data("success-id"); // Replace with how you get the ID from your click event
    jQuery.ajax({
      type: "POST",
      url: ajaxurl,
      data: {
        action: "custom_unserialize", // Action name for the AJAX callback
        id: id, // Pass the ID to the server
      },
      success: function (response) {
        // Handle the response from the server
        jQuery("#result-container").html(response);
        jQuery(".modal-title").html("Success Pins");
        var pinDetailsContainer = $("#pinDetailsContainer");
        // Append the HTML content to the modal container
        pinDetailsContainer.html(response);
        // Show the modal
        $("#pinDetailsModal").modal("show");
      },
    });
  });
  jQuery(document).on("click", ".failed-pins-button", function () {
    console.log("Button clicked");
    var id = $(this).data("failed-id"); // Replace with how you get the ID from your click event
    jQuery.ajax({
      type: "POST",
      url: ajaxurl,
      data: {
        action: "failed_unserialize", // Action name for the AJAX callback
        id: id, // Pass the ID to the server
      },
      success: function (response) {
        // Handle the response from the server
        jQuery("#result-container").html(response);
        jQuery(".modal-title").html("Failed Pins");
        var pinDetailsContainer = $("#pinDetailsContainer");
        // Append the HTML content to the modal container
        pinDetailsContainer.html(response);
        // Show the modal
        $("#pinDetailsModal").modal("show");
      },
    });
  });
});
