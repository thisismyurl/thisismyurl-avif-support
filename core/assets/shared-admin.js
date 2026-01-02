/**
 * TIMU Shared Admin UI
 * Handles visibility, color pickers, and media uploader.
 * Version: 1.260103
 */
jQuery(document).ready(function($) {
    'use strict';

    const TIMU_Admin_UI = {
        init: function() {
            this.initColorPickers();
            this.initMediaUploader();
            this.initDynamicVisibility(0); // Initial load (instant)
            this.bindEvents();
        },

        initColorPickers: function() { 
            if ($.isFunction($.fn.wpColorPicker)) { 
                $('.timu-color-picker').wpColorPicker(); 
            } 
        },

        initMediaUploader: function() {
            $(document).on('click', '.media_btn', function(e) {
                e.preventDefault();
                const btn = $(this), target = $(btn.data('target')), preview = $(btn.data('preview'));
                const frame = wp.media({ title: 'Select Media', multiple: false }).on('select', function() {
                    const asset = frame.state().get('selection').first().toJSON();
                    target.val(asset.url);
                    if (preview.length) { preview.html('<img src="' + asset.url + '" style="max-width:100%;">'); }
                }).open();
            });
        },

        initDynamicVisibility: function(speed) {
            // Find the master input inside the .timu-master-toggle wrapper
            const $masterInput = $('.timu-master-toggle input');
            if ($masterInput.length === 0) return;

            const isEnabled = $masterInput.is(':checked');
            const $masterRow = $masterInput.closest('tr');
            const $siblingRows = $masterRow.siblings('tr');
            const $otherCards = $('.timu-card').not(':first').not('.timu-registration-card');

            // 1. Handle Master Plugin Toggle
            if (!isEnabled) {
                $siblingRows.fadeOut(speed);
                $otherCards.slideUp(speed);
                $('.timu-bulk-actions').fadeOut(speed);
                return;
            }

            // 2. Plugin is Enabled: Show standard rows
            $siblingRows.not(':has([data-show-if-field])').fadeIn(speed);
            $otherCards.slideDown(speed);
            $('.timu-bulk-actions').fadeIn(speed);

            // 3. Handle Dynamic "show_if" Visibility
            $('[data-show-if-field]').each(function() {
                const $childWrapper = $(this);
                const parentId      = $childWrapper.data('show-if-field');
                const requiredValue = $childWrapper.data('show-if-value');
                
                // Selector matches parent field ID regardless of the dynamic plugin prefix
                const $parentInput  = $('input[name$="[' + parentId + ']"], select[name$="[' + parentId + ']"]');
                
                let currentValue;
                if ($parentInput.is(':radio')) {
                    currentValue = $parentInput.filter(':checked').val();
                } else if ($parentInput.is(':checkbox')) {
                    currentValue = $parentInput.is(':checked') ? '1' : '0';
                } else {
                    currentValue = $parentInput.val();
                }

                const $row = $childWrapper.closest('tr');
                if (currentValue == requiredValue) {
                    $row.fadeIn(speed);
                } else {
                    $row.hide(); // Pulls the following rows up immediately
                }
            });
        },

        bindEvents: function() {
            const self = this;
            $(document).on('change', 'input, select', function() {
                self.initDynamicVisibility(300); // Smooth transition on interaction
            });
        }
    };

    TIMU_Admin_UI.init();
});