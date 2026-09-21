// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Interactive player controller.
 *
 * @module mod_videoquiz/player
 * @package   mod_videoquiz
 * @copyright 2026 Eduardo Kraus
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define(['core/ajax', 'core/notification'], function (Ajax, Notification) {
    const loadScript = (url, ready) => new Promise((resolve, reject) => {
        if (ready()) {
            resolve();
            return;
        }
        const existing = Array.from(document.scripts).find((script) => script.src === url);
        if (!existing) {
            const script = document.createElement('script');
            script.src = url;
            script.async = true;
            script.onerror = reject;
            document.head.appendChild(script);
        }
        let attempts = 0;
        const timer = window.setInterval(() => {
            attempts++;
            if (ready()) {
                window.clearInterval(timer);
                resolve();
            } else if (attempts > 200) {
                window.clearInterval(timer);
                reject(new Error('Video provider API did not load.'));
            }
        }, 50);
    });

    const youtubeId = (url) => {
        try {
            const parsed = new URL(url);
            if (parsed.hostname.includes('youtu.be')) {
                return parsed.pathname.replace(/^\//, '').split('/')[0];
            }
            if (parsed.searchParams.get('v')) {
                return parsed.searchParams.get('v');
            }
            const parts = parsed.pathname.split('/').filter(Boolean);
            const marker = parts.findIndex((part) => ['embed', 'shorts', 'live'].includes(part));
            if (marker >= 0 && parts[marker + 1]) {
                return parts[marker + 1];
            }
        } catch (error) {
            return String(url).trim();
        }
        return String(url).trim();
    };

    const vimeoId = (url) => {
        const match = String(url).match(/(?:vimeo\.com\/(?:video\/)?|^)(\d+)/);
        return match ? Number(match[1]) : 0;
    };

    const html5Adapter = (root) => {
        const video = root.querySelector('[data-region="html5-player"]');
        return {
            ready: () => new Promise((resolve) => {
                if (video.readyState >= 1) {
                    resolve();
                } else {
                    video.addEventListener('loadedmetadata', resolve, {once: true});
                }
            }),
            current: () => Promise.resolve(Number(video.currentTime) || 0),
            duration: () => Promise.resolve(Number(video.duration) || 0),
            playing: () => Promise.resolve(!video.paused && !video.ended),
            rate: () => Promise.resolve(Number(video.playbackRate) || 1),
            play: () => {
                const result = video.play();
                return result && typeof result.then === 'function' ? result.catch(() => {
                }) : Promise.resolve();
            },
            pause: () => {
                video.pause();
                return Promise.resolve();
            },
            seek: (seconds) => {
                video.currentTime = Math.max(0, seconds);
                return Promise.resolve();
            },
            limitRate: (maximum) => {
                const rates = [0.5, 0.75, 1, 1.25, 1.5, 1.75, 2].filter((rate) => rate <= maximum + 0.001);
                if ('playbackRate' in video && rates.length && video.playbackRate > maximum) {
                    video.playbackRate = maximum;
                }
                video.addEventListener('ratechange', () => {
                    if (video.playbackRate > maximum) {
                        video.playbackRate = maximum;
                    }
                });
            },
        };
    };

    const youtubeAdapter = async (root, config) => {
        await loadScript('https://www.youtube.com/iframe_api', () => window.YT && window.YT.Player);
        const target = root.querySelector('[data-region="embed-player"]');
        const player = await new Promise((resolve) => {
            const instance = new window.YT.Player(target, {
                videoId: youtubeId(config.url),
                width: '100%',
                height: '100%',
                playerVars: {playsinline: 1, rel: 0},
                events: {onReady: () => resolve(instance)},
            });
        });
        return {
            ready: () => Promise.resolve(),
            current: () => Promise.resolve(Number(player.getCurrentTime()) || 0),
            duration: () => Promise.resolve(Number(player.getDuration()) || 0),
            playing: () => Promise.resolve(player.getPlayerState() === window.YT.PlayerState.PLAYING),
            rate: () => Promise.resolve(Number(player.getPlaybackRate()) || 1),
            play: () => {
                player.playVideo();
                return Promise.resolve();
            },
            pause: () => {
                player.pauseVideo();
                return Promise.resolve();
            },
            seek: (seconds) => {
                player.seekTo(Math.max(0, seconds), true);
                return Promise.resolve();
            },
            limitRate: (maximum) => {
                const available = player.getAvailablePlaybackRates().filter((rate) => rate <= maximum + 0.001);
                if (available.length && player.getPlaybackRate() > maximum) {
                    player.setPlaybackRate(available[available.length - 1]);
                }
            },
        };
    };

    const vimeoAdapter = async (root, config) => {
        await loadScript('https://player.vimeo.com/api/player.js', () => window.Vimeo && window.Vimeo.Player);
        const target = root.querySelector('[data-region="embed-player"]');
        const id = vimeoId(config.url);
        const player = id ? new window.Vimeo.Player(target, {id: id}) : new window.Vimeo.Player(target, {url: config.url});
        await player.ready();
        return {
            ready: () => Promise.resolve(),
            current: () => player.getCurrentTime().catch(() => 0),
            duration: () => player.getDuration().catch(() => 0),
            playing: () => player.getPaused().then((paused) => !paused).catch(() => false),
            rate: () => player.getPlaybackRate().catch(() => 1),
            play: () => player.play().catch(() => {
            }),
            pause: () => player.pause().catch(() => {
            }),
            seek: (seconds) => player.setCurrentTime(Math.max(0, seconds)).catch(() => {
            }),
            limitRate: (maximum) => player.getPlaybackRate().then((rate) => {
                if (rate > maximum) {
                    return player.setPlaybackRate(maximum);
                }
                return null;
            }).catch(() => {
            }),
        };
    };

    const createAdapter = async (root, config) => {
        if (config.source === 'youtube') {
            return youtubeAdapter(root, config);
        }
        if (config.source === 'vimeo') {
            return vimeoAdapter(root, config);
        }
        return html5Adapter(root);
    };

    const formatTime = (seconds) => {
        const value = Math.max(0, Math.round(Number(seconds) || 0));
        const hours = Math.floor(value / 3600);
        const minutes = Math.floor((value % 3600) / 60);
        const secs = value % 60;
        const pair = (n) => String(n).padStart(2, '0');
        return hours > 0 ? `${pair(hours)}:${pair(minutes)}:${pair(secs)}` : `${pair(minutes)}:${pair(secs)}`;
    };

    const createSessionKey = () => {
        if (window.crypto && typeof window.crypto.getRandomValues === 'function') {
            const bytes = new Uint8Array(24);
            window.crypto.getRandomValues(bytes);
            return Array.from(bytes, (byte) => byte.toString(16).padStart(2, '0')).join('');
        }
        return `${Date.now()}${Math.random().toString(36).slice(2)}${Math.random().toString(36).slice(2)}`
            .replace(/[^a-zA-Z0-9]/g, '');
    };

    const init = async (config) => {
        const root = document.getElementById(`videoquiz-player-${config.cmid}`);
        if (!root) {
            return;
        }
        const overlay = root.querySelector('[data-region="question-overlay"]');
        const questionText = root.querySelector('[data-region="question-text"]');
        const questionTime = root.querySelector('[data-region="question-time"]');
        const answers = root.querySelector('[data-region="answers"]');
        const feedback = root.querySelector('[data-region="feedback"]');
        const submit = root.querySelector('[data-action="submit-answer"]');
        const continueButton = root.querySelector('[data-action="continue-video"]');
        const watchedNode = root.querySelector('[data-region="watched-percent"]');
        const scoreNode = root.querySelector('[data-region="score"]');
        const questions = (config.questions || []).slice().sort((a, b) => a.timepoint - b.timepoint);
        const disarmed = new Set();
        let activeQuestion = null;
        let modalOpen = false;
        let sendingProgress = false;
        let previousPosition = 0;
        let lastTrackedPosition = 0;
        let maxPosition = Math.max(0, Number(config.lastposition) || 0);
        let lastPollAt = window.performance.now();
        const sessionKey = createSessionKey();
        let sequence = 0;

        let adapter;
        try {
            adapter = await createAdapter(root, config);
            await adapter.ready();
        } catch (error) {
            Notification.exception(error);
            return;
        }
        const configuredMaxRate = Math.max(1, Number(config.maxplaybackrate) || 2);
        await Promise.resolve(adapter.limitRate(configuredMaxRate));

        if (config.disablecontextmenu) {
            root.addEventListener('contextmenu', (event) => event.preventDefault());
        }

        const clearAnswers = () => {
            while (answers.firstChild) {
                answers.removeChild(answers.firstChild);
            }
        };

        const buildAnswers = (question) => {
            clearAnswers();
            if (question.qtype === 'shortanswer') {
                const input = document.createElement('input');
                input.type = 'text';
                input.className = 'form-control';
                input.setAttribute('data-region', 'short-answer');
                answers.appendChild(input);
                return;
            }
            (question.options || []).forEach((option) => {
                const label = document.createElement('label');
                const input = document.createElement('input');
                input.type = question.qtype === 'multianswer' ? 'checkbox' : 'radio';
                input.name = `videoquiz-question-${question.id}`;
                input.value = String(option.id);
                input.className = 'mt-1';
                const text = document.createElement('span');
                text.textContent = option.text;
                label.appendChild(input);
                label.appendChild(text);
                answers.appendChild(label);
            });
        };

        const responseValue = (question) => {
            if (question.qtype === 'shortanswer') {
                const input = answers.querySelector('[data-region="short-answer"]');
                return input ? input.value.trim() : '';
            }
            const checked = Array.from(answers.querySelectorAll('input:checked'));
            if (question.qtype === 'multianswer') {
                return checked.map((input) => Number(input.value));
            }
            return checked.length ? Number(checked[0].value) : null;
        };

        const canSubmit = (question) => {
            if (question.state && Number(question.state.remaining) === 0) {
                return false;
            }
            const value = responseValue(question);
            if (question.qtype === 'multianswer') {
                return Array.isArray(value) && value.length > 0;
            }
            return value !== null && value !== '';
        };

        const closeQuestion = async () => {
            overlay.classList.add('d-none');
            overlay.setAttribute('aria-hidden', 'true');
            modalOpen = false;
            activeQuestion = null;
            await adapter.play();
        };

        const showQuestion = async (question) => {
            if (modalOpen) {
                return;
            }
            modalOpen = true;
            activeQuestion = question;
            disarmed.add(question.id);
            await adapter.pause();
            questionTime.textContent = formatTime(question.timepoint);
            questionText.textContent = question.text;
            buildAnswers(question);
            feedback.textContent = '';
            feedback.classList.add('d-none');
            const answered = question.state && question.state.answered;
            const remaining = question.state ? Number(question.state.remaining) : question.attemptsallowed;
            submit.disabled = remaining === 0;
            submit.classList.toggle('d-none', remaining === 0);
            continueButton.classList.toggle('d-none', Boolean(question.required && !answered));
            if (remaining === 0) {
                feedback.textContent = config.labels.attemptlimit;
                feedback.classList.remove('d-none');
                continueButton.classList.remove('d-none');
            } else if (!question.required) {
                continueButton.classList.remove('d-none');
            }
            overlay.classList.remove('d-none');
            overlay.setAttribute('aria-hidden', 'false');
            const focusable = answers.querySelector('input');
            if (focusable) {
                focusable.focus();
            }
        };

        submit.addEventListener('click', () => {
            const question = activeQuestion;
            if (!question || !canSubmit(question)) {
                feedback.textContent = config.labels.required;
                feedback.classList.remove('d-none');
                return;
            }
            submit.disabled = true;
            const response = JSON.stringify(responseValue(question));
            Ajax.call([{
                methodname: 'mod_videoquiz_submit_answer',
                args: {cmid: config.cmid, questionid: question.id, response: response},
            }])[0].then((result) => {
                question.state = question.state || {};
                question.state.answered = true;
                question.state.attempts = result.attemptno;
                question.state.remaining = result.attemptsremaining;
                question.state.bestfraction = result.bestfraction;
                feedback.textContent = result.feedback;
                feedback.classList.remove('d-none');
                scoreNode.textContent = Number(result.score).toFixed(1);
                continueButton.classList.remove('d-none');
                if (result.canretry) {
                    submit.disabled = false;
                    submit.classList.remove('d-none');
                } else {
                    submit.classList.add('d-none');
                }
                return result;
            }).catch((error) => {
                submit.disabled = false;
                Notification.exception(error);
            });
        });

        continueButton.addEventListener('click', () => {
            closeQuestion().catch(Notification.exception);
        });

        const initialRequired = questions.find((question) => question.required && !question.state.answered &&
            question.timepoint <= Number(config.lastposition || 0));
        let startPosition = Number(config.lastposition) || 0;
        if (initialRequired) {
            startPosition = initialRequired.timepoint;
        }
        if (startPosition > 1 && Number(config.resumeplayback) === 2) {
            if (!window.confirm(config.labels.resumequestion)) {
                startPosition = 0;
            }
        } else if (Number(config.resumeplayback) === 0) {
            startPosition = 0;
        }
        if (startPosition > 0) {
            await adapter.seek(startPosition);
        }
        previousPosition = startPosition;
        lastTrackedPosition = startPosition;
        maxPosition = Math.max(maxPosition, startPosition);
        if (initialRequired && startPosition === initialRequired.timepoint) {
            await showQuestion(initialRequired);
        } else if (startPosition <= 0.01) {
            const initialQuestion = questions.find((question) => question.timepoint <= 0.01 &&
                !(question.state.answered && !question.allowchange) &&
                !(question.state && Number(question.state.remaining) === 0));
            if (initialQuestion) {
                await showQuestion(initialQuestion);
            }
        }

        const poll = async () => {
            if (modalOpen) {
                return;
            }
            const pollNow = window.performance.now();
            const pollElapsed = Math.max(0.05, (pollNow - lastPollAt) / 1000);
            lastPollAt = pollNow;
            const [current, playing, currentRate] = await Promise.all([
                adapter.current(), adapter.playing(), adapter.rate(),
            ]);
            if (!Number.isFinite(current)) {
                return;
            }
            if (Number(currentRate) > configuredMaxRate + 0.01) {
                await Promise.resolve(adapter.limitRate(configuredMaxRate));
            }

            const allowedAdvance = Math.max(3, pollElapsed * Math.max(1, Number(currentRate) || 1) + 2);
            if (!config.allowseek && current > maxPosition + 1 && current - previousPosition > allowedAdvance) {
                await adapter.seek(maxPosition);
                previousPosition = maxPosition;
                lastTrackedPosition = maxPosition;
                return;
            }
            if (playing && current >= previousPosition && current - previousPosition <= allowedAdvance) {
                maxPosition = Math.max(maxPosition, current);
            }
            if (current < previousPosition - 1) {
                questions.forEach((question) => {
                    if (current < question.timepoint - 0.5) {
                        disarmed.delete(question.id);
                    }
                });
                lastTrackedPosition = current;
                previousPosition = current;
                return;
            }

            const crossed = questions.find((question) => {
                if (disarmed.has(question.id)) {
                    return false;
                }
                if (question.state.answered && !question.allowchange) {
                    return false;
                }
                if (question.state && Number(question.state.remaining) === 0) {
                    return false;
                }
                return previousPosition < question.timepoint && current >= question.timepoint;
            });
            previousPosition = current;
            if (crossed) {
                if (current > crossed.timepoint + 0.75) {
                    await adapter.seek(crossed.timepoint);
                    previousPosition = crossed.timepoint;
                    lastTrackedPosition = crossed.timepoint;
                }
                await showQuestion(crossed);
            }
        };

        const heartbeat = async () => {
            if (modalOpen || sendingProgress) {
                return;
            }
            const [current, duration, playing, rate] = await Promise.all([
                adapter.current(), adapter.duration(), adapter.playing(), adapter.rate(),
            ]);
            const ended = duration > 0 && current >= duration - 0.75;
            if ((!playing && !ended) || duration <= 0) {
                lastTrackedPosition = current;
                return;
            }
            const delta = current - lastTrackedPosition;
            const maximumExpected = Math.max(8, (Number(rate) || 1) * 8);
            if (delta <= 0 || delta > maximumExpected) {
                lastTrackedPosition = current;
                return;
            }
            sendingProgress = true;
            const start = lastTrackedPosition;
            lastTrackedPosition = current;
            sequence++;
            Ajax.call([{
                methodname: 'mod_videoquiz_update_progress',
                args: {
                    cmid: config.cmid,
                    currentposition: current,
                    duration: duration,
                    segmentstart: start,
                    segmentend: current,
                    playbackrate: Number(rate) || 1,
                    sequence: sequence,
                    sessionkey: sessionKey,
                    clienttime: Math.floor(Date.now() / 1000),
                    playerstate: ended ? 'ended' : 'playing',
                },
            }])[0].then(async (result) => {
                watchedNode.textContent = Number(result.percent).toFixed(1);
                scoreNode.textContent = Number(result.score).toFixed(1);
                if ((result.reason === 'seekblocked' || result.reason === 'interactionrequired') &&
                    Math.abs(current - Number(result.correctposition)) > 0.5) {
                    await adapter.seek(Number(result.correctposition));
                    previousPosition = Number(result.correctposition);
                    lastTrackedPosition = Number(result.correctposition);
                }
                sendingProgress = false;
                return result;
            }).catch((error) => {
                sendingProgress = false;
                Notification.exception(error);
            });
        };

        window.setInterval(() => poll().catch(Notification.exception), 300);
        window.setInterval(() => heartbeat().catch(Notification.exception), 5000);
    };

    return {init: init};
});
