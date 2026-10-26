jQuery(document).ready(function ($) {
    const adminWrap = $('#advisio-admin-wrap');
    const themeCheckbox = $('#advisio-theme-checkbox');
    const themeLabel = $('#theme-label-text');
    let performanceChartInstance = null;
    let currentChartMode = 'monthly';

    // ==========================================
    // 1. THEME SWITCHER (DARK / LIGHT MODE)
    // ==========================================
    const savedTheme = localStorage.getItem('advisio_theme') || 'dark';
    if (savedTheme === 'light') {
        themeCheckbox.prop('checked', true);
        adminWrap.addClass('light-mode');
        themeLabel.text('☀️ Light Mode');
    } else {
        themeLabel.text('🌙 Dark Mode');
    }

    themeCheckbox.on('change', function () {
        if ($(this).is(':checked')) {
            adminWrap.addClass('light-mode');
            themeLabel.text('☀️ Light Mode');
            localStorage.setItem('advisio_theme', 'light');
        } else {
            adminWrap.removeClass('light-mode');
            themeLabel.text('🌙 Dark Mode');
            localStorage.setItem('advisio_theme', 'dark');
        }
        renderPerformanceChart(currentChartMode);
    });

    // ==========================================
    // 2. TAB NAVIGATION & JUMP LINKS
    // ==========================================
    function activateTab(tabId) {
        $('.advisio-tab-btn').removeClass('active');
        $('.advisio-tab-btn[data-tab="' + tabId + '"]').addClass('active');
        $('.advisio-tab-content').removeClass('active');
        $('#' + tabId).addClass('active');
    }

    $('.advisio-tab-btn').on('click', function (e) {
        e.preventDefault();
        activateTab($(this).data('tab'));
    });

    $('.advisio-jump-tab').on('click', function (e) {
        e.preventDefault();
        const target = $(this).data('target-tab');
        if (target) {
            activateTab(target);
            window.scrollTo({ top: 0, behavior: 'smooth' });
        }
    });

    // ==========================================
    // 3. PERFORMANCE GRAPH (MONTHLY / YEARLY SWITCHER)
    // ==========================================
    function renderPerformanceChart(mode) {
        currentChartMode = mode || 'monthly';
        const canvas = document.getElementById('advisioMonthlyChart');
        if (!canvas || typeof Chart === 'undefined') {
            return;
        }

        const sourceData = (currentChartMode === 'yearly' && AdvisioAdmin.yearlyStats)
            ? AdvisioAdmin.yearlyStats
            : (AdvisioAdmin.monthlyStats || { labels: [], messages: [], leads: [] });

        const isLight = adminWrap.hasClass('light-mode');
        const textColor = isLight ? '#334155' : '#9CA3AF';
        const gridColor = isLight ? 'rgba(203, 213, 225, 0.5)' : 'rgba(55, 65, 81, 0.45)';

        if (performanceChartInstance) {
            performanceChartInstance.destroy();
        }

        const ctx = canvas.getContext('2d');
        performanceChartInstance = new Chart(ctx, {
            type: 'bar',
            data: {
                labels: sourceData.labels || [],
                datasets: [
                    {
                        type: 'bar',
                        label: 'Total Chat Messages',
                        data: sourceData.messages || [],
                        backgroundColor: 'rgba(99, 102, 241, 0.72)',
                        borderColor: '#6366F1',
                        borderWidth: 1,
                        borderRadius: 6,
                        yAxisID: 'y'
                    },
                    {
                        type: 'line',
                        label: 'Leads Captured',
                        data: sourceData.leads || [],
                        backgroundColor: 'rgba(16, 185, 129, 0.2)',
                        borderColor: '#10B981',
                        borderWidth: 3,
                        pointBackgroundColor: '#10B981',
                        pointRadius: 4,
                        tension: 0.35,
                        fill: true,
                        yAxisID: 'y1'
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                interaction: {
                    mode: 'index',
                    intersect: false
                },
                plugins: {
                    legend: {
                        labels: {
                            color: textColor,
                            font: { size: 12, weight: '600' }
                        }
                    }
                },
                scales: {
                    x: {
                        ticks: { color: textColor },
                        grid: { color: gridColor }
                    },
                    y: {
                        type: 'linear',
                        display: true,
                        position: 'left',
                        beginAtZero: true,
                        ticks: { color: textColor, precision: 0 },
                        grid: { color: gridColor },
                        title: {
                            display: true,
                            text: 'Messages',
                            color: textColor
                        }
                    },
                    y1: {
                        type: 'linear',
                        display: true,
                        position: 'right',
                        beginAtZero: true,
                        ticks: { color: '#10B981', precision: 0 },
                        grid: { drawOnChartArea: false },
                        title: {
                            display: true,
                            text: 'Leads Captured',
                            color: '#10B981'
                        }
                    }
                }
            }
        });
    }

    $('.advisio-chart-mode-btn').on('click', function () {
        const mode = $(this).data('chart-mode');
        $('.advisio-chart-mode-btn').removeClass('active');
        $(this).addClass('active');

        if (mode === 'yearly') {
            $('#advisio-chart-heading').text('📈 Performance Graph — Yearly View (Last 12 Months)');
        } else {
            $('#advisio-chart-heading').text('📈 Performance Graph — Monthly View (Last 30 Days)');
        }
        renderPerformanceChart(mode);
    });

    renderPerformanceChart('monthly');

    // ==========================================
    // 4. 50-CHAT AI LEARNING ENGINE PROPOSALS
    // ==========================================
    $(document).on('click', '.advisio-btn-approve, .advisio-btn-reject', function () {
        const btn = $(this);
        const propId = btn.data('id');
        const decision = btn.data('action');
        const card = $('#proposal-card-' + propId);

        btn.prop('disabled', true).text(decision === 'approve' ? 'Approving...' : 'Rejecting...');

        $.post(AdvisioAdmin.ajaxUrl, {
            action: 'advisio_handle_proposal',
            nonce: AdvisioAdmin.nonce,
            proposal_id: propId,
            decision: decision
        }, function (res) {
            if (res && res.success) {
                card.fadeOut(250, function () {
                    $(this).remove();
                    if ($('#advisio-proposals-grid .advisio-proposal-card').length === 0) {
                        $('#advisio-proposals-grid').html(
                            '<div class="advisio-empty-proposals"><p>All strategy proposals have been reviewed! Approved strategies are active in Tab 3.</p></div>'
                        );
                    }
                });

                if (typeof res.data.strategies !== 'undefined') {
                    $('#advisio_approved_strategies').val(res.data.strategies);
                }
                if (typeof res.data.pending !== 'undefined') {
                    $('#kpi-pending-proposals').text(res.data.pending);
                    const pill = $('.advisio-tab-pill');
                    if (res.data.pending > 0) {
                        pill.text(res.data.pending).show();
                    } else {
                        pill.hide();
                    }
                }
            } else {
                alert('Error: ' + ((res && res.data) ? res.data : 'Could not update proposal.'));
                btn.prop('disabled', false).text(decision === 'approve' ? '✓ Approve' : '✕ Reject');
            }
        });
    });

    $('#btn-run-learning-now').on('click', function () {
        const btn = $(this);
        const statusEl = $('#learning-engine-status');
        btn.prop('disabled', true).text('⏳ Analyzing Conversations with Gemini 3.5 Flash Lite...');
        statusEl.html('<span class="advisio-badge">Scanning recent sessions and inquiry trends...</span>');

        $.post(AdvisioAdmin.ajaxUrl, {
            action: 'advisio_run_learning_engine',
            nonce: AdvisioAdmin.nonce
        }, function (res) {
            btn.prop('disabled', false).text('⚡ Analyze & Generate Proposals Now');
            if (res && res.success && Array.isArray(res.data.proposals)) {
                statusEl.html('<span class="advisio-badge success">Generated ' + res.data.proposals.length + ' new AI strategy proposals!</span>');
                $('#no-proposals-placeholder, .advisio-empty-proposals').remove();

                res.data.proposals.forEach(function (p) {
                    const cardHtml =
                        '<div class="advisio-proposal-card" id="proposal-card-' + p.id + '">' +
                        '<div class="proposal-meta">' +
                        '<span class="proposal-badge">' + $('<div>').text(p.category).html() + '</span>' +
                        '<span class="proposal-date">' + $('<div>').text(p.created_at).html() + '</span>' +
                        '</div>' +
                        '<p class="proposal-rationale"><em>"' + $('<div>').text(p.rationale).html() + '"</em></p>' +
                        '<div class="proposal-rule-box"><strong>Proposed Strategy Rule:</strong><p>' + $('<div>').text(p.proposal_text).html() + '</p></div>' +
                        '<div class="proposal-actions">' +
                        '<button type="button" class="advisio-btn advisio-btn-approve" data-id="' + p.id + '" data-action="approve">✓ Approve</button>' +
                        '<button type="button" class="advisio-btn advisio-btn-reject" data-id="' + p.id + '" data-action="reject">✕ Reject</button>' +
                        '</div>' +
                        '</div>';
                    $('#advisio-proposals-grid').prepend(cardHtml);
                });

                const totalCards = $('#advisio-proposals-grid .advisio-proposal-card').length;
                $('#kpi-pending-proposals').text(totalCards);
            } else {
                statusEl.html('<span class="advisio-badge error">Error: ' + ((res && res.data) ? res.data : 'Failed to generate proposals.') + '</span>');
            }
        }).fail(function () {
            btn.prop('disabled', false).text('⚡ Analyze & Generate Proposals Now');
            statusEl.html('<span class="advisio-badge error">Network error while contacting Gemini API.</span>');
        });
    });

    // ==========================================
    // 5. API KEY TEST & SHOW/HIDE
    // ==========================================
    $('#toggle-api-key').on('click', function () {
        const input = $('#advisio_api_key');
        input.attr('type', input.attr('type') === 'password' ? 'text' : 'password');
    });

    $('#btn-test-api').on('click', function () {
        const key = $('#advisio_api_key').val();
        const resDiv = $('#api-test-result');
        resDiv.html('<span class="advisio-badge">Testing Gemini 3.5 Flash Lite connection...</span>');

        $.post(AdvisioAdmin.ajaxUrl, {
            action: 'advisio_test_api_key',
            nonce: AdvisioAdmin.nonce,
            api_key: key
        }, function (res) {
            if (res && res.success) {
                resDiv.html('<span class="advisio-badge success">✓ ' + res.data + '</span>');
            } else {
                resDiv.html('<span class="advisio-badge error">✕ Error: ' + ((res && res.data) ? res.data : 'Connection failed') + '</span>');
            }
        });
    });

    // ==========================================
    // 6. CRASH-PROOF TWO-PHASE QUEUE DEEP INDEXER
    //    Phase 1: advisio_get_index_queue (Fetch IDs)
    //    Phase 2: advisio_process_index_batch (5 IDs per batch + up to 2 retries)
    // ==========================================
    $('.btn-trigger-index').on('click', function () {
        const btn = $(this);
        const requestedScope = btn.data('scope'); // 'shop', 'content', or 'all'
        const isIncremental = parseInt(btn.data('incremental'), 10) === 1;
        const BATCH_SIZE = 5;

        const scopesQueue = requestedScope === 'all' ? ['shop', 'content'] : [requestedScope];
        $('.btn-trigger-index').prop('disabled', true);
        $('#index-progress-container').show();
        $('#index-progress-bar').css('width', '4%');

        function updateScopeBadges(scope, data) {
            if (scope === 'shop') {
                if (data.badge_text) $('#shop-idx-badge').text(data.badge_text);
                if (data.short_text) $('#dash-shop-idx').text(data.short_text);
            } else {
                if (data.badge_text) $('#content-idx-badge').text(data.badge_text);
                if (data.short_text) $('#dash-content-idx').text(data.short_text);
            }
            if (typeof data.combined_total !== 'undefined' && data.combined_total > 0) {
                $('#combined-idx-count').text(data.combined_total + ' indexed items');
            }
        }

        function runNextScope() {
            if (scopesQueue.length === 0) {
                $('.btn-trigger-index').prop('disabled', false);
                $('#index-progress-bar').css('width', '100%');
                $('#persistent-idx-status').attr('class', 'advisio-badge success').text(
                    '✓ Indexing complete (' + (isIncremental ? 'Quick Sync — New/Updated Only' : 'Full Deep Crawl') + ').'
                );
                setTimeout(function () {
                    $('#index-progress-container').fadeOut(400);
                }, 2200);
                return;
            }

            const currentScope = scopesQueue.shift();
            const scopeLabel = currentScope === 'shop' ? 'WooCommerce Shop' : 'Site Content & Footers';

            $('#persistent-idx-status').attr('class', 'advisio-badge').text(
                'Phase 1: Discovering published IDs for ' + scopeLabel + '...'
            );

            // PHASE 1: Lightweight ID Discovery AJAX
            $.post(AdvisioAdmin.ajaxUrl, {
                action: 'advisio_get_index_queue',
                nonce: AdvisioAdmin.nonce,
                scope: currentScope,
                incremental: isIncremental ? 1 : 0
            }, function (queueRes) {
                if (!queueRes || !queueRes.success || !queueRes.data) {
                    $('.btn-trigger-index').prop('disabled', false);
                    $('#persistent-idx-status').attr('class', 'advisio-badge error').text(
                        'Error discovering IDs: ' + ((queueRes && queueRes.data) ? queueRes.data : 'Failed to build queue.')
                    );
                    return;
                }

                const idQueue = Array.isArray(queueRes.data.ids) ? queueRes.data.ids : [];
                const totalItems = idQueue.length;

                // If 0 items need indexing (e.g., Quick Sync with no changes), update badge & advance
                if (totalItems === 0) {
                    updateScopeBadges(currentScope, queueRes.data);
                    $('#index-progress-bar').css('width', '100%');
                    $('#persistent-idx-status').text('Processed 0 of 0 items in ' + scopeLabel + ' (Already up to date).');
                    runNextScope();
                    return;
                }

                // PHASE 2: Micro-Batch Deep Extraction (Strictly 5 IDs per request + up to 2 retries)
                function processMicroBatch(startIndex, retryCount) {
                    const batchIds = idQueue.slice(startIndex, startIndex + BATCH_SIZE);
                    const nextIndex = startIndex + batchIds.length;
                    const isFinalBatch = nextIndex >= totalItems;

                    $('#persistent-idx-status').attr('class', 'advisio-badge').text(
                        'Processed ' + startIndex + ' of ' + totalItems + ' items in ' + scopeLabel +
                        (retryCount > 0 ? ' (Retry ' + retryCount + '/2)...' : '...')
                    );

                    $.post(AdvisioAdmin.ajaxUrl, {
                        action: 'advisio_process_index_batch',
                        nonce: AdvisioAdmin.nonce,
                        scope: currentScope,
                        ids: JSON.stringify(batchIds),
                        is_final: isFinalBatch ? 1 : 0,
                        total_queued: totalItems
                    }, function (batchRes) {
                        if (batchRes && batchRes.success && batchRes.data) {
                            const pct = Math.min(100, Math.round((nextIndex / totalItems) * 100));
                            $('#index-progress-bar').css('width', pct + '%');
                            $('#persistent-idx-status').text(
                                'Processed ' + nextIndex + ' of ' + totalItems + ' items in ' + scopeLabel + ' (' + pct + '%)'
                            );

                            if (!isFinalBatch) {
                                processMicroBatch(nextIndex, 0);
                            } else {
                                updateScopeBadges(currentScope, batchRes.data);
                                runNextScope();
                            }
                        } else if (retryCount < 2) {
                            setTimeout(function () {
                                processMicroBatch(startIndex, retryCount + 1);
                            }, 1000);
                        } else {
                            $('.btn-trigger-index').prop('disabled', false);
                            $('#persistent-idx-status').attr('class', 'advisio-badge error').text(
                                'Error at item ' + startIndex + ' of ' + totalItems + ': ' +
                                ((batchRes && batchRes.data) ? batchRes.data : 'Batch failed after 2 retries.')
                            );
                        }
                    }).fail(function () {
                        if (retryCount < 2) {
                            setTimeout(function () {
                                processMicroBatch(startIndex, retryCount + 1);
                            }, 1200);
                        } else {
                            $('.btn-trigger-index').prop('disabled', false);
                            $('#persistent-idx-status').attr('class', 'advisio-badge error').text(
                                'Network/Server timeout at item ' + startIndex + ' of ' + totalItems + ' after 2 retries.'
                            );
                        }
                    });
                }

                processMicroBatch(0, 0);
            }).fail(function () {
                $('.btn-trigger-index').prop('disabled', false);
                $('#persistent-idx-status').attr('class', 'advisio-badge error').text(
                    'Failed to contact server for Phase 1 ID queue.'
                );
            });
        }

        runNextScope();
    });

    // ==========================================
    // 7. MANUAL KNOWLEDGE & DIRECT FILE UPLOADER
    // ==========================================
    $('#btn-save-knowledge').on('click', function () {
        const text = $('#advisio_manual_text').val();
        const fileInput = document.getElementById('advisio_manual_file');
        const statusDiv = $('#knowledge-save-status');

        const fd = new FormData();
        fd.append('action', 'advisio_upload_knowledge');
        fd.append('nonce', AdvisioAdmin.nonce);
        fd.append('manual_text', text);

        if (fileInput && fileInput.files.length > 0) {
            fd.append('file', fileInput.files[0]);
        }

        statusDiv.html('<span class="advisio-badge">Saving & indexing into scope=custom...</span>');

        $.ajax({
            url: AdvisioAdmin.ajaxUrl,
            type: 'POST',
            data: fd,
            processData: false,
            contentType: false,
            success: function (res) {
                if (res && res.success) {
                    statusDiv.html('<span class="advisio-badge success">✓ ' + res.data + '</span>');
                    if (fileInput && fileInput.files.length > 0) {
                        fileInput.value = '';
                        setTimeout(function () {
                            location.reload();
                        }, 900);
                    }
                } else {
                    statusDiv.html('<span class="advisio-badge error">✕ Error: ' + ((res && res.data) ? res.data : 'Upload failed') + '</span>');
                }
            },
            error: function () {
                statusDiv.html('<span class="advisio-badge error">✕ Network error while uploading knowledge.</span>');
            }
        });
    });

    $(document).on('click', '.btn-delete-doc', function () {
        const docId = $(this).data('id');
        const row = $('#custom-doc-row-' + docId);
        $.post(AdvisioAdmin.ajaxUrl, {
            action: 'advisio_delete_custom_doc',
            nonce: AdvisioAdmin.nonce,
            doc_id: docId
        }, function (res) {
            if (res && res.success) {
                row.fadeOut(200, function () {
                    $(this).remove();
                });
            }
        });
    });

    // ==========================================
    // 8. WIDGET DESIGN & REAL-TIME LIVE PREVIEW
    // ==========================================
    const lpTitle = $('#lp-title');
    const lpWelcomeBubble = $('#lp-welcome-bubble');
    const lpHeaderBg = $('#lp-header-bg');
    const lpHeaderIcon = $('#lp-header-icon');
    const lpLauncher = $('#lp-launcher');
    const lpUserBubble = $('#lp-user-bubble');
    const lpSendBtn = $('#lp-send-btn');
    const lpCbChip = $('#lp-cb-chip');
    const lpIconRender = $('#lp-icon-render');
    const lpProactivePill = $('#lp-proactive-pill');
    const lpLeadSubmitBtn = $('#lp-lead-submit-btn');
    const lpPrefPillEmail = $('#lp-pref-pill-email');

    if ($('.advisio-color-picker').length) {
        $('.advisio-color-picker').wpColorPicker({
            change: function () {
                setTimeout(updateLivePreview, 20);
            },
            clear: function () {
                setTimeout(updateLivePreview, 20);
            }
        });
    }

    $('#preview_chat_title, #preview_welcome_msg, #preview_proactive_text, #advisio_label_callback').on('input', updateLivePreview);
    $('#preview_proactive_toggle').on('change', updateLivePreview);

    $('#advisio_launcher_icon').on('change', function () {
        if ($(this).val() === 'custom') {
            $('#custom-icon-wrapper').show();
        } else {
            $('#custom-icon-wrapper').hide();
        }
        updateLivePreview();
    });

    let mediaUploader;
    $('#btn-upload-icon').on('click', function (e) {
        e.preventDefault();
        if (mediaUploader) {
            mediaUploader.open();
            return;
        }
        mediaUploader = wp.media.frames.file_frame = wp.media({
            title: 'Select Custom Chat Launcher Icon',
            button: { text: 'Use This Icon' },
            multiple: false
        });
        mediaUploader.on('select', function () {
            const attachment = mediaUploader.state().get('selection').first().toJSON();
            $('#advisio_custom_icon').val(attachment.url);
            $('#custom-icon-preview').attr('src', attachment.url).show();
            updateLivePreview();
        });
        mediaUploader.open();
    });

    function updateLivePreview() {
        const titleText = $('#preview_chat_title').val() || 'Advisio AI Kundtjänst';
        lpTitle.text(titleText);

        const welcomeText = $('#preview_welcome_msg').val() || 'Hej! Välkommen till vår kundtjänst. Hur kan jag hjälpa dig idag?';
        lpWelcomeBubble.text(welcomeText);

        const cbLabel = $('#advisio_label_callback').val() || '📞 Bli kontaktad av en säljare';
        lpCbChip.text(cbLabel);

        const primaryColor = $('#preview_color_primary').val() || '#6366F1';
        lpLauncher.css('background-color', primaryColor);
        lpUserBubble.css('background-color', primaryColor);
        lpSendBtn.css('background-color', primaryColor);
        lpCbChip.css({
            'background-color': primaryColor,
            'border-color': primaryColor,
            'color': '#FFFFFF'
        });
        if (lpLeadSubmitBtn.length) {
            lpLeadSubmitBtn.css('background-color', primaryColor);
        }
        if (lpPrefPillEmail.length) {
            lpPrefPillEmail.css('background-color', primaryColor);
        }

        const headerColor = $('#preview_color_header').val() || '#1E293B';
        lpHeaderBg.css('background-color', headerColor);

        if ($('#preview_proactive_toggle').is(':checked')) {
            lpProactivePill.show().text($('#preview_proactive_text').val() || 'Behöver du hjälp? 👋');
        } else {
            lpProactivePill.hide();
        }

        const iconType = $('#advisio_launcher_icon').val();
        let iconHtml = '';
        if (iconType === 'custom') {
            const customUrl = $('#advisio_custom_icon').val();
            if (customUrl) {
                iconHtml = '<img src="' + customUrl + '" alt="Icon" style="width:100%;height:100%;border-radius:50%;object-fit:cover;">';
            } else {
                iconHtml = '<svg fill="currentColor" viewBox="0 0 24 24"><path d="M20 2H4c-1.1 0-2 .9-2 2v18l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2z"/></svg>';
            }
        } else if (iconType === 'preset-2') {
            iconHtml = '<svg fill="currentColor" viewBox="0 0 24 24"><path d="M12 2L9.5 9.5 2 12l7.5 2.5L12 22l2.5-7.5L22 12l-7.5-2.5L12 2z"/></svg>';
        } else if (iconType === 'preset-3') {
            iconHtml = '<svg fill="currentColor" viewBox="0 0 24 24"><path d="M12 3a9 9 0 00-9 9v4a3 3 0 003 3h1v-6H5v-1a7 7 0 0114 0v1h-2v6h1a3 3 0 003-3v-4a9 9 0 00-9-9z"/></svg>';
        } else if (iconType === 'preset-4') {
            iconHtml = '<svg fill="currentColor" viewBox="0 0 24 24"><path d="M13 2L3 14h9l-1 8 10-12h-9l1-8z"/></svg>';
        } else {
            iconHtml = '<svg fill="currentColor" viewBox="0 0 24 24"><path d="M20 2H4c-1.1 0-2 .9-2 2v18l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2z"/></svg>';
        }
        lpIconRender.html(iconHtml);
        if (lpHeaderIcon.length) {
            lpHeaderIcon.html(iconHtml);
        }
    }

    updateLivePreview();

    // ==========================================
    // 9. INTERACTIVE LEAD FORM FIELD BUILDER (TAB 5)
    // ==========================================
    const fieldsInput = $('#advisio_lead_form_fields_input');
    const builderList = $('#advisio-field-builder-list');
    const leadLivePreview = $('#advisio-lead-form-live-preview');

    let leadFields = [];
    try {
        leadFields = JSON.parse(fieldsInput.val() || '[]');
    } catch (e) {
        leadFields = [];
    }
    if (!Array.isArray(leadFields) || leadFields.length === 0) {
        leadFields = [
            { id: 'field_name', label: 'Namn', type: 'text', placeholder: 'Ditt för- och efternamn', required: true, options: '' },
            { id: 'field_email', label: 'E-postadress', type: 'email', placeholder: 'din@epost.se', required: true, options: '' },
            { id: 'field_phone', label: 'Telefonnummer', type: 'tel', placeholder: '070-123 45 67', required: false, options: '' }
        ];
    }

    function syncLeadBuilderState() {
        fieldsInput.val(JSON.stringify(leadFields));
        renderLeadLivePreview();
    }

    function renderLeadBuilderUI() {
        if (!builderList.length) return;
        builderList.empty();

        leadFields.forEach(function (field, idx) {
            const isSelect = field.type === 'select';
            const row = $(
                '<div class="advisio-builder-item" data-idx="' + idx + '">' +
                '<div class="builder-flex-bar">' +
                '<div class="builder-col builder-col-label">' +
                '<label class="builder-mini-label">Field Label</label>' +
                '<input type="text" class="advisio-input b-label" placeholder="e.g. Namn">' +
                '</div>' +
                '<div class="builder-col builder-col-type">' +
                '<label class="builder-mini-label">Type</label>' +
                '<select class="advisio-input b-type">' +
                '<option value="text">Text</option>' +
                '<option value="email">Email</option>' +
                '<option value="tel">Phone</option>' +
                '<option value="textarea">Textarea</option>' +
                '<option value="select">Dropdown</option>' +
                '</select>' +
                '</div>' +
                '<div class="builder-col builder-col-placeholder">' +
                '<label class="builder-mini-label">Placeholder</label>' +
                '<input type="text" class="advisio-input b-placeholder" placeholder="Placeholder text...">' +
                '</div>' +
                '<div class="builder-col builder-col-req">' +
                '<label class="builder-mini-label">Required</label>' +
                '<label class="advisio-mini-switch" title="Toggle Required Field">' +
                '<input type="checkbox" class="b-required">' +
                '<span class="mini-slider"></span>' +
                '</label>' +
                '</div>' +
                '<div class="builder-col builder-col-del">' +
                '<label class="builder-mini-label">&nbsp;</label>' +
                '<button type="button" class="advisio-btn advisio-btn-reject b-remove" title="Delete Field">✕</button>' +
                '</div>' +
                '</div>' +
                '<div class="builder-options-row" style="' + (isSelect ? 'display:block;' : 'display:none;') + '">' +
                '<label class="builder-mini-label">Dropdown Options (comma-separated, e.g., <em>Fönster, Altandörr, Helrenovering</em>)</label>' +
                '<input type="text" class="advisio-input b-options" value="">' +
                '</div>' +
                '</div>'
            );

            row.find('.b-label').val(field.label || '');
            row.find('.b-type').val(field.type || 'text');
            row.find('.b-placeholder').val(field.placeholder || '');
            row.find('.b-required').prop('checked', !!field.required);
            row.find('.b-options').val(field.options || '');

            builderList.append(row);
        });

        syncLeadBuilderState();
    }

    function renderLeadLivePreview() {
        if (!leadLivePreview.length) return;
        leadLivePreview.empty();

        $('#lp-lead-header-text').text($('#advisio_label_lead_header').val() || 'Fyll i dina uppgifter så återkommer en rådgivare:');

        leadFields.forEach(function (field) {
            const labelTxt = field.label || 'Field';
            const ph = field.placeholder || labelTxt;
            const fieldBlock = $('<div class="lp-lead-field-group"></div>');
            const labelEl = $('<label class="lp-lead-field-label"></label>').text(labelTxt);
            if (field.required) {
                labelEl.append('<span class="lp-req-star"> *</span>');
            }
            fieldBlock.append(labelEl);

            let inputEl;
            if (field.type === 'textarea') {
                inputEl = $('<textarea class="lp-lead-preview-input" rows="2" disabled></textarea>').attr('placeholder', ph + (field.required ? ' *' : ''));
            } else if (field.type === 'select') {
                inputEl = $('<select class="lp-lead-preview-input" disabled></select>');
                inputEl.append($('<option></option>').text(ph + (field.required ? ' *' : '')));
            } else {
                inputEl = $('<input class="lp-lead-preview-input" type="text" disabled>').attr('placeholder', ph + (field.required ? ' *' : ''));
            }
            fieldBlock.append(inputEl);
            leadLivePreview.append(fieldBlock);
        });
    }

    $(document).on('input change', '#advisio-field-builder-list .advisio-builder-item input, #advisio-field-builder-list .advisio-builder-item select', function () {
        const item = $(this).closest('.advisio-builder-item');
        const idx = parseInt(item.data('idx'), 10);
        if (isNaN(idx) || !leadFields[idx]) return;

        leadFields[idx].label = item.find('.b-label').val();
        leadFields[idx].type = item.find('.b-type').val();
        leadFields[idx].placeholder = item.find('.b-placeholder').val();
        leadFields[idx].required = item.find('.b-required').is(':checked');
        leadFields[idx].options = item.find('.b-options').val();

        if (leadFields[idx].type === 'select') {
            item.find('.builder-options-row').slideDown(150);
        } else {
            item.find('.builder-options-row').slideUp(150);
        }

        syncLeadBuilderState();
    });

    $(document).on('click', '#advisio-field-builder-list .b-remove', function () {
        const item = $(this).closest('.advisio-builder-item');
        const idx = parseInt(item.data('idx'), 10);
        if (leadFields.length <= 1) {
            alert('At least one field is required in the lead form.');
            return;
        }
        leadFields.splice(idx, 1);
        renderLeadBuilderUI();
    });

    $('#btn-add-lead-field').on('click', function () {
        leadFields.push({
            id: 'field_' + Date.now(),
            label: 'New Field',
            type: 'text',
            placeholder: 'Enter value...',
            required: false,
            options: ''
        });
        renderLeadBuilderUI();
    });

    $('#advisio_label_lead_header').on('input', function () {
        $('#lp-lead-header-text').text($(this).val());
    });

    renderLeadBuilderUI();
});
