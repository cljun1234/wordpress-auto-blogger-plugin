jQuery(document).ready(function($) {

    // Dynamic Model Switcher
    $('#aab_provider').on('change', function() {
        var provider = $(this).val();
        var $model = $('#aab_model');
        $model.empty();

        if (provider === 'openai') {
            $model.append('<option value="gpt-4o">GPT-4o</option>');
            $model.append('<option value="gpt-4o-mini">GPT-4o Mini</option>');
            $model.append('<option value="gpt-4">GPT-4</option>');
        } else if (provider === 'gemini') {
            $model.append('<option value="gemini-1.5-pro">Gemini 1.5 Pro</option>');
            $model.append('<option value="gemini-1.5-flash">Gemini 1.5 Flash</option>');
        } else if (provider === 'deepseek') {
            $model.append('<option value="deepseek-chat">DeepSeek Chat</option>');
            $model.append('<option value="deepseek-coder">DeepSeek Coder</option>');
        }
    });

    // Trigger change initially
    $('#aab_provider').trigger('change');

    // Handle Generation
    $('#aab-generator-form').on('submit', function(e) {
        e.preventDefault();

        var $btn = $('#aab-generate-btn');
        var $spinner = $('.spinner');
        var $results = $('#aab-results');
        var $error = $('#aab-error');

        $btn.prop('disabled', true);
        $spinner.addClass('is-active');
        $results.hide();
        $error.hide();

        var data = {
            action: 'aab_generate_post',
            security: aab_vars.nonce,
            keyword: $('#aab_keyword').val(),
            template_id: $('#aab_template').val(),
            provider: $('#aab_provider').val(),
            model: $('#aab_model').val()
        };

        $.post(aab_vars.ajax_url, data, function(response) {
            $btn.prop('disabled', false);
            $spinner.removeClass('is-active');

            if (response.success) {
                $results.show();
                var editUrl = response.data.edit_url;
                var viewUrl = response.data.view_url;
                $('#aab-generated-links').html(
                    '<a href="' + editUrl + '" class="button button-secondary" target="_blank">Edit Draft</a> ' +
                    '<a href="' + viewUrl + '" class="button button-secondary" target="_blank">Preview</a>'
                );
            } else {
                $error.show();
                $('#aab-error-msg').text(response.data || 'Unknown error occurred.');
            }
        }).fail(function() {
            $btn.prop('disabled', false);
            $spinner.removeClass('is-active');
            $error.show();
            $('#aab-error-msg').text('Server error. Please try again.');
        });
    });
});
