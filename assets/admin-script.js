jQuery(document).ready(function ($) {
    const adminWrap = $('#advisio-admin-wrap');
    if (!adminWrap.length) {
        return; // Guard: Do not execute on non-Advisio admin pages
    }

    // Defensive fallback: Guaranteed non-null AdvisioAdmin object
    const AdvisioAdmin = window.AdvisioAdmin || {
        ajaxUrl: (typeof ajaxurl !== 'undefined' ? ajaxurl : '/wp-admin/admin-ajax.php'),
        nonce: '',
        homeUrl: '/',
        monthlyStats: { labels: [], messages: [], leads: [] },
        yearlyStats: { labels: [], messages: [], leads: [] },
        manualList: [],
        memoryStats: { total: 0, manual: 0, global_site: 0, content: 0, shop: 0, file: 0 }
    };
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
        if (!tabId) return;
        $('.advisio-tab-btn').removeClass('active');
        $('.advisio-tab-btn[data-tab="' + tabId + '"]').addClass('active');
        $('.advisio-tab-content').removeClass('active');
        $('#' + tabId).addClass('active');
        try {
            sessionStorage.setItem('advisio_active_tab', tabId);
        } catch (e) {}
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

    // Ensure initial active tab state is restored or defaults to tab-dashboard
    try {
        const savedTab = sessionStorage.getItem('advisio_active_tab');
        if (savedTab && $('#' + savedTab).length) {
            activateTab(savedTab);
        } else {
            activateTab('tab-dashboard');
        }
    } catch (e) {
        activateTab('tab-dashboard');
    }

    // ==========================================
    // 3. PERFORMANCE GRAPH (MONTHLY / YEARLY SWITCHER)
    // ==========================================
    function renderPerformanceChart(mode) {
        try {
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
    } catch (chartErr) {
        console.error('[Advisio Admin] Chart render error:', chartErr);
    }
}

    try {
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

        if (typeof Chart !== 'undefined') {
            renderPerformanceChart('monthly');
        } else {
            setTimeout(function () {
                if (typeof Chart !== 'undefined') {
                    renderPerformanceChart('monthly');
                }
            }, 600);
        }
    } catch (e) {
        console.error('[Advisio Admin] Chart mode setup error:', e);
    }

    // ==========================================
    try {
    // 4. API KEY TEST & VISIBILITY TOGGLE
    // ==========================================
    $('#toggle-api-key').on('click', function () {
        const input = $('#advisio_api_key');
        const type = input.attr('type') === 'password' ? 'text' : 'password';
        input.attr('type', type);
        $(this).text(type === 'password' ? '👁️' : '🙈');
    });

    $('#btn-test-api').on('click', function () {
        const btn = $(this);
        const apiKey = $('#advisio_api_key').val().trim();
        const resDiv = $('#api-test-result');

        if (!apiKey) {
            resDiv.html('<span class="advisio-badge error">Please enter an API Key.</span>');
            return;
        }

        btn.prop('disabled', true).text('Testing API Key...');
        resDiv.html('<span class="advisio-badge">Testing connection to Gemini 3.5 Flash Lite...</span>');

        $.post(AdvisioAdmin.ajaxUrl, {
            action: 'advisio_test_api_key',
            nonce: AdvisioAdmin.nonce,
            api_key: apiKey
        }, function (res) {
            btn.prop('disabled', false).text('Save & Test API Key');
            if (res && res.success) {
                resDiv.html('<span class="advisio-badge success">✓ ' + $('<div>').text(res.data).html() + '</span>');
            } else {
                resDiv.html('<span class="advisio-badge error">✕ Error: ' + $('<div>').text((res && res.data) ? res.data : 'Failed to connect').html() + '</span>');
            }
        }).fail(function () {
            btn.prop('disabled', false).text('Save & Test API Key');
            resDiv.html('<span class="advisio-badge error">✕ Network error while verifying API key.</span>');
        });
    });

    
    } catch (secErr) {
        console.error('[Advisio Admin Error in 4. API KEY TEST]:', secErr);
    }

// ==========================================
    try {
    // 5. 50-CHAT AI LEARNING ENGINE PROPOSALS
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

                const newPending = $('#advisio-proposals-grid .advisio-proposal-card').length;
                $('#kpi-pending-proposals').text(newPending);
                const pill = $('.advisio-tab-pill');
                if (newPending > 0) {
                    pill.text(newPending).show();
                }
            } else {
                statusEl.html('<span class="advisio-badge error">✕ ' + ((res && res.data) ? res.data : 'Analysis failed') + '</span>');
            }
        });
    });

    // ==========================================
    // 6. HELPER: UPDATE LIVE MEMORY STAT COUNTERS
    // ==========================================
    function updateMemoryBadges(stats) {
        if (!stats) return;
        if (typeof stats.total !== 'undefined') $('#mem-stat-total').text(stats.total);
        if (typeof stats.live !== 'undefined') $('#mem-stat-live').text(stats.live);
        if (typeof stats.pending !== 'undefined') $('#mem-stat-pending').text(stats.pending);
        if (typeof stats.content !== 'undefined' || typeof stats.global_site !== 'undefined') {
            const cTotal = (parseInt(stats.content, 10) || 0) + (parseInt(stats.global_site, 10) || 0);
            $('#mem-stat-content').text(cTotal);
        }
        if (typeof stats.shop !== 'undefined') $('#mem-stat-shop').text(stats.shop);
        if (typeof stats.manual !== 'undefined') $('#mem-stat-manual').text(stats.manual);
        if (typeof stats.global_site !== 'undefined') $('#mem-stat-global').text(stats.global_site);
        if (typeof stats.file !== 'undefined') $('#mem-stat-file').text(stats.file);
    }

    
    } catch (secErr) {
        console.error('[Advisio Admin Error in 5. 50-CHAT AI LEARNING]:', secErr);
    }

