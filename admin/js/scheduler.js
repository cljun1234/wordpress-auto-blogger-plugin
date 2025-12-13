jQuery(document).ready(function($) {

    // Helper to add row
    function addQueueRow(value) {
        var html = '<li style="padding: 10px; border-bottom: 1px solid #eee; display: flex; align-items: center;">';
        html += '<span class="dashicons dashicons-sort" style="color:#ccc; margin-right:10px; cursor:move;"></span>';
        html += '<input type="text" name="aab_queue[]" value="' + (value || '') + '" style="flex-grow:1; margin-right: 10px;" />';
        html += '<button type="button" class="button-link aab-remove-topic" style="color: #a00;">Remove</button>';
        html += '</li>';

        var $list = $('#aab-queue-list');
        if ( $list.find('.no-items').length ) {
            $list.empty();
        }
        $list.append(html);
    }

    // Add Manual
    $('#aab-add-manual-topic').on('click', function() {
        addQueueRow('');
    });

    // Remove
    $(document).on('click', '.aab-remove-topic', function() {
        $(this).closest('li').remove();
        if ( $('#aab-queue-list li').length === 0 ) {
             $('#aab-queue-list').html('<li class="no-items" style="padding: 10px;">Queue is empty. Add topics manually or generate them.</li>');
        }
    });

    // Sortable (using jQuery UI if available in WP Admin)
    if ( typeof $.fn.sortable !== 'undefined' ) {
        $('#aab-queue-list').sortable({
            handle: '.dashicons-sort',
             placeholder: "ui-state-highlight"
        });
    }

    // Generate Topics
    $('#aab-generate-topics-btn').on('click', function() {
        var broad_topic = $('input[name="aab_sched[broad_topic]"]').val();
        var provider = $('#aab_sched_provider').val();
        var model = $('#aab_sched_model').val();

        if ( ! broad_topic ) {
            alert('Please enter a Broad Topic first.');
            return;
        }

        var $btn = $(this);
        var $spinner = $btn.next('.spinner');
        var $msg = $('#aab-topic-msg');

        $btn.prop('disabled', true);
        $spinner.addClass('is-active');
        $msg.text('');

        $.post(aab_vars.ajax_url, {
            action: 'aab_generate_topics',
            security: aab_vars.nonce,
            broad_topic: broad_topic,
            provider: provider,
            model: model
        }, function(response) {
            $btn.prop('disabled', false);
            $spinner.removeClass('is-active');

            if ( response.success ) {
                var topics = response.data;
                $.each(topics, function(i, val) {
                    addQueueRow(val);
                });
                $msg.text('Added ' + topics.length + ' topics!');
                $msg.css('color', 'green');
            } else {
                $msg.text('Error: ' + response.data);
                $msg.css('color', 'red');
            }
        }).fail(function() {
             $btn.prop('disabled', false);
             $spinner.removeClass('is-active');
             $msg.text('Server Error');
        });
    });

    // Model Switcher logic (Copied from generator.js, reused)
     $('#aab_sched_provider').on('change', function() {
        var provider = $(this).val();
        var $model = $('#aab_sched_model');
        var current = $model.val(); // keep selection if possible
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

        // Restore if exists
        if ( current && $model.find('option[value="'+current+'"]').length ) {
            $model.val(current);
        }
    });

    // Init model trigger only if not already populated (which it is by PHP for saved value, but we need to re-populate list options)
    // Actually the PHP only sets the "selected" option in a potentially empty list if we don't populate all options in PHP.
    // Let's trigger change to populate, then set val.
    var savedModel = $('#aab_sched_model').find('option:selected').val();
    $('#aab_sched_provider').trigger('change');
    if ( savedModel ) {
         $('#aab_sched_model').val(savedModel);
    }

});
