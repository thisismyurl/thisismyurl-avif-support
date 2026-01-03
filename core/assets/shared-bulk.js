/**
 * TIMU Shared Bulk Processing
 * Handles batch optimization, restoration loops, and remote installation.
 * Version: 1.26010218
 * 
 */
jQuery(document).ready(function($) {
    'use strict';

    const TIMU_Bulk = {
        init: function() {
            this.bindActions();
        },

        bindActions: function() {
            $(document).on('click', '#timu-run-bulk', (e) => this.handleBulkProcess(e));
            $(document).on('click', '#timu-undo-bulk', (e) => this.handleBulkRestore(e));
            $(document).on('click', '.timu-process-single', (e) => this.handleSingleProcess(e));
            $(document).on('click', '.timu-restore-single', (e) => this.handleSingleRestore(e));
            $(document).on('click', '.timu-install-btn', (e) => this.handleInstaller(e));
        },

        handleBulkProcess: function(e) {
            e.preventDefault();
            const $btn = $(e.target);
            if (!confirm('Optimize all pending images? This may take several minutes.')) return;

            $btn.prop('disabled', true).addClass('updating-message').text('Processing...');
            
            const doBatch = () => {
                $.post(timu_core_vars.ajax_url, {
                    action: 'timu_run_bulk_process',
                    nonce: timu_core_vars.nonce
                }, (res) => {
                    if (res.success) {
                        if (res.data.done) {
                            $btn.text('All Done!').removeClass('updating-message');
                            location.reload();
                        } else {
                            $btn.text('Processing... (' + res.data.count + ' done)');
                            doBatch();
                        }
                    } else {
                        alert(res.data);
                        $btn.prop('disabled', false).removeClass('updating-message').text('Bulk Process Media Library');
                    }
                });
            };
            doBatch();
        },

        handleSingleProcess: function(e) {
            const $btn = $(e.target);
            const $row = $btn.closest('tr');
            $btn.prop('disabled', true).text('...');

            $.post(timu_core_vars.ajax_url, {
                action: 'timu_process_single',
                nonce: timu_core_vars.nonce,
                attachment_id: $btn.data('id')
            }, (res) => {
                if (res.success) {
                    $row.css('background-color', '#f0fff0').fadeOut(400, () => location.reload());
                } else {
                    alert(res.data);
                    $btn.prop('disabled', false).text('Process');
                }
            });
        },

        handleSingleRestore: function(e) {
            const $btn = $(e.target);
            const $row = $btn.closest('tr');
            if (!confirm('Restore this image to its original format?')) return;

            $btn.prop('disabled', true).text('...');

            $.post(timu_core_vars.ajax_url, {
                action: 'timu_restore_single',
                nonce: timu_core_vars.nonce,
                attachment_id: $btn.data('id')
            }, (res) => {
                if (res.success) {
                    $row.css('background-color', '#fff0f0').fadeOut(400, () => location.reload());
                } else {
                    alert(res.data);
                    $btn.prop('disabled', false).text('Restore');
                }
            });
        },

        handleBulkRestore: function(e) {
            const $btn = $(e.target);
            if (!confirm('Restore all images and delete optimized versions? This cannot be undone.')) return;

            $btn.prop('disabled', true).text('Restoring Library...');
            $.post(timu_core_vars.ajax_url, {
                action: 'timu_undo_bulk_changes',
                nonce: timu_core_vars.nonce
            }, (res) => {
                alert(res.data);
                location.reload();
            });
        },

        handleInstaller: function(e) {
            e.preventDefault();
            const $btn = $(e.target);
            if ($btn.hasClass('updating-message')) return;

            $btn.addClass('updating-message').text('Installing...');

            $.post(timu_core_vars.ajax_url, {
                action: 'timu_install_tool',
                slug: $btn.data('slug'),
                download_url: $btn.data('url'),
                nonce: timu_core_vars.nonce
            }, (res) => {
                if (res.success) {
                    $btn.removeClass('updating-message').css('color', '#46b450').text('Installed!');
                    setTimeout(() => location.reload(), 1000);
                } else {
                    $btn.removeClass('updating-message').text('Error.');
                    alert(res.data);
                }
            });
        }
    };

    TIMU_Bulk.init();
});