// ==========================================
    try {
    // 7. 5-STAGE SEQUENTIAL DEEP CRAWLER
    //    Accuracy over speed (1–3 items per batch)
    // ==========================================
    const stageLabels = {
        1: 'Homepage',
        2: 'Footer',
        3: 'Menus',
        4: 'Subpages',
        5: 'Products'
    };

    let crawlCancelled = false;

    $('#btn-stop-crawl').on('click', function (e) {
        e.preventDefault();
        crawlCancelled = true;
        $('#crawl-current-item').text('Avbryter körning...');
    });

    const allCrawlBtns = $('#btn-crawl-website, #btn-quick-sync, #btn-crawl-shop, #btn-sync-shop, #btn-crawl-content, #btn-sync-content, .btn-trigger-index');

    allCrawlBtns.on('click', function () {
        const btn = $(this);
        const btnId = btn.attr('id') || '';
        const requestedScope = btn.data('scope') || 'all'; // 'shop', 'content', or 'all'
        const isIncremental = btnId.indexOf('sync') !== -1 || parseInt(btn.data('incremental'), 10) === 1;

        crawlCancelled = false;
        const tracker = $('#advisio-crawl-tracker');
        const progressBar = $('#crawl-progress-fill, #index-progress-bar');
        const progressPct = $('#crawl-progress-text, #crawl-progress-pct');
        const currentItem = $('#crawl-current-item');
        const liveFeed = $('#crawl-live-feed');
        const summaryBox = $('#crawl-summary');
        const statusBadge = $('#persistent-idx-status, #crawl-current-item');

        allCrawlBtns.prop('disabled', true);
        $('#btn-stop-crawl').show();
        tracker.slideDown(250);
        summaryBox.hide().empty();
        liveFeed.empty();
        progressBar.css('width', '2%');
        progressPct.text('0%');
        currentItem.text('Initializing 5-stage sequential crawler queue...');

        // Reset stage tracker pills
        $('.crawl-stage').removeClass('active completed');
        for (let s = 1; s <= 5; s++) {
            $('#crawl-stage-count-' + s).text('');
        }

        statusBadge.attr('class', 'advisio-badge').text('Phase 1: Building crawler queue for ' + requestedScope + ' (' + (isIncremental ? 'Quick Sync' : 'Full Deep Index') + ')...');

        // Step 1: Discover queue
        $.post(AdvisioAdmin.ajaxUrl, {
            action: 'advisio_get_index_queue',
            nonce: AdvisioAdmin.nonce,
            scope: requestedScope,
            incremental: isIncremental ? 1 : 0
        }, function (queueRes) {
            if (!queueRes || !queueRes.success || !queueRes.data) {
                allCrawlBtns.prop('disabled', false); $('#btn-stop-crawl').hide();
                statusBadge.attr('class', 'advisio-badge error').text('Error initializing queue: ' + ((queueRes && queueRes.data) ? queueRes.data : 'Server error.'));
                currentItem.text('Failed to initialize crawler.');
                return;
            }

            const queueData = queueRes.data;
            const fullQueue = Array.isArray(queueData.queue) ? queueData.queue : [];
            const totalItems = fullQueue.length;
            const stageCounts = queueData.stage_counts || {};

            // Display counts on stages
            for (let s = 1; s <= 5; s++) {
                if (typeof stageCounts[s] !== 'undefined' && stageCounts[s] > 0) {
                    $('#crawl-stage-count-' + s).text('(' + stageCounts[s] + ')');
                }
            }

            if (totalItems === 0) {
                // Already completely up to date
                $('.crawl-stage').addClass('completed');
                progressBar.css('width', '100%');
                progressPct.text('100%');
                currentItem.text('All content is fully up to date. No changes detected.');
                statusBadge.attr('class', 'advisio-badge success').text('✓ Quick Sync: Knowledge base is already up to date.');
                if (queueData.shop_badge) $('#shop-idx-badge').text(queueData.shop_badge);
                if (queueData.content_badge) $('#content-idx-badge').text(queueData.content_badge);
                if (queueData.combined_total) $('#combined-idx-count').text(queueData.combined_total + ' indexed items');
                updateMemoryBadges(queueData.memory_stats);
                if (queueData.last_index_stats) {
                    const s = queueData.last_index_stats;
                    $('#sync-stat-timestamp').text(s.timestamp || '');
                    $('#sync-stat-type').text(s.sync_type || '');
                    $('#sync-val-new').text(s.new_added || 0);
                    $('#sync-val-updated').text(s.updated || 0);
                    if (s.breakdown) {
                        $('#sync-breakdown-gf').text(s.breakdown.startsida_footer || 0);
                        $('#sync-breakdown-ms').text(s.breakdown.menyer_sidor || 0);
                        $('#sync-breakdown-pk').text(s.breakdown.produkter || 0);
                        $('#sync-breakdown-mf').text(s.breakdown.manuell_filer || 0);
                    }
                    $('#advisio-last-sync-card').slideDown(250);
                }
                allCrawlBtns.prop('disabled', false); $('#btn-stop-crawl').hide();
                return;
            }

            let currentIndex = 0;
            let currentStage = 0;
            let hadErrors = false;
            const BATCH_SIZE = 2; // Process 1-3 items per batch to avoid PHP memory exhaustion

            function setStageActive(stage) {
                if (stage === currentStage) return;
                currentStage = stage;
                $('.crawl-stage').each(function () {
                    const st = parseInt($(this).data('stage'), 10);
                    if (st < currentStage) {
                        $(this).removeClass('active').addClass('completed');
                    } else if (st === currentStage) {
                        $(this).addClass('active').removeClass('completed');
                    } else {
                        $(this).removeClass('active completed');
                    }
                });
            }

            function processNextBatch() {
                if (crawlCancelled) {
                    allCrawlBtns.prop('disabled', false);
                    $('#btn-stop-crawl').hide();
                    currentItem.text('Körning avbruten av användaren.');
                    return;
                }
                if (currentIndex >= totalItems) {
                    return;
                }

                const batch = fullQueue.slice(currentIndex, currentIndex + BATCH_SIZE);
                const nextIndex = currentIndex + batch.length;
                const isFinal = nextIndex >= totalItems;

                // Update active stage indicator
                if (batch[0] && batch[0].s) {
                    setStageActive(parseInt(batch[0].s, 10));
                }

                const activeStageName = stageLabels[currentStage] || ('Stage ' + currentStage);
                currentItem.html('<strong>Stage ' + currentStage + ' (' + activeStageName + '):</strong> Crawling ' + $('<div>').text(batch[0].title || 'item').html() + '...');

                $.post(AdvisioAdmin.ajaxUrl, {
                    action: 'advisio_process_index_batch',
                    nonce: AdvisioAdmin.nonce,
                    items: JSON.stringify(batch),
                    is_final: isFinal ? 1 : 0,
                    had_errors: hadErrors ? 1 : 0
                }, function (batchRes) {
                    if (batchRes && batchRes.success && batchRes.data) {
                        const bData = batchRes.data;
                        const processed = bData.processed || [];

                        // Add processed items to live feed (keep last 8)
                        processed.forEach(function (p) {
                            const note = p.note ? ' <span class="feed-note">(' + $('<div>').text(p.note).html() + ')</span>' : '';
                            const chars = p.chars > 0 ? ' <span class="feed-chars">[' + p.chars + ' chars]</span>' : '';
                            const li = $('<li><span class="feed-stage">Stage ' + p.stage + ':</span> <span class="feed-title">' + $('<div>').text(p.title).html() + '</span>' + chars + note + '</li>');
                            liveFeed.prepend(li);
                            if (liveFeed.children().length > 8) {
                                liveFeed.children().last().remove();
                            }
                        });

                        currentIndex = nextIndex;
                        const pct = Math.min(100, Math.round((currentIndex / totalItems) * 100));
                        progressBar.css('width', pct + '%');
                        progressPct.text(pct + '%');
                        statusBadge.text('Crawling ' + currentIndex + ' of ' + totalItems + ' items (' + pct + '%)...');

                        if (!isFinal) {
                            setTimeout(processNextBatch, 80);
                        } else {
                            // Completed!
                            $('.crawl-stage').removeClass('active').addClass('completed');
                            progressBar.css('width', '100%');
                            progressPct.text('100%');
                            currentItem.text('✓ 5-Stage Sequential Crawl completed successfully!');
                            statusBadge.attr('class', 'advisio-badge success').text('✓ Indexing complete. ' + (bData.written_total || currentIndex) + ' items saved/verified.');

                            if (bData.shop_badge) $('#shop-idx-badge').text(bData.shop_badge);
                            if (bData.content_badge) $('#content-idx-badge').text(bData.content_badge);
                            if (bData.shop_short) $('#dash-shop-idx').text(bData.shop_short);
                            if (bData.content_short) $('#dash-content-idx').text(bData.content_short);
                            if (bData.combined_total) $('#combined-idx-count').text(bData.combined_total + ' indexed items');
                            updateMemoryBadges(bData.memory_stats);

                            if (bData.last_index_stats) {
                                const s = bData.last_index_stats;
                                $('#sync-stat-timestamp').text(s.timestamp || '');
                                $('#sync-stat-type').text(s.sync_type || '');
                                $('#sync-val-new').text(s.new_added || 0);
                                $('#sync-val-updated').text(s.updated || 0);
                                if (s.breakdown) {
                                    $('#sync-breakdown-gf').text(s.breakdown.startsida_footer || 0);
                                    $('#sync-breakdown-ms').text(s.breakdown.menyer_sidor || 0);
                                    $('#sync-breakdown-pk').text(s.breakdown.produkter || 0);
                                    $('#sync-breakdown-mf').text(s.breakdown.manuell_filer || 0);
                                }
                                $('#advisio-last-sync-card').slideDown(250);
                            }

                            summaryBox.html(
                                '<div class="summary-card">' +
                                '<h4>✓ Crawl Run Summary:</h4>' +
                                '<p>Items written/verified: <strong>' + (bData.written_total || currentIndex) + '</strong> | Stale pruned: <strong>' + (bData.pruned || 0) + '</strong> | Mode: <strong>' + (isIncremental ? 'Quick Sync' : 'Full Deep Index') + '</strong></p>' +
                                '<p class="desc">Manual Knowledge entries and uploaded reference files were strictly preserved.</p>' +
                                '</div>'
                            ).slideDown(200);

                            allCrawlBtns.prop('disabled', false); $('#btn-stop-crawl').hide();
                        }
                    } else {
                        hadErrors = true;
                        currentIndex = nextIndex;
                        const pct = Math.min(100, Math.round((currentIndex / totalItems) * 100));
                        progressBar.css('width', pct + '%');
                        if (nextIndex < totalItems) {
                            setTimeout(processNextBatch, 300);
                        } else {
                            allCrawlBtns.prop('disabled', false); $('#btn-stop-crawl').hide();
                            statusBadge.attr('class', 'advisio-badge error').text('Completed with some batch warnings.');
                        }
                    }
                }).fail(function () {
                    hadErrors = true;
                    currentIndex = nextIndex;
                    if (nextIndex < totalItems) {
                        setTimeout(processNextBatch, 500);
                    } else {
                        allCrawlBtns.prop('disabled', false); $('#btn-stop-crawl').hide();
                        statusBadge.attr('class', 'advisio-badge error').text('Server network timeout during final micro-batch.');
                    }
                });
            }

            processNextBatch();
        }).fail(function () {
            allCrawlBtns.prop('disabled', false); $('#btn-stop-crawl').hide();
            statusBadge.attr('class', 'advisio-badge error').text('Failed to contact server to build crawler queue.');
            currentItem.text('Network error.');
        });
    });

        
    } catch (secErr) {
        console.error('[Advisio Admin Error in 7. 5-STAGE SEQUENTIAL DEEP CRAWLER]:', secErr);
    }

