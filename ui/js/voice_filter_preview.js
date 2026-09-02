/**
 * Voice Filter sample playback for the NPC, Narrator, and Player editors.
 *
 * Every [data-voice-filter] block rendered by ui/core/tmpl/voice_filter_field.php is wired here.
 * The sample always uses the values currently in the form, not the saved ones, so users can audition
 * a preset before pressing Save.
 *
 * Request  (POST, application/x-www-form-urlencoded) to data-voice-filter-url:
 *   scope        npc | narrator | player
 *   filter       selected preset value from the server catalog
 *   voice        unsaved voice id / VoiceID field value
 *   profile_id   unsaved profile select value  (npc, narrator)
 *   connector_id unsaved TTS connector select value (player)
 *   npc_id       optional NPC id for scope=npc
 * Response (JSON): { ok: true, audio_url: "..." } or { ok: false, error: "..." }
 * The legacy { status: "success", url|message } shape is also accepted.
 */
(function () {
    'use strict';

    var READY_TEXT = 'Sample ready.';
    var BUSY_TEXT = 'Generating sample…';

    function readSource(field, attribute) {
        var selector = field.getAttribute(attribute) || '';
        if (!selector) {
            return '';
        }
        var element = null;
        try {
            var form = field.closest('form');
            element = (form && form.querySelector(selector)) || document.querySelector(selector);
        } catch (error) {
            return '';
        }
        return element ? String(element.value || '').trim() : '';
    }

    function setStatus(statusNode, message, state) {
        if (!statusNode) {
            return;
        }
        statusNode.textContent = message;
        statusNode.classList.toggle('is-error', state === 'error');
        statusNode.classList.toggle('is-ready', state === 'ready');
    }

    function stopOtherAudio(current) {
        document.querySelectorAll('audio').forEach(function (audio) {
            if (audio !== current && !audio.paused) {
                audio.pause();
            }
        });
    }

    function wire(field) {
        if (field.dataset.voiceFilterReady === '1') {
            return;
        }
        field.dataset.voiceFilterReady = '1';

        var button = field.querySelector('[data-voice-filter-play]');
        var select = field.querySelector('[data-voice-filter-select]');
        var statusNode = field.querySelector('[data-voice-filter-status]');
        var audio = field.querySelector('[data-voice-filter-audio]');
        var url = field.getAttribute('data-voice-filter-url') || '';
        if (!button || !url) {
            return;
        }
        if (!select) {
            button.disabled = true;
            return;
        }

        // A new preset selection invalidates the sample that is already loaded.
        select.addEventListener('change', function () {
            if (audio && !audio.hidden) {
                audio.pause();
                audio.hidden = true;
                audio.removeAttribute('src');
            }
            setStatus(statusNode, '', '');
        });

        button.addEventListener('click', function () {
            if (button.disabled) {
                return;
            }

            var voice = readSource(field, 'data-voice-filter-voice-source');
            var profileId = readSource(field, 'data-voice-filter-profile-source');
            var connectorId = readSource(field, 'data-voice-filter-connector-source');
            var scope = field.getAttribute('data-voice-filter-scope') || '';

            // Catch the two "nothing to speak through" cases here rather than on a server round trip.
            if (scope === 'player' && !connectorId) {
                setStatus(statusNode, 'Select a Player TTS connector first.', 'error');
                return;
            }
            if (scope !== 'player' && field.getAttribute('data-voice-filter-profile-source') && !profileId) {
                setStatus(statusNode, 'Select a profile first.', 'error');
                return;
            }

            var body = new URLSearchParams();
            body.set('scope', scope);
            body.set('filter', String(select.value || ''));
            body.set('voice', voice);
            if (profileId) {
                body.set('profile_id', profileId);
            }
            if (connectorId) {
                body.set('connector_id', connectorId);
            }
            var npcId = field.getAttribute('data-voice-filter-npc-id') || '';
            if (npcId) {
                body.set('npc_id', npcId);
            }

            button.disabled = true;
            button.classList.add('is-busy');
            setStatus(statusNode, BUSY_TEXT, '');

            fetch(url, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
                body: body.toString(),
                credentials: 'same-origin'
            }).then(function (response) {
                return response.json().catch(function () {
                    return { ok: false, error: 'Preview failed (HTTP ' + response.status + ').' };
                });
            }).then(function (payload) {
                var data = payload || {};
                var ok = data.ok === true || data.status === 'success';
                var audioUrl = data.audio_url || data.url || '';
                if (!ok || !audioUrl) {
                    setStatus(statusNode, data.error || data.message || 'Preview could not be generated.', 'error');
                    return;
                }
                if (!audio) {
                    setStatus(statusNode, READY_TEXT, 'ready');
                    return;
                }
                stopOtherAudio(audio);
                audio.hidden = false;
                audio.src = audioUrl;
                var played = audio.play();
                if (played && typeof played.catch === 'function') {
                    played.catch(function () {
                        setStatus(statusNode, 'Sample ready. Press play to listen.', 'ready');
                    });
                }
                setStatus(statusNode, READY_TEXT, 'ready');
            }).catch(function () {
                setStatus(statusNode, 'Preview request failed. Check that the server is reachable.', 'error');
            }).then(function () {
                button.disabled = false;
                button.classList.remove('is-busy');
            });
        });
    }

    function init(root) {
        (root || document).querySelectorAll('[data-voice-filter]').forEach(wire);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () { init(document); });
    } else {
        init(document);
    }

    // The NPC editor renders inside a modal, so late-inserted fields still need wiring.
    window.dialecticVoiceFilterPreview = { init: init };
}());
