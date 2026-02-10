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
        
        // ==================== RICH RESULTS ====================

        $('#btn-rich-results-test').on('click', function() {
            var $btn = $(this);
            var originalText = $btn.html();
            var $result = $('#rich-results-test-result');

            $btn.prop('disabled', true).html(
                '<span class="dashicons dashicons-yes-alt"></span> Test in corso...' +
                '<span class="infobit-loading"></span>'
            );
            $result.hide();

            $.post(osgAdmin.ajaxurl, {
                action: 'osg_rich_results_test',
                nonce: osgAdmin.nonce
            }, function(response) {
                if (response.success && response.data) {
                    var html = '<div class="rich-results-test-output" style="margin-top:10px;">';
                    var allPass = response.data.success;

                    html += '<p style="font-weight:bold;color:' + (allPass ? '#00a32a' : '#d63638') + ';">';
                    html += allPass ? '&#10003; Tutti i test superati!' : '&#10007; Alcuni test non superati';
                    html += '</p>';

                    html += '<table class="widefat striped" style="max-width:700px;">';
                    html += '<thead><tr><th>Test</th><th>Esito</th><th>Dettaglio</th></tr></thead><tbody>';

                    var results = response.data.results || [];
                    for (var i = 0; i < results.length; i++) {
                        var r = results[i];
                        var icon = r.pass ? '<span style="color:#00a32a;">&#10003;</span>' : '<span style="color:#d63638;">&#10007;</span>';
                        html += '<tr>';
                        html += '<td>' + escHtml(r.name) + '</td>';
                        html += '<td>' + icon + '</td>';
                        html += '<td><small>' + escHtml(r.detail) + '</small></td>';
                        html += '</tr>';
                    }

                    html += '</tbody></table></div>';
                    $result.html(html).fadeIn();
                } else {
                    $result.html('<p style="color:#d63638;">Errore durante il test: ' + escHtml(response.data || 'sconosciuto') + '</p>').fadeIn();
                }
            }).fail(function(xhr, status, error) {
                $result.html('<p style="color:#d63638;">Errore di connessione: ' + escHtml(error) + '</p>').fadeIn();
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
        
        function escHtml(str) {
            if (!str) return '';
            return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
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