// ==========================================
    try {
    // 8. UNIFIED CUSTOM KNOWLEDGE BASE (MANUAL TEXT & FILE UPLOADER) (TAB 2)
    // ==========================================
    const customTextId = $('#advisio_custom_text_id');
    const customTextTitle = $('#advisio_custom_text_title');
    const customTextContent = $('#advisio_custom_text_content');
    const customFileInput = $('#custom_knowledge_file_input');
    const fileDropzone = $('#advisio-file-dropzone, #dropzone-trigger');
    const selectedFileName = $('#selected-file-name');
    const fileSelectedStatus = $('#file-selected-status');
    const btnClearFile = $('#btn-clear-file');
    const btnAddCustom = $('#btn-add-custom-knowledge');
    const customStatus = $('#custom-knowledge-status');

    let selectedCustomFile = null;

    function resetCustomForm() {
        customTextId.val('');
        customTextTitle.val('');
        customTextContent.val('');
        selectedCustomFile = null;
        if (customFileInput.length) customFileInput.val('');
        if (fileSelectedStatus.length) fileSelectedStatus.hide();
        if (selectedFileName.length) selectedFileName.text('');
        if (btnAddCustom.length) {
            btnAddCustom.prop('disabled', false).html('<span>➕ Lägg till i Kunskapsbasen</span>');
        }
    }

    if (btnClearFile.length) {
        btnClearFile.on('click', function (e) {
            e.preventDefault();
            e.stopPropagation();
            selectedCustomFile = null;
            if (customFileInput.length) customFileInput.val('');
            if (fileSelectedStatus.length) fileSelectedStatus.hide();
            if (selectedFileName.length) selectedFileName.text('');
        });
    }

    // File Drag & Drop Handlers
    if (fileDropzone.length) {
        fileDropzone.on('click', function (e) {
            if ($(e.target).closest('#btn-clear-file').length) return;
            customFileInput.trigger('click');
        });

        fileDropzone.on('dragover dragenter', function (e) {
            e.preventDefault();
            e.stopPropagation();
            fileDropzone.addClass('drag-active');
        });

        fileDropzone.on('dragleave dragend drop', function (e) {
            e.preventDefault();
            e.stopPropagation();
            fileDropzone.removeClass('drag-active');
        });

        fileDropzone.on('drop', function (e) {
            const files = e.originalEvent.dataTransfer.files;
            if (files && files.length) {
                handleFileSelection(files[0]);
            }
        });
    }

    if (customFileInput.length) {
        customFileInput.on('change', function () {
            if (this.files && this.files.length) {
                handleFileSelection(this.files[0]);
            }
        });
    }

    function handleFileSelection(file) {
        const ext = file.name.split('.').pop().toLowerCase();
        if (!['txt', 'md', 'csv', 'json'].includes(ext)) {
            alert('Endast .txt, .md, .csv och .json filer stöds.');
            return;
        }
        if (file.size > 5 * 1024 * 1024) {
            alert('Filen är för stor (max 5 MB).');
            return;
        }
        selectedCustomFile = file;
        selectedFileName.text(file.name + ' (' + Math.round(file.size / 1024) + ' KB)');
        fileSelectedStatus.show();
    }

    // Submit Custom Knowledge (Text or File)
    if (btnAddCustom.length) {
        btnAddCustom.on('click', function (e) {
            e.preventDefault();

            const textContent = $.trim(customTextContent.val());
            const textTitle = $.trim(customTextTitle.val());

            if (!selectedCustomFile && !textContent) {
                alert('Vänligen skriv in kunskapstext eller välj en referensfil att lägga till.');
                customTextContent.focus();
                return;
            }

            btnAddCustom.prop('disabled', true).html('<span>⏳ Sparar till kunskapsbasen...</span>');
            customStatus.html('<span class="advisio-loading-inline">Bearbetar och lagrar i kunskapsbasen...</span>');

            if (selectedCustomFile) {
                // Upload file via FormData
                const formData = new FormData();
                formData.append('action', 'advisio_upload_custom_file');
                formData.append('nonce', AdvisioAdmin.nonce);
                formData.append('file', selectedCustomFile);

                $.ajax({
                    url: AdvisioAdmin.ajaxUrl,
                    type: 'POST',
                    data: formData,
                    processData: false,
                    contentType: false,
                    dataType: 'json'
                }).done(function (res) {
                    handleSaveSuccess(res);
                }).fail(function () {
                    btnAddCustom.prop('disabled', false).html('<span>➕ Lägg till i Kunskapsbasen</span>');
                    customStatus.html('<span class="advisio-status-error">✗ Nätverksfel vid filuppladdning.</span>');
                });
            } else {
                // Save text
                $.post(AdvisioAdmin.ajaxUrl, {
                    action: 'advisio_save_custom_knowledge',
                    nonce: AdvisioAdmin.nonce,
                    id: customTextId.val(),
                    title: textTitle,
                    content: textContent
                }, function (res) {
                    handleSaveSuccess(res);
                }).fail(function () {
                    btnAddCustom.prop('disabled', false).html('<span>➕ Lägg till i Kunskapsbasen</span>');
                    customStatus.html('<span class="advisio-status-error">✗ Nätverksfel vid sparning.</span>');
                });
            }
        });
    }

    function handleSaveSuccess(res) {
        if (res && res.success) {
            const item = res.data.item;
            const status = item.status || 'pending';
            customStatus.html('<span class="advisio-status-success">✓ ' + (status === 'live' ? 'Kunskap tillagd och LIVE direkt!' : 'Kunskap sparad! (Väntar på att aktiveras via The Brain)') + '</span>');
            resetCustomForm();
            setTimeout(function () {
                customStatus.fadeOut(400, function () { $(this).empty().show(); });
            }, 4000);

            // Update stats badges
            if (res.data.stats) {
                updateMemoryBadges(res.data.stats);
            }

            // Append item row to target list
            appendCustomItemToUI(item);
        } else {
            btnAddCustom.prop('disabled', false).html('<span>➕ Lägg till i Kunskapsbasen</span>');
            const err = res && res.data ? res.data : 'Kunde inte spara kunskap.';
            customStatus.html('<span class="advisio-status-error">✗ ' + err + '</span>');
        }
    }

    function appendCustomItemToUI(item) {
        const targetList = $('#advisio-live-knowledge-list');
        const countPill = $('#live-knowledge-count-pill');

        targetList.find('.no-items-placeholder').remove();

        const typeLabel = item.type === 'file' ? 'Fil' : 'Text';
        const itemHtml = $(
            '<div class="advisio-custom-item item-live" id="live-item-' + item.id + '">' +
            '<div class="advisio-custom-item-head">' +
            '<div class="head-left">' +
            '<span class="custom-type-pill badge-success">' + typeLabel + '</span>' +
            '<strong class="custom-item-title">' + $('<div>').text(item.title).html() + '</strong>' +
            '</div>' +
            '<div class="head-actions">' +
            '<button type="button" class="advisio-btn advisio-btn-reject advisio-btn-sm btn-delete-custom-item" data-id="' + item.id + '">🗑️ Ta bort</button>' +
            '</div>' +
            '</div>' +
            '<div class="advisio-custom-item-body">' +
            '<span class="preview-text">' + $('<div>').text(item.content ? item.content.substring(0, 180) + (item.content.length > 180 ? '...' : '') : '').html() + '</span>' +
            '</div>' +
            '</div>'
        );

        targetList.prepend(itemHtml);

        const currentCount = targetList.children('.advisio-custom-item').length;
        countPill.text(currentCount + ' sparade');
    }

    // Delete Custom Item
    $(document).on('click', '.btn-delete-custom-item', function (e) {
        e.preventDefault();
        const btn = $(this);
        const id = btn.data('id');
        if (!id) return;

        if (!confirm('Är du säker på att du vill ta bort denna kunskapspost?')) {
            return;
        }

        btn.prop('disabled', true).text('⏳...');

        $.post(AdvisioAdmin.ajaxUrl, {
            action: 'advisio_delete_custom_knowledge',
            nonce: AdvisioAdmin.nonce,
            id: id
        }, function (res) {
            if (res && res.success) {
                const itemRow = btn.closest('.advisio-custom-item');
                const parentList = itemRow.parent();
                itemRow.fadeOut(250, function () {
                    $(this).remove();
                    const remaining = parentList.children('.advisio-custom-item').length;
                    $('#live-knowledge-count-pill').text(remaining + ' sparade');
                    if (remaining === 0) {
                        parentList.html('<p class="desc no-items-placeholder">Ingen sparad manuell kunskap än. Skriv eller ladda upp information till vänster för att lägga till i minnesbanken.</p>');
                    }
                });

                if (res.data && res.data.stats) {
                    updateMemoryBadges(res.data.stats);
                }
            } else {
                btn.prop('disabled', false).text('🗑️ Ta bort');
                alert(res && res.data ? res.data : 'Kunde inte ta bort posten.');
            }
        }).fail(function () {
            btn.prop('disabled', false).text('🗑️ Ta bort');
            alert('Nätverksfel vid borttagning.');
        });
    });

    function updateMemoryBadges(stats) {
        if (!stats) return;
        if (typeof stats.total !== 'undefined') $('#mem-stat-total').text(stats.total);
        if (typeof stats.live !== 'undefined') $('#mem-stat-live').text(stats.live);
        if (typeof stats.manual !== 'undefined') {
            $('#mem-stat-manual').text(stats.manual);
            $('#mem-stat-pending').text(stats.manual);
        } else if (typeof stats.pending !== 'undefined') {
            $('#mem-stat-pending').text(stats.pending);
        }
        if (typeof stats.content !== 'undefined' || typeof stats.global_site !== 'undefined') {
            const cTotal = (parseInt(stats.content, 10) || 0) + (parseInt(stats.global_site, 10) || 0);
            $('#mem-stat-content').text(cTotal);
        }
        if (typeof stats.shop !== 'undefined') $('#mem-stat-shop').text(stats.shop);
    }

    } catch (secErr) {
        console.error('[Advisio Admin Error in 8. UNIFIED CUSTOM KNOWLEDGE BASE]:', secErr);
    }

