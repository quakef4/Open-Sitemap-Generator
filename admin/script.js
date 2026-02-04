/**
 * Script Admin - Infobit Sitemap Generator v1.2.0
 */

(function($) {
    'use strict';
    
    $(document).ready(function() {
        
        // ==================== SITEMAP ====================
        
        $('#btn-regenerate').on('click', function() {
            var $btn = $(this);
            var originalText = $btn.html();
            
            $btn.prop('disabled', true).html(
                '<span class="dashicons dashicons-update"></span> Generazione...' +
                '<span class="infobit-loading"></span>'
            );
            hideResult('#action-result');
            
            $.post(osgAdmin.ajaxurl, {
                action: 'osg_generate_sitemap',
                nonce: osgAdmin.nonce
            }, function(response) {
                if (response.success) {
                    showResult('#action-result', 'success', response.data.message);
                    $('#last-generated').text(response.data.time);
                } else {
                    showResult('#action-result', 'error', response.data || 'Errore sconosciuto');
                }
            }).fail(function(xhr, status, error) {
                showResult('#action-result', 'error', 'Errore di connessione: ' + error);
            }).always(function() {
                $btn.prop('disabled', false).html(originalText);
            });
        });
        
        // ==================== GOOGLE PING ====================
        
        $('#btn-ping-google').on('click', function() {
            var $btn = $(this);
            var originalText = $btn.html();
            
            $btn.prop('disabled', true).html(
                '<span class="dashicons dashicons-megaphone"></span> Invio...' +
                '<span class="infobit-loading"></span>'
            );
            hideResult('#action-result');
            
            $.post(osgAdmin.ajaxurl, {
                action: 'osg_ping_google',
                nonce: osgAdmin.nonce
            }, function(response) {
                if (response.success) {
                    showResult('#action-result', 'success', response.data.message);
                } else {
                    showResult('#action-result', 'error', response.data || 'Errore');
                }
            }).fail(function(xhr, status, error) {
                showResult('#action-result', 'error', 'Errore: ' + error);
            }).always(function() {
                $btn.prop('disabled', false).html(originalText);
            });
        });
        
        // ==================== INDEXNOW ====================
        
        // Verifica file chiave
        $('#btn-verify-key').on('click', function() {
            var $btn = $(this);
            var $status = $('#key-file-status');
            var keyUrl = $btn.closest('td').find('a').attr('href');
            
            $status.removeClass('success error').text('Verifica...');
            $btn.prop('disabled', true);
            
            $.ajax({
                url: keyUrl,
                type: 'GET',
                dataType: 'text',
                success: function(data) {
                    if (data && data.trim().length === 32 && /^[a-z0-9]+$/.test(data.trim())) {
                        $status.addClass('success').text('✓ File OK');
                    } else {
                        $status.addClass('error').text('✗ Contenuto non valido');
                    }
                },
                error: function() {
                    $status.addClass('error').text('✗ File non trovato - Vai su Impostazioni > Permalink e salva');
                },
                complete: function() {
                    $btn.prop('disabled', false);
                }
            });
        });
        
        // Invio bulk IndexNow
        $('#btn-indexnow-bulk').on('click', function() {
            if (!confirm('Vuoi inviare TUTTI gli URL pubblicati a Bing/Yandex?\n\nQuesta operazione bypassa la coda e invia immediatamente.')) {
                return;
            }
            
            var $btn = $(this);
            var originalText = $btn.html();
            
            $btn.prop('disabled', true).html(
                '<span class="dashicons dashicons-upload"></span> Invio...' +
                '<span class="infobit-loading"></span>'
            );
            hideResult('#indexnow-result');
            
            $.post(osgAdmin.ajaxurl, {
                action: 'osg_indexnow_bulk',
                nonce: osgAdmin.nonce
            }, function(response) {
                if (response.success) {
                    showResult('#indexnow-result', 'success', response.data.message);
                    setTimeout(function() { location.reload(); }, 2000);
                } else {
                    showResult('#indexnow-result', 'error', response.data || 'Errore');
                }
            }).fail(function(xhr, status, error) {
                showResult('#indexnow-result', 'error', 'Errore: ' + error);
            }).always(function() {
                $btn.prop('disabled', false).html(originalText);
            });
        });
        
        // ==================== CODA ====================
        
        // Processa coda adesso
        $('#btn-process-queue').on('click', function() {
            var $btn = $(this);
            var originalText = $btn.html();
            
            $btn.prop('disabled', true).html(
                '<span class="dashicons dashicons-controls-play"></span> Elaborazione...' +
                '<span class="infobit-loading"></span>'
            );
            hideResult('#queue-result');
            
            $.post(osgAdmin.ajaxurl, {
                action: 'osg_indexnow_process_now',
                nonce: osgAdmin.nonce
            }, function(response) {
                if (response.success) {
                    showResult('#queue-result', 'success', response.data.message);
                    $('.queue-count').text(response.data.remaining);
                    if (response.data.remaining === 0) {
                        setTimeout(function() { location.reload(); }, 1500);
                    }
                } else {
                    showResult('#queue-result', 'error', response.data || 'Errore');
                }
            }).fail(function(xhr, status, error) {
                showResult('#queue-result', 'error', 'Errore: ' + error);
            }).always(function() {
                $btn.prop('disabled', false).html(originalText);
            });
        });
        
        // Svuota coda
        $('#btn-clear-queue').on('click', function() {
            if (!confirm('Sei sicuro di voler svuotare la coda?\n\nGli URL in attesa NON verranno inviati a IndexNow.')) {
                return;
            }
            
            var $btn = $(this);
            var originalText = $btn.html();
            
            $btn.prop('disabled', true).html(
                '<span class="dashicons dashicons-trash"></span> Eliminazione...'
            );
            
            $.post(osgAdmin.ajaxurl, {
                action: 'osg_indexnow_clear_queue',
                nonce: osgAdmin.nonce
            }, function(response) {
                if (response.success) {
                    showResult('#queue-result', 'success', response.data.message);
                    setTimeout(function() { location.reload(); }, 1000);
                } else {
                    showResult('#queue-result', 'error', response.data || 'Errore');
                }
            }).fail(function(xhr, status, error) {
                showResult('#queue-result', 'error', 'Errore: ' + error);
            }).always(function() {
                $btn.prop('disabled', false).html(originalText);
            });
        });
        
        // ==================== HELPER ====================
        
        function showResult(selector, type, message) {
            $(selector)
                .removeClass('notice-success notice-error')
                .addClass('notice-' + type)
                .html('<p>' + message + '</p>')
                .fadeIn();
        }
        
        function hideResult(selector) {
            $(selector).fadeOut();
        }
        
        // Click-to-copy sugli URL sitemap
        $('.infobit-sitemap-links code').css('cursor', 'pointer').on('click', function() {
            var text = $(this).text();
            var $el = $(this);
            
            if (navigator.clipboard) {
                navigator.clipboard.writeText(text).then(function() {
                    var origBg = $el.css('background');
                    $el.css('background', '#d4edda');
                    setTimeout(function() { $el.css('background', origBg); }, 500);
                });
            }
        }).attr('title', 'Clicca per copiare');
    });
    
})(jQuery);
