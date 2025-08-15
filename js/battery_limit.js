(function ($, Drupal) {
    Drupal.behaviors.lendingLibraryBatteryLimit = {
      attach: function (context) {
        var $wrap = $(context).find('[data-drupal-selector="edit-field-library-borrow-batteries"]');
        if (!$wrap.length) return;
  
        var limit = 2;
        var $checkboxes = $wrap.find('input[type="checkbox"]').once('battery-limit');
  
        function enforce() {
          var checked = $checkboxes.filter(':checked').length;
          var disable = checked >= limit;
          $checkboxes.not(':checked').prop('disabled', disable);
          // Optional message
          var $msg = $wrap.find('.battery-limit-msg');
          if (!$msg.length) {
            $msg = $('<div class="battery-limit-msg" style="margin-top:6px;font-size:0.9em;"></div>').appendTo($wrap);
          }
          $msg.text(disable ? 'You can select up to ' + limit + ' batteries.' : '');
        }
  
        $checkboxes.on('change', enforce);
        enforce();
      }
    };
  })(jQuery, Drupal);
  