// ==========================================
    try {
    // 11. WIDGET DESIGN & REAL-TIME LIVE PREVIEW
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

    $('#preview_chat_title, #preview_welcome_msg, #preview_proactive_text, #advisio_label_callback, #preview_color_primary, #preview_color_header').on('input change', updateLivePreview);
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

        const proactiveVal = $('#preview_proactive_text').val();
        if ($('#preview_proactive_toggle').length ? $('#preview_proactive_toggle').is(':checked') : (proactiveVal && proactiveVal.trim() !== '')) {
            lpProactivePill.show().text(proactiveVal || 'Behöver du hjälp med mått eller fönsterval? 👋');
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

    
    } catch (secErr) {
        console.error('[Advisio Admin Error in 11. WIDGET DESIGN]:', secErr);
    }

// ==========================================
    try {
    // 12. INTERACTIVE LEAD FORM FIELD BUILDER (TAB 5)
    // ==========================================
    const fieldsInput = $('#advisio_lead_form_fields_input');
    const builderList = $('#advisio-field-builder-list');
    const leadLivePreview = $('#lp-lead-fields-preview, #advisio-lead-form-live-preview');

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

    
    } catch (secErr) {
        console.error('[Advisio Admin Error in 12. INTERACTIVE LEAD FORM BUILDER]:', secErr);
    }

// ==========================================
    try {
    // 13. ADVISIO THE BRAIN (SELF-BUILDING MASTER PROMPT) (TAB 3)
    // ==========================================

    // --- STATE 1: INITIAL SETUP MODE ---
    $(document).on('click', '.btn-save-setup-box', function (e) {
        e.preventDefault();
        const btn = $(this);
        const box = btn.data('box');
        const content = $.trim($('#setup_box_' + box + '_content').val());

        if (!content) {
            alert('Innehållet kan inte vara tomt.');
            $('#setup_box_' + box + '_content').focus();
            return;
        }

        btn.prop('disabled', true).text('Sparar...');

        $.post(AdvisioAdmin.ajaxUrl, {
            action: 'advisio_save_setup_box',
            nonce: AdvisioAdmin.nonce,
            box: box,
            content: content
        }, function (res) {
            btn.prop('disabled', false).text('💾 Spara ändring');
            if (res && res.success && res.data) {
                const badge = $('#badge-box-' + box);
                badge.removeClass('badge-pending').addClass('badge-ready').text('✓ Redo');

                const readyCount = res.data.ready_count || 0;
                $('#setup-ready-count').text(readyCount);

                const statusBadge = $('#setup-status-badge');
                if (readyCount >= 3) {
                    statusBadge.addClass('all-ready');
                    $('#btn-create-master-brain').prop('disabled', false);
                } else {
                    statusBadge.removeClass('all-ready');
                    $('#btn-create-master-brain').prop('disabled', true);
                }

                btn.text('✓ Sparad!');
                setTimeout(function () { btn.text('💾 Spara ändring'); }, 2000);
            } else {
                alert(res && res.data ? res.data : 'Kunde inte spara rutan.');
            }
        }).fail(function () {
            btn.prop('disabled', false).text('💾 Spara ändring');
            alert('Nätverksfel vid sparning.');
        });
    });

    // Toggle Double Confirmation for Create Master Brain
    $('#btn-create-master-brain').on('click', function (e) {
        e.preventDefault();
        $('#create-brain-confirm-box').slideToggle(200);
    });

    $('#btn-cancel-create-brain').on('click', function (e) {
        e.preventDefault();
        $('#create-brain-confirm-box').slideUp(200);
    });

    // Confirm Create Master Brain
    $('#btn-confirm-create-brain').on('click', function (e) {
        e.preventDefault();
        const btn = $(this);
        btn.prop('disabled', true).text('🧠 Genererar Hjärna...');
        $('#btn-cancel-create-brain').hide();
        $('#btn-create-master-brain').prop('disabled', true);

        $('#create-brain-status').html(
            '<div class="advisio-loading-box">' +
            '<div class="advisio-spinner"></div>' +
            '<p><strong>1/3:</strong> Sammanställer butikens minne och beteenderegler...</p>' +
            '<p class="sub-step">Skickar till Gemini Flash Lite för optimering...</p>' +
            '</div>'
        );

        $.post(AdvisioAdmin.ajaxUrl, {
            action: 'advisio_generate_master_brain',
            nonce: AdvisioAdmin.nonce
        }, function (res) {
            if (res && res.success) {
                $('#create-brain-status').html(
                    '<div class="advisio-status-success" style="padding:16px; font-size:15px;">' +
                    '✓ <strong>Advisios Hjärna har skapats framgångsrikt!</strong><br>' +
                    'Modell: ' + (res.data.model || 'Gemini Flash Lite') + ' • Sparat Master System Prompt.<br>' +
                    'Laddar om gränssnittet till aktivt läge...' +
                    '</div>'
                );
                setTimeout(function () {
                    window.location.reload();
                }, 1500);
            } else {
                btn.prop('disabled', false).text('Ja, Skapa Advisio nu');
                $('#btn-cancel-create-brain').show();
                $('#btn-create-master-brain').prop('disabled', false);
                const err = res && res.data ? res.data : 'Kunde inte skapa Advisios Hjärna.';
                $('#create-brain-status').html('<div class="advisio-status-error" style="padding:12px;">✗ ' + err + '</div>');
            }
        }).fail(function () {
            btn.prop('disabled', false).text('Ja, Skapa Advisio nu');
            $('#btn-cancel-create-brain').show();
            $('#btn-create-master-brain').prop('disabled', false);
            $('#create-brain-status').html('<div class="advisio-status-error" style="padding:12px;">✗ Nätverksfel vid anrop till Gemini Flash Lite.</div>');
        });
    });


    // --- STATE 2: ACTIVE BRAIN MODE ---

    // Toggle Update Confirm Box
    $('#btn-update-master-brain').on('click', function (e) {
        e.preventDefault();
        $('#reset-brain-confirm-box').hide();
        $('#update-brain-confirm-box').slideToggle(200);
    });

    $('#btn-cancel-update-brain').on('click', function (e) {
        e.preventDefault();
        $('#update-brain-confirm-box').slideUp(200);
    });

    // Confirm Update Master Brain
    $('#btn-confirm-update-brain').on('click', function (e) {
        e.preventDefault();
        const btn = $(this);
        btn.prop('disabled', true).text('🔄 Uppdaterar...');
        $('#btn-cancel-update-brain').hide();
        $('#btn-update-master-brain').prop('disabled', true);

        $('#brain-action-status').html(
            '<div class="advisio-loading-box">' +
            '<div class="advisio-spinner"></div>' +
            '<p><strong>Uppdaterar Advisio AI...</strong> Sammanställer alla nya tillägg och justerar Master Prompt via Gemini Flash Lite...</p>' +
            '</div>'
        );

        $.post(AdvisioAdmin.ajaxUrl, {
            action: 'advisio_update_master_brain',
            nonce: AdvisioAdmin.nonce
        }, function (res) {
            if (res && res.success) {
                $('#brain-action-status').html(
                    '<div class="advisio-status-success" style="padding:14px;">' +
                    '✓ <strong>Advisio AI har uppdaterats framgångsrikt!</strong><br>' +
                    'Alla nya minnen och beteenden är nu aktiva i chatten. Laddar om...' +
                    '</div>'
                );
                setTimeout(function () {
                    window.location.reload();
                }, 1500);
            } else {
                btn.prop('disabled', false).text('Ja, Uppdatera Advisio nu');
                $('#btn-cancel-update-brain').show();
                $('#btn-update-master-brain').prop('disabled', false);
                const err = res && res.data ? res.data : 'Kunde inte uppdatera Advisio.';
                $('#brain-action-status').html('<div class="advisio-status-error" style="padding:12px;">✗ ' + err + '</div>');
            }
        }).fail(function () {
            btn.prop('disabled', false).text('Ja, Uppdatera Advisio nu');
            $('#btn-cancel-update-brain').show();
            $('#btn-update-master-brain').prop('disabled', false);
            $('#brain-action-status').html('<div class="advisio-status-error" style="padding:12px;">✗ Nätverksfel vid uppdatering via Gemini API.</div>');
        });
    });

    // Toggle Reset Confirm Box
    $('#btn-reset-master-brain').on('click', function (e) {
        e.preventDefault();
        $('#update-brain-confirm-box').hide();
        $('#reset-brain-confirm-box').slideToggle(200);
    });

    $('#btn-cancel-reset-brain').on('click', function (e) {
        e.preventDefault();
        $('#reset-brain-confirm-box').slideUp(200);
    });

    // Confirm Reset Brain
    $('#btn-confirm-reset-brain').on('click', function (e) {
        e.preventDefault();
        const btn = $(this);
        btn.prop('disabled', true).text('Nollställer...');
        $('#btn-cancel-reset-brain').hide();

        $.post(AdvisioAdmin.ajaxUrl, {
            action: 'advisio_reset_brain',
            nonce: AdvisioAdmin.nonce
        }, function (res) {
            if (res && res.success) {
                $('#brain-action-status').html(
                    '<div class="advisio-status-success" style="padding:14px;">' +
                    '✓ <strong>Advisios Hjärna har nollställts.</strong> Laddar om till setup-läge...' +
                    '</div>'
                );
                setTimeout(function () {
                    window.location.reload();
                }, 1000);
            } else {
                btn.prop('disabled', false).text('Ja, Nollställ och börja om');
                $('#btn-cancel-reset-brain').show();
                alert(res && res.data ? res.data : 'Kunde inte nollställa hjärnan.');
            }
        }).fail(function () {
            btn.prop('disabled', false).text('Ja, Nollställ och börja om');
            $('#btn-cancel-reset-brain').show();
            alert('Nätverksfel vid nollställning.');
        });
    });

    // Real-time Detection of '-' (Removal Rule) in Behavior Boxes 1-3
    $(document).on('input keyup', '.input-pending-addition', function () {
        const input = $(this);
        const box = parseInt(input.data('box'), 10);
        const val = $.trim(input.val());
        const hintEl = input.siblings('.removal-hint');

        if (box <= 3 && val.startsWith('-')) {
            if (!hintEl.length) {
                input.after('<span class="removal-hint" style="display:block; color:#f59e0b; font-size:12px; margin-top:4px;">💡 Börjar med minus (-) = Tas bort vid nästa uppdatering</span>');
            } else {
                hintEl.show();
            }
        } else {
            if (hintEl.length) hintEl.hide();
        }
    });

    // Enter key triggers Add Pending Addition
    $(document).on('keydown', '.input-pending-addition', function (e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            const box = $(this).data('box');
            $('.btn-add-pending-addition[data-box="' + box + '"]').trigger('click');
        }
    });

    // Add Pending Addition to Box 1, 2, 3
    $(document).on('click', '.btn-add-pending-addition', function (e) {
        e.preventDefault();
        const btn = $(this);
        const box = btn.data('box');
        const input = $('.input-pending-addition[data-box="' + box + '"]');
        const text = $.trim(input.val());

        if (!text) {
            alert('Vänligen skriv en regel eller instruktion att lägga till.');
            input.focus();
            return;
        }

        btn.prop('disabled', true).text('Sparar...');

        $.post(AdvisioAdmin.ajaxUrl, {
            action: 'advisio_add_pending_addition',
            nonce: AdvisioAdmin.nonce,
            box: box,
            text: text
        }, function (res) {
            btn.prop('disabled', false).text('➕ Lägg till');
            if (res && res.success && res.data) {
                input.val('');
                input.siblings('.removal-hint').hide();

                const item = res.data.item;
                const list = $('#pending-list-box-' + box);
                list.find('.no-items-placeholder').remove();

                const isRemoval = item.is_removal;
                const row = $(
                    '<div class="dna-item-row pending-item ' + (isRemoval ? 'item-removal' : '') + '" data-id="' + item.id + '">' +
                    '<span class="dna-item-text">' +
                    (isRemoval ? '<span class="badge-removal">TAS BORT:</span> ' : '') +
                    $('<div>').text(item.text).html() +
                    '</span>' +
                    '<div class="dna-item-actions">' +
                    '<button type="button" class="btn-delete-pending-item" data-box="' + box + '" data-id="' + item.id + '" title="Ta bort">🗑️</button>' +
                    '</div>' +
                    '</div>'
                );
                list.append(row);

                // Update pending total badge
                updateTotalPendingCount(res.data.total_pending);
            } else {
                alert(res && res.data ? res.data : 'Kunde inte lägga till instruktionen.');
            }
        }).fail(function () {
            btn.prop('disabled', false).text('➕ Lägg till');
            alert('Nätverksfel vid sparning.');
        });
    });

    // Delete Pending Addition
    $(document).on('click', '.btn-delete-pending-item', function (e) {
        e.preventDefault();
        const btn = $(this);
        const box = btn.data('box');
        const id = btn.data('id');
        if (!id) return;

        btn.prop('disabled', true).text('⏳');

        $.post(AdvisioAdmin.ajaxUrl, {
            action: 'advisio_delete_pending_addition',
            nonce: AdvisioAdmin.nonce,
            box: box,
            id: id
        }, function (res) {
            if (res && res.success) {
                const row = btn.closest('.dna-item-row');
                const list = row.parent();
                row.fadeOut(200, function () {
                    $(this).remove();
                    if (list.children('.dna-item-row').length === 0) {
                        list.html('<p class="desc no-items-placeholder">Inga väntande ändringar.</p>');
                    }
                });

                if (typeof res.data.total_pending !== 'undefined') {
                    updateTotalPendingCount(res.data.total_pending);
                }
            } else {
                btn.prop('disabled', false).text('🗑️');
                alert(res && res.data ? res.data : 'Kunde inte ta bort posten.');
            }
        }).fail(function () {
            btn.prop('disabled', false).text('🗑️');
            alert('Nätverksfel vid borttagning.');
        });
    });

    function updateTotalPendingCount(count) {
        $('#brain-pending-total-badge').text(count);
        const updateBtn = $('#btn-update-master-brain');
        if (count > 0) {
            updateBtn.addClass('advisio-btn-pulse');
        } else {
            updateBtn.removeClass('advisio-btn-pulse');
        }
    }

    } catch (secErr) {
        console.error('[Advisio Admin Error in 13. ADVISIO THE BRAIN]:', secErr);
    }

