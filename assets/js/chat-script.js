document.addEventListener('DOMContentLoaded', function () {
    const launcher = document.getElementById('advisio-launcher');
    const chatWindow = document.getElementById('advisio-chat-window');
    const closeBtn = document.getElementById('advisio-close-chat');
    const chatBody = document.getElementById('advisio-chat-body');
    const chatInput = document.getElementById('advisio-chat-input');
    const sendBtn = document.getElementById('advisio-chat-send');
    const proactiveBubble = document.getElementById('advisio-proactive-bubble');
    const leadFormContainer = document.getElementById('advisio-lead-form-container');
    const dynamicFieldsMount = document.getElementById('advisio-dynamic-fields-mount');
    const submitLeadBtn = document.getElementById('advisio-submit-lead-btn');
    const cancelLeadBtn = document.getElementById('advisio-cancel-lead-btn');

    if (!launcher || !chatWindow) {
        return;
    }

    const sessionId = 'adv_' + Math.random().toString(36).substring(2, 10) + '_' + Date.now().toString(36);
    let chatHistory = [];
    let hasShownInitialWelcome = false;
    let isRequestInFlight = false;
    let capturedEmailFromChat = '';

    // Render Dynamic Lead Form Fields from Admin Builder configuration
    const leadFieldsConfig = Array.isArray(AdvisioChat.leadFields) ? AdvisioChat.leadFields : [];
    renderDynamicLeadFields();

    // Preferred Contact Method pill switching
    const contactPills = document.querySelectorAll('.advisio-contact-pill');
    contactPills.forEach(function (pill) {
        pill.addEventListener('click', function () {
            contactPills.forEach(function (p) {
                p.classList.remove('active');
            });
            pill.classList.add('active');
            const radio = pill.querySelector('input[type="radio"]');
            if (radio) {
                radio.checked = true;
            }
        });
    });

    // Proactive Greeting Bubble
    if (parseInt(AdvisioChat.proactive, 10) === 1 && proactiveBubble) {
        setTimeout(function () {
            if (chatWindow.classList.contains('advisio-hidden')) {
                proactiveBubble.classList.remove('advisio-hidden');
            }
        }, 5000);

        proactiveBubble.addEventListener('click', function () {
            openChatWindow();
        });
    }

    /**
     * Open Chat Window:
     * When the chat opens initially, display ONLY the warm welcome greeting.
     * DO NOT display any quick-reply suggestion chips or callback buttons before the user writes.
     */
    function openChatWindow() {
        chatWindow.classList.remove('advisio-hidden');
        if (proactiveBubble) {
            proactiveBubble.classList.add('advisio-hidden');
        }
        if (!hasShownInitialWelcome && chatHistory.length === 0) {
            hasShownInitialWelcome = true;
            const welcomeText = AdvisioChat.welcomeMsg || 'Hej! Välkommen till vår kundtjänst. Hur kan jag hjälpa dig idag?';
            appendMessage('bot', welcomeText, [], true);
        }
        setTimeout(function () {
            chatInput.focus();
        }, 150);
    }

    launcher.addEventListener('click', function () {
        if (chatWindow.classList.contains('advisio-hidden')) {
            openChatWindow();
        } else {
            chatWindow.classList.add('advisio-hidden');
        }
    });

    closeBtn.addEventListener('click', function () {
        chatWindow.classList.add('advisio-hidden');
    });

    sendBtn.addEventListener('click', function () {
        sendMessage();
    });

    chatInput.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            sendMessage();
        }
    });

    /**
     * Build the dynamic lead form fields configured in Tab 5.
     */
    function renderDynamicLeadFields() {
        if (!dynamicFieldsMount) {
            return;
        }
        dynamicFieldsMount.innerHTML = '';

        leadFieldsConfig.forEach(function (field, index) {
            const fieldWrap = document.createElement('div');
            fieldWrap.className = 'advisio-lead-field-wrap';
            fieldWrap.dataset.fieldType = field.type || 'text';
            fieldWrap.dataset.fieldLabel = field.label || ('Field ' + (index + 1));

            const inputId = 'advisio-dyn-field-' + index;
            const reqMark = field.required ? ' *' : '';
            const placeholderText = (field.placeholder || field.label || '') + reqMark;

            let inputEl;
            if (field.type === 'textarea') {
                inputEl = document.createElement('textarea');
                inputEl.rows = 2;
                inputEl.placeholder = placeholderText;
            } else if (field.type === 'select') {
                inputEl = document.createElement('select');
                const defaultOpt = document.createElement('option');
                defaultOpt.value = '';
                defaultOpt.textContent = placeholderText;
                inputEl.appendChild(defaultOpt);

                const opts = (field.options || '').split(',');
                opts.forEach(function (optStr) {
                    const trimmed = optStr.trim();
                    if (trimmed) {
                        const o = document.createElement('option');
                        o.value = trimmed;
                        o.textContent = trimmed;
                        inputEl.appendChild(o);
                    }
                });
            } else {
                inputEl = document.createElement('input');
                inputEl.type = field.type || 'text';
                inputEl.placeholder = placeholderText;
            }

            inputEl.id = inputId;
            inputEl.className = 'advisio-dyn-input';
            inputEl.dataset.label = field.label || ('Field ' + (index + 1));
            inputEl.dataset.required = field.required ? '1' : '0';
            inputEl.dataset.type = field.type || 'text';

            fieldWrap.appendChild(inputEl);
            dynamicFieldsMount.appendChild(fieldWrap);
        });
    }

    /**
     * Smart Progressive Profiling: Pre-fill & hide email field if already captured in chat.
     */
    function applyProgressiveProfiling(email) {
        if (!email || !dynamicFieldsMount) {
            return;
        }
        capturedEmailFromChat = email;
        const emailInputs = dynamicFieldsMount.querySelectorAll('input[data-type="email"]');
        emailInputs.forEach(function (inp) {
            inp.value = email;
            const wrap = inp.closest('.advisio-lead-field-wrap');
            if (wrap) {
                wrap.style.display = 'none';
            }
        });
    }

    /**
     * Send user message to backend with strict AJAX debouncing.
     */
    function sendMessage(overrideText) {
        if (isRequestInFlight) {
            return;
        }

        const text = typeof overrideText === 'string' ? overrideText.trim() : chatInput.value.trim();
        if (!text) {
            return;
        }

        isRequestInFlight = true;
        chatInput.value = '';
        chatInput.disabled = true;
        sendBtn.disabled = true;
        chatInput.placeholder = 'Tänker...';

        // Detect email in user message for Smart Progressive Profiling
        const emailMatch = text.match(/([a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,})/);
        if (emailMatch && emailMatch[1]) {
            applyProgressiveProfiling(emailMatch[1]);
        }

        appendMessage('user', text, [], false);
        chatHistory.push({ role: 'user', content: text });

        showTypingIndicator();

        const formData = new FormData();
        formData.append('action', 'advisio_send_chat_message');
        formData.append('nonce', AdvisioChat.nonce);
        formData.append('session_id', sessionId);
        formData.append('message', text);
        formData.append('history', JSON.stringify(chatHistory.slice(-10)));
        formData.append('product_id', AdvisioChat.productId || 0);

        fetch(AdvisioChat.ajaxUrl, {
            method: 'POST',
            body: formData
        })
            .then(function (res) {
                return res.json();
            })
            .then(function (data) {
                removeTypingIndicator();
                isRequestInFlight = false;
                chatInput.disabled = false;
                sendBtn.disabled = false;
                chatInput.placeholder = 'Skriv ditt meddelande...';
                chatInput.focus();

                if (data && data.success && data.data && data.data.reply) {
                    let reply = data.data.reply;

                    // Parse [SUGGESTIONS: Option 1 | Option 2 | Inte säker]
                    let rawSuggestions = [];
                    const suggestionRegex = /\[SUGGESTIONS:\s*(.*?)\]/gi;
                    let match;
                    while ((match = suggestionRegex.exec(reply)) !== null) {
                        const parts = match[1].split('|');
                        rawSuggestions = rawSuggestions.concat(parts.map(function (s) {
                            return s.trim();
                        }));
                    }

                    const cleanReply = reply.replace(suggestionRegex, '').trim();
                    chatHistory.push({ role: 'model', content: cleanReply });

                    // Enforce Measurement Question Rule & Mandatory "Not Sure" Option
                    const processedSuggestions = normalizeContextSuggestions(cleanReply, rawSuggestions);
                    appendMessage('bot', cleanReply, processedSuggestions, false);
                } else {
                    const errMsg = (data && data.data) ? data.data : 'Ett tillfälligt fel uppstod. Försök gärna igen.';
                    appendMessage('bot', errMsg, [], false);
                }
            })
            .catch(function () {
                removeTypingIndicator();
                isRequestInFlight = false;
                chatInput.disabled = false;
                sendBtn.disabled = false;
                chatInput.placeholder = 'Skriv ditt meddelande...';
                appendMessage('bot', 'Nätverksfel. Kontrollera din anslutning och försök igen.', [], false);
            });
    }

    /**
     * Context-Locked Quick-Reply Chip Rules:
     * 1. Inspects the guiding question at the end of the AI response.
     * 2. Example 3 (Measurements): When asking for exact dimensions (Width x Height), outputs
     *    [ Hur mäter jag? | Inte säker på måtten ] (localized) so the customer can type numbers or click for help.
     * 3. Examples 1 & 2 (Specific Questions): Guarantees 2–3 direct, concrete answers to that exact question
     *    followed by 'Inte säker' (or equivalent in the active language).
     */
    function normalizeContextSuggestions(botReplyText, suggestions) {
        const measureHelpLabel = AdvisioChat.measureHelpChip || 'Hur mäter jag?';
        const notSureMeasurementsLabel = AdvisioChat.notSureMeasurementsChip || 'Inte säker på måtten';
        const notSureLabel = AdvisioChat.notSureChip || 'Inte säker';
        const notSureRaw = (AdvisioChat.notSureRaw || 'Inte säker').toLowerCase();

        // Extract the trailing question sentence from the AI reply
        const sentences = String(botReplyText || '').split(/(?<=[.!?])\s+|\n+/);
        let trailingQuestion = '';
        for (let i = sentences.length - 1; i >= 0; i--) {
            if (sentences[i] && sentences[i].indexOf('?') !== -1) {
                trailingQuestion = sentences[i].trim();
                break;
            }
        }
        const targetQuestionText = trailingQuestion || String(botReplyText || '');
        const isQuestion = /\?/.test(botReplyText);

        // Example 3: If the AI's question asks for exact measurements (Width x Height)
        const asksForMeasurements = isQuestion &&
            /(\bmått|bredd|höjd|karmyttermått|modulmått|storlek|dimension|width|height|measurements?\b)/i.test(targetQuestionText);

        if (asksForMeasurements) {
            return [measureHelpLabel, notSureMeasurementsLabel];
        }

        // Filter out any fake number chips or generic meta-chips that don't answer a question
        const dummyChipPattern = /^(ange mått|skriv mått|fyll i mått|skriv in mått|enter measurements|fill in measurements|input dimensions|mått bredd x höjd|\d+\s*x\s*\d+|kontakta kundtjänst|läs mer)$/i;
        let filtered = (Array.isArray(suggestions) ? suggestions : []).filter(function (chip) {
            if (!chip) return false;
            return !dummyChipPattern.test(chip.trim());
        });

        // Separate concrete answer chips from any "Not sure" chip
        const isNotSureChip = function (chip) {
            const low = String(chip || '').toLowerCase();
            return low.indexOf(notSureRaw) !== -1 ||
                low.indexOf('inte säker') !== -1 ||
                low.indexOf('not sure') !== -1 ||
                low.indexOf('usikker') !== -1 ||
                low.indexOf('ikke sikker') !== -1 ||
                low.indexOf('nicht sicher') !== -1 ||
                low.indexOf('epävarma') !== -1 ||
                low.indexOf('en ole varma') !== -1;
        };

        let concreteAnswers = filtered.filter(function (chip) {
            return !isNotSureChip(chip);
        });

        // Fallback synthesis if the AI asked a question but returned fewer than 2 concrete answers
        if (isQuestion && concreteAnswers.length < 2) {
            if (/(2-glas|3-glas|tvåglas|treglas|isolerglas|glastyp|u-värde|glazing)/i.test(targetQuestionText)) {
                concreteAnswers = ['2-glas', '3-glas (Bäst isolering)'];
            } else if (/(material|trä|aluminium|pvc|komposit)/i.test(targetQuestionText)) {
                concreteAnswers = ['Trä/Aluminium', 'Träfönster', 'PVC'];
            } else if (/(öppningsbar|fast|vridfönster|sidohängt|funktion)/i.test(targetQuestionText)) {
                concreteAnswers = ['Fast fönster', 'Öppningsbart (Vridfönster)', 'Sidohängt'];
            } else if (/(typ av fönster|vilket fönster|vilken produkt|letar du efter|söker du)/i.test(targetQuestionText)) {
                concreteAnswers = ['Fast fönster', 'Öppningsbart (Vridfönster)', 'Altandörr'];
            }
        }

        // Keep 2 to 3 direct, concrete answers, then append 'Inte säker' at the end
        concreteAnswers = concreteAnswers.slice(0, 3);
        if (isQuestion || concreteAnswers.length > 0) {
            concreteAnswers.push(notSureLabel);
        }

        return concreteAnswers;
    }

    /**
     * Safe Markdown Renderer:
     * Escapes HTML first, converts Markdown links [Link Text](https://...) into safe clickable
     * <a href="https://..." target="_blank" rel="noopener noreferrer" class="fg-chat-link">Link Text</a>,
     * and formats **bold** and line breaks.
     */
    function formatMessageHtml(text) {
        const escaped = String(text || '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');

        // Convert Markdown links [Link Text](https://...) or [Link Text](/subpage) into safe clickable links
        const withLinks = escaped.replace(
            /\[([^\]]+)\]\(((?:https?:\/\/|\/)[^\s)]+)\)/gi,
            function (fullMatch, label, rawUrl) {
                const cleanUrl = rawUrl.replace(/&quot;/g, '').trim();
                return '<a href="' + cleanUrl + '" target="_blank" rel="noopener noreferrer" class="fg-chat-link">' + label + '</a>';
            }
        );

        return withLinks
            .replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>')
            .replace(/\n/g, '<br>');
    }

    /**
     * Append message bubble and guided quick-reply chips.
     * If isInitialWelcome === true, NO chips or callback buttons are rendered.
     */
    function appendMessage(sender, text, suggestions, isInitialWelcome) {
        const row = document.createElement('div');
        row.className = 'advisio-msg-row ' + sender;

        const bubble = document.createElement('div');
        bubble.className = 'advisio-bubble';
        bubble.innerHTML = formatMessageHtml(text);

        row.appendChild(bubble);
        chatBody.appendChild(row);

        // Render quick-reply chips and persistent Callback Button ONLY after customer has started interacting
        if (sender === 'bot' && !isInitialWelcome) {
            const chipsWrap = document.createElement('div');
            chipsWrap.className = 'advisio-chips-wrap';

            if (Array.isArray(suggestions)) {
                suggestions.forEach(function (sug) {
                    if (!sug) return;
                    const chipBtn = document.createElement('button');
                    chipBtn.type = 'button';
                    chipBtn.className = 'advisio-chip';
                    chipBtn.textContent = sug;
                    chipBtn.addEventListener('click', function () {
                        sendMessage(sug);
                    });
                    chipsWrap.appendChild(chipBtn);
                });
            }

            if (AdvisioChat.cbLabel) {
                const leadBtn = document.createElement('button');
                leadBtn.type = 'button';
                leadBtn.className = 'advisio-chip advisio-chip-primary';
                leadBtn.textContent = AdvisioChat.cbLabel;
                leadBtn.addEventListener('click', function () {
                    openLeadDrawer();
                });
                chipsWrap.appendChild(leadBtn);
            }

            chatBody.appendChild(chipsWrap);
        }

        chatBody.scrollTop = chatBody.scrollHeight;
    }

    function openLeadDrawer() {
        if (!leadFormContainer) return;
        if (capturedEmailFromChat) {
            applyProgressiveProfiling(capturedEmailFromChat);
        }
        leadFormContainer.classList.remove('advisio-hidden');
        chatBody.scrollTop = chatBody.scrollHeight;
    }

    function showTypingIndicator() {
        removeTypingIndicator();
        const wrap = document.createElement('div');
        wrap.className = 'advisio-msg-row bot advisio-typing';
        wrap.id = 'advisio-typing-indicator';

        const bubble = document.createElement('div');
        bubble.className = 'advisio-bubble';
        bubble.innerHTML = '<span></span><span></span><span></span>';

        wrap.appendChild(bubble);
        chatBody.appendChild(wrap);
        chatBody.scrollTop = chatBody.scrollHeight;
    }

    function removeTypingIndicator() {
        const el = document.getElementById('advisio-typing-indicator');
        if (el) {
            el.remove();
        }
    }

    if (cancelLeadBtn) {
        cancelLeadBtn.addEventListener('click', function () {
            leadFormContainer.classList.add('advisio-hidden');
        });
    }

    if (submitLeadBtn) {
        submitLeadBtn.addEventListener('click', function () {
            const inputs = dynamicFieldsMount ? dynamicFieldsMount.querySelectorAll('.advisio-dyn-input') : [];
            const collectedFields = {};
            let missingRequired = false;

            inputs.forEach(function (inp) {
                const label = inp.dataset.label || 'Field';
                const isReq = inp.dataset.required === '1';
                const val = inp.value.trim();

                if (isReq && !val) {
                    missingRequired = true;
                    inp.classList.add('advisio-input-error');
                } else {
                    inp.classList.remove('advisio-input-error');
                }

                if (val) {
                    collectedFields[label] = val;
                }
            });

            if (missingRequired) {
                alert('Vänligen fyll i alla obligatoriska fält (*).');
                return;
            }

            const selectedContactRadio = document.querySelector('input[name="advisio_preferred_contact"]:checked');
            const preferredContact = selectedContactRadio ? selectedContactRadio.value : 'Email';

            submitLeadBtn.disabled = true;
            const origText = submitLeadBtn.textContent;
            submitLeadBtn.textContent = 'Skickar & skapar sammanfattning...';

            const formData = new FormData();
            formData.append('action', 'advisio_submit_lead');
            formData.append('nonce', AdvisioChat.nonce);
            formData.append('session_id', sessionId);
            formData.append('preferred_contact', preferredContact);
            formData.append('fields', JSON.stringify(collectedFields));
            formData.append('history', JSON.stringify(chatHistory));

            fetch(AdvisioChat.ajaxUrl, {
                method: 'POST',
                body: formData
            })
                .then(function (res) {
                    return res.json();
                })
                .then(function (data) {
                    if (data && data.success) {
                        const formInner = document.getElementById('advisio-lead-form-inner');
                        if (formInner) {
                            formInner.innerHTML = '<div class="advisio-lead-success">' + (AdvisioChat.leadSuccess || 'Tack! Vi kontaktar dig inom kort.') + '</div>';
                        }
                        setTimeout(function () {
                            leadFormContainer.classList.add('advisio-hidden');
                        }, 3800);
                    } else {
                        alert('Kunde inte skicka formuläret. Försök igen.');
                        submitLeadBtn.disabled = false;
                        submitLeadBtn.textContent = origText;
                    }
                })
                .catch(function () {
                    alert('Nätverksfel vid inskickning.');
                    submitLeadBtn.disabled = false;
                    submitLeadBtn.textContent = origText;
                });
        });
    }
});
