//
//  This file validates wordpress admin form fields based on what classes are set.
//
//  Supported classes:
//      .pap_required           - Require a value in this field.
//      .pap_validate           - Validate this field. This must be set in order for the other ".validate_" rules to be run.
//      .validate_url           - The item in the textbox must be a URL.
//      .validate_github_url    - The item in the testbox must be a Github repo URL.
//
//  Example: Make a required field that must contain a github repository url.
//      ".pap_required .pap_validate .validate_github_url"
//
jQuery().ready(function ($) {
  
    var options = {
        errorClass: 'error-text',
        rules: {}
    }

    $.validator.addMethod("require_from_group", function(value, element, options) {
        var $fields = $(options[1], element.form),
            $fieldsFirst = $fields.eq(0),
            validator = $fieldsFirst.data("valid_req_grp") ? $fieldsFirst.data("valid_req_grp") : $.extend({}, this),
            isValid = $fields.filter(function() {
                return validator.elementValue(this);
            }).length >= options[0];
    
        // Store the cloned validator for future validation
        $fieldsFirst.data("valid_req_grp", validator);
    
        // If element isn't being validated, run each require_from_group field's validation rules
        if (!$(element).data("being_validated")) {
            $fields.data("being_validated", true);
            $fields.each(function() {
                validator.element(this);
            });
            $fields.data("being_validated", false);
        }
        return isValid;
    }, $.validator.format("Please fill at least {0} of these fields."));

    //
    // URL VALIDATORS
    //

    //
    // Github_URL validator
    //
    jQuery.validator.addMethod(
        "github_url",
        function (value, element) {
            value = value.replace("www.", "");
            value = value.replace("http://", "https://").toLowerCase();
            return (this.optional(element) || // Mandatory
                ((value.indexOf("https://api.github.com") < 0) &&
                    (value.indexOf("github.com/repos") < 0) &&
                    (value.indexOf("https://github.com") >= 0)));
        },
        "* Not a GitHub url. Url should be like: http://github.com/facebook/react-native!"
    );

    //
    // Looks for classes of the type "pap_validate", and then applies rules based on the full class name
    //
    jQuery(".pap_validate").each(function (index) {
        var classes = $(this).attr("class");
        var id = $(this).attr("id");;
        options.rules[id] = {};
        
        // Require one of these fields
        options.rules[id]['require_from_group'] = [1, ".validate_oneof"];

        classes.split(" ").forEach(function (c) {
            
            // Need to validate a URL
            if (c.indexOf("url") != -1) {
                options.rules[id]['url'] = true;
            }
            
            // Validate the URL as a github URL
            if (c.indexOf("github_url") != -1) {
                options.rules[id]['github_url'] = true;
            }
        });
    });

    //
    // All fields marked with the class "pap_required" will be required 
    //
    jQuery(".pap_required").each(function (index) {
        var id = $(this).attr("id");
        if (!(options.rules).hasOwnProperty(id)) {
            options.rules[id] = {};
        }
        options.rules[id]['required'] = true;
    });

    // Start the validator
    jQuery("#post").validate(options);

    // Copies text from the shortcode textbox.
    jQuery("#gitget_shortcode_copy").click(function(e){
        $("#gitget_shortcode").select();
        document.execCommand('copy');
        $(this).text("Copied!");
        e.preventDefault();
    });

    // Start the shortcode generator.
    jQuery(".gitget-clear-list li").click(function() {
        // This algorithm is responsible for constructing the gitget shortcode previews
        // which depend on the json path of an item. 
        var group_name = "";                              // Name of the group to fetch data from.
        var query_path = $(this).children('b').text();    // Path separated by colons to get at the requested data.
        var parent_li = $(this).parent().prev();          // The parent <li> that we walk up.
        var github_id = $('#github_id').text();
        var convert_markdown = "";

        // Iterate until we've hit the topmost <li>.
        while(!parent_li.hasClass("is_gitget_list_top"))
        {
            // If the parent was a gitget group, save that string for use in our tag.
            if (parent_li.hasClass("is_gitget_group"))
            {
                group_name = parent_li.children('b').text();
            } else {
                // If the parent was not a gitget group, it must be added to our path.
                query_path = parent_li.children('b').text() + ":" + query_path;
            }

            // Move to the next parent li, if it exists.
            parent_li = parent_li.parent().prev();
        }

        // For the readme we need to append the convert_markdown argument by default.
        if(query_path == "readme") {
            convert_markdown = "convert_markdown='true'";
        }
        var simple_command_string = "[fsd_github_get_project_field github_id='" + github_id + "' field='" + query_path + "' " + convert_markdown +"]";

        $("#gitget_shortcode").text(simple_command_string);
    });

    // Initialize the color picker widget in the admin panel.
    $(".color-picker").spectrum({
        preferredFormat: "hex",        
        showInput: true,
        allowEmpty:true
    });
});