// ==========================================
    try {
    // 14. CAPTURED LEADS MANAGEMENT (TAB 5)
    // ==========================================
    $(document).on('click', '.btn-delete-lead', function () {
        const btn = $(this);
        const leadId = btn.data('id');
        if (!leadId) return;

        if (!confirm('Vill du radera detta lead och dess AI-sammanfattning permanent?')) {
            return;
        }

        btn.prop('disabled', true).text('⏳');

        $.post(AdvisioAdmin.ajaxUrl, {
            action: 'advisio_delete_lead',
            nonce: AdvisioAdmin.nonce,
            id: leadId
        }, function (res) {
            if (res && res.success) {
                $('#lead-row-' + leadId).fadeOut(250, function () {
                    $(this).remove();
                    const remaining = $('.advisio-leads-table tbody tr').length;
                    if (remaining === 0) {
                        $('.advisio-leads-table-wrap').html('<p class="desc" style="padding:24px 0; text-align:center;">Inga förfrågningar eller leads har fångats in än.</p>');
                    }
                });
            } else {
                btn.prop('disabled', false).text('🗑️');
                alert(res && res.data ? res.data : 'Kunde inte radera lead.');
            }
        }).fail(function () {
            btn.prop('disabled', false).text('🗑️');
            alert('Nätverksfel vid radering av lead.');
        });
    });


    } catch (secErr) {
        console.error('[Advisio Admin Error in 14. CAPTURED LEADS MANAGEMENT]:', secErr);
    }

});
