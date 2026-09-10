function luminaEach(nodes, fn) {
    if (!nodes || !fn) return;
    for (var i = 0; i < nodes.length; i++) {
        fn(nodes[i], i);
    }
}

var luminaFancyboxScrollTop = 0;
var luminaFancyboxTrigger = null;
var luminaFancyboxTriggerTop = 0;

function luminaGetScrollTop() {
    return window.pageYOffset || document.documentElement.scrollTop || document.body.scrollTop || 0;
}

function luminaRememberFancyboxScroll(trigger) {
    luminaFancyboxScrollTop = luminaGetScrollTop();
    luminaFancyboxTrigger = trigger || null;
    luminaFancyboxTriggerTop = 0;
    if (luminaFancyboxTrigger && luminaFancyboxTrigger.getBoundingClientRect) {
        luminaFancyboxTriggerTop = luminaFancyboxTrigger.getBoundingClientRect().top;
    }
}

function luminaRestoreFancyboxScroll() {
    var targetTop = luminaFancyboxScrollTop || 0;
    var nextFrame = window.requestAnimationFrame || function (fn) {
        return window.setTimeout(fn, 16);
    };
    function resolveTargetTop() {
        if (luminaFancyboxTrigger && luminaFancyboxTrigger.isConnected && luminaFancyboxTrigger.getBoundingClientRect) {
            return Math.max(0, luminaGetScrollTop() + luminaFancyboxTrigger.getBoundingClientRect().top - luminaFancyboxTriggerTop);
        }
        return targetTop;
    }
    function restore() {
        window.scrollTo(0, resolveTargetTop());
    }
    window.setTimeout(function () {
        restore();
        nextFrame(function () {
            restore();
        });
    }, 0);
    window.setTimeout(restore, 80);
}

function luminaInitFancybox() {
    if (!window.jQuery || !jQuery.fn.fancybox) {
        return;
    }
    jQuery(document)
        .off('click.luminaFancyboxScroll', '[data-fancybox]')
        .on('click.luminaFancyboxScroll', '[data-fancybox]', function () {
            luminaRememberFancyboxScroll(this);
        });
    jQuery('[data-fancybox]').fancybox({
        buttons: ["zoom", "slideShow", "thumbs", "close"],
        hash: false,
        backFocus: false,
        autoFocus: false,
        trapFocus: false,
        beforeShow: function (instance, current) {
            var trigger = current && current.opts && current.opts.$orig ? current.opts.$orig[0] : null;
            luminaRememberFancyboxScroll(trigger || luminaFancyboxTrigger);
        },
        afterClose: function () {
            luminaRestoreFancyboxScroll();
        }
    });
}

(function () {
    var loadingToast = null;
    var activeMaskOwner = null;

    function ensureStack() {
        var stack = document.getElementById('lumina-toast-stack');
        if (!stack) {
            stack = document.createElement('div');
            stack.id = 'lumina-toast-stack';
            stack.className = 'lumina-toast-stack';
            document.body.appendChild(stack);
        }
        return stack;
    }

    function ensureMask() {
        var mask = document.getElementById('lumina-toast-mask');
        if (!mask) {
            mask = document.createElement('div');
            mask.id = 'lumina-toast-mask';
            mask.className = 'lumina-toast-mask';
            document.body.appendChild(mask);
        }
        return mask;
    }

    function setMask(active, owner) {
        var mask = ensureMask();
        if (active) {
            activeMaskOwner = owner || true;
            mask.classList.add('is-active');
            return;
        }
        if (!owner || activeMaskOwner === owner) {
            activeMaskOwner = null;
            mask.classList.remove('is-active');
        }
    }

    function resetStackState(stack) {
        if (stack && !stack.children.length) {
            stack.classList.remove('is-loading');
        }
    }

    function removeToast(el, immediate) {
        if (!el || !el.parentNode) return;
        var parent = el.parentNode;
        if (el.__luminaToastTimer) {
            window.clearTimeout(el.__luminaToastTimer);
            el.__luminaToastTimer = null;
        }
        if (el.__luminaToastMasked) {
            setMask(false, el);
        }
        if (loadingToast === el) {
            loadingToast = null;
        }
        if (immediate) {
            parent.removeChild(el);
            resetStackState(parent);
            return;
        }
        el.classList.add('is-leaving');
        window.setTimeout(function () {
            if (el && el.parentNode) {
                var currentParent = el.parentNode;
                currentParent.removeChild(el);
                resetStackState(currentParent);
            }
        }, 190);
    }

    function closeLoading() {
        if (loadingToast) {
            removeToast(loadingToast, true);
            loadingToast = null;
        }
    }

    function iconText(type) {
        if (type === 'success') return '✓';
        if (type === 'error') return '!';
        if (type === 'warning') return '!';
        return '';
    }

    function luminaToast(type, text, opts) {
        opts = opts || {};
        type = type || 'info';
        text = text == null ? '' : String(text);
        if (type !== 'loading') {
            closeLoading();
        } else {
            closeLoading();
        }

        var stack = ensureStack();
        stack.classList.toggle('is-loading', type === 'loading');
        var toast = document.createElement('div');
        toast.className = 'lumina-toast lumina-toast-' + type;
        toast.setAttribute('role', type === 'error' ? 'alert' : 'status');

        var icon = document.createElement('span');
        icon.className = 'lumina-toast-icon';
        icon.textContent = iconText(type);
        toast.appendChild(icon);

        var body = document.createElement('span');
        body.className = 'lumina-toast-text';
        body.textContent = text;
        toast.appendChild(body);

        stack.appendChild(toast);

        if (opts.mask) {
            toast.__luminaToastMasked = true;
            setMask(true, toast);
        }

        if (type === 'loading') {
            loadingToast = toast;
            return toast;
        }

        toast.__luminaToastTimer = window.setTimeout(function () {
            removeToast(toast);
        }, opts.duration || 2200);
        return toast;
    }

    window.luminaToast = luminaToast;
    window.delclose = closeLoading;
    window.successpop = function (text, maskok) {
        return luminaToast('success', text, { mask: maskok === 'ok' });
    };
    window.errorpop = function (text, maskok) {
        return luminaToast('error', text, { mask: maskok === 'ok', duration: 2600 });
    };
    window.warnpop = function (text, maskok) {
        return luminaToast('warning', text, { mask: maskok === 'ok', duration: 2500 });
    };
    window.loadpop = function (text, maskok) {
        return luminaToast('loading', text || '处理中...', { mask: maskok === 'ok' });
    };
})();

// 微信资料卡：点击微信号复制（委托绑定，脚本只加载一次，PJAX 后依然生效）
document.addEventListener('click', function (event) {
    var btn = event.target && event.target.closest ? event.target.closest('.lumina-profile-wechat-text') : null;
    if (!btn) {
        return;
    }
    event.preventDefault();
    event.stopPropagation();
    var value = (btn.getAttribute('data-copy') || '').trim();
    if (!value) {
        return;
    }
    var done = function () {
        if (typeof window.luminaToast === 'function') {
            window.luminaToast('success', '微信号已复制');
        }
    };
    var fallback = function () {
        var ta = document.createElement('textarea');
        ta.value = value;
        ta.setAttribute('readonly', '');
        ta.style.position = 'fixed';
        ta.style.left = '-9999px';
        document.body.appendChild(ta);
        ta.select();
        try {
            document.execCommand('copy');
        } catch (err) {}
        document.body.removeChild(ta);
        done();
    };
    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(value).then(done, fallback);
    } else {
        fallback();
    }
}, true);

// 全文/收起按钮：原定义在 index.js，仅首页/详情页加载，导致 pjax 跨页面类型导航后
// window.quanwenan 缺失、内联 onclick 失效。这里兜底注册为全局，保证所有页面 + pjax 持续可用。
if (typeof window.quanwenan !== 'function') {
    window.quanwenan = function (btn, evt) {
        btn = btn || (evt && (evt.currentTarget || evt.target)) || (window.event && (window.event.target || window.event.srcElement)) || null;
        if (!btn) return false;
        if (evt && typeof evt.preventDefault === 'function') {
            evt.preventDefault();
        }
        var previewId = btn.getAttribute('data-preview');
        var fullId = btn.getAttribute('data-full');
        if (previewId && fullId) {
            var preview = document.getElementById(previewId);
            var full = document.getElementById(fullId);
            if (!preview || !full) return false;
            var opened = btn.getAttribute('data-open') === '1';
            if (opened) {
                full.style.display = 'none';
                preview.style.display = '';
                btn.setAttribute('data-open', '0');
                btn.innerText = '全文';
            } else {
                preview.style.display = 'none';
                full.style.display = '';
                btn.setAttribute('data-open', '1');
                btn.innerText = '收起';
            }
            return false;
        }
        // 兼容 echo_log.php 旧式 id/lang 写法
        var ele = btn.id || '';
        var elelang = btn.lang;
        var quanwid = ele.replace('sh-content-quanwenan-', '');
        var detailBlock = document.getElementById('sh-content-qwdid-' + quanwid);
        var detailBtn = document.getElementById('sh-content-quanwenan-' + quanwid);
        if (!detailBlock || !detailBtn) {
            return false;
        }
        if (elelang == 0) {
            var re = new RegExp('wzndhycyc', 'g');
            detailBlock.className = detailBlock.className.replace(re, '');
            detailBtn.lang = 1;
            detailBtn.innerText = '收起';
        } else if (elelang == 1) {
            detailBlock.className += 'wzndhycyc';
            detailBtn.lang = 0;
            detailBtn.innerText = '全文';
        }
        return false;
    };
}

function luminaInitPageBindings() {
    // PJAX replaces this node, so its marker naturally resets for every new page.
    // It also prevents the normal DOMContentLoaded path from binding the first page twice.
    var pageRoot = document.querySelector('[data-lumina-pjax-container]') || document.body;
    if (pageRoot && pageRoot.getAttribute('data-lumina-bindings-ready') === '1') {
        return;
    }
    var imgs = document.querySelectorAll('img[data-src]');
    luminaEach(imgs, function (img) {
        var src = img.getAttribute('data-src');
        if (src) {
            img.setAttribute('src', src);
        }
    });

    luminaInitFancybox();

    var commentWrap = document.getElementById('comments');
    var commentPost = document.getElementById('comment-post');
    var pidInput = document.getElementById('comment-pid');
    if (commentWrap && commentPost && pidInput) {
        luminaEach(document.querySelectorAll('.com-reply'), function (btn) {
            btn.addEventListener('click', function () {
                var cid = btn.getAttribute('data-cid');
                if (!cid) return;
                var target = document.getElementById('comment-' + cid);
                if (!target) return;

                if (pidInput.value === cid) {
                    commentWrap.appendChild(commentPost);
                    pidInput.value = '0';
                } else {
                    target.appendChild(commentPost);
                    pidInput.value = cid;
                }
            });
        });
    }

    var postForm = document.getElementById('lumina-post-form');
    if (postForm) {
        var publishSubmitBtn = document.getElementById('lumina-publish-submit');
        var publishContentInput = document.getElementById('lumina-content');

        function luminaUpdatePublishSubmitState() {
            if (!publishSubmitBtn) return;
            var contentValue = publishContentInput ? publishContentInput.value : '';
            var photosField = document.getElementById('lumina-photos');
            var liveCoverField = document.getElementById('lumina-live-photos-cover');
            var livePhotosField = document.getElementById('lumina-live-photos');
            var videoField = document.getElementById('lumina-video');
            var embedField = document.getElementById('lumina-embed');
            var musicField = document.getElementById('lumina-music');
            var linkField = document.getElementById('lumina-link-url');
            var redTotalField = document.getElementById('lumina-redpacket-total');
            var redCountField = document.getElementById('lumina-redpacket-count');
            var typeField = document.getElementById('lumina-type');
            var hasContent = !!String(contentValue || '').trim();
            var isLiveType = typeField && typeField.value === 'live';
            var hasPhotos = !!String(isLiveType && liveCoverField ? liveCoverField.value : (photosField ? photosField.value : '')).trim();
            var hasLivePhotos = !!String(livePhotosField ? livePhotosField.value : '').trim();
            var hasVideo = !!String(videoField ? videoField.value : '').trim();
            var hasEmbed = !!String(embedField ? embedField.value : '').trim();
            var hasMusic = !!String(musicField ? musicField.value : '').trim();
            var hasLink = !!String(linkField ? linkField.value : '').trim();
            var hasRedpacket = (typeField && typeField.value === 'redpacket')
                || !!String(redTotalField ? redTotalField.value : '').trim()
                || !!String(redCountField ? redCountField.value : '').trim();
            var canSubmit = isLiveType ? (hasPhotos && hasLivePhotos) : (hasContent || hasPhotos || hasVideo || hasEmbed || hasMusic || hasLink || hasRedpacket);
            publishSubmitBtn.disabled = !canSubmit;
            publishSubmitBtn.classList.toggle('is-active', canSubmit);
            publishSubmitBtn.setAttribute('aria-disabled', canSubmit ? 'false' : 'true');
        }

        if (publishSubmitBtn) {
            if (publishContentInput) {
                publishContentInput.addEventListener('input', luminaUpdatePublishSubmitState);
            }
            luminaEach(postForm.querySelectorAll('[data-field-key]'), function (input) {
                input.addEventListener('input', luminaUpdatePublishSubmitState);
                input.addEventListener('change', luminaUpdatePublishSubmitState);
            });
            var liveCoverInput = document.getElementById('lumina-live-photos-cover');
            if (liveCoverInput) {
                liveCoverInput.addEventListener('input', luminaUpdatePublishSubmitState);
                liveCoverInput.addEventListener('change', luminaUpdatePublishSubmitState);
            }
            luminaUpdatePublishSubmitState();
        }
        window.luminaUpdatePublishSubmitState = luminaUpdatePublishSubmitState;

        postForm.addEventListener('submit', function (e) {
            if (publishSubmitBtn && publishSubmitBtn.disabled) {
                e.preventDefault();
                return;
            }
            e.preventDefault();
            var msg = document.getElementById('lumina-post-msg');
            function setPostStatus(text, kind) {
                if (msg) {
                    msg.textContent = '';
                    msg.hidden = true;
                }
                if (!text) return;
                if (kind === 'success' && typeof successpop === 'function') {
                    successpop(text);
                } else if (kind === 'loading' && typeof loadpop === 'function') {
                    loadpop(text, 'ok');
                } else if (kind === 'error' && typeof warnpop === 'function') {
                    warnpop(text);
                }
            }
            function looksLikeSupportedEmbed(value) {
                value = String(value || '').trim();
                if (!value) return false;
                var m = value.match(/<iframe[^>]+src=["']([^"']+)["']/i);
                var src = m ? m[1] : value;
                if (src.indexOf('//') === 0) src = 'https:' + src;
                return /(?:bilibili\.com\/video\/|player\.bilibili\.com\/player\.html|bilibili\.com\/blackboard\/|live\.bilibili\.com\/|v\.douyin\.com\/|douyin\.com\/video\/|iesdouyin\.com\/share\/video\/|ixigua\.com\/|open\.douyin\.com\/player\/video|v\.qq\.com\/x\/|v\.qq\.com\/txp\/iframe|v\.youku\.com\/v_show\/|player\.youku\.com\/embed\/|youtube\.com\/watch|youtube(?:-nocookie)?\.com\/embed\/|youtu\.be\/)/i.test(src);
            }
            if (parseInt(postForm.getAttribute('data-lumina-poster-tasks') || '0', 10) > 0) {
                setPostStatus('正在生成视频封面，请稍候', 'error');
                return;
            }

            var photosField = document.getElementById('lumina-photos');
            var liveCoverField = document.getElementById('lumina-live-photos-cover');
            var livePhotosField = document.getElementById('lumina-live-photos');
            var videoField = document.getElementById('lumina-video');
            var embedField = document.getElementById('lumina-embed');
            var musicField = document.getElementById('lumina-music');
            var linkField = document.getElementById('lumina-link-url');
            var redTotalField = document.getElementById('lumina-redpacket-total');
            var redCountField = document.getElementById('lumina-redpacket-count');
            var typeField = document.getElementById('lumina-type');
            if (typeField) {
                if (typeField.value === 'live' && photosField && liveCoverField) {
                    photosField.value = liveCoverField.value;
                }
                var photosVal = photosField ? photosField.value.trim() : '';
                var livePhotosVal = livePhotosField ? livePhotosField.value.trim() : '';
                var videoVal = videoField ? videoField.value.trim() : '';
                var embedVal = embedField ? embedField.value.trim() : '';
                var musicVal = musicField ? musicField.value.trim() : '';
                var linkVal = linkField ? linkField.value.trim() : '';
                var redTotalVal = redTotalField ? redTotalField.value.trim() : '';
                var redCountVal = redCountField ? redCountField.value.trim() : '';
                var hasRedpacket = (typeField.value === 'redpacket') || redTotalVal !== '' || redCountVal !== '';
                if (hasRedpacket) {
                    typeField.value = 'redpacket';
                } else if (embedVal) {
                    typeField.value = 'embed';
                } else if (videoVal) {
                    typeField.value = 'video';
                } else if (musicVal) {
                    typeField.value = 'music';
                } else if (photosVal || livePhotosVal) {
                    if (typeField.value === 'live') {
                        typeField.value = 'live';
                    } else {
                        typeField.value = 'img';
                    }
                } else if (linkVal) {
                    typeField.value = 'link';
                } else {
                    typeField.value = 'only';
                }
                if (typeField.value === 'live' && (!photosVal || !livePhotosVal)) {
                    setPostStatus('实况图需要同时填写图片和实况视频', 'error');
                    return;
                }
                if (typeField.value === 'embed' && !looksLikeSupportedEmbed(embedVal)) {
                    setPostStatus('请填写支持的平台视频链接或官方 iframe', 'error');
                    return;
                }
            }

            var formData = new FormData(postForm);

            var content = (document.getElementById('lumina-content') || {}).value || '';
            var title = (document.getElementById('lumina-title') || {}).value || '';
            var excerpt = content.trim().slice(0, 120);

            if (!title.trim()) {
                title = content.trim().slice(0, 20) || '未命名';
                formData.set('title', title);
            }
            formData.set('excerpt', excerpt);

            var photos = (document.getElementById('lumina-photos') || {}).value || '';
            var coverInput = document.getElementById('lumina-cover');
            if (coverInput && coverInput.value) {
                formData.set('cover', coverInput.value);
            }

            var allowRemark = document.getElementById('lumina-allow-remark-input');
            var draft = document.getElementById('lumina-draft-input');
            if (allowRemark) {
                formData.set('allow_remark', allowRemark.value || 'n');
            }
            if (draft) {
                formData.set('draft', draft.value || 'n');
            }

            var fieldInputs = postForm.querySelectorAll('[data-field-key]');
            luminaEach(fieldInputs, function (input) {
                var key = input.getAttribute('data-field-key');
                var value = input.value || '';
                formData.append('field_keys[]', key);
                formData.append('field_values[]', value);
            });

            var actionType = formData.get('lumina_action') || 'post_publish';
            var isUpdate = actionType === 'post_update';
            setPostStatus(isUpdate ? '正在更新...' : '正在发布...', 'loading');
            fetch(postForm.action, {
                method: 'POST',
                body: formData,
                credentials: 'same-origin'
            }).then(function (res) {
                return res.json();
            }).then(function (data) {
                if (data && data.code === 0) {
                    if (typeof delclose === 'function') delclose();
                    setPostStatus(isUpdate ? '更新成功' : '发布成功', 'success');
                    if (data.data && data.data.article_id) {
                        var blogUrl = postForm.getAttribute('data-blog-url') || './';
                        window.location.href = blogUrl + '?post=' + data.data.article_id;
                    }
                } else {
                    if (typeof delclose === 'function') delclose();
                    setPostStatus((data && data.msg) ? data.msg : (isUpdate ? '更新失败' : '发布失败'), 'error');
                }
            }).catch(function () {
                if (typeof delclose === 'function') delclose();
                setPostStatus(isUpdate ? '更新失败' : '发布失败', 'error');
            });
        });

        (function initPlainLocationFallback() {
            var locationWrap = document.querySelector('.lumina-pub-location');
            var locationInput = document.getElementById('lumina-location');
            if (!locationWrap || !locationInput || locationWrap.getAttribute('data-map-enabled') === 'y') {
                return;
            }
            var hiddenIds = [
                'lumina-location-address',
                'lumina-location-lat',
                'lumina-location-lng',
                'lumina-location-poi-id',
                'lumina-location-city'
            ];
            locationInput.addEventListener('input', function () {
                hiddenIds.forEach(function (id) {
                    var input = document.getElementById(id);
                    if (input) input.value = '';
                });
            });
        })();

        (function initLocationPicker() {
            var locationWrap = document.querySelector('.lumina-pub-location');
            var locationInput = document.getElementById('lumina-location');
            var pickBtn = document.getElementById('lumina-location-pick');
            if (!locationWrap || !locationInput || !pickBtn || locationWrap.getAttribute('data-map-enabled') !== 'y') {
                return;
            }
            var tokenInput = postForm.querySelector('input[name="token"]');
            var addressInput = document.getElementById('lumina-location-address');
            var latInput = document.getElementById('lumina-location-lat');
            var lngInput = document.getElementById('lumina-location-lng');
            var poiInput = document.getElementById('lumina-location-poi-id');
            var cityInput = document.getElementById('lumina-location-city');
            var sheet = null;
            var listEl = null;
            var searchInput = null;
            var currentCoords = null;
            var searchTimer = null;

            function setField(input, value) {
                if (!input) return;
                input.value = value || '';
                input.dispatchEvent(new Event('input', { bubbles: true }));
                input.dispatchEvent(new Event('change', { bubbles: true }));
            }

            function setState(text) {
                if (!listEl) return;
                listEl.innerHTML = '<div class="lumina-location-state">' + text + '</div>';
            }

            function ensureSheet() {
                if (sheet) return;
                sheet = document.createElement('div');
                sheet.className = 'lumina-location-sheet';
                sheet.innerHTML = [
                    '<div class="lumina-location-panel">',
                    '<div class="lumina-location-head"><span>所在位置</span><button type="button" class="lumina-location-close" aria-label="关闭">×</button></div>',
                    '<div class="lumina-location-search"><input type="search" placeholder="搜索附近位置"></div>',
                    '<div class="lumina-location-list"></div>',
                    '</div>'
                ].join('');
                document.body.appendChild(sheet);
                listEl = sheet.querySelector('.lumina-location-list');
                searchInput = sheet.querySelector('.lumina-location-search input');
                sheet.querySelector('.lumina-location-close').addEventListener('click', closeSheet);
                sheet.addEventListener('click', function (e) {
                    if (e.target === sheet) closeSheet();
                });
                searchInput.addEventListener('input', function () {
                    clearTimeout(searchTimer);
                    searchTimer = setTimeout(function () {
                        requestPois(searchInput.value.trim());
                    }, 260);
                });
            }

            function openSheet() {
                ensureSheet();
                sheet.classList.add('is-open');
                setState('正在获取当前位置...');
            }

            function closeSheet() {
                if (sheet) sheet.classList.remove('is-open');
            }

            function renderItems(items) {
                if (!listEl) return;
                listEl.innerHTML = '';
                var clearBtn = document.createElement('button');
                clearBtn.type = 'button';
                clearBtn.className = 'lumina-location-item';
                clearBtn.innerHTML = '<div class="lumina-location-title">不显示位置</div><div class="lumina-location-address">发布时不附带地理位置</div>';
                clearBtn.addEventListener('click', function () {
                    setField(locationInput, '');
                    setField(addressInput, '');
                    setField(latInput, '');
                    setField(lngInput, '');
                    setField(poiInput, '');
                    setField(cityInput, '');
                    closeSheet();
                });
                listEl.appendChild(clearBtn);
                if (!items || !items.length) {
                    var empty = document.createElement('div');
                    empty.className = 'lumina-location-state';
                    empty.textContent = '没有找到附近位置';
                    listEl.appendChild(empty);
                    return;
                }
                items.forEach(function (item) {
                    var btn = document.createElement('button');
                    btn.type = 'button';
                    btn.className = 'lumina-location-item';
                    var title = item.title || '当前位置';
                    var address = item.address || '';
                    btn.innerHTML = '<div class="lumina-location-title"></div><div class="lumina-location-address"></div>';
                    btn.querySelector('.lumina-location-title').textContent = title;
                    btn.querySelector('.lumina-location-address').textContent = address || '使用当前坐标';
                    btn.addEventListener('click', function () {
                        setField(locationInput, title);
                        setField(addressInput, address);
                        setField(latInput, item.lat || '');
                        setField(lngInput, item.lng || '');
                        setField(poiInput, item.poi_id || '');
                        setField(cityInput, item.city || '');
                        closeSheet();
                    });
                    listEl.appendChild(btn);
                });
            }

            function requestPois(keyword) {
                if (!currentCoords) return;
                setState('正在加载附近位置...');
                var data = new FormData();
                data.append('lumina_action', 'location_nearby');
                data.append('token', tokenInput ? tokenInput.value : '');
                data.append('lat', currentCoords.lat);
                data.append('lng', currentCoords.lng);
                if (keyword) data.append('keyword', keyword);
                fetch(postForm.action, {
                    method: 'POST',
                    body: data,
                    credentials: 'same-origin'
                }).then(function (res) {
                    return res.json();
                }).then(function (json) {
                    if (json && json.code === 0 && json.data) {
                        renderItems(json.data.items || []);
                    } else {
                        setState((json && json.msg) ? json.msg : '位置服务不可用');
                    }
                }).catch(function () {
                    setState('位置服务请求失败');
                });
            }

            function startPick() {
                openSheet();
                if (!navigator.geolocation) {
                    setState('当前浏览器不支持定位，可以手动输入位置');
                    return;
                }
                navigator.geolocation.getCurrentPosition(function (pos) {
                    currentCoords = {
                        lat: pos.coords.latitude,
                        lng: pos.coords.longitude
                    };
                    requestPois(searchInput ? searchInput.value.trim() : '');
                }, function () {
                    setState('定位失败，请允许浏览器定位权限，或手动输入位置');
                }, {
                    enableHighAccuracy: true,
                    timeout: 10000,
                    maximumAge: 60000
                });
            }

            pickBtn.addEventListener('click', function (e) {
                e.preventDefault();
                startPick();
            });
            locationWrap.addEventListener('click', function (e) {
                if (e.target === pickBtn) return;
                startPick();
            });
        })();
    }

    function luminaShowResult(ok, msgText, msgEl, opts) {
        var text = msgText;
        if (!text || text === 'ok' || text === 'OK') {
            text = ok ? '保存成功' : '保存失败';
        }
        if (msgEl) msgEl.textContent = text;
        if (opts && opts.silent) return;
        if (ok) {
            if (typeof successpop === 'function') {
                successpop(text);
            }
        } else {
            if (typeof warnpop === 'function') {
                warnpop(text);
            }
        }
    }

    function luminaSubmitForm(form, msgId, opts) {
        if (!form) return Promise.resolve({ ok: false, msg: '表单不存在' });
        var msg = msgId ? document.getElementById(msgId) : null;
        var action = form.getAttribute('action') || window.location.href;
        var data = new FormData(form);
        return fetch(action, {
            method: 'POST',
            body: data,
            credentials: 'same-origin'
        }).then(function (res) {
            return res.text();
        }).then(function (text) {
            var json = null;
            try {
                json = JSON.parse(text);
            } catch (e) {
                json = null;
            }
            var payload = json;
            if (Array.isArray(json)) {
                payload = json[0] || {};
            }
            var code = payload && payload.code;
            var ok = (code === 0 || code === '0' || code === 200 || code === '200');
            var msgText = payload && payload.msg ? payload.msg : (ok ? '保存成功' : (text || '保存失败'));
            if (json === null && text) {
                ok = false;
            }
            luminaShowResult(ok, msgText, msg, opts);
            return { ok: ok, msg: msgText };
        }).catch(function () {
            luminaShowResult(false, '保存失败', msg, opts);
            return { ok: false, msg: '保存失败' };
        });
    }

    function luminaBindAjaxForm(formId, msgId) {
        var form = document.getElementById(formId);
        if (!form) return;
        form.addEventListener('submit', function (e) {
            e.preventDefault();
            luminaSubmitForm(form, msgId);
        });
    }

    luminaBindAjaxForm('lumina-profile-form', 'lumina-profile-msg');
    luminaBindAjaxForm('lumina-password-form', 'lumina-password-msg');

    var saveAllBtn = document.getElementById('lumina-profile-save-all');
    if (saveAllBtn) {
        saveAllBtn.addEventListener('click', function () {
            var profileForm = document.getElementById('lumina-profile-form');
            var passForm = document.getElementById('lumina-password-form');
            var passInputs = passForm ? passForm.querySelectorAll('input[type="password"]') : [];
            var hasPassValue = false;
            if (passInputs && passInputs.length) {
                for (var i = 0; i < passInputs.length; i++) {
                    if ((passInputs[i].value || '').trim() !== '') {
                        hasPassValue = true;
                        break;
                    }
                }
            }

            if (hasPassValue && passInputs && passInputs.length) {
                for (var j = 0; j < passInputs.length; j++) {
                    if ((passInputs[j].value || '').trim() === '') {
                        if (typeof warnpop === 'function') {
                            warnpop('请完整填写密码');
                        }
                        return;
                    }
                }
            }

            if (typeof loadpop === 'function') {
                loadpop('正在保存，请稍后...', 'ok');
            }

            luminaSubmitForm(profileForm, 'lumina-profile-msg', { silent: hasPassValue }).then(function (res) {
                if (!res.ok) {
                    return;
                }
                if (!hasPassValue) {
                    return;
                }
                return luminaSubmitForm(passForm, 'lumina-password-msg');
            });
        });
    }

    function luminaToggleSettingPanel(targetId, toggleEl) {
        if (!targetId) return;
        var panel = document.getElementById(targetId);
        if (!panel) return;
        var isOpen = panel.classList.contains('is-open');
        if (isOpen) {
            panel.classList.remove('is-open');
        } else {
            panel.classList.add('is-open');
        }
        if (toggleEl) {
            if (isOpen) {
                toggleEl.classList.remove('is-open');
            } else {
                toggleEl.classList.add('is-open');
            }
        }
    }

    var settingToggles = document.querySelectorAll('.lumina-setting-toggle');
    if (settingToggles && settingToggles.length) {
        luminaEach(settingToggles, function (toggle) {
            toggle.addEventListener('click', function (e) {
                if (e && e.target && e.target.closest && e.target.closest('.lumina-setting-file')) {
                    return;
                }
                if (e && e.target && e.target.closest && e.target.closest('.lumina-setting-toggle-btn')) {
                    return;
                }
                var targetId = toggle.getAttribute('data-target');
                luminaToggleSettingPanel(targetId, toggle);
            });
        });
    }

    var settingToggleBtns = document.querySelectorAll('.lumina-setting-toggle-btn');
    if (settingToggleBtns && settingToggleBtns.length) {
        luminaEach(settingToggleBtns, function (btn) {
            btn.addEventListener('click', function (e) {
                if (e) {
                    e.preventDefault();
                    e.stopPropagation();
                }
                var targetId = btn.getAttribute('data-target');
                var row = btn.closest ? btn.closest('.lumina-setting-item') : null;
                luminaToggleSettingPanel(targetId, row || btn);
            });
        });
    }

    var uploadBtns = document.querySelectorAll('[data-upload-target]');
    if (uploadBtns && uploadBtns.length) {
        luminaEach(uploadBtns, function (btn) {
            btn.addEventListener('click', function (e) {
                e.preventDefault();
                var targetId = btn.getAttribute('data-upload-target');
                var input = targetId ? document.getElementById(targetId) : null;
                if (input) {
                    input.click();
                }
            });
        });
    }

    function luminaResolveUploadUrl(rawUrl, baseUrl) {
        if (!rawUrl) return '';
        if (/^https?:\/\//i.test(rawUrl)) return rawUrl;
        if (rawUrl.indexOf('//') === 0) {
            return window.location.protocol + rawUrl;
        }
        if (!baseUrl) return rawUrl;
        var cleanBase = baseUrl.split('#')[0].split('?')[0];
        if (cleanBase.slice(-1) !== '/') cleanBase += '/';
        rawUrl = rawUrl.replace(/^\/+/, '');
        return cleanBase + rawUrl;
    }

    function luminaGetBlogBase(input) {
        if (!input) return '';
        var base = input.getAttribute('data-blog-url') || '';
        if (base) return base;
        var upload = input.getAttribute('data-upload-url') || '';
        if (!upload) return '';
        var clean = upload.split('#')[0].split('?')[0];
        return clean.replace(/(?:index\.php\/)?user\/profile.*$/i, '');
    }

    function luminaUpdateUploadPreview(input, url) {
        if (!input || !url) return;
        var key = input.getAttribute('data-preview') || '';
        if (key) {
            var imgs = document.querySelectorAll('img[data-preview="' + key + '"]');
            luminaEach(imgs, function (img) {
                img.src = url;
                img.style.display = 'block';
            });
            var plusAll = document.querySelectorAll('.lumina-setting-plus[data-preview="' + key + '"]');
            luminaEach(plusAll, function (plus) {
                plus.style.display = 'none';
            });
            return;
        }
        var wrap = input.parentNode;
        if (!wrap) return;
        var img = wrap.querySelector('img');
        var plus = wrap.querySelector('span');
        if (img) {
            img.src = url;
            img.style.display = 'block';
        }
        if (plus) {
            plus.style.display = 'none';
        }
    }

    function luminaShowUploadTip(ok, msg) {
        var text = msg;
        if (!text || text === 'ok' || text === 'OK') {
            text = ok ? '上传成功' : '上传失败';
        }
        if (ok) {
            if (typeof successpop === 'function') {
                successpop(text);
                return;
            }
        } else {
            if (typeof warnpop === 'function') {
                warnpop(text);
                return;
            }
        }
        alert(text);
    }

    function luminaUploadSingleImage(input, opts) {
        if (!input || !input.files || !input.files.length) return;
        var file = input.files[0];
        if (!file) return;
        var fd = new FormData();
        var token = input.getAttribute('data-token') || '';
        if (token) fd.append('token', token);
        var mode = input.getAttribute('data-mode') || '';
        var action = (opts && opts.action) ? opts.action : (input.getAttribute('data-action') || '');
        var url = input.getAttribute('data-upload-url') || '';
        if (mode === 'blogger') {
            url = input.getAttribute('data-blogger-url') || url;
            fd.append('image', file);
        } else {
            if (action) fd.append('lumina_action', action);
            fd.append('file', file);
        }
        if (!url) {
            luminaShowUploadTip(false, '上传地址无效');
            input.value = '';
            return;
        }
        if (typeof loadpop === 'function') {
            loadpop('上传中，请稍后...', 'ok');
        }
        fetch(url, {
            method: 'POST',
            body: fd,
            credentials: 'same-origin'
        }).then(function (res) {
            return res.text();
        }).then(function (text) {
            var json = null;
            try {
                json = JSON.parse(text);
            } catch (e) {
                json = null;
            }
            var ok = false;
            var msg = '上传失败';
            var fileUrl = '';
            if (json) {
                var code = json.code;
                ok = (code === 0 || code === '0' || code === 200 || code === '200');
                msg = json.msg ? json.msg : (ok ? '上传成功' : '上传失败');
                if (json.data) {
                    if (typeof json.data === 'string') {
                        fileUrl = json.data;
                    } else if (json.data.url) {
                        fileUrl = json.data.url;
                    }
                }
            } else if (text) {
                msg = text;
            }

            if (ok) {
                if (fileUrl) {
                    fileUrl = luminaResolveUploadUrl(fileUrl, luminaGetBlogBase(input));
                    luminaUpdateUploadPreview(input, fileUrl);
                }
                luminaShowUploadTip(true, msg || '上传成功');
            } else {
                luminaShowUploadTip(false, msg || '上传失败');
            }
            input.value = '';
        }).catch(function () {
            luminaShowUploadTip(false, '上传失败');
            input.value = '';
        });
    }

    var avatarInput = document.getElementById('lumina-avatar-input');
    if (avatarInput) {
        avatarInput.addEventListener('change', function () {
            luminaUploadSingleImage(avatarInput, { action: 'avatar_upload' });
        });
    }

    var coverInput = document.getElementById('lumina-cover-input');
    if (coverInput) {
        coverInput.addEventListener('change', function () {
            var action = coverInput.getAttribute('data-action') || 'cover_upload';
            luminaUploadSingleImage(coverInput, { action: action });
        });
    }

    var imgAdd = document.getElementById('lumina-img-add');
    var imgTextarea = document.getElementById('lumina-photos');
    var liveCoverTextarea = document.getElementById('lumina-live-photos-cover');
    var livePhotosTextarea = document.getElementById('lumina-live-photos');
    var emojiPanel = document.getElementById('lumina-emoji-panel');
    var typeField = document.getElementById('lumina-type');
    var emojiBtn = document.getElementById('bqkg');
    var emojiIcon = document.getElementById('bqkgimg');
    var toggleImg = document.getElementById('tpscfs');
    var toggleImgIcon = document.getElementById('twlimg');
    var imgWrap = document.getElementById('sh-cont-img');
    var imgUrlWrap = document.getElementById('sh-cont-imgul');
    var liveWrap = document.getElementById('sh-cont-live');
    var videoWrap = document.getElementById('sh-cont-sp');
    var videoPosterWrap = document.getElementById('sh-cont-spfm');
    var embedWrap = document.getElementById('sh-cont-embed');
    var musicWrap = document.getElementById('sh-cont-yy');
    var linkWrap = document.getElementById('sh-cont-link');
    var advanced = document.getElementById('lumina-advanced');
    var morePanel = document.getElementById('lumina-more-panel');
    var moreBtn = document.getElementById('lumina-more-btn');
    var moreIcon = document.getElementById('lumina-more-icon');
    var typeImgBtn = document.getElementById('lumina-type-img');
    var typeLiveBtn = document.getElementById('lumina-type-live');
    var typeVideoBtn = document.getElementById('lumina-type-video');
    var typeEmbedBtn = document.getElementById('lumina-type-embed');
    var typeMusicBtn = document.getElementById('lumina-type-music');
    var typeLinkBtn = document.getElementById('lumina-type-link');
    var typeRedpacketBtn = document.getElementById('lumina-type-redpacket');
    var typeImgIcon = document.getElementById('lumina-type-img-icon');
    var typeLiveIcon = document.getElementById('lumina-type-live-icon');
    var typeVideoIcon = document.getElementById('lumina-type-video-icon');
    var typeEmbedIcon = document.getElementById('lumina-type-embed-icon');
    var typeMusicIcon = document.getElementById('lumina-type-music-icon');
    var typeLinkIcon = document.getElementById('lumina-type-link-icon');
    var typeRedpacketIcon = document.getElementById('lumina-type-redpacket-icon');
    var uploadImgInput = document.getElementById('lumina-upload-img');
    var uploadLiveImgInput = document.getElementById('lumina-upload-live-img');
    var uploadVideoInput = document.getElementById('lumina-upload-video');
    var uploadLiveVideoInput = document.getElementById('lumina-upload-live-video');
    var uploadMusicInput = document.getElementById('lumina-upload-music');
    var videoTextarea = document.getElementById('lumina-video');
    var videoPosterInput = document.getElementById('lumina-video-poster');
    var embedTextarea = document.getElementById('lumina-embed');
    var musicTextarea = document.getElementById('lumina-music');
    var musicTitleInput = document.getElementById('lumina-music-title');
    var musicArtistInput = document.getElementById('lumina-music-artist');
    var musicCoverInput = document.getElementById('lumina-music-cover');
    var linkUrlInput = document.getElementById('lumina-link-url');
    var linkTitleInput = document.getElementById('lumina-link-title');
    var linkDescInput = document.getElementById('lumina-link-desc');
    var linkImageInput = document.getElementById('lumina-link-image');
    var redpacketWrap = document.getElementById('sh-cont-hb');
    var redpacketTotalInput = document.getElementById('lumina-redpacket-total');
    var redpacketCountInput = document.getElementById('lumina-redpacket-count');
    var redpacketModeInput = document.getElementById('lumina-redpacket-mode');
    var redpacketTitleInput = document.getElementById('lumina-redpacket-title');
    var uploadImgBtn = document.getElementById('lumina-upload-img-btn');
    var uploadLiveImgBtn = document.getElementById('lumina-upload-live-img-btn');
    var uploadVideoBtn = document.getElementById('lumina-upload-video-btn');
    var uploadLiveVideoBtn = document.getElementById('lumina-upload-live-video-btn');
    var uploadMusicBtn = document.getElementById('lumina-upload-music-btn');
    var uploadMusicCoverBtn = document.getElementById('lumina-upload-music-cover-btn');
    var uploadMusicCoverInput = document.getElementById('lumina-upload-music-cover');
    var uploadLinkImageBtn = document.getElementById('lumina-upload-link-image-btn');
    var uploadLinkImageInput = document.getElementById('lumina-upload-link-image');
    var linkFetchBtn = document.getElementById('lumina-link-fetch-btn');
    var embedUrlInput = document.getElementById('lumina-embed');

    function setType(type) {
        if (typeField) {
            typeField.value = type;
        }
    }

    function setToolbarButtonActive(btn, active) {
        if (!btn) return;
        btn.classList.toggle('is-active', !!active);
    }

    function setToolbarIcon(icon, name, active) {
        if (!icon) return;
        icon.className = 'iconfont ' + name + ' ' + (active ? 'ri-sxfbbqls' : 'ri-sxfbbq');
    }

    function syncEmojiButton(open) {
        setToolbarButtonActive(emojiBtn, open);
        setToolbarIcon(emojiIcon, 'icon-biaoqing', open);
    }

    function syncToggleImgButton() {
        if (!toggleImg) return;
        var open = toggleImg.getAttribute('lang') === '1';
        setToolbarButtonActive(toggleImg, open);
        setToolbarIcon(toggleImgIcon, 'icon-qiehuan', open);
    }

    function syncMoreButton(open) {
        setToolbarButtonActive(moreBtn, open);
        setToolbarIcon(moreIcon, 'icon-gengduo', open);
    }

    function showType(type) {
        if (imgWrap) imgWrap.style.display = 'none';
        if (liveWrap) liveWrap.style.display = 'none';
        if (imgUrlWrap) imgUrlWrap.style.display = 'none';
        if (videoWrap) videoWrap.style.display = 'none';
        if (videoPosterWrap) videoPosterWrap.style.display = 'none';
        if (embedWrap) embedWrap.style.display = 'none';
        if (musicWrap) musicWrap.style.display = 'none';
        if (linkWrap) linkWrap.style.display = 'none';
        if (redpacketWrap) redpacketWrap.style.display = 'none';
        if (videoTextarea) videoTextarea.classList.remove('show');
        if (embedTextarea) embedTextarea.classList.remove('show');
        if (musicTextarea) musicTextarea.classList.remove('show');

        if (type === 'img') {
            if (imgWrap) imgWrap.style.display = 'flex';
            if (imgUrlWrap) imgUrlWrap.style.display = (toggleImg && toggleImg.getAttribute('lang') === '1') ? 'flex' : 'none';
        } else if (type === 'live') {
            if (liveWrap) liveWrap.style.display = 'flex';
        } else if (type === 'video') {
            if (videoWrap) videoWrap.style.display = 'flex';
            if (videoPosterWrap) videoPosterWrap.style.display = 'flex';
            if (advanced) advanced.classList.add('show');
            if (videoTextarea) videoTextarea.classList.add('show');
        } else if (type === 'embed') {
            if (embedWrap) embedWrap.style.display = 'flex';
            if (advanced) advanced.classList.add('show');
            if (embedTextarea) embedTextarea.classList.add('show');
        } else if (type === 'music') {
            if (musicWrap) musicWrap.style.display = 'flex';
            if (advanced) advanced.classList.add('show');
            if (musicTextarea) musicTextarea.classList.add('show');
        } else if (type === 'link') {
            if (linkWrap) linkWrap.style.display = 'flex';
            if (advanced) advanced.classList.add('show');
        } else if (type === 'redpacket') {
            if (redpacketWrap) redpacketWrap.style.display = 'flex';
            if (advanced) advanced.classList.add('show');
        }
    }

    function appendLine(field, value) {
        if (!field || !value) return;
        var raw = field.value || '';
        var next = raw.trim();
        field.value = next ? (next + "\n" + value) : value;
        field.dispatchEvent(new Event('input', { bubbles: true }));
        field.dispatchEvent(new Event('change', { bubbles: true }));
        if (typeof window.luminaUpdatePublishSubmitState === 'function') {
            window.luminaUpdatePublishSubmitState();
        }
    }

    function luminaCaptureLocalVideoPoster(file) {
        return new Promise(function (resolve) {
            if (!file || !window.URL || !URL.createObjectURL) {
                resolve(null);
                return;
            }
            var url = URL.createObjectURL(file);
            var probe = document.createElement('video');
            var done = false;
            var timer = 0;

            function finish(blob) {
                if (done) return;
                done = true;
                if (timer) window.clearTimeout(timer);
                probe.removeAttribute('src');
                try { probe.load(); } catch (err) {}
                URL.revokeObjectURL(url);
                resolve(blob || null);
            }

            function capture() {
                try {
                    if (!probe.videoWidth || !probe.videoHeight) {
                        finish(null);
                        return;
                    }
                    var maxWidth = 1280;
                    var scale = Math.min(1, maxWidth / probe.videoWidth);
                    var canvas = document.createElement('canvas');
                    canvas.width = Math.max(1, Math.round(probe.videoWidth * scale));
                    canvas.height = Math.max(1, Math.round(probe.videoHeight * scale));
                    var context = canvas.getContext('2d');
                    context.drawImage(probe, 0, 0, canvas.width, canvas.height);
                    if (canvas.toBlob) {
                        canvas.toBlob(function (blob) {
                            finish(blob);
                        }, 'image/jpeg', 0.86);
                    } else {
                        var dataUrl = canvas.toDataURL('image/jpeg', 0.86);
                        var comma = dataUrl.indexOf(',');
                        var raw = atob(dataUrl.slice(comma + 1));
                        var bytes = new Uint8Array(raw.length);
                        for (var i = 0; i < raw.length; i++) bytes[i] = raw.charCodeAt(i);
                        finish(new Blob([bytes], { type: 'image/jpeg' }));
                    }
                } catch (err) {
                    finish(null);
                }
            }

            function seekAndCapture() {
                var target = 0;
                try {
                    target = probe.duration && isFinite(probe.duration) && probe.duration > 0.3 ? 0.15 : 0;
                    probe.addEventListener('seeked', capture, { once: true });
                    probe.currentTime = target;
                    window.setTimeout(capture, 600);
                } catch (err) {
                    capture();
                }
            }

            probe.muted = true;
            probe.playsInline = true;
            probe.preload = 'auto';
            probe.addEventListener('loadeddata', seekAndCapture, { once: true });
            probe.addEventListener('error', function () { finish(null); }, { once: true });
            timer = window.setTimeout(function () { finish(null); }, 7000);
            probe.src = url;
            try { probe.load(); } catch (err2) {}
        });
    }

    function luminaUploadGeneratedPoster(blob, filename, token) {
        if (!blob || !postForm || !videoPosterInput || String(videoPosterInput.value || '').trim()) return Promise.resolve();
        var fd = new FormData();
        fd.append('lumina_action', 'media_upload');
        if (token) fd.append('token', token);
        fd.append('file', blob, filename || 'video-poster.jpg');
        return fetch(postForm.action, {
            method: 'POST',
            body: fd,
            credentials: 'same-origin'
        }).then(function (res) {
            return res.json();
        }).then(function (json) {
            if (!json || json.code !== 0 || !json.data || !json.data.url || String(videoPosterInput.value || '').trim()) return;
            setFieldValue(videoPosterInput, json.data.url);
            if (typeof window.luminaUpdateLivePreview === 'function') {
                window.luminaUpdateLivePreview();
            }
        }).catch(function () {});
    }

    function luminaAdjustPosterTasks(delta) {
        if (!postForm) return;
        var current = parseInt(postForm.getAttribute('data-lumina-poster-tasks') || '0', 10);
        current = Math.max(0, current + delta);
        postForm.setAttribute('data-lumina-poster-tasks', String(current));
    }

    function luminaUploadFiles(type, files) {
        if (!files || !files.length) return;
        if (!postForm) return;
        var tokenInput = postForm.querySelector('input[name="token"]');
        var tokenVal = tokenInput ? tokenInput.value : '';
        Array.prototype.slice.call(files).forEach(function (file) {
            if (type === 'video' && videoPosterInput && !String(videoPosterInput.value || '').trim()) {
                luminaAdjustPosterTasks(1);
                luminaCaptureLocalVideoPoster(file).then(function (blob) {
                    return luminaUploadGeneratedPoster(blob, (file.name || 'video').replace(/\.[^.]+$/, '') + '-poster.jpg', tokenVal);
                }).then(function () {
                    luminaAdjustPosterTasks(-1);
                }).catch(function () {
                    luminaAdjustPosterTasks(-1);
                });
            }
            var fd = new FormData();
            fd.append('lumina_action', 'media_upload');
            if (tokenVal) fd.append('token', tokenVal);
            fd.append('file', file);
            if (typeof loadpop === 'function') {
                loadpop('上传中，请稍后...', 'ok');
            }
            fetch(postForm.action, {
                method: 'POST',
                body: fd,
                credentials: 'same-origin'
            }).then(function (res) {
                return res.json();
            }).then(function (json) {
                if (json && json.code === 0 && json.data && json.data.url) {
                    if (type === 'img' && imgTextarea) {
                        appendLine(imgTextarea, json.data.url);
                        if (imgUrlWrap) imgUrlWrap.style.display = 'flex';
                        if (toggleImg) toggleImg.setAttribute('lang', '1');
                        syncToggleImgButton();
                    } else if (type === 'live_img') {
                        appendLine(liveCoverTextarea, json.data.url);
                    } else if (type === 'video') {
                        var videoField = document.getElementById('lumina-video');
                        appendLine(videoField, json.data.url);
                    } else if (type === 'live_video') {
                        var liveField = document.getElementById('lumina-live-photos');
                        appendLine(liveField, json.data.url);
                    } else if (type === 'music') {
                        var musicField = document.getElementById('lumina-music');
                        appendLine(musicField, json.data.url);
                    } else if (type === 'music_cover') {
                        if (musicCoverInput) {
                            musicCoverInput.value = json.data.url;
                            musicCoverInput.dispatchEvent(new Event('input', { bubbles: true }));
                            musicCoverInput.dispatchEvent(new Event('change', { bubbles: true }));
                        }
                    } else if (type === 'link_image') {
                        if (linkImageInput) {
                            linkImageInput.value = json.data.url;
                            linkImageInput.dispatchEvent(new Event('input', { bubbles: true }));
                            linkImageInput.dispatchEvent(new Event('change', { bubbles: true }));
                        }
                    }
                    if (typeof window.luminaUpdatePublishSubmitState === 'function') {
                        window.luminaUpdatePublishSubmitState();
                    }
                    if (typeof window.luminaUpdateLivePreview === 'function') {
                        window.luminaUpdateLivePreview();
                    }
                    if (typeof successpop === 'function') {
                        successpop('上传成功');
                    }
                } else {
                    if (typeof warnpop === 'function') {
                        warnpop(json && json.msg ? json.msg : '上传失败');
                    }
                }
            }).catch(function () {
                if (typeof warnpop === 'function') {
                    warnpop('上传失败');
                }
            });
        });
    }

    function setFieldValue(input, value) {
        if (!input || value === undefined || value === null) return;
        input.value = value;
        input.dispatchEvent(new Event('input', { bubbles: true }));
        input.dispatchEvent(new Event('change', { bubbles: true }));
    }

    function luminaFetchLinkPreview() {
        if (!postForm || !linkFetchBtn || !linkUrlInput) return;
        var url = String(linkUrlInput.value || '').trim();
        if (!url) {
            if (typeof warnpop === 'function') warnpop('请先填写链接地址');
            return;
        }
        var tokenInput = postForm.querySelector('input[name="token"]');
        var fd = new FormData();
        fd.append('lumina_action', 'link_fetch');
        fd.append('url', url);
        if (tokenInput && tokenInput.value) {
            fd.append('token', tokenInput.value);
        }
        var oldText = linkFetchBtn.textContent;
        linkFetchBtn.disabled = true;
        linkFetchBtn.classList.add('is-loading');
        linkFetchBtn.textContent = '获取中';
        fetch(postForm.action, {
            method: 'POST',
            body: fd,
            credentials: 'same-origin'
        }).then(function (res) {
            return res.json();
        }).then(function (json) {
            if (!json || json.code !== 0 || !json.data) {
                if (typeof warnpop === 'function') warnpop(json && json.msg ? json.msg : '链接信息获取失败');
                return;
            }
            var data = json.data;
            setFieldValue(linkUrlInput, data.url || url);
            setFieldValue(linkTitleInput, data.title || '');
            setFieldValue(linkDescInput, data.desc || data.host || '');
            if (data.image) {
                setFieldValue(linkImageInput, data.image);
            }
            window.luminaSetType('link');
            if (typeof window.luminaUpdatePublishSubmitState === 'function') {
                window.luminaUpdatePublishSubmitState();
            }
            if (typeof window.luminaUpdateLivePreview === 'function') {
                window.luminaUpdateLivePreview();
            }
            if (typeof successpop === 'function') successpop('链接信息已获取');
        }).catch(function () {
            if (typeof warnpop === 'function') warnpop('链接信息获取失败');
        }).finally(function () {
            linkFetchBtn.disabled = false;
            linkFetchBtn.classList.remove('is-loading');
            linkFetchBtn.textContent = oldText || '获取';
        });
    }

    function setTypeButtons(active) {
        setToolbarButtonActive(typeImgBtn, active === 'img');
        setToolbarButtonActive(typeLiveBtn, active === 'live');
        setToolbarButtonActive(typeVideoBtn, active === 'video');
        setToolbarButtonActive(typeEmbedBtn, active === 'embed');
        setToolbarButtonActive(typeMusicBtn, active === 'music');
        setToolbarButtonActive(typeLinkBtn, active === 'link');
        setToolbarButtonActive(typeRedpacketBtn, active === 'redpacket');
        setToolbarIcon(typeImgIcon, 'icon-tupian', active === 'img');
        setToolbarIcon(typeLiveIcon, 'icon-xiangji1', active === 'live');
        setToolbarIcon(typeVideoIcon, 'icon-shipinbofang', active === 'video');
        setToolbarIcon(typeEmbedIcon, 'icon-24gf-playCircle', active === 'embed');
        setToolbarIcon(typeMusicIcon, 'icon-yinle_2', active === 'music');
        setToolbarIcon(typeLinkIcon, 'icon-lianjie1', active === 'link');
        setToolbarIcon(typeRedpacketIcon, 'icon-hongbao', active === 'redpacket');
    }

    window.luminaSetType = function (type) {
        setType(type);
        showType(type);
        setTypeButtons(type);
        if (emojiPanel) emojiPanel.classList.remove('show');
        syncEmojiButton(false);
        if (type !== 'img' && toggleImg) {
            toggleImg.style.display = 'none';
            if (imgUrlWrap) imgUrlWrap.style.display = 'none';
        } else if (toggleImg) {
            toggleImg.style.display = 'flex';
        }
        syncToggleImgButton();
        if (typeof window.luminaUpdateLivePreview === 'function') {
            window.luminaUpdateLivePreview();
        }
    };

    window.shkgbqkg = function () {
        if (!emojiPanel) return;
        var isOpen = emojiPanel.classList.contains('show');
        if (isOpen) {
            emojiPanel.classList.remove('show');
            syncEmojiButton(false);
        } else {
            emojiPanel.classList.add('show');
            syncEmojiButton(true);
        }
    };

    window.tpscfs = function () {
        if (!imgUrlWrap || !toggleImg || !toggleImgIcon) return;
        var open = toggleImg.getAttribute('lang') === '1';
        if (open) {
            imgUrlWrap.style.display = 'none';
            toggleImg.setAttribute('lang', '0');
        } else {
            imgUrlWrap.style.display = 'flex';
            toggleImg.setAttribute('lang', '1');
        }
        syncToggleImgButton();
    };

    if (window.LUMINA_MEDIA && Array.isArray(window.LUMINA_MEDIA)) {
        function luminaEscapeHtml(value) {
            return String(value || '')
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#39;');
        }
        function luminaMediaCategory(item) {
            var mime = (item && item.mime ? String(item.mime) : '').toLowerCase();
            var url = item && item.url ? String(item.url) : '';
            var clean = url.split('?')[0].toLowerCase();
            var ext = clean.indexOf('.') !== -1 ? clean.split('.').pop() : '';
            var imgExt = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg', 'avif', 'ico'];
            var videoExt = ['mp4', 'webm', 'ogg', 'mov', 'm4v', 'mkv'];
            var audioExt = ['mp3', 'wav', 'm4a', 'aac', 'flac', 'ogg', 'oga'];
            if (mime.indexOf('image/') === 0) return 'image';
            if (mime.indexOf('video/') === 0) return 'video';
            if (mime.indexOf('audio/') === 0) return 'audio';
            if (ext && imgExt.indexOf(ext) !== -1) return 'image';
            if (ext && videoExt.indexOf(ext) !== -1) return 'video';
            if (ext && audioExt.indexOf(ext) !== -1) return 'audio';
            return 'other';
        }

        function luminaBuildMediaGrid(type) {
            var grid = document.getElementById('lumina-media-grid');
            if (!grid) return;
            var filter = 'all';
            if (type === 'img' || type === 'live_img' || type === 'music_cover' || type === 'link_image') {
                filter = 'image';
            } else if (type === 'video' || type === 'live_video') {
                filter = 'video';
            } else if (type === 'music') {
                filter = 'audio';
            }
            var html = '';
            window.LUMINA_MEDIA.forEach(function (item) {
                var url = item.url || '';
                if (!url) return;
                var cat = luminaMediaCategory(item);
                if (filter !== 'all' && cat !== filter) {
                    return;
                }
                var name = item.name || '';
                var safeUrl = luminaEscapeHtml(url);
                var safeName = luminaEscapeHtml(name);
                if (cat === 'image') {
                    var thumb = item.thumb || url;
                    var safeThumb = luminaEscapeHtml(thumb);
                    html += '<div class="lumina-media-item" data-url="' + safeUrl + '" data-type="' + cat + '"><img src="' + safeThumb + '" alt=""><span class="lumina-media-name">' + safeName + '</span></div>';
                } else {
                    var label = cat === 'video' ? '视频' : (cat === 'audio' ? '音乐' : '文件');
                    var icon = cat === 'video' ? 'icon-shipinbofang' : (cat === 'audio' ? 'icon-yinle_2' : 'icon-qiehuan');
                    html += '<div class="lumina-media-item is-placeholder" data-url="' + safeUrl + '" data-type="' + cat + '"><div class="lumina-media-placeholder"><i class="iconfont ' + icon + '"></i><div class="lumina-media-placeholder-text">' + label + '</div></div><span class="lumina-media-name">' + safeName + '</span></div>';
                }
            });
            grid.innerHTML = html || '<div style="color:var(--texths);padding:8px;">暂无媒体</div>';
        }

        window.luminaOpenMedia = function (type) {
            var modal = document.getElementById('lumina-media-modal');
            if (!modal) return;
            var mode = type || 'img';
            modal.setAttribute('data-type', mode);
            luminaBuildMediaGrid(mode);
            modal.style.display = 'flex';
        };
        window.luminaCloseMedia = function () {
            var modal = document.getElementById('lumina-media-modal');
            if (modal) modal.style.display = 'none';
        };
        var mediaBtns = document.querySelectorAll('.lumina-media-btn');
        luminaEach(mediaBtns, function (btn) {
            btn.addEventListener('click', function (e) {
                e.preventDefault();
                var type = btn.getAttribute('data-type') || 'img';
                window.luminaOpenMedia(type);
            });
        });
        var grid = document.getElementById('lumina-media-grid');
        if (grid) {
            grid.addEventListener('click', function (e) {
                var item = e.target.closest('.lumina-media-item');
                if (!item) return;
                var url = item.getAttribute('data-url');
                var modal = document.getElementById('lumina-media-modal');
                var type = modal ? modal.getAttribute('data-type') : 'img';
                if (typeof window.luminaSetType === 'function') {
                    var nextType = type === 'music_cover' ? 'music' : (type === 'link_image' ? 'link' : ((type === 'live_video' || type === 'live_img') ? 'live' : type));
                    window.luminaSetType(nextType);
                }
                if (type === 'img' && imgTextarea) {
                    appendLine(imgTextarea, url);
                    if (imgUrlWrap) imgUrlWrap.style.display = 'flex';
                    if (toggleImg) toggleImg.setAttribute('lang', '1');
                    syncToggleImgButton();
                } else if (type === 'live_video') {
                    appendLine(livePhotosTextarea, url);
                } else if (type === 'live_img') {
                    appendLine(liveCoverTextarea, url);
                } else if (type === 'video') {
                    var videoField = document.getElementById('lumina-video');
                    appendLine(videoField, url);
                } else if (type === 'music') {
                    var musicField = document.getElementById('lumina-music');
                    appendLine(musicField, url);
                } else if (type === 'music_cover') {
                    if (musicCoverInput) {
                        musicCoverInput.value = url;
                        musicCoverInput.dispatchEvent(new Event('input', { bubbles: true }));
                        musicCoverInput.dispatchEvent(new Event('change', { bubbles: true }));
                    }
                } else if (type === 'link_image') {
                    if (linkImageInput) {
                        linkImageInput.value = url;
                        linkImageInput.dispatchEvent(new Event('input', { bubbles: true }));
                        linkImageInput.dispatchEvent(new Event('change', { bubbles: true }));
                    }
                }
                if (typeof window.luminaUpdatePublishSubmitState === 'function') {
                    window.luminaUpdatePublishSubmitState();
                }
                if (typeof window.luminaCloseMedia === 'function') {
                    window.luminaCloseMedia();
                }
                if (typeof window.luminaUpdateLivePreview === 'function') {
                    window.luminaUpdateLivePreview();
                }
            });
        }
    }

    window.luminaToggleMore = function () {
        if (!morePanel) return;
        var open = morePanel.classList.contains('show');
        if (open) {
            morePanel.classList.remove('show');
            syncMoreButton(false);
            var advToggle = document.getElementById('lumina-advanced-toggle');
            if (advToggle) advToggle.style.display = 'block';
        } else {
            morePanel.classList.add('show');
            syncMoreButton(true);
            var advToggle = document.getElementById('lumina-advanced-toggle');
            if (advToggle) advToggle.style.display = 'none';
        }
    };

    if (imgAdd && imgTextarea) {
        imgAdd.addEventListener('click', function () {
            window.luminaSetType('img');
            if (uploadImgInput) {
                uploadImgInput.click();
            }
        });
    }
    if (uploadImgBtn && uploadImgInput) {
        uploadImgBtn.addEventListener('click', function () {
            window.luminaSetType('img');
            uploadImgInput.click();
        });
    }
    if (uploadVideoBtn && uploadVideoInput) {
        uploadVideoBtn.addEventListener('click', function () {
            window.luminaSetType('video');
            uploadVideoInput.click();
        });
    }
    if (uploadLiveImgBtn && uploadLiveImgInput) {
        uploadLiveImgBtn.addEventListener('click', function () {
            window.luminaSetType('live');
            uploadLiveImgInput.click();
        });
    }
    if (uploadLiveVideoBtn && uploadLiveVideoInput) {
        uploadLiveVideoBtn.addEventListener('click', function () {
            window.luminaSetType('live');
            uploadLiveVideoInput.click();
        });
    }
    if (uploadMusicBtn && uploadMusicInput) {
        uploadMusicBtn.addEventListener('click', function () {
            window.luminaSetType('music');
            uploadMusicInput.click();
        });
    }
    if (uploadMusicCoverBtn && uploadMusicCoverInput) {
        uploadMusicCoverBtn.addEventListener('click', function () {
            window.luminaSetType('music');
            uploadMusicCoverInput.click();
        });
    }
    if (uploadLinkImageBtn && uploadLinkImageInput) {
        uploadLinkImageBtn.addEventListener('click', function () {
            window.luminaSetType('link');
            uploadLinkImageInput.click();
        });
    }
    if (linkFetchBtn) {
        linkFetchBtn.addEventListener('click', function (e) {
            e.preventDefault();
            luminaFetchLinkPreview();
        });
    }
    if (uploadImgInput) {
        uploadImgInput.addEventListener('change', function () {
            luminaUploadFiles('img', uploadImgInput.files);
            uploadImgInput.value = '';
        });
    }
    if (uploadVideoInput) {
        uploadVideoInput.addEventListener('change', function () {
            luminaUploadFiles('video', uploadVideoInput.files);
            uploadVideoInput.value = '';
        });
    }
    if (uploadLiveImgInput) {
        uploadLiveImgInput.addEventListener('change', function () {
            luminaUploadFiles('live_img', uploadLiveImgInput.files);
            uploadLiveImgInput.value = '';
        });
    }
    if (uploadLiveVideoInput) {
        uploadLiveVideoInput.addEventListener('change', function () {
            luminaUploadFiles('live_video', uploadLiveVideoInput.files);
            uploadLiveVideoInput.value = '';
        });
    }
    if (uploadMusicInput) {
        uploadMusicInput.addEventListener('change', function () {
            luminaUploadFiles('music', uploadMusicInput.files);
            uploadMusicInput.value = '';
        });
    }
    if (uploadMusicCoverInput) {
        uploadMusicCoverInput.addEventListener('change', function () {
            luminaUploadFiles('music_cover', uploadMusicCoverInput.files);
            uploadMusicCoverInput.value = '';
        });
    }
    if (uploadLinkImageInput) {
        uploadLinkImageInput.addEventListener('change', function () {
            luminaUploadFiles('link_image', uploadLinkImageInput.files);
            uploadLinkImageInput.value = '';
        });
    }

    var advToggle = document.getElementById('lumina-advanced-toggle');
    var advPanel = document.getElementById('lumina-advanced');
    if (advToggle && advPanel) {
        advToggle.addEventListener('click', function () {
            advPanel.classList.toggle('show');
            advToggle.textContent = advPanel.classList.contains('show') ? '收起设置' : '更多设置';
        });
    }

    if (emojiPanel) {
        emojiPanel.addEventListener('click', function (e) {
            var target = e.target;
            if (!target) return;
            if (target.tagName !== 'IMG' && target.closest) {
                target = target.closest('img');
            }
            if (!target || !target.getAttribute) return;
            var token = target.getAttribute('data-emoji-token') || target.getAttribute('alt') || '';
            if (!token) return;
            if (token.indexOf('::(') !== 0) {
                token = '::(' + token + ')';
            }
            var textarea = document.getElementById('lumina-content');
            if (!textarea) return;
            var tag = token;
            var start = textarea.selectionStart || 0;
            var end = textarea.selectionEnd || 0;
            var value = textarea.value || '';
            textarea.value = value.slice(0, start) + tag + value.slice(end);
            var pos = start + tag.length;
            textarea.focus();
            textarea.setSelectionRange(pos, pos);
            if (typeof window.luminaUpdateLivePreview === 'function') {
                window.luminaUpdateLivePreview();
            }
        });
    }

    if (typeField) {
        if (typeField.value === 'video') {
            window.luminaSetType('video');
        } else if (typeField.value === 'embed') {
            window.luminaSetType('embed');
        } else if (typeField.value === 'live') {
            window.luminaSetType('live');
        } else if (typeField.value === 'music') {
            window.luminaSetType('music');
        } else if (typeField.value === 'link') {
            window.luminaSetType('link');
        } else if (typeField.value === 'redpacket') {
            window.luminaSetType('redpacket');
        } else {
            window.luminaSetType('img');
        }
    }
    syncMoreButton(!!(morePanel && morePanel.classList.contains('show')));

    function luminaSyncToggle(toggle) {
        var targetId = toggle.getAttribute('data-target');
        var onValue = toggle.getAttribute('data-on') || 'y';
        var target = targetId ? document.getElementById(targetId) : null;
        var isOn = target ? target.value === onValue : toggle.classList.contains('is-on') || toggle.style.justifyContent === 'flex-end';
        toggle.classList.toggle('is-on', isOn);
        toggle.style.background = isOn ? 'var(--theme)' : 'rgb(238 238 238)';
        toggle.style.justifyContent = isOn ? 'flex-end' : 'flex-start';
    }

    luminaEach(document.querySelectorAll('.lumina-toggle'), function (toggle) {
        luminaSyncToggle(toggle);
        toggle.addEventListener('click', function () {
            var targetId = toggle.getAttribute('data-target');
            var onValue = toggle.getAttribute('data-on') || 'y';
            var offValue = toggle.getAttribute('data-off') || 'n';
            var target = targetId ? document.getElementById(targetId) : null;
            var isOn = target ? target.value === onValue : toggle.classList.contains('is-on');

            if (target) {
                target.value = isOn ? offValue : onValue;
            }
            luminaSyncToggle(toggle);
            if (typeof window.luminaUpdateLivePreview === 'function') {
                window.luminaUpdateLivePreview();
            }
        });
    });

    var sendBtn = document.getElementById('lumina-send-code');
    if (sendBtn) {
        sendBtn.addEventListener('click', function () {
            var emailInput = document.getElementById('lumina-email');
            var email = emailInput ? emailInput.value : '';
            var form = document.getElementById('lumina-email-form');
            var blogUrl = form ? (form.getAttribute('data-blog-url') || './') : './';
            var msg = document.getElementById('lumina-email-msg');
            if (!email) {
                if (msg) msg.textContent = '请输入邮箱';
                return;
            }
            sendBtn.disabled = true;
            var count = 60;
            var timer = setInterval(function () {
                sendBtn.textContent = '重新发送(' + count + ')';
                count -= 1;
                if (count < 0) {
                    clearInterval(timer);
                    sendBtn.textContent = '发送验证码';
                    sendBtn.disabled = false;
                }
            }, 1000);

            var fd = new FormData();
            fd.append('mail', email);
            fetch(blogUrl + 'admin/account.php?action=send_email_code', {
                method: 'POST',
                body: fd,
                credentials: 'same-origin'
            }).then(function (res) {
                return res.json();
            }).then(function (json) {
                if (json && json.code === 0) {
                    if (msg) msg.textContent = '验证码已发送';
                } else {
                    if (msg) msg.textContent = (json && json.msg) ? json.msg : '发送失败';
                }
            }).catch(function () {
                if (msg) msg.textContent = '发送失败';
            });
        });
    }

    luminaEach(document.querySelectorAll('.lumina-like-btn'), function (btn) {
        btn.addEventListener('click', function () {
            if (btn.getAttribute('data-loading') === '1') return;
            var gid = btn.getAttribute('data-gid');
            if (!gid) return;
            var liked = btn.getAttribute('data-liked') === '1';
            var isLogin = window.LUMINA && window.LUMINA.isLogin;
            var blogUrl = document.body.getAttribute('data-blog-url') || './';
            var action = liked ? 'unlike' : 'addlike';
            var apiCfg = window.LUMINA_API || {};
            var useGuestUnlike = liked && !isLogin && apiCfg.guestUnlike;
            var url = liked ? (useGuestUnlike ? apiCfg.guestUnlike : (apiCfg.unlike || (blogUrl + '?action=' + action))) : (apiCfg.like || (blogUrl + '?action=' + action));
            var fd = new FormData();
            fd.append('gid', gid);
            if (useGuestUnlike) {
                fd.append('lumina_action', 'guest_unlike');
                if (apiCfg.token) {
                    fd.append('token', apiCfg.token);
                }
            }
            if (!liked) {
                fd.append('name', '访客');
                fd.append('avatar', '');
            }
            btn.setAttribute('data-loading', '1');
            fetch(url, {
                method: 'POST',
                body: fd,
                credentials: 'same-origin'
            }).then(function (res) {
                return res.json();
            }).then(function (json) {
                btn.setAttribute('data-loading', '0');
                if (json && json.code === 0) {
                    var countEl = btn.querySelector('.lumina-like-count');
                    var textEl = btn.querySelector('.lumina-like-text');
                    var iconEl = btn.querySelector('i');
                    var count = countEl ? parseInt(countEl.textContent || '0', 10) : 0;
                    if (liked) {
                        btn.setAttribute('data-liked', '0');
                        if (iconEl) {
                            iconEl.className = 'iconfont icon-aixin ri-sxdzlike';
                        }
                        if (textEl) textEl.textContent = '赞';
                        if (countEl) countEl.textContent = Math.max(0, count - 1);
                    } else {
                        btn.setAttribute('data-liked', '1');
                        if (iconEl) {
                            iconEl.className = 'iconfont icon-aixin2 ri-sxdzlikehs';
                        }
                        if (textEl) textEl.textContent = '取消';
                        if (countEl) countEl.textContent = count + 1;
                    }
                } else {
                    var msg = (json && json.msg) ? json.msg : '操作失败';
                    if (!isLogin && !liked && /已|already|赞/i.test(msg)) {
                        msg = '当前网络已点过赞';
                    }
                    if (typeof warnpop === 'function') {
                        warnpop(msg);
                    } else {
                        alert(msg);
                    }
                }
            }).catch(function () {
                btn.setAttribute('data-loading', '0');
                if (typeof warnpop === 'function') {
                    warnpop('操作失败');
                } else {
                    alert('操作失败');
                }
            });
        });
    });

    function luminaRedpacketNotice(msg) {
        if (typeof warnpop === 'function') {
            warnpop(msg);
        } else {
            alert(msg);
        }
    }

    function luminaGetRedpacketInfo(card) {
        var titleEl = card.querySelector('.lumina-redpacket-title');
        var subEl = card.querySelector('.lumina-redpacket-sub');
        var countEl = card.querySelector('.lumina-redpacket-count');
        var amountEl = card.querySelector('.lumina-redpacket-amount');
        var amountText = amountEl ? (amountEl.textContent || '').trim() : '';
        var amountMatch = amountText.match(/(\d+(?:\.\d+)?)/);
        return {
            title: titleEl ? (titleEl.textContent || '').trim() : '恭喜发财',
            subtitle: subEl ? (subEl.textContent || '').trim() : '红包',
            countText: countEl ? (countEl.textContent || '').trim() : '',
            amountText: amountText,
            amountValue: amountMatch ? amountMatch[1] : '',
            claimed: card.getAttribute('data-claimed') === '1',
            status: card.getAttribute('data-status') === '1'
        };
    }

    function luminaSetRedpacketCardState(card, state) {
        if (!card || !state) return;
        var btn = card.querySelector('.lumina-redpacket-btn');
        var btnText = btn ? btn.querySelector('.lumina-redpacket-btn-text') : null;
        var countEl = card.querySelector('.lumina-redpacket-count');
        var amountEl = card.querySelector('.lumina-redpacket-amount');

        if (state.claimed) {
            card.setAttribute('data-claimed', '1');
        }
        if (typeof state.status !== 'undefined') {
            card.setAttribute('data-status', state.status ? '1' : '0');
        }
        if (state.claimed || state.status) {
            card.classList.add('is-disabled');
        }
        if (btn) {
            btn.classList.add('is-disabled');
            btn.removeAttribute('data-loading');
            if (state.buttonText) {
                if (btnText) {
                    btnText.textContent = state.buttonText;
                } else {
                    btn.textContent = state.buttonText;
                }
                btn.setAttribute('aria-label', state.buttonText);
                btn.setAttribute('title', state.buttonText);
            }
        }
        if (countEl && typeof state.countText !== 'undefined') {
            countEl.textContent = state.countText;
        }
        if (amountEl && typeof state.amountText !== 'undefined') {
            amountEl.textContent = state.amountText;
        }
    }

    function luminaGetRedpacketModal() {
        var existing = document.getElementById('lumina-redpacket-modal');
        if (existing && existing._luminaParts) {
            return existing._luminaParts;
        }

        var modal = document.createElement('div');
        modal.id = 'lumina-redpacket-modal';
        modal.className = 'lumina-redpacket-modal';
        modal.innerHTML = [
            '<div class="lumina-redpacket-dialog" role="dialog" aria-modal="true" aria-labelledby="lumina-redpacket-modal-author">',
            '<div class="lumina-redpacket-stage lumina-redpacket-stage-unopened">',
            '<div class="lumina-redpacket-modal-card">',
            '<button type="button" class="lumina-redpacket-modal-close" aria-label="关闭">&times;</button>',
            '<div class="lumina-redpacket-modal-top">',
            '<div class="lumina-redpacket-modal-badge"><i class="iconfont icon-hongbao"></i></div>',
            '<div class="lumina-redpacket-modal-author" id="lumina-redpacket-modal-author"></div>',
            '<div class="lumina-redpacket-modal-note"></div>',
            '</div>',
            '<div class="lumina-redpacket-modal-main">',
            '<i class="iconfont icon-hongbao lumina-redpacket-modal-mark"></i>',
            '<div class="lumina-redpacket-modal-blessing"></div>',
            '</div>',
            '<button type="button" class="lumina-redpacket-modal-open"><span>拆</span></button>',
            '</div>',
            '</div>',
            '<div class="lumina-redpacket-stage lumina-redpacket-stage-result">',
            '<div class="lumina-redpacket-result-card">',
            '<button type="button" class="lumina-redpacket-modal-close" aria-label="关闭">&times;</button>',
            '<div class="lumina-redpacket-result-head">',
            '<div class="lumina-redpacket-result-author"></div>',
            '<div class="lumina-redpacket-result-status"></div>',
            '<div class="lumina-redpacket-result-amount-wrap">',
            '<span class="lumina-redpacket-result-amount"></span>',
            '<span class="lumina-redpacket-result-unit">积分</span>',
            '</div>',
            '<div class="lumina-redpacket-result-note"></div>',
            '</div>',
            '<div class="lumina-redpacket-result-foot">',
            '<div class="lumina-redpacket-result-count"></div>',
            '<button type="button" class="lumina-redpacket-result-confirm">知道了</button>',
            '</div>',
            '</div>',
            '</div>',
            '</div>'
        ].join('');
        document.body.appendChild(modal);

        var parts = {
            modal: modal,
            unopenedStage: modal.querySelector('.lumina-redpacket-stage-unopened'),
            resultStage: modal.querySelector('.lumina-redpacket-stage-result'),
            modalAuthor: modal.querySelector('.lumina-redpacket-modal-author'),
            modalNote: modal.querySelector('.lumina-redpacket-modal-note'),
            modalBlessing: modal.querySelector('.lumina-redpacket-modal-blessing'),
            openBtn: modal.querySelector('.lumina-redpacket-modal-open'),
            resultCard: modal.querySelector('.lumina-redpacket-result-card'),
            resultAuthor: modal.querySelector('.lumina-redpacket-result-author'),
            resultStatus: modal.querySelector('.lumina-redpacket-result-status'),
            resultAmountWrap: modal.querySelector('.lumina-redpacket-result-amount-wrap'),
            resultAmount: modal.querySelector('.lumina-redpacket-result-amount'),
            resultNote: modal.querySelector('.lumina-redpacket-result-note'),
            resultCount: modal.querySelector('.lumina-redpacket-result-count'),
            confirmBtn: modal.querySelector('.lumina-redpacket-result-confirm'),
            currentCard: null
        };

        parts.close = function () {
            parts.modal.classList.remove('is-active');
            document.body.classList.remove('lumina-redpacket-open');
            parts.openBtn.classList.remove('is-loading');
            parts.currentCard = null;
        };

        parts.showStage = function (name) {
            parts.unopenedStage.classList.toggle('is-active', name === 'unopened');
            parts.resultStage.classList.toggle('is-active', name === 'result');
        };

        luminaEach(modal.querySelectorAll('.lumina-redpacket-modal-close'), function (btn) {
            btn.addEventListener('click', function () {
                parts.close();
            });
        });
        parts.confirmBtn.addEventListener('click', function () {
            parts.close();
        });
        parts.openBtn.addEventListener('click', function () {
            luminaClaimRedpacketFromModal();
        });
        modal.addEventListener('click', function (e) {
            if (e.target === modal) {
                parts.close();
            }
        });
        if (!window.__luminaRedpacketEscBound) {
            document.addEventListener('keydown', function (e) {
                var current = document.getElementById('lumina-redpacket-modal');
                if (e.key === 'Escape' && current && current.classList.contains('is-active') && current._luminaParts) {
                    current._luminaParts.close();
                }
            });
            window.__luminaRedpacketEscBound = true;
        }

        modal._luminaParts = parts;
        return parts;
    }

    function luminaShowRedpacketResult(parts, info, mode) {
        if (!parts) return;
        var isEmpty = mode === 'exhausted';
        parts.resultCard.classList.toggle('is-empty', isEmpty);
        parts.resultAuthor.textContent = info.subtitle || '红包';
        parts.resultCount.textContent = info.countText || '';
        parts.resultCount.style.display = info.countText ? 'block' : 'none';

        if (mode === 'claimed') {
            parts.resultStatus.textContent = '红包已领取';
            parts.resultAmount.textContent = info.amountValue || '--';
            parts.resultAmountWrap.style.display = 'flex';
            parts.resultNote.textContent = '已存入账户，可在站内继续使用';
        } else {
            parts.resultStatus.textContent = '手慢了，红包已抢完';
            parts.resultAmount.textContent = '';
            parts.resultAmountWrap.style.display = 'none';
            parts.resultNote.textContent = '下次记得早点来';
        }

        parts.showStage('result');
        parts.modal.classList.add('is-active');
        document.body.classList.add('lumina-redpacket-open');
    }

    function luminaOpenRedpacketModal(card) {
        if (!card) return;
        var parts = luminaGetRedpacketModal();
        var info = luminaGetRedpacketInfo(card);
        parts.currentCard = card;
        parts.modalAuthor.textContent = info.subtitle || '红包';
        parts.modalNote.textContent = info.claimed ? '红包已领取' : '给你发了一个红包';
        parts.modalBlessing.textContent = info.title || '恭喜发财';
        parts.openBtn.classList.remove('is-loading');

        if (info.claimed) {
            luminaShowRedpacketResult(parts, info, 'claimed');
            return;
        }
        if (info.status) {
            luminaShowRedpacketResult(parts, info, 'exhausted');
            return;
        }

        parts.showStage('unopened');
        parts.modal.classList.add('is-active');
        document.body.classList.add('lumina-redpacket-open');
    }

    function luminaClaimRedpacketFromModal() {
        var parts = luminaGetRedpacketModal();
        var card = parts.currentCard;
        if (!card || parts.openBtn.classList.contains('is-loading')) return;

        var claimed = card.getAttribute('data-claimed') === '1';
        var status = card.getAttribute('data-status') === '1';
        if (claimed) {
            luminaShowRedpacketResult(parts, luminaGetRedpacketInfo(card), 'claimed');
            return;
        }
        if (status) {
            luminaShowRedpacketResult(parts, luminaGetRedpacketInfo(card), 'exhausted');
            return;
        }

        var isLogin = card.getAttribute('data-login') === '1';
        if (!isLogin) {
            luminaRedpacketNotice('请先登录');
            return;
        }

        var gid = card.getAttribute('data-gid');
        var token = card.getAttribute('data-token') || '';
        var blogUrl = card.getAttribute('data-blog-url') || './';
        var claimUrl = card.getAttribute('data-claim-url') || (blogUrl.replace(/\/?$/, '/') + 'index.php/user');
        var fd = new FormData();
        fd.append('lumina_action', 'redpacket_claim');
        fd.append('gid', gid || '0');
        if (token) fd.append('token', token);

        parts.openBtn.classList.add('is-loading');

        fetch(claimUrl, {
            method: 'POST',
            body: fd,
            credentials: 'same-origin'
        }).then(function (res) {
            return res.json();
        }).then(function (json) {
            if (json && json.code === 0) {
                var amount = json.data && json.data.amount ? json.data.amount : 0;
                var remain = json.data && json.data.remain_count !== undefined ? json.data.remain_count : '';
                var total = json.data && json.data.total_count !== undefined ? json.data.total_count : '';
                var remainText = total !== '' ? ('剩余 ' + remain + '/' + total) : '';
                var shouldClose = remain !== '' && Number(remain) <= 0;
                luminaSetRedpacketCardState(card, {
                    claimed: true,
                    status: shouldClose,
                    buttonText: '已领取',
                    countText: remainText,
                    amountText: '已领取 ' + amount + ' 积分'
                });
                parts.openBtn.classList.remove('is-loading');
                luminaShowRedpacketResult(parts, luminaGetRedpacketInfo(card), 'claimed');
            } else {
                parts.openBtn.classList.remove('is-loading');
                luminaRedpacketNotice(json && json.msg ? json.msg : '领取失败');
            }
        }).catch(function () {
            parts.openBtn.classList.remove('is-loading');
            luminaRedpacketNotice('领取失败');
        });
    }

    function luminaBindRedpacket() {
        var cards = document.querySelectorAll('.lumina-redpacket-card');
        if (!cards || !cards.length) return;
        luminaEach(cards, function (card) {
            if (card.getAttribute('data-redpacket-bound') === '1') return;
            card.setAttribute('data-redpacket-bound', '1');
            var btn = card.querySelector('.lumina-redpacket-btn');
            var openPacket = function (e) {
                if (e) {
                    e.preventDefault();
                    e.stopPropagation();
                }
                luminaOpenRedpacketModal(card);
            };
            card.addEventListener('click', openPacket);
            if (btn) {
                btn.addEventListener('click', openPacket);
            }
        });
    }
    luminaBindRedpacket();
    window.luminaBindRedpacket = luminaBindRedpacket;
    if (!window.__luminaRedpacketDelegated) {
        document.addEventListener('click', function (e) {
            var target = e.target && e.target.closest ? e.target.closest('.lumina-redpacket-card') : null;
            if (!target) return;
            e.preventDefault();
            e.stopPropagation();
            luminaOpenRedpacketModal(target);
        }, true);
        window.__luminaRedpacketDelegated = true;
    }

    var noticeDays = document.getElementById('lumina-notice-days');
    if (noticeDays) {
        noticeDays.addEventListener('change', function () {
            var value = noticeDays.value;
            var url = new URL(window.location.href);
            url.searchParams.set('days', value);
            if (!url.searchParams.get('cpage')) {
                url.searchParams.set('cpage', '1');
            }
            if (!url.searchParams.get('lpage')) {
                url.searchParams.set('lpage', '1');
            }
            window.location.href = url.toString();
        });
    }
    if (pageRoot) {
        pageRoot.setAttribute('data-lumina-bindings-ready', '1');
    }
}

window.luminaInitPageBindings = luminaInitPageBindings;
document.addEventListener('DOMContentLoaded', luminaInitPageBindings);

function luminaAlignTopBar() {
    var headTop = document.getElementById('sh-main-head-top');
    var main = document.querySelector('.sh-main');
    if (!headTop || !main) {
        return;
    }
    var rect = main.getBoundingClientRect();
    if (!rect || rect.width <= 0) {
        return;
    }
    headTop.style.left = rect.left + 'px';
    headTop.style.width = rect.width + 'px';
    headTop.style.maxWidth = rect.width + 'px';
    headTop.style.transform = '';
    if (document.body && document.body.classList.contains('lumina-pc-layout-double')) {
        var scrollTop = window.pageYOffset || document.documentElement.scrollTop || document.body.scrollTop || 0;
        var isScrolled = scrollTop > luminaGetTopBarThreshold(headTop);
        headTop.style.top = isScrolled ? '0px' : Math.max(0, rect.top) + 'px';
    } else {
        headTop.style.top = '';
    }
}

function luminaGetTopBarThreshold(headTop) {
    var banner = document.querySelector('.sh-main-head-img');
    if (!headTop || !banner) {
        return 230;
    }
    var bannerBottom = (banner.offsetTop || 0) + (banner.offsetHeight || 0);
    var barHeight = headTop.offsetHeight || 0;
    var threshold = bannerBottom - barHeight;
    return threshold > 0 ? threshold : 230;
}

function luminaSyncTopBarState() {
    var headTop = document.getElementById('sh-main-head-top');
    if (!headTop) {
        return;
    }

    var scrollTop = window.pageYOffset || document.documentElement.scrollTop || document.body.scrollTop || 0;
    var isScrolled = scrollTop > luminaGetTopBarThreshold(headTop);

    headTop.classList.toggle('is-scrolled', isScrolled);
    headTop.classList.toggle('is-transparent', !isScrolled);
    headTop.style.background = '';
    headTop.style.backdropFilter = '';
    headTop.style.webkitBackdropFilter = '';

    var title = document.getElementById('setup-view-title');
    if (title) {
        title.style.color = '';
    }

    var icons = headTop.querySelectorAll('.iconfont');
    luminaEach(icons, function (icon) {
        icon.classList.add('lumina-top-icon');
        icon.classList.remove('al-sxb');
        icon.classList.remove('al-sxbh');
    });

    var noticeIcon = headTop.querySelector('[data-top-icon-role="notice"]');
    if (noticeIcon) {
        noticeIcon.classList.add('lumina-top-bell-icon');
    }
}

var luminaLegacyOnScroll = window.onscroll;
function luminaScrollBridge(event) {
    if (typeof luminaLegacyOnScroll === 'function') {
        luminaLegacyOnScroll.call(window, event);
    }
    luminaAlignTopBar();
    luminaSyncTopBarState();
}

function luminaInstallScrollBridge() {
    // Dynamically loaded legacy page scripts still assign window.onscroll.
    // Re-wrap that handler after a PJAX script load so theme-level scrolling
    // continues to run on every destination page.
    var nextLegacy = window.onscroll;
    if (nextLegacy && nextLegacy !== luminaScrollBridge) {
        luminaLegacyOnScroll = nextLegacy;
    }
    window.onscroll = luminaScrollBridge;
}

luminaInstallScrollBridge();
window.luminaInstallScrollBridge = luminaInstallScrollBridge;

// Durable load-more triggers. Per-page scripts (index.js/view.js) each assign
// window.onscroll and only load once, so after a pjax swap the scroll trigger
// and the button's inline handler can be lost. These addEventListener-based
// bindings live in theme.js, which persists across every pjax navigation.
function luminaHasMoreToLoad() {
    var footer = document.getElementById('footer-text-zt');
    if (!footer) return false;
    var state = footer.getAttribute('data-state') || 'idle';
    return state !== 'done' && state !== 'loading';
}

function luminaTriggerLoadMore() {
    if (typeof window.luminaLoadMore === 'function') {
        window.luminaLoadMore();
        return;
    }
    luminaLoadMoreFallback();
}

function luminaLoadMoreFallback() {
    var footer = document.getElementById('footer-text-zt');
    var nav = document.getElementById('lumina-page-nav');
    if (!footer || footer.getAttribute('data-state') === 'loading' || footer.getAttribute('data-state') === 'done') return;
    var next = nav && nav.querySelector('a[rel="next"], a.next');
    var nextUrl = (footer.getAttribute('data-next') || '') || (nav && nav.getAttribute('data-next')) || (next && next.href) || '';
    if (!nextUrl) {
        footer.setAttribute('data-state', 'done');
        footer.textContent = '没有更多了';
        return;
    }

    footer.setAttribute('data-state', 'loading');
    footer.textContent = '正在加载';
    fetch(nextUrl, { credentials: 'same-origin' })
        .then(function (res) {
            if (!res.ok) throw new Error('load failed');
            return res.text();
        })
        .then(function (html) {
            var doc = new DOMParser().parseFromString(html, 'text/html');
            var nextRoot = doc.getElementById('sh-nrbk');
            var target = document.getElementById('sh-nrbk');
            if (!nextRoot || !target) throw new Error('list missing');
            var authorTarget = document.querySelector('.lumina-author-list');
            var nextAuthor = nextRoot.querySelector('.lumina-author-list');
            var isHome = document.querySelector('.sh-homecontent') !== null;
            var items = authorTarget && nextAuthor
                ? Array.prototype.filter.call(nextAuthor.children, function (node) {
                    return node.classList && (node.classList.contains('sh-homecontent-timed') || node.classList.contains('sh-homecontent-lie'));
                })
                : Array.prototype.slice.call(isHome ? nextRoot.querySelectorAll('.sh-homecontent-lie') : nextRoot.querySelectorAll('.sh-content'));
            if (!items.length) throw new Error('no items');
            var appendTarget = authorTarget || target;
            items.forEach(function (item) { appendTarget.appendChild(item); });

            var nextNav = doc.getElementById('lumina-page-nav');
            var updatedUrl = nextNav ? (nextNav.getAttribute('data-next') || '') : '';
            if (nav && nextNav) nav.innerHTML = nextNav.innerHTML;
            if (nav) nav.setAttribute('data-next', updatedUrl);
            footer.setAttribute('data-next', updatedUrl);
            footer.setAttribute('data-state', updatedUrl ? 'idle' : 'done');
            footer.textContent = updatedUrl ? '查看更多' : '没有更多了';
            if (typeof window.luminaRefreshDynamicContent === 'function') window.luminaRefreshDynamicContent();
        })
        .catch(function () {
            footer.setAttribute('data-state', 'idle');
            footer.textContent = '加载失败，点击重试';
        });
}

function luminaIsNearBottom() {
    var docHeight = Math.max(
        document.body.scrollHeight,
        document.documentElement.scrollHeight,
        document.body.offsetHeight,
        document.documentElement.offsetHeight,
        document.body.clientHeight,
        document.documentElement.clientHeight
    );
    var winHeight = window.innerHeight || document.documentElement.clientHeight || document.body.clientHeight;
    var scrollTop = window.pageYOffset || document.documentElement.scrollTop || document.body.scrollTop;
    return scrollTop + winHeight >= docHeight - 4;
}

window.addEventListener('scroll', function () {
    // Page scripts still contain legacy window.onscroll assignments. Keep the
    // persistent theme behavior on addEventListener so PJAX cannot replace it.
    luminaAlignTopBar();
    luminaSyncTopBarState();
    if (luminaHasMoreToLoad() && luminaIsNearBottom()) {
        luminaTriggerLoadMore();
    }
}, { passive: true });

document.addEventListener('click', function (e) {
    var btn = e.target && e.target.closest ? e.target.closest('#footer-text-zt') : null;
    if (!btn) return;
    e.preventDefault();
    luminaTriggerLoadMore();
});

window.addEventListener('resize', function () {
    luminaAlignTopBar();
    luminaSyncTopBarState();
});

function luminaInitTopBgm() {
    var wrap = document.getElementById('lumina-top-bgm');
    if (!wrap) {
        return;
    }
    var audio = document.getElementById('lumina-top-bgm-audio');
    var toggle = wrap.querySelector('[data-action="top-bgm-toggle"]');
    var progress = wrap.querySelector('.lumina-top-bgm-progress-bar');
    var autoplay = wrap.getAttribute('data-autoplay') === 'y';
    if (!audio || !toggle || !progress) {
        return;
    }

    function syncProgress() {
        var percent = 0;
        if (audio.duration && isFinite(audio.duration) && audio.duration > 0) {
            percent = Math.max(0, Math.min(100, (audio.currentTime / audio.duration) * 100));
        }
        progress.style.width = percent + '%';
        wrap.classList.toggle('has-progress', percent > 0.6);
    }

    function syncState() {
        wrap.classList.toggle('is-playing', !audio.paused && !audio.ended);
        syncProgress();
    }

    function pauseOtherAudios() {
        var audios = document.querySelectorAll('audio');
        luminaEach(audios, function (item) {
            if (item !== audio && typeof item.pause === 'function') {
                item.pause();
            }
        });
    }

    toggle.addEventListener('click', function (e) {
        e.preventDefault();
        e.stopPropagation();
        if (audio.paused || audio.ended) {
            pauseOtherAudios();
            var playPromise = audio.play();
            if (playPromise && typeof playPromise.catch === 'function') {
                playPromise.catch(function () {
                    syncState();
                });
            }
        } else {
            audio.pause();
        }
    });

    audio.addEventListener('play', syncState);
    audio.addEventListener('pause', syncState);
    audio.addEventListener('ended', syncState);
    audio.addEventListener('timeupdate', syncProgress);
    audio.addEventListener('loadedmetadata', syncProgress);

    document.addEventListener('play', function (e) {
        var target = e && e.target;
        if (!target || target === audio || target.tagName !== 'AUDIO') {
            return;
        }
        audio.pause();
    }, true);

    if (autoplay) {
        setTimeout(function () {
            pauseOtherAudios();
            var playPromise = audio.play();
            if (playPromise && typeof playPromise.catch === 'function') {
                playPromise.catch(function () {
                    syncState();
                });
            }
        }, 120);
    }

    syncState();
}

window.luminaAlignTopBar = luminaAlignTopBar;
window.luminaSyncTopBarState = luminaSyncTopBarState;
window.luminaInitTopBgm = luminaInitTopBgm;

function luminaInitLivePhotos() {
    var items = document.querySelectorAll('.sh-content-right-img-pic.is-live-photo');
    luminaEach(items, function (item) {
        if (item.dataset.luminaLiveInit === '1') {
            return;
        }
        var video = item.querySelector('.lumina-live-video');
        if (!video) {
            return;
        }
        var playing = false;
        var touchTimer = null;

        function playLive(restart) {
            if (playing) return;
            playing = true;
            item.classList.add('is-playing');
            if (restart) {
                try {
                    video.currentTime = 0;
                } catch (e) {}
            }
            video.muted = true;
            var promise = video.play();
            if (promise && typeof promise.catch === 'function') {
                promise.catch(function () {
                    stopLive();
                });
            }
        }

        function stopLive() {
            playing = false;
            item.classList.remove('is-playing');
            video.pause();
            try {
                video.currentTime = 0;
            } catch (e) {}
            if (touchTimer) {
                clearTimeout(touchTimer);
                touchTimer = null;
            }
        }

        item.addEventListener('mouseenter', function () {
            playLive(true);
        });
        item.addEventListener('mouseleave', stopLive);
        item.addEventListener('touchstart', function () {
            touchTimer = setTimeout(function () {
                playLive(true);
            }, 160);
        }, { passive: true });
        item.addEventListener('touchend', function () {
            if (touchTimer) {
                clearTimeout(touchTimer);
                touchTimer = null;
            }
        }, { passive: true });
        item.addEventListener('touchcancel', stopLive, { passive: true });
        item.addEventListener('click', function (event) {
            event.preventDefault();
            event.stopPropagation();
            if (playing) {
                stopLive();
            } else {
                playLive(true);
            }
        });
        item.dataset.luminaLiveInit = '1';
    });
}
window.luminaInitLivePhotos = luminaInitLivePhotos;

function luminaApplyVideoAspect(wrap, video) {
    if (!wrap || !video || !video.videoWidth || !video.videoHeight) return;
    var ratio = video.videoWidth / video.videoHeight;
    var isPortrait = ratio > 0 && ratio <= 0.78;
    wrap.classList.toggle('lumina-video-portrait', isPortrait);
    wrap.classList.toggle('lumina-video-landscape', !isPortrait);
    var compactPreview = wrap.closest ? wrap.closest('.homecontent-right-tw') : null;
    if (compactPreview) {
        compactPreview.classList.toggle('lumina-video-portrait', isPortrait);
        compactPreview.classList.toggle('lumina-video-landscape', !isPortrait);
    }
    wrap.dataset.luminaVideoRatio = ratio.toFixed(4);
}
window.luminaApplyVideoAspect = luminaApplyVideoAspect;

function luminaObserveVideoAspect(wrap, video) {
    if (!wrap || !video || video.dataset.luminaAspectBound === '1') return;
    video.dataset.luminaAspectBound = '1';
    var sync = function () { luminaApplyVideoAspect(wrap, video); };
    video.addEventListener('loadedmetadata', sync);
    video.addEventListener('loadeddata', sync, { once: true });
    sync();
}

function luminaCreateVideoPosterOverlay(wrap, video, interactive) {
    if (!wrap || !video || wrap.dataset.luminaVideoPoster === '1') return;
    luminaObserveVideoAspect(wrap, video);
    wrap.dataset.luminaVideoPoster = '1';
    wrap.classList.add('lumina-video-poster-ready');

    var poster = video.getAttribute('poster') || video.getAttribute('data-poster') || '';
    if (poster) {
        wrap.classList.add('lumina-video-poster-has-image');
    }
    var overlay = document.createElement(interactive ? 'button' : 'div');
    overlay.className = 'lumina-video-poster-overlay' + (poster ? ' has-poster' : ' no-poster');
    if (interactive) {
        overlay.type = 'button';
        overlay.setAttribute('aria-label', '播放视频');
    } else {
        overlay.setAttribute('aria-hidden', 'true');
    }

    if (poster) {
        var img = document.createElement('img');
        img.src = poster;
        img.alt = '';
        img.loading = 'lazy';
        overlay.appendChild(img);
    } else {
        var empty = document.createElement('span');
        empty.className = 'lumina-video-poster-empty';
        empty.textContent = 'MP4';
        overlay.appendChild(empty);
    }

    var icon = document.createElement('span');
    icon.className = 'lumina-video-poster-play';
    overlay.appendChild(icon);
    wrap.appendChild(overlay);

    if (interactive) {
        overlay.addEventListener('click', function (e) {
            e.preventDefault();
            e.stopPropagation();
            overlay.classList.add('is-hidden');
            wrap.classList.add('lumina-video-activated');
            if (wrap.luminaDPlayer && typeof wrap.luminaDPlayer.play === 'function') {
                wrap.luminaDPlayer.play();
                return;
            }
            video.controls = true;
            video.style.display = '';
            var promise = video.play();
            if (promise && typeof promise.catch === 'function') {
                promise.catch(function () {
                    overlay.classList.remove('is-hidden');
                    wrap.classList.remove('lumina-video-activated');
                });
            }
        });
    }

    if (!poster) {
        luminaGenerateVideoPoster(video, function (dataUrl) {
            if (dataUrl) {
                luminaApplyVideoPoster(wrap, video, dataUrl);
            }
        });
    }
}

function luminaVideoFallbackPoster() {
    var templateUrl = window.LUMINA && window.LUMINA.templateUrl ? String(window.LUMINA.templateUrl) : '';
    if (templateUrl && templateUrl.charAt(templateUrl.length - 1) !== '/') {
        templateUrl += '/';
    }
    return templateUrl ? (templateUrl + 'assets/img/thumbnailbg.svg') : 'assets/img/thumbnailbg.svg';
}

function luminaApplyVideoPoster(wrap, video, poster) {
    if (!wrap || !video || !poster) return;
    video.setAttribute('poster', poster);
    video.setAttribute('data-poster', poster);
    wrap.classList.add('lumina-video-poster-has-image');
    var overlay = wrap.querySelector('.lumina-video-poster-overlay');
    if (!overlay) return;
    overlay.classList.remove('no-poster');
    overlay.classList.add('has-poster');
    var empty = overlay.querySelector('.lumina-video-poster-empty');
    if (empty && empty.parentNode) {
        empty.parentNode.removeChild(empty);
    }
    var img = overlay.querySelector('img');
    if (!img) {
        img = document.createElement('img');
        img.alt = '';
        overlay.insertBefore(img, overlay.firstChild);
    }
    img.src = poster;
}

function luminaGenerateVideoPoster(video, done) {
    if (!video) return;
    if (!video._luminaPosterCallbacks) {
        video._luminaPosterCallbacks = [];
    }
    if (typeof done === 'function') {
        video._luminaPosterCallbacks.push(done);
    }
    if (video.dataset.luminaPosterCapture === 'done') {
        var captured = video.dataset.luminaPosterData || '';
        var callbacks = video._luminaPosterCallbacks.splice(0);
        callbacks.forEach(function (callback) { callback(captured); });
        return;
    }
    if (video.dataset.luminaPosterCapture === 'pending') return;
    video.dataset.luminaPosterCapture = 'pending';
    var src = video.currentSrc || video.getAttribute('src') || '';
    if (!src) return;
    var finished = false;
    var cleanupFns = [];

    function cleanup() {
        cleanupFns.forEach(function (fn) {
            try { fn(); } catch (err) {}
        });
        cleanupFns = [];
    }

    function finish(dataUrl) {
        if (finished) return;
        finished = true;
        cleanup();
        video.dataset.luminaPosterCapture = 'done';
        video.dataset.luminaPosterData = dataUrl || '';
        var callbacks = video._luminaPosterCallbacks ? video._luminaPosterCallbacks.splice(0) : [];
        callbacks.forEach(function (callback) { callback(dataUrl || ''); });
    }

    function on(el, name, fn, opts) {
        el.addEventListener(name, fn, opts || false);
        cleanupFns.push(function () {
            el.removeEventListener(name, fn, opts || false);
        });
    }

    function capture() {
        if (finished) return;
        try {
            if (!video.videoWidth || !video.videoHeight) {
                return;
            }
            var canvas = document.createElement('canvas');
            var maxWidth = 960;
            var ratio = Math.min(1, maxWidth / video.videoWidth);
            canvas.width = Math.max(1, Math.round(video.videoWidth * ratio));
            canvas.height = Math.max(1, Math.round(video.videoHeight * ratio));
            var ctx = canvas.getContext('2d');
            ctx.drawImage(video, 0, 0, canvas.width, canvas.height);
            finish(canvas.toDataURL('image/jpeg', 0.82));
        } catch (err) {
            finish('');
        }
    }

    function seekAndCapture() {
        if (finished) return;
        if (video.readyState >= 2) {
            var target = 0;
            try {
                target = video.duration && isFinite(video.duration) && video.duration > 0.35 ? 0.18 : 0;
                if (Math.abs(video.currentTime - target) > 0.03) {
                    on(video, 'seeked', capture, { once: true });
                    video.currentTime = target;
                    window.setTimeout(capture, 900);
                    return;
                }
            } catch (err) {}
            capture();
            return;
        }
        on(video, 'loadeddata', seekAndCapture, { once: true });
    }

    on(video, 'error', function () { finish(''); }, { once: true });
    window.setTimeout(function () { finish(''); }, 2800);
    try {
        video.preload = 'auto';
        video.load();
    } catch (err2) {}
    seekAndCapture();
}

function luminaInitVideoPosterOverlays() {
    luminaEach(document.querySelectorAll('.sh-video video.sh-content-video'), function (video) {
        luminaCreateVideoPosterOverlay(video.closest('.sh-video'), video, true);
    });
    luminaEach(document.querySelectorAll('.homecontent-right-tw-video video.homecontent-right-tw-videoau'), function (video) {
        var wrap = video.closest('.homecontent-right-tw-video');
        luminaObserveVideoAspect(wrap, video);
        luminaCreateVideoPosterOverlay(wrap, video, false);
    });
}
window.luminaInitVideoPosterOverlays = luminaInitVideoPosterOverlays;

function luminaInitDPlayerVideos() {
    var videos = document.querySelectorAll('.sh-video video.sh-content-video');
    luminaEach(videos, function (video) {
        var wrap = video.closest('.sh-video');
        if (!wrap || wrap.dataset.luminaDplayer === '1' || wrap.dataset.luminaDplayer === 'pending') return;
        var src = video.getAttribute('src') || '';
        if (!src) return;

        luminaCreateVideoPosterOverlay(wrap, video, true);
        wrap.removeAttribute('onclick');
        video.removeAttribute('onclick');
        video.removeAttribute('loop');
        video.removeAttribute('muted');
        video.controls = true;
        video.setAttribute('playsinline', '');
        video.setAttribute('webkit-playsinline', '');
        video.setAttribute('controlslist', 'nodownload noremoteplayback noplaybackrate');

        if (!window.DPlayer) {
            wrap.dataset.luminaDplayer = '1';
            wrap.classList.add('lumina-native-video');
            return;
        }

        var poster = video.getAttribute('poster') || video.getAttribute('data-poster') || '';
        if (!poster) {
            wrap.dataset.luminaDplayer = 'pending';
            luminaGenerateVideoPoster(video, function (dataUrl) {
                if (!wrap.isConnected) return;
                var nextPoster = dataUrl || luminaVideoFallbackPoster();
                if (nextPoster) {
                    luminaApplyVideoPoster(wrap, video, nextPoster);
                }
                delete wrap.dataset.luminaDplayer;
                luminaInitDPlayerVideos();
            });
            return;
        }
        wrap.dataset.luminaDplayer = '1';
        var playerBox = document.createElement('div');
        playerBox.className = 'lumina-dplayer';
        wrap.insertBefore(playerBox, video);
        video.style.display = 'none';
        wrap.classList.add('lumina-dplayer-ready');

        var themeColor = '#09c362';
        try {
            var computedTheme = getComputedStyle(document.documentElement).getPropertyValue('--theme');
            if (computedTheme) themeColor = computedTheme.trim();
        } catch (err) {}

        try {
            var player = new window.DPlayer({
                container: playerBox,
                theme: themeColor,
                screenshot: false,
                preload: 'metadata',
                mutex: true,
                video: {
                    url: src,
                    pic: poster,
                    type: 'auto'
                }
            });
            wrap.luminaDPlayer = player;
            var playerVideo = player.video || playerBox.querySelector('video');
            if (playerVideo) {
                playerVideo.setAttribute('playsinline', '');
                playerVideo.setAttribute('webkit-playsinline', '');
                playerVideo.setAttribute('controlslist', 'nodownload noremoteplayback noplaybackrate');
                luminaObserveVideoAspect(wrap, playerVideo);
            }
        } catch (err2) {
            wrap.classList.remove('lumina-dplayer-ready');
            wrap.classList.add('lumina-native-video');
            if (playerBox.parentNode) {
                playerBox.parentNode.removeChild(playerBox);
            }
            video.style.display = '';
            video.controls = true;
        }
    });
}
window.luminaInitDPlayerVideos = luminaInitDPlayerVideos;

/* ===== 平台视频弹窗播放器（对标 morpho douyin-modal） ===== */
var luminaVideoModal = (function () {
    var modal = null;
    var dialog = null;
    var body = null;
    var closeBtn = null;
    var iframe = null;
    var spinner = null;
    var retryBtn = null;
    var lastFocus = null;
    var loadToken = 0;
    var stallTimer = 0;

    function ensure() {
        if (modal) return modal;
        modal = document.createElement('div');
        modal.id = 'lumina-video-modal';
        modal.setAttribute('role', 'dialog');
        modal.setAttribute('aria-modal', 'true');
        modal.setAttribute('aria-label', '视频播放器');
        modal.innerHTML = ''
            + '<div class="lumina-video-modal-backdrop"></div>'
            + '<section class="lumina-video-modal-dialog">'
            +   '<button type="button" class="lumina-video-modal-close" aria-label="关闭" title="关闭">'
            +     '<svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18"/><path d="M6 6l12 12"/></svg>'
            +   '</button>'
            +   '<div class="lumina-video-modal-body">'
            +     '<span class="lumina-video-modal-spinner" aria-hidden="true"></span>'
            +     '<button type="button" class="lumina-video-modal-retry">重新加载</button>'
            +     '<iframe title="视频" allow="autoplay; encrypted-media; fullscreen; picture-in-picture" allowfullscreen referrerpolicy="strict-origin-when-cross-origin" scrolling="no"></iframe>'
            +   '</div>'
            + '</section>';
        document.body.appendChild(modal);
        dialog = modal.querySelector('.lumina-video-modal-dialog');
        body = modal.querySelector('.lumina-video-modal-body');
        closeBtn = modal.querySelector('.lumina-video-modal-close');
        iframe = modal.querySelector('iframe');
        spinner = modal.querySelector('.lumina-video-modal-spinner');
        retryBtn = modal.querySelector('.lumina-video-modal-retry');
        modal.querySelector('.lumina-video-modal-backdrop').addEventListener('click', close);
        closeBtn.addEventListener('click', close);
        retryBtn.addEventListener('click', function (e) { e.stopPropagation(); reload(); });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && modal && modal.classList.contains('is-open')) {
                e.preventDefault();
                close();
            }
        });
        return modal;
    }

    function clearStall() {
        if (stallTimer) { window.clearTimeout(stallTimer); stallTimer = 0; }
    }
    function armStall(src) {
        clearStall();
        stallTimer = window.setTimeout(function () {
            if (modal && modal.classList.contains('is-open') && !modal.classList.contains('is-loaded')) {
                modal.classList.add('is-stalled');
            }
        }, 4500);
    }
    function reload() {
        if (!iframe) return;
        var src = iframe.getAttribute('src') || iframe.getAttribute('data-src') || '';
        if (!src) return;
        modal.classList.remove('is-loaded', 'is-stalled');
        iframe.setAttribute('src', '');
        window.setTimeout(function () {
            iframe.setAttribute('src', src);
            armStall(src);
        }, 30);
    }

    function open(src, ratio, label, focusEl) {
        if (!src) return;
        ensure();
        loadToken++;
        lastFocus = focusEl || document.activeElement;
        modal.classList.remove('is-loaded', 'is-stalled');
        modal.classList.remove('is-portrait');
        if (ratio === 'tb') modal.classList.add('is-portrait');
        body.setAttribute('data-label', label || '视频');
        iframe.setAttribute('data-src', src);
        iframe.setAttribute('title', label || '视频');
        document.documentElement.classList.add('lumina-lock-scroll');
        modal.classList.add('is-open');
        if (closeBtn) closeBtn.focus({ preventScroll: true });
        // 双 rAF 确保过渡帧后再注入 src，避免弹窗未显形就加载
        window.requestAnimationFrame(function () {
            window.requestAnimationFrame(function () {
                if (!modal.classList.contains('is-open')) return;
                iframe.setAttribute('src', src);
                armStall(src);
            });
        });
    }

    function close() {
        if (!modal || !modal.classList.contains('is-open')) return;
        clearStall();
        modal.classList.remove('is-open');
        document.documentElement.classList.remove('lumina-lock-scroll');
        // 暂停并清空 iframe，停止后台音视频
        try { iframe.setAttribute('src', ''); } catch (e) {}
        if (lastFocus && typeof lastFocus.focus === 'function') {
            lastFocus.focus({ preventScroll: true });
        }
    }

    // iframe 加载完成 → 显形
    document.addEventListener('DOMContentLoaded', function () {
        ensure();
        iframe.addEventListener('load', function () {
            var src = iframe.getAttribute('src') || '';
            if (!src || src === 'about:blank') return;
            if (!modal.classList.contains('is-open')) return;
            clearStall();
            modal.classList.remove('is-stalled');
            modal.classList.add('is-loaded');
        });
        iframe.addEventListener('error', function () {
            if (modal.classList.contains('is-open')) modal.classList.add('is-stalled');
        });
    });

    return { open: open, close: close };
})();

function luminaInitEmbedVideos() {
    luminaEach(document.querySelectorAll('.lumina-embed-video'), function (wrap) {
        if (!wrap || wrap.dataset.luminaEmbedInit === '1') return;
        wrap.dataset.luminaEmbedInit = '1';
        var start = wrap.querySelector('.lumina-embed-video-start');
        var isPreview = wrap.classList.contains('lumina-embed-video-list-preview');
        var isEmbedded = wrap.classList.contains('is-embedded');

        // 横屏内嵌视频(如B站)：iframe 已直接嵌入真实 src（无遮罩，对标 morpho）。
        // 此处仅做兜底规范化：html5mobileplayer 端点省略 autoplay 参数（存在即强制静音自动播放），
        // player.html 端点尊重 autoplay=0；再从 allow 中剥离 autoplay 权限
        if (isEmbedded) {
            var frame = wrap.querySelector('iframe');
            if (frame) {
                var realSrc = frame.getAttribute('src') || '';
                if (realSrc) {
                    try {
                        var u = new URL(realSrc, location.href);
                        var host = u.hostname.toLowerCase();
                        var path = u.pathname.toLowerCase();
                        var isMobilePlayer = (host === 'www.bilibili.com' || host === 'bilibili.com') && path.indexOf('/blackboard/html5mobileplayer.html') !== -1;
                        var isBiliPlayer = isMobilePlayer || (host === 'player.bilibili.com' && path.endsWith('/player.html'));
                        if (isBiliPlayer) {
                            if (isMobilePlayer) {
                                // 该端点 autoplay 参数存在即强制静音自动播放，必须完全删除
                                u.searchParams.delete('autoplay');
                            } else {
                                u.searchParams.set('autoplay', '0');
                            }
                            if (!u.searchParams.has('danmaku')) u.searchParams.set('danmaku', '0');
                            frame.setAttribute('src', u.toString());
                        }
                    } catch (e) {}
                    var allow = (frame.getAttribute('allow') || '').split(';').map(function (s) { return s.trim(); }).filter(function (s) { return s && s.toLowerCase() !== 'autoplay'; }).join('; ');
                    allow ? frame.setAttribute('allow', allow) : frame.removeAttribute('allow');
                }
            }
            return;
        }

        // 列表预览：点击跳转详情页
        if (isPreview) {
            if (start) {
                start.addEventListener('click', function (event) {
                    event.preventDefault();
                    event.stopPropagation();
                    var card = wrap.closest ? wrap.closest('[data-lumina-ajax-href]') : null;
                    var href = card ? card.getAttribute('data-lumina-ajax-href') : '';
                    if (href) {
                        if (typeof window.luminaAjaxVisit === 'function') {
                            window.luminaAjaxVisit(href);
                        } else {
                            window.location.href = href;
                        }
                    }
                });
            }
            ['click', 'pointerdown', 'touchstart'].forEach(function (eventName) {
                wrap.addEventListener(eventName, function (event) {
                    if (eventName === 'click') event.stopPropagation();
                }, eventName === 'touchstart' ? { passive: true } : false);
            });
            return;
        }

        // 竖屏(抖音等)：卡片 + 弹窗播放（is-modal 标记竖屏弹窗语义，data-src 为播放地址）
        var isModal = wrap.classList.contains('is-modal');
        if (isModal) {
            var src = wrap.getAttribute('data-src') || '';
            var ratio = wrap.getAttribute('data-ratio') === 'tb' ? 'tb' : 'lr';
            var label = wrap.getAttribute('data-label') || '平台视频';
            if (start) {
                start.addEventListener('click', function (event) {
                    event.preventDefault();
                    event.stopPropagation();
                    if (src) luminaVideoModal.open(src, ratio, label, start);
                });
            }
            return;
        }
    });
}
window.luminaInitEmbedVideos = luminaInitEmbedVideos;

// 音乐/音频开始播放时，暂停横屏内嵌视频(如B站)，避免双音源（对标 morpho ambient-play 停嵌入机制）。
// 跨域 iframe 内部的事件不会冒泡到父文档，此处仅捕获页面自身 audio/video 的 play；
// 将 iframe 置 about:blank 再恢复 src，使视频回到封面停驻状态。
function luminaBindEmbeddedVideoStop() {
    if (window.__luminaEmbedStopBound) return;
    window.__luminaEmbedStopBound = 1;
    document.addEventListener('play', function (event) {
        var target = event.target;
        if (!target || !(target instanceof HTMLAudioElement || target instanceof HTMLVideoElement)) return;
        luminaEach(document.querySelectorAll('.lumina-embed-video.is-embedded'), function (wrap) {
            var frame = wrap.querySelector('iframe');
            if (!frame) return;
            var src = frame.getAttribute('src') || '';
            if (!src || src === 'about:blank') return;
            var token = String(Date.now()) + '-' + Math.random().toString(36).slice(2);
            wrap.dataset.luminaEmbedStopToken = token;
            frame.setAttribute('src', 'about:blank');
            window.setTimeout(function () {
                if (wrap.dataset.luminaEmbedStopToken === token) {
                    frame.setAttribute('src', src);
                }
            }, 60);
        });
    }, true);
}
window.luminaBindEmbeddedVideoStop = luminaBindEmbeddedVideoStop;

function luminaBindAuthorCoverUpload() {
    var btn = document.getElementById('lumina-cover-btn');
    var input = document.getElementById('lumina-cover-file');
    var cover = document.querySelector('.sh-main-head-img');
    if (!btn || !input || !cover || input.getAttribute('data-lumina-bound') === '1') {
        return;
    }
    input.setAttribute('data-lumina-bound', '1');

    btn.addEventListener('click', function (e) {
        if (e) {
            e.preventDefault();
            e.stopPropagation();
        }
        input.click();
    });

    input.addEventListener('change', function () {
        var file = input.files && input.files[0];
        if (!file) return;

        var fd = new FormData();
        var token = input.getAttribute('data-token') || '';
        fd.append('file', file);
        fd.append('lumina_action', 'cover_upload');
        if (token) {
            fd.append('token', token);
        }

        if (typeof loadpop === 'function') {
            loadpop('正在上传封面，请稍后...', 'ok');
        }

        fetch(input.getAttribute('data-upload-url') || window.location.href, {
            method: 'POST',
            body: fd,
            credentials: 'same-origin'
        }).then(function (res) {
            return res.json();
        }).then(function (data) {
            var msg = data && data.msg ? data.msg : '';
            if (data && (data.code === 0 || data.code === '0')) {
                if (typeof successpop === 'function') {
                    successpop('封面修改成功');
                } else {
                    alert('封面修改成功');
                }
                if (data.data && data.data.url) {
                    cover.style.backgroundImage = 'url(' + data.data.url + ')';
                }
            } else if (typeof warnpop === 'function') {
                warnpop(msg || '封面修改失败');
            } else {
                alert(msg || '封面修改失败');
            }
            input.value = '';
        }).catch(function () {
            if (typeof warnpop === 'function') {
                warnpop('上传失败');
            } else {
                alert('上传失败');
            }
            input.value = '';
        });
    });
}
window.luminaBindAuthorCoverUpload = luminaBindAuthorCoverUpload;

function luminaRefreshDynamicContent() {
    // The first page eagerly promotes data-src during DOMContentLoaded. PJAX
    // never fires that event, so do the same work explicitly after every swap.
    var lazyImages = document.querySelectorAll('img[data-src]');
    luminaEach(lazyImages, function (img) {
        var src = img.getAttribute('data-src');
        if (src && src !== 'null') {
            img.setAttribute('src', src);
        }
    });
    var captcha = document.getElementById('captcha');
    if (captcha && captcha.dataset.luminaCaptchaBound !== '1') {
        captcha.dataset.luminaCaptchaBound = '1';
        captcha.addEventListener('click', function () {
            var src = captcha.getAttribute('src') || '';
            var base = src.split('?')[0];
            if (base) captcha.setAttribute('src', base + '?t=' + Date.now());
        });
    }
    if (typeof window.luminaInitPageBindings === 'function') {
        window.luminaInitPageBindings();
    }
    if (typeof loaddemand === 'function') {
        try {
            loaddemand();
        } catch (err) {
            if (window.console && window.console.warn) {
                window.console.warn('[Lumina] lazy loader refresh failed; images were loaded eagerly instead.', err);
            }
        }
    }
    if (typeof window.luminaRefreshLegacyGlobals === 'function') {
        window.luminaRefreshLegacyGlobals();
    }
    if (typeof window.luminaInitListLoadMore === 'function') {
        window.luminaInitListLoadMore();
    }
    luminaInitFancybox();
    luminaInitVideoPosterOverlays();
    luminaInitDPlayerVideos();
    luminaInitEmbedVideos();
    luminaBindEmbeddedVideoStop();
    if (typeof window.luminaInitMusicCards === 'function') {
        window.luminaInitMusicCards();
    }
    if (typeof window.luminaInitLivePhotos === 'function') {
        window.luminaInitLivePhotos();
    }
    if (typeof window.luminaBindRedpacket === 'function') {
        window.luminaBindRedpacket();
    }
    if (typeof window.luminaNoticeApplyRead === 'function') {
        window.luminaNoticeApplyRead();
    }
    luminaBindAuthorCoverUpload();
    if (typeof wzcsql === 'function' && document.querySelector('.sh-homecontent-left-time')) {
        wzcsql();
    }
    luminaAlignTopBar();
    luminaSyncTopBarState();
    luminaInitTopBgm();
}

window.luminaRefreshDynamicContent = luminaRefreshDynamicContent;

function luminaDisposePageContent(root) {
    if (!root || !root.querySelectorAll) return;

    // DPlayer keeps listeners and media references outside the removed DOM.
    // Destroy it before replacing a PJAX fragment so video/audio never leaks
    // into the next route.
    luminaEach(root.querySelectorAll('.sh-video'), function (wrap) {
        var player = wrap.luminaDPlayer;
        if (player && typeof player.destroy === 'function') {
            try {
                player.destroy();
            } catch (err) {}
        }
        wrap.luminaDPlayer = null;
    });

    luminaEach(root.querySelectorAll('video, audio'), function (media) {
        try {
            media.pause();
            media.removeAttribute('autoplay');
        } catch (err) {}
    });

    luminaEach(root.querySelectorAll('iframe'), function (frame) {
        // Detached cross-origin iframes can keep playing briefly in some
        // mobile WebViews unless their source is cleared first.
        try {
            frame.src = 'about:blank';
        } catch (err) {}
    });
}

function luminaCloseTransientPageUi() {
    ['sh-view-set', 'sh-news', 'sh-link', 'sh-login', 'sh-fabu', 'so'].forEach(function (id) {
        var node = document.getElementById(id);
        if (node) node.style.display = 'none';
    });
    if (document.body) {
        document.body.classList.remove('lumina-auth-open', 'lumina-redpacket-open');
    }
}

window.luminaDisposePageContent = luminaDisposePageContent;
window.luminaCloseTransientPageUi = luminaCloseTransientPageUi;

function luminaSetOverlayVisible(id, visible) {
    var el = document.getElementById(id);
    if (!el) return false;
    el.style.left = '';
    el.style.width = '';
    el.style.maxWidth = '';
    el.style.right = '';
    el.style.display = visible ? 'flex' : 'none';
    return true;
}

window.kqlink = function () {
    luminaSetOverlayVisible('sh-link', true);
};
window.gblink = function () {
    luminaSetOverlayVisible('sh-link', false);
};
window.kqnews = function () {
    luminaSetOverlayVisible('sh-news', true);
};
window.gbnews = function () {
    luminaSetOverlayVisible('sh-news', false);
};
function luminaOpenViewSet() {
    var wrap = document.getElementById('sh-view-set');
    var panel = document.getElementById('sh-view-set-wk-con');
    var isPcDouble = document.body && document.body.classList.contains('lumina-pc-layout-double');
    if (!wrap) return;
    wrap.style.left = '';
    wrap.style.width = '';
    wrap.style.maxWidth = '';
    wrap.style.right = '';
    wrap.style.display = 'flex';
    if (panel) {
        panel.style.animation = isPcDouble ? 'luminaPcPopoverIn 0.16s ease both' : 'move_4 0.2s';
    }
}
document.addEventListener('click', function (e) {
    var target = e.target;
    if (!target || !target.closest) return;
    if (target.closest('.lumina-top-link-btn')) {
        window.kqlink();
        return;
    }
    if (target.closest('[data-top-icon-role="notice"]')) {
        window.kqnews();
        return;
    }
    var moreTarget = target.closest('[data-top-icon-role="more"], #top-right-more, [onclick*="viewsetk"]');
    if (!moreTarget && target.closest('.sh-main-head-top') && target.closest('.icon-gengduo')) {
        moreTarget = target;
    }
    if (moreTarget) {
        e.preventDefault();
        e.stopPropagation();
        luminaOpenViewSet();
    }
}, true);
window.luminaOpenViewSet = luminaOpenViewSet;
window.viewsetk = function () {
    luminaOpenViewSet();
};
window.viewsetg = function (e) {
    var evt = e || window.event;
    if (evt && evt.stopPropagation) {
        evt.stopPropagation();
    }
    var wrap = document.getElementById('sh-view-set');
    var shell = document.getElementById('sh-view-set-wk');
    var panel = document.getElementById('sh-view-set-wk-con');
    var isPcDouble = document.body && document.body.classList.contains('lumina-pc-layout-double');
    if (!wrap) return;
    if (shell) {
        shell.style.transition = 'opacity 0.25s';
        shell.style.opacity = '0';
    }
    if (panel && !isPcDouble) {
        panel.style.animation = 'move_4t 0.2s';
    }
    window.setTimeout(function () {
        wrap.style.display = 'none';
        if (shell) {
            shell.style.opacity = '';
            shell.style.transition = '';
        }
        if (panel) {
            panel.style.animation = '';
        }
    }, 200);
};

function luminaAdminPostAction(el, action, payload) {
    if (!el) return Promise.reject(new Error('missing element'));
    var url = el.getAttribute('data-action-url') || '';
    var blogid = el.getAttribute('data-blogid') || '';
    var token = el.getAttribute('data-token') || '';
    if (!url || !blogid || !token) {
        return Promise.reject(new Error('missing params'));
    }
    var fd = new FormData();
    fd.append('lumina_action', action);
    fd.append('blogid', blogid);
    fd.append('token', token);
    Object.keys(payload || {}).forEach(function (key) {
        fd.append(key, payload[key]);
    });
    return fetch(url, {
        method: 'POST',
        body: fd,
        credentials: 'same-origin'
    }).then(function (res) {
        return res.json().catch(function () {
            throw new Error('响应解析失败');
        });
    }).then(function (resp) {
        if (!resp || resp.code !== 0) {
            throw new Error(resp && resp.msg ? resp.msg : '操作失败');
        }
        return resp;
    });
}

function luminaAdminSyncDetailBadge(type, active) {
    var target = document.querySelector('.sh-content-right-head-title > p');
    if (!target) return;
    var cls = type === 'top' ? 'lumina-log-badge-top' : 'lumina-log-badge-private';
    var existing = target.querySelector('.' + cls);
    if (!active) {
        if (existing) existing.remove();
        return;
    }
    if (existing) return;
    var badge = document.createElement('span');
    badge.className = 'lumina-log-badge ' + cls;
    badge.title = type === 'top' ? '置顶' : '仅自己可看';
    badge.setAttribute('aria-label', badge.title);
    badge.textContent = type === 'top' ? '置顶' : '私密';
    target.appendChild(badge);
}

window.luminaTogglePrivate = function (el) {
    if (!el) return;
    var state = el.getAttribute('data-hide-state') || 'n';
    var hideUrl = el.getAttribute('data-hide-url') || '';
    var pubUrl = el.getAttribute('data-pub-url') || '';
    var targetUrl = state === 'y' ? pubUrl : hideUrl;
    if (!targetUrl) return;
    if (state !== 'y' && !confirm('设为草稿后将从公开内容中隐藏，确定继续吗？')) {
        return;
    }
    if (typeof loadpop === 'function') {
        loadpop('正在处理...', 'ok');
    }
    fetch(targetUrl, { credentials: 'same-origin' })
        .then(function () {
            if (typeof successpop === 'function') {
                successpop(state === 'y' ? '已取消草稿' : '已设为草稿');
            }
            var newState = state === 'y' ? 'n' : 'y';
            el.setAttribute('data-hide-state', newState);
            var span = el.querySelector('span');
            if (span) {
                span.textContent = newState === 'y' ? '取消草稿' : '设为草稿';
            }
        })
        .catch(function () {
            if (typeof warnpop === 'function') {
                warnpop('操作失败');
            } else {
                alert('操作失败');
            }
        });
};

window.luminaToggleTop = function (el) {
    if (!el) return;
    var state = el.getAttribute('data-top-state') || 'n';
    var next = state === 'y' ? 'n' : 'y';
    if (typeof loadpop === 'function') {
        loadpop('正在处理...', 'ok');
    }
    luminaAdminPostAction(el, 'post_toggle_top', { top: next })
        .then(function (resp) {
            var newState = resp.data && resp.data.top ? resp.data.top : next;
            el.setAttribute('data-top-state', newState);
            var span = el.querySelector('span');
            if (span) {
                span.textContent = newState === 'y' ? '取消置顶' : '设为置顶';
            }
            luminaAdminSyncDetailBadge('top', newState === 'y');
            if (typeof successpop === 'function') {
                successpop(newState === 'y' ? '已置顶' : '已取消置顶');
            }
        })
        .catch(function (err) {
            if (typeof warnpop === 'function') {
                warnpop(err.message || '操作失败');
            } else {
                alert(err.message || '操作失败');
            }
        });
};

window.luminaToggleOnlyMe = function (el) {
    if (!el) return;
    var state = el.getAttribute('data-private-state') || 'n';
    var next = state === 'y' ? 'n' : 'y';
    if (next === 'y' && !confirm('设为仅自己可看后，未登录和其他用户将不可见，确定继续吗？')) {
        return;
    }
    if (typeof loadpop === 'function') {
        loadpop('正在处理...', 'ok');
    }
    luminaAdminPostAction(el, 'post_toggle_private', { private: next })
        .then(function (resp) {
            var newState = resp.data && resp.data.private ? resp.data.private : next;
            el.setAttribute('data-private-state', newState);
            var span = el.querySelector('span');
            if (span) {
                span.textContent = newState === 'y' ? '取消仅自己可看' : '仅自己可看';
            }
            luminaAdminSyncDetailBadge('private', newState === 'y');
            if (typeof successpop === 'function') {
                successpop(newState === 'y' ? '已设为仅自己可看' : '已恢复公开');
            }
        })
        .catch(function (err) {
            if (typeof warnpop === 'function') {
                warnpop(err.message || '操作失败');
            } else {
                alert(err.message || '操作失败');
            }
        });
};

window.luminaDeleteLog = function (el) {
    if (!el) return;
    var url = el.getAttribute('data-del-url') || '';
    if (!url) return;
    if (!confirm('确定要删除此文章吗？')) {
        return;
    }
    if (typeof loadpop === 'function') {
        loadpop('正在删除...', 'ok');
    }
    fetch(url, { credentials: 'same-origin' })
        .then(function () {
            if (typeof successpop === 'function') {
                successpop('已删除');
            }
            var msg = document.getElementById('setup-view-title');
            if (msg) {
                msg.textContent = '已删除';
            }
            var content = document.querySelector('.sh-content');
            if (content) {
                content.style.opacity = '0.4';
                content.style.pointerEvents = 'none';
            }
        })
        .catch(function () {
            if (typeof warnpop === 'function') {
                warnpop('删除失败');
            } else {
                alert('删除失败');
            }
        });
};

if (typeof window.hfljurl !== 'function') {
    window.hfljurl = function () {
        var e = window.event;
        if (e && e.stopPropagation) {
            e.stopPropagation();
        }
    };
}
if (typeof window.js_menu !== 'function') {
    window.js_menu = function () {
        var menu = document.getElementById('js_menu');
        if (!menu) return;
        menu.style.display = (menu.style.display === 'none' || menu.style.display === '') ? 'flex' : 'none';
    };
}
if (typeof window.xxsczt !== 'function') {
    window.xxsczt = function () {
        var e = window.event;
        if (e && e.stopPropagation) e.stopPropagation();
        var toggle = document.getElementById('xxsczt');
        var container = document.getElementById('sh-news-con');
        if (!toggle || !container) return;
        var selecting = toggle.getAttribute('lang') === '-1';
        toggle.setAttribute('lang', selecting ? '0' : '-1');
        toggle.textContent = selecting ? '选择消息' : '取消选择';
        container.classList.toggle('lumina-news-select', !selecting);
        if (selecting) {
            var selected = container.querySelectorAll('.lumina-news-selected');
            luminaEach(selected, function (item) {
                item.classList.remove('lumina-news-selected');
            });
        }
        var menu = document.getElementById('js_menu');
        if (menu) menu.style.display = 'none';
    };
}
if (typeof window.xxscyd !== 'function') {
    window.xxscyd = function () {
        var dots = document.querySelectorAll('#sh-news-con .xiaoxhd, #lumina-notice-badge');
        luminaEach(dots, function (dot) {
            dot.style.display = 'none';
        });
        var menu = document.getElementById('js_menu');
        if (menu) menu.style.display = 'none';
    };
}
if (typeof window.xxscztSelected !== 'function') {
    window.xxscztSelected = function () {
        var e = window.event;
        if (e && e.stopPropagation) e.stopPropagation();
        var container = document.getElementById('sh-news-con');
        if (!container) return;
        var selected = container.querySelectorAll('.lumina-news-selected');
        luminaEach(selected, function (item) {
            item.remove();
        });
        var menu = document.getElementById('js_menu');
        if (menu) menu.style.display = 'none';
    };
}
if (typeof window.xxscztqb !== 'function') {
    window.xxscztqb = function () {
        var container = document.getElementById('sh-news-con');
        if (!container) return;
        container.innerHTML = '';
        var menu = document.getElementById('js_menu');
        if (menu) menu.style.display = 'none';
    };
}
if (typeof window.mesgxq !== 'function') {
    window.mesgxq = function () {
        var e = window.event;
        if (e && e.stopPropagation) e.stopPropagation();
        var target = e && e.target ? e.target : null;
        var item = target && target.closest ? target.closest('.sh-news-con-lie') : null;
        if (!item) return;
        var selecting = document.getElementById('xxsczt') && document.getElementById('xxsczt').getAttribute('lang') === '-1';
        if (selecting) {
            item.classList.toggle('lumina-news-selected');
            return;
        }
        var dot = item.querySelector('.xiaoxhd');
        if (dot) dot.style.display = 'none';
        var href = item.getAttribute('data-href') || item.getAttribute('lang');
        if (href) window.location.href = href;
    };
}
if (typeof window.demes !== 'function') {
    window.demes = function () {
        var e = window.event;
        if (e && e.stopPropagation) e.stopPropagation();
        var target = e && e.target ? e.target : null;
        var item = target && target.closest ? target.closest('.sh-news-con-lie') : null;
        if (item) {
            item.remove();
        }
    };
}

function luminaInlineHandlerStatus() {
    var names = {};
    var nodes = document.querySelectorAll('[onclick], [onchange], [oninput], [onsubmit]');
    luminaEach(nodes, function (node) {
        var attrs = ['onclick', 'onchange', 'oninput', 'onsubmit'];
        attrs.forEach(function (attr) {
            var code = node.getAttribute(attr) || '';
            code.replace(/\b([A-Za-z_$][\w$]*)\s*\(/g, function (_, name) {
                if (['if', 'return', 'location', 'history', 'confirm', 'event', 'window', 'back', 'stopPropagation'].indexOf(name) === -1) {
                    names[name] = true;
                }
                return _;
            });
        });
    });
    var missing = [];
    Object.keys(names).sort().forEach(function (name) {
        if (typeof window[name] !== 'function') {
            missing.push(name);
        }
    });
    return {
        total: Object.keys(names).length,
        missing: missing
    };
}
window.luminaInlineHandlerStatus = luminaInlineHandlerStatus;

document.addEventListener('DOMContentLoaded', function () {
    luminaRefreshDynamicContent();
    setTimeout(function () {
        luminaAlignTopBar();
        luminaSyncTopBarState();
    }, 60);
});

function luminaToggleLike() {
    var tieIdEl = document.getElementById('sh-tieid');
    if (!tieIdEl) return;
    var id = tieIdEl.innerText;
    if (!id) return;
    var textEl = document.getElementById('tiezdz-' + id);
    var iconEl = document.getElementById('tiezimg-' + id);
    if (!textEl || !iconEl) return;

    var apiCfg = window.LUMINA_API || {};
    var liked = textEl.dataset.liked !== undefined
        ? textEl.dataset.liked === '1'
        : (textEl.innerText !== '赞' && textEl.innerText !== '在看');
    var isLogin = window.LUMINA && window.LUMINA.isLogin;
    var useGuestUnlike = liked && !isLogin && apiCfg.guestUnlike;
    var url = liked
        ? (useGuestUnlike ? apiCfg.guestUnlike : (apiCfg.unlike || ((window.LUMINA_BASE || '/') + 'index.php?action=unlike')))
        : (apiCfg.like || ((window.LUMINA_BASE || '/') + 'index.php?action=addlike'));
    var data = 'gid=' + encodeURIComponent(id);
    var name = (window.LUMINA && window.LUMINA.userName) ? window.LUMINA.userName : '';
    if (!isLogin) {
        var visName = document.getElementById('vis_name');
        if (visName && visName.value) {
            name = visName.value;
        }
        if (!name) {
            name = '一名游客';
        }
    }
    if (!liked && name) {
        data += '&name=' + encodeURIComponent(name);
    }
    if (useGuestUnlike) {
        data += '&lumina_action=guest_unlike';
        if (apiCfg.token) {
            data += '&token=' + encodeURIComponent(apiCfg.token);
        }
    }

    var likeWrap = document.getElementById('zans-' + id);
    var wrap = document.getElementById('zanss-' + id);
    var list = document.getElementById('zlbeh-' + id) || document.getElementById('sh-zanp-ul');
    var commentList = document.getElementById('sh-zanp-pl-' + id);
    if (textEl.dataset.luminaLikePending === '1') {
        return;
    }

    var hideMenu = function () {
        var panel = document.getElementById('pl-' + id);
        if (panel) {
            panel.style.display = 'none';
        }
    };
    var setPending = function (pending) {
        textEl.dataset.luminaLikePending = pending ? '1' : '0';
        textEl.style.opacity = pending ? '0.56' : '';
        iconEl.style.opacity = pending ? '0.56' : '';
    };

    var xhr = new XMLHttpRequest();
    xhr.open('POST', url);
    xhr.setRequestHeader('Content-type', 'application/x-www-form-urlencoded');
    xhr.onreadystatechange = function () {
        if (xhr.readyState !== 4) return;
        setPending(false);
        var resp;
        try {
            resp = JSON.parse(xhr.responseText);
        } catch (e) {
            if (typeof warnpop === 'function') {
                warnpop('响应解析失败');
            }
            hideMenu();
            return;
        }
        if (xhr.status !== 200 || !resp || resp.code !== 0) {
            if (typeof warnpop === 'function') {
                var msg = resp && resp.msg ? resp.msg : '操作失败';
                if (!isLogin && !liked) {
                    msg = msg || '当前网络已点过赞';
                    if (/已|already|赞/i.test(msg)) {
                        msg = '当前网络已点过赞';
                    }
                }
                warnpop(msg);
            }
            hideMenu();
            return;
        }
        if (resp && resp.code === 0) {
            if (liked) {
                textEl.innerText = '赞';
                iconEl.className = 'iconfont icon-aixin ri-sxdzlike';
                if (textEl.dataset.liked !== undefined) {
                    textEl.dataset.liked = '0';
                    var _n = parseInt(textEl.innerText, 10);
                    textEl.innerText = (!isNaN(_n) && _n > 1) ? String(_n - 1) : '在看';
                }
                var _countTextUnlike = textEl.dataset.likeCountId && document.getElementById(textEl.dataset.likeCountId);
                if (_countTextUnlike) {
                    var _cu = parseInt(_countTextUnlike.textContent, 10);
                    _countTextUnlike.textContent = (!isNaN(_cu) && _cu > 1) ? String(_cu - 1) + '人觉得很赞' : '';
                    if (likeWrap) { likeWrap.style.display = _countTextUnlike.textContent ? '' : 'none'; }
                }
                if (typeof successpop === 'function') {
                    successpop('点赞取消');
                }
                if (!isLogin && list) {
                    var guestItem = document.getElementById('fkzan-' + id);
                    if (guestItem) {
                        var guestCount = parseInt(guestItem.textContent, 10);
                        if (isNaN(guestCount) || guestCount <= 1) {
                            guestItem.remove();
                        } else {
                            guestItem.textContent = (guestCount - 1) + '位访客';
                        }
                    }
                } else if (list && name) {
                    var items = list.querySelectorAll('li');
                    for (var i = items.length - 1; i >= 0; i--) {
                        var li = items[i];
                        var liName = li.getAttribute('data-name') || li.textContent;
                        if (liName === name) {
                            li.remove();
                            break;
                        }
                    }
                }
                if (likeWrap && list && list.children.length === 0) {
                    likeWrap.style.display = 'none';
                }
            } else {
                textEl.innerText = '取消';
                iconEl.className = 'iconfont icon-aixin2 ri-sxdzlikehs';
                if (textEl.dataset.liked !== undefined) {
                    textEl.dataset.liked = '1';
                    var _n2 = parseInt(textEl.innerText, 10);
                    textEl.innerText = !isNaN(_n2) ? String(_n2 + 1) : '1';
                }
                var _countTextLike = textEl.dataset.likeCountId && document.getElementById(textEl.dataset.likeCountId);
                if (_countTextLike) {
                    var _cl = parseInt(_countTextLike.textContent, 10);
                    _countTextLike.textContent = (!isNaN(_cl) && _cl > 0) ? String(_cl + 1) + '人觉得很赞' : '1人觉得很赞';
                    if (likeWrap) { likeWrap.style.display = 'flex'; }
                }
                if (typeof successpop === 'function') {
                    successpop('点赞成功');
                }
                if (likeWrap) {
                    likeWrap.style.display = 'flex';
                }
                if (wrap) {
                    wrap.style.display = 'block';
                }
                if (list && name) {
                    if (!isLogin) {
                        var guestItem = document.getElementById('fkzan-' + id);
                        if (!guestItem) {
                            guestItem = document.createElement('li');
                            guestItem.id = 'fkzan-' + id;
                            guestItem.textContent = '1位访客';
                            list.appendChild(guestItem);
                        } else {
                            var count = parseInt(guestItem.textContent, 10);
                            if (isNaN(count)) count = 0;
                            guestItem.textContent = (count + 1) + '位访客';
                        }
                    } else {
                        var exists = false;
                        var items2 = list.querySelectorAll('li');
                        for (var j = 0; j < items2.length; j++) {
                            var li2 = items2[j];
                            var li2Name = li2.getAttribute('data-name') || li2.textContent;
                            if (li2Name === name) {
                                exists = true;
                                break;
                            }
                        }
                        if (!exists) {
                            var liAdd = document.createElement('li');
                            liAdd.setAttribute('data-name', name);
                            liAdd.textContent = name;
                            var guest = document.getElementById('fkzan-' + id);
                            if (guest) {
                                list.insertBefore(liAdd, guest);
                            } else {
                                list.appendChild(liAdd);
                            }
                        }
                    }
                }
            }
            if (wrap && list && list.children.length === 0 && (!commentList || commentList.children.length === 0)) {
                wrap.style.display = 'none';
            }
            hideMenu();
        }
    };
    hideMenu();
    setPending(true);
    xhr.send(data);
}

window.dinazan = luminaToggleLike;
window.dinazanv = luminaToggleLike;

function luminaInlineEventId() {
    var ev = window.event || null;
    var node = ev && (ev.target || ev.srcElement) ? (ev.target || ev.srcElement) : null;
    while (node && !node.id) {
        node = node.parentElement;
    }
    return node && node.id ? node.id : '';
}

function luminaOpenActionMenu() {
    var ele = luminaInlineEventId();
    if (!ele) return;
    var panel = document.getElementById('pl-' + ele);
    if (!panel) return;
    var willOpen = panel.style.display !== 'flex';
    var arrs = document.getElementsByName('pl');
    for (var i = 0; i < arrs.length; i++) {
        if (arrs[i]) {
            arrs[i].style.display = 'none';
        }
    }
    panel.style.display = willOpen ? 'flex' : 'none';
    var tieId = document.getElementById('sh-tieid');
    if (tieId) {
        tieId.innerText = ele;
    }
}

function luminaToggleCommentBox(postId) {
    var ele = postId || luminaInlineEventId();
    if (!ele) return;
    var tieId = document.getElementById('sh-tieid');
    if (tieId) {
        tieId.innerText = ele;
    }
    var list = document.getElementById('sh-zanp-pl-' + ele);
    var box = document.getElementById('pinglunkuang');
    if (!list || !box) return;
    var wrap = document.getElementById('zanss-' + ele);
    if (wrap && (wrap.style.display === 'none' || wrap.style.display === '')) {
        wrap.style.display = 'block';
    }
    if (list.style.display === 'none' || list.style.display === '') {
        list.style.display = 'block';
    }
    if (box.parentNode === list) {
        var holder = document.getElementById('pinglunkfk');
        if (holder) holder.appendChild(box);
        if (!list.children.length) list.style.display = 'none';
    } else {
        list.insertBefore(box, list.firstChild);
        var reply = document.getElementById('sh-tiehf');
        var email = document.getElementById('sh-tieea');
        var pid = document.getElementById('sh-tiepid');
        var textarea = document.getElementById('bletext');
        if (reply) reply.innerText = 'false';
        if (email) email.innerText = 'false';
        if (pid) pid.innerText = '0';
        if (textarea) {
            textarea.placeholder = '评论';
            textarea.focus();
        }
    }
    var panel = document.getElementById('pl-' + ele);
    if (panel) panel.style.display = 'none';
}

function luminaSubmitComment() {
    var tieId = document.getElementById('sh-tieid');
    var textarea = document.getElementById('bletext');
    if (!tieId || !textarea) return;
    var id = (tieId.innerText || '').trim();
    var text = (textarea.value || '').trim();
    if (!id || id === '-') return;
    if (!text) {
        if (typeof warnpop === 'function') warnpop('请输入评论内容');
        textarea.focus();
        return;
    }

    var isLogin = window.LUMINA && window.LUMINA.isLogin;
    var allowGuest = window.LUMINA && window.LUMINA.allowGuest;
    var guestBox = document.getElementById('sh-plk-yk');
    var visName = document.getElementById('vis_name');
    var visEmail = document.getElementById('vis_email');
    var visUrl = document.getElementById('vis_url');

    if (!isLogin) {
        if (!allowGuest || !guestBox) {
            if (typeof warnpop === 'function') warnpop('请先登录');
            return;
        }
        if (!visName || !visEmail || !visName.value || !visEmail.value) {
            if (guestBox.style.display !== 'flex') {
                guestBox.style.display = 'flex';
                var guestIcon = document.getElementById('sh-pinglun-fs-right-ykkgb');
                if (guestIcon) guestIcon.className = 'iconfont icon-yonghu1 ri-sxbqxzls';
            }
            return;
        }
        if (!/^\w+@\w+\.\w+$/i.test(visEmail.value)) {
            if (typeof warnpop === 'function') warnpop('邮箱格式不正确');
            return;
        }
        if (visUrl && visUrl.value && !/^(https?:\/\/)?([\w.-]+)\.([a-z]{2,})(\/\S*)?$/i.test(visUrl.value)) {
            if (typeof warnpop === 'function') warnpop('网址格式不正确');
            return;
        }
    }

    var imgcodeInput = document.querySelector('input[name="imgcode"]');
    if (imgcodeInput && !imgcodeInput.value.trim()) {
        if (typeof warnpop === 'function') warnpop('请输入验证码');
        imgcodeInput.focus();
        return;
    }

    var form = document.createElement('form');
    form.method = 'post';
    form.action = (window.LUMINA_BASE || '/') + 'index.php?action=addcom';
    var addField = function (name, value) {
        var input = document.createElement('input');
        input.type = 'hidden';
        input.name = name;
        input.value = value || '';
        form.appendChild(input);
    };
    addField('gid', id);
    addField('pid', document.getElementById('sh-tiepid') ? document.getElementById('sh-tiepid').innerText : '0');
    addField('comment', text);
    if (!isLogin && allowGuest) {
        addField('comname', visName ? visName.value : '');
        addField('commail', visEmail ? visEmail.value : '');
        addField('comurl', visUrl ? visUrl.value : '');
    }
    if (imgcodeInput && imgcodeInput.value.trim()) {
        addField('imgcode', imgcodeInput.value.trim());
    }
    document.body.appendChild(form);
    form.submit();
}

function luminaRefreshLegacyGlobals() {
    window.dinazan = luminaToggleLike;
    window.dinazanv = luminaToggleLike;
    window.plk = luminaOpenActionMenu;
    window.plkkg = luminaToggleCommentBox;
    window.fasong = luminaSubmitComment;
    window.fasongv = luminaSubmitComment;
}
window.luminaRefreshLegacyGlobals = luminaRefreshLegacyGlobals;
luminaRefreshLegacyGlobals();

function luminaMusicMiniState() {
    if (!window.LUMINA) window.LUMINA = {};
    return window.LUMINA.musicMini || (window.LUMINA.musicMini = {
        audio: null,
        card: null,
        bound: false,
        dragBound: false,
        moved: false
    });
}

function luminaMusicMiniElements() {
    return {
        wrap: document.getElementById('lumina-music-mini'),
        cover: document.getElementById('lumina-music-mini-cover'),
        toggle: document.getElementById('lumina-music-mini-toggle'),
        close: document.getElementById('lumina-music-mini-close')
    };
}

function luminaMusicCardMeta(card) {
    if (!card) {
        return {
            title: '未命名音乐',
            artist: '',
            cover: ''
        };
    }
    var title = card.getAttribute('data-track-title') || '';
    var artist = card.getAttribute('data-track-artist') || '';
    var cover = card.getAttribute('data-track-cover') || '';
    var coverImg = card.querySelector('.lumina-music-cover img');
    if (!title) {
        var titleEl = card.querySelector('.lumina-music-title');
        title = titleEl ? titleEl.textContent.trim() : '未命名音乐';
    }
    if (!artist) {
        var artistEl = card.querySelector('.lumina-music-subtitle');
        artist = artistEl ? artistEl.textContent.trim() : '';
    }
    if (!cover && coverImg) {
        cover = coverImg.getAttribute('data-src') || coverImg.getAttribute('src') || '';
    }
    return {
        title: title || '未命名音乐',
        artist: artist || '',
        cover: cover || ''
    };
}

function luminaBindMusicMini() {
    var state = luminaMusicMiniState();
    if (state.bound) return;
    var mini = luminaMusicMiniElements();
    if (!mini.wrap || !mini.toggle || !mini.close) return;

    mini.toggle.addEventListener('click', function (e) {
        e.preventDefault();
        e.stopPropagation();
        var currentState = luminaMusicMiniState();
        if (!currentState.audio) return;
        if (currentState.audio.paused) {
            currentState.audio.play().catch(function () {});
        } else {
            currentState.audio.pause();
        }
    });

    mini.close.addEventListener('click', function (e) {
        e.preventDefault();
        e.stopPropagation();
        var currentState = luminaMusicMiniState();
        if (currentState.audio && !currentState.audio.paused) {
            currentState.audio.pause();
        }
        luminaHideMusicMini();
    });

    state.bound = true;
}

function luminaClampMusicMiniPosition(left, top, wrap) {
    var width = wrap ? wrap.offsetWidth || 110 : 110;
    var height = wrap ? wrap.offsetHeight || 110 : 110;
    var maxLeft = Math.max(8, window.innerWidth - width - 8);
    var maxTop = Math.max(8, window.innerHeight - height - 8);
    return {
        left: Math.max(8, Math.min(left, maxLeft)),
        top: Math.max(8, Math.min(top, maxTop))
    };
}

function luminaApplyMusicMiniDock(wrap, dock) {
    if (!wrap) return;
    wrap.classList.toggle('is-docked-right', dock === 'right');
    wrap.classList.toggle('is-docked-left', dock !== 'right');
}

function luminaSnapMusicMiniPosition(left, top, wrap, dockHint) {
    var next = luminaClampMusicMiniPosition(left, top, wrap);
    var maxLeft = Math.max(8, window.innerWidth - ((wrap ? wrap.offsetWidth : 110) || 110) - 8);
    var dock = dockHint;
    if (dock !== 'left' && dock !== 'right') {
        var center = next.left + ((((wrap ? wrap.offsetWidth : 110) || 110)) / 2);
        dock = center >= (window.innerWidth / 2) ? 'right' : 'left';
    }
    next.left = dock === 'right' ? maxLeft : 8;
    next.dock = dock;
    return next;
}

function luminaSaveMusicMiniPosition(left, top, dock) {
    try {
        window.localStorage.setItem('lumina_music_mini_pos', JSON.stringify({
            left: Math.round(left),
            top: Math.round(top),
            dock: dock === 'right' ? 'right' : 'left'
        }));
    } catch (e) {}
}

function luminaRestoreMusicMiniPosition() {
    var mini = luminaMusicMiniElements();
    if (!mini.wrap) return;
    try {
        var raw = window.localStorage.getItem('lumina_music_mini_pos');
        if (!raw) return;
        var pos = JSON.parse(raw);
        if (typeof pos.left !== 'number' || typeof pos.top !== 'number') return;
        var next = luminaSnapMusicMiniPosition(pos.left, pos.top, mini.wrap, pos.dock);
        mini.wrap.style.left = next.left + 'px';
        mini.wrap.style.top = next.top + 'px';
        luminaApplyMusicMiniDock(mini.wrap, next.dock);
    } catch (e) {}
}

function luminaBindMusicMiniDrag() {
    var state = luminaMusicMiniState();
    if (state.dragBound) return;
    var mini = luminaMusicMiniElements();
    if (!mini.wrap) return;

    luminaRestoreMusicMiniPosition();

    var drag = {
        active: false,
        pointerId: null,
        startX: 0,
        startY: 0,
        originLeft: 0,
        originTop: 0
    };

    mini.wrap.addEventListener('pointerdown', function (e) {
        if (e.target && e.target.closest('button')) return;
        if (e.button !== undefined && e.button !== 0) return;
        state.moved = false;
        drag.active = true;
        drag.pointerId = e.pointerId;
        drag.startX = e.clientX;
        drag.startY = e.clientY;
        drag.originLeft = mini.wrap.offsetLeft;
        drag.originTop = mini.wrap.offsetTop;
        mini.wrap.classList.add('is-dragging');
        if (mini.wrap.setPointerCapture) {
            mini.wrap.setPointerCapture(e.pointerId);
        }
    });

    mini.wrap.addEventListener('pointermove', function (e) {
        if (!drag.active || drag.pointerId !== e.pointerId) return;
        var deltaX = e.clientX - drag.startX;
        var deltaY = e.clientY - drag.startY;
        if (!state.moved && (Math.abs(deltaX) > 4 || Math.abs(deltaY) > 4)) {
            state.moved = true;
        }
        var next = luminaClampMusicMiniPosition(drag.originLeft + deltaX, drag.originTop + deltaY, mini.wrap);
        mini.wrap.style.left = next.left + 'px';
        mini.wrap.style.top = next.top + 'px';
        e.preventDefault();
    });

    function endDrag(e) {
        if (!drag.active) return;
        if (e && drag.pointerId !== null && e.pointerId !== undefined && drag.pointerId !== e.pointerId) return;
        drag.active = false;
        mini.wrap.classList.remove('is-dragging');
        if (drag.pointerId !== null && mini.wrap.releasePointerCapture) {
            try {
                mini.wrap.releasePointerCapture(drag.pointerId);
            } catch (err) {}
        }
        drag.pointerId = null;
        var snapped = luminaSnapMusicMiniPosition(mini.wrap.offsetLeft, mini.wrap.offsetTop, mini.wrap);
        mini.wrap.style.left = snapped.left + 'px';
        mini.wrap.style.top = snapped.top + 'px';
        luminaApplyMusicMiniDock(mini.wrap, snapped.dock);
        luminaSaveMusicMiniPosition(snapped.left, snapped.top, snapped.dock);
    }

    mini.wrap.addEventListener('pointerup', endDrag);
    mini.wrap.addEventListener('pointercancel', endDrag);
    window.addEventListener('resize', function () {
        var dock = mini.wrap.classList.contains('is-docked-right') ? 'right' : 'left';
        var next = luminaSnapMusicMiniPosition(mini.wrap.offsetLeft, mini.wrap.offsetTop, mini.wrap, dock);
        mini.wrap.style.left = next.left + 'px';
        mini.wrap.style.top = next.top + 'px';
        luminaApplyMusicMiniDock(mini.wrap, next.dock);
        luminaSaveMusicMiniPosition(next.left, next.top, next.dock);
    });

    state.dragBound = true;
}

function luminaShowMusicMini(card, audio) {
    var mini = luminaMusicMiniElements();
    if (!mini.wrap) return;
    luminaBindMusicMini();
    luminaBindMusicMiniDrag();
    var state = luminaMusicMiniState();
    var meta = luminaMusicCardMeta(card);
    if (mini.cover) {
        var fallbackCover = mini.cover.getAttribute('data-default-src') || mini.cover.getAttribute('src') || '';
        mini.cover.setAttribute('src', meta.cover || fallbackCover);
    }
    mini.wrap.classList.add('is-visible');
    mini.wrap.setAttribute('aria-hidden', 'false');
    mini.wrap.setAttribute('data-state', audio && !audio.paused ? 'playing' : 'paused');
    mini.wrap.setAttribute('title', meta.artist ? (meta.title + ' - ' + meta.artist) : meta.title);
    if (!mini.wrap.classList.contains('is-docked-right') && !mini.wrap.classList.contains('is-docked-left')) {
        luminaApplyMusicMiniDock(mini.wrap, 'left');
    }
    if (mini.toggle) {
        mini.toggle.setAttribute('aria-label', audio && !audio.paused ? '暂停播放' : '继续播放');
    }
    state.audio = audio || null;
    state.card = card || null;
}

function luminaHideMusicMini() {
    var mini = luminaMusicMiniElements();
    if (mini.wrap) {
        mini.wrap.classList.remove('is-visible');
        mini.wrap.setAttribute('aria-hidden', 'true');
        mini.wrap.removeAttribute('data-state');
    }
    var state = luminaMusicMiniState();
    state.audio = null;
    state.card = null;
}

function luminaInitMusicCards() {
    luminaBindMusicMini();
    luminaBindMusicMiniDrag();
    var cards = document.querySelectorAll('.lumina-music-card');
    luminaEach(cards, function (card) {
        if (card.dataset && card.dataset.luminaMusicInit) return;
        var audio = card.querySelector('audio');
        var btn = card.querySelector('.lumina-music-btn');
        if (!audio || !btn) return;

        function stopOthers() {
            luminaEach(cards, function (other) {
                if (other !== card) {
                    var otherAudio = other.querySelector('audio');
                    if (otherAudio && !otherAudio.paused) {
                        otherAudio.pause();
                    }
                }
            });
            var globalAudio = document.getElementById('musicplay');
            if (globalAudio && !globalAudio.paused) {
                globalAudio.pause();
            }
        }

        btn.addEventListener('click', function (e) {
            e.stopPropagation();
            e.preventDefault();
            if (audio.paused) {
                stopOthers();
                audio.play().catch(function () {});
            } else {
                audio.pause();
            }
        });

        audio.addEventListener('play', function () {
            card.classList.add('is-playing');
            luminaShowMusicMini(card, audio);
        });
        audio.addEventListener('pause', function () {
            card.classList.remove('is-playing');
            var miniState = luminaMusicMiniState();
            if (miniState.audio === audio) {
                luminaShowMusicMini(card, audio);
            }
        });
        audio.addEventListener('ended', function () {
            card.classList.remove('is-playing');
            var miniState = luminaMusicMiniState();
            if (miniState.audio === audio) {
                luminaHideMusicMini();
            }
        });

        card.dataset.luminaMusicInit = '1';
    });
}
window.luminaInitMusicCards = luminaInitMusicCards;
document.addEventListener('DOMContentLoaded', luminaInitMusicCards);

function luminaInitLivePreview() {
    var previewCard = document.getElementById('lumina-preview-card');
    if (!previewCard) return;
    var previewSummary = document.getElementById('lumina-preview-summary');
    var previewPills = document.getElementById('lumina-preview-pills');
    var previewList = document.getElementById('lumina-preview-list');
    var previewTip = document.getElementById('lumina-preview-tip');
    var contentInput = document.getElementById('lumina-content');
    var typeField = document.getElementById('lumina-type');
    var photosField = document.getElementById('lumina-photos');
    var liveCoverField = document.getElementById('lumina-live-photos-cover');
    var livePhotosField = document.getElementById('lumina-live-photos');
    var videoField = document.getElementById('lumina-video');
    var videoPosterField = document.getElementById('lumina-video-poster');
    var embedField = document.getElementById('lumina-embed');
    var embedRatioField = document.getElementById('lumina-embed-ratio');
    var musicField = document.getElementById('lumina-music');
    var musicTitleField = document.getElementById('lumina-music-title');
    var musicArtistField = document.getElementById('lumina-music-artist');
    var musicCoverField = document.getElementById('lumina-music-cover');
    var linkUrlField = document.getElementById('lumina-link-url');
    var linkTitleField = document.getElementById('lumina-link-title');
    var linkImageField = document.getElementById('lumina-link-image');
    var redpacketTotalInput = document.getElementById('lumina-redpacket-total');
    var redpacketCountInput = document.getElementById('lumina-redpacket-count');
    var redpacketModeInput = document.getElementById('lumina-redpacket-mode');
    var redpacketTitleInput = document.getElementById('lumina-redpacket-title');
    var locationInput = document.getElementById('lumina-location');
    var sortField = document.querySelector('select[name="sort_id"]');
    var tagsField = document.querySelector('input[name="tags"]');
    var allowRemarkInput = document.getElementById('lumina-allow-remark-input');
    var draftInput = document.getElementById('lumina-draft-input');
    var topInput = document.getElementById('lumina-top-input');

    function splitLines(val) {
        if (!val) return [];
        return val.split(/[\r\n,]+/).map(function (v) { return (v || '').trim(); }).filter(function (v) { return v; });
    }

    function clearNode(node) {
        if (!node) return;
        while (node.firstChild) {
            node.removeChild(node.firstChild);
        }
    }

    function normalizeText(text) {
        return String(text || '').replace(/\s+/g, ' ').trim();
    }

    function excerptText(text, max) {
        var cleaned = normalizeText(text);
        if (!cleaned) return '还没有输入内容';
        max = typeof max === 'number' ? max : 44;
        if (cleaned.length <= max) return cleaned;
        return cleaned.slice(0, max) + '...';
    }

    function currentType() {
        var type = typeField ? String(typeField.value || '').trim() : '';
        var photos = splitLines(type === 'live' && liveCoverField ? liveCoverField.value : (photosField ? photosField.value : ''));
        var videos = splitLines(videoField ? videoField.value : '');
        var embedUrl = normalizeText(embedField ? embedField.value : '');
        var musicLines = splitLines(musicField ? musicField.value : '');
        var hasPhotos = photos.length > 0;
        var hasVideos = videos.length > 0;
        var hasEmbed = !!embedUrl;
        var hasMusic = musicLines.length > 0 || !!normalizeText(musicField ? musicField.value : '');
        var hasLink = !!normalizeText(linkUrlField ? linkUrlField.value : '');
        var hasRedpacket = !!normalizeText(redpacketTotalInput ? redpacketTotalInput.value : '') || !!normalizeText(redpacketCountInput ? redpacketCountInput.value : '');
        if (type === 'redpacket' || hasRedpacket) return 'redpacket';
        if (type === 'embed' && hasEmbed) return 'embed';
        if (type === 'video' && hasVideos) return 'video';
        if (type === 'music' && hasMusic) return 'music';
        if (type === 'link' && hasLink) return 'link';
        if (type === 'live') return 'live';
        if (type === 'img' && hasPhotos) return 'img';
        if (hasVideos) return 'video';
        if (hasEmbed) return 'embed';
        if (hasMusic) return 'music';
        if (hasPhotos) return 'img';
        if (hasLink) return 'link';
        return 'only';
    }

    function typeLabel(type) {
        if (type === 'img') return '图文';
        if (type === 'live') return '实况图';
        if (type === 'video') return '视频';
        if (type === 'embed') return '平台视频';
        if (type === 'music') return '音乐';
        if (type === 'link') return '链接';
        if (type === 'redpacket') return '红包';
        return '纯文字';
    }

    function selectedSortText() {
        if (!sortField) return '未分类';
        var idx = sortField.selectedIndex;
        var option = idx >= 0 ? sortField.options[idx] : null;
        return normalizeText(option ? option.text : '') || '未分类';
    }

    function renderPills(items) {
        clearNode(previewPills);
        if (!previewPills) return;
        items.forEach(function (item) {
            if (!item || !item.text) return;
            var pill = document.createElement('span');
            pill.className = 'lumina-pill' + (item.active ? ' is-active' : '');
            pill.textContent = item.text;
            previewPills.appendChild(pill);
        });
    }

    function renderList(items) {
        clearNode(previewList);
        if (!previewList) return;
        items.forEach(function (item) {
            if (!item || !item.label) return;
            var row = document.createElement('div');
            row.className = 'lumina-side-list-item lumina-helper-item';
            var key = document.createElement('div');
            key.className = 'lumina-helper-item-key';
            key.textContent = item.label;
            var value = document.createElement('div');
            value.className = 'lumina-helper-item-value';
            value.textContent = item.value || '未设置';
            row.appendChild(key);
            row.appendChild(value);
            previewList.appendChild(row);
        });
    }

    function mediaSummary(type, photos, videos, musicLines) {
        var livePhotos = splitLines(livePhotosField ? livePhotosField.value : '');
        if (type === 'live') {
            if (photos.length && livePhotos.length) {
                return '已选 ' + photos.length + ' 张图片 / ' + livePhotos.length + ' 个实况';
            }
            return photos.length ? ('已选 ' + photos.length + ' 张图片，待补实况视频') : '还没有添加实况图';
        }
        if (type === 'img') {
            return photos.length ? ('已选 ' + photos.length + ' 张图片') : '还没有添加图片';
        }
        if (type === 'video') {
            return videos.length ? ('已填 ' + videos.length + ' 条视频地址') : '还没有添加视频';
        }
        if (type === 'embed') {
            var ratio = embedRatioField && embedRatioField.value === 'tb' ? '竖屏' : '横屏';
            return normalizeText(embedField ? embedField.value : '') ? ('已添加' + ratio + '平台视频') : '还没有添加平台视频';
        }
        if (type === 'music') {
            var count = musicLines.length || (normalizeText(musicField ? musicField.value : '') ? 1 : 0);
            return count ? ('已填 ' + count + ' 条音乐') : '还没有添加音乐';
        }
        if (type === 'link') {
            var linkTitle = normalizeText(linkTitleField ? linkTitleField.value : '');
            return linkTitle ? ('链接卡片：' + linkTitle) : '已添加链接卡片';
        }
        if (type === 'redpacket') {
            var total = normalizeText(redpacketTotalInput ? redpacketTotalInput.value : '') || '0';
            var countValue = normalizeText(redpacketCountInput ? redpacketCountInput.value : '') || '0';
            return '总积分 ' + total + ' / 数量 ' + countValue;
        }
        return '当前为纯文字发布';
    }

    function helperTip(type, photos, videos, musicLines) {
        var livePhotos = splitLines(livePhotosField ? livePhotosField.value : '');
        if (type === 'live') {
            if (photos.length && livePhotos.length) {
                return '实况图已准备好，发布后点击图片会播放短视频。';
            }
            return '实况图需要同时填写图片和短视频，短视频按图片顺序配对。';
        }
        if (type === 'img') {
            return photos.length ? '图片已准备好，发布后会按朋友圈图文形式展示。' : '如果你想贴近朋友圈风格，图文是最自然的一种发布形式。';
        }
        if (type === 'video') {
            var hasPoster = !!normalizeText(videoPosterField ? videoPosterField.value : '');
            return hasPoster ? '视频和封面都已设置，最终展示会更完整。' : '建议补一张视频封面，这样列表和详情里的视觉会更稳定。';
        }
        if (type === 'embed') {
            return '平台视频会以官方 iframe 播放器展示，建议使用平台网页链接或官方分享代码。';
        }
        if (type === 'music') {
            var hasCover = !!normalizeText(musicCoverField ? musicCoverField.value : '');
            return hasCover ? '音乐封面已设置，卡片展示会更完整。' : '建议补充音乐标题、作者和封面，音乐卡片会更好看。';
        }
        if (type === 'link') {
            var hasLinkImage = !!normalizeText(linkImageField ? linkImageField.value : '');
            return hasLinkImage ? '链接缩略图已设置，会以微信样式卡片展示。' : '没有缩略图时会使用链接图标占位，仍然可以正常展示。';
        }
        if (type === 'redpacket') {
            return '红包会在发布时一次性扣除积分，建议再次确认总额和数量。';
        }
        return '这条内容当前更适合做成简洁的文字动态。';
    }

    function updatePreview() {
        var raw = contentInput ? contentInput.value : '';
        var fieldType = typeField ? String(typeField.value || '').trim() : '';
        var photos = splitLines(fieldType === 'live' && liveCoverField ? liveCoverField.value : (photosField ? photosField.value : ''));
        var livePhotos = splitLines(livePhotosField ? livePhotosField.value : '');
        var videos = splitLines(videoField ? videoField.value : '');
        var musicLines = splitLines(musicField ? musicField.value : '');
        var type = currentType();
        var textLen = normalizeText(raw).length;
        var tags = normalizeText(tagsField ? tagsField.value : '');
        var location = normalizeText(locationInput ? locationInput.value : '');
        var allowRemark = !allowRemarkInput || String(allowRemarkInput.value || 'y') === 'y';
        var draft = !!(draftInput && String(draftInput.value || 'n') === 'y');
        var top = !!(topInput && String(topInput.value || 'n') === 'y');
        var mediaText = mediaSummary(type, photos, videos, musicLines);

        if (previewSummary) {
            previewSummary.textContent = excerptText(raw, 52);
        }

        renderPills([
            { text: typeLabel(type), active: true },
            { text: textLen ? (textLen + ' 字') : '未写正文' },
            { text: mediaText },
            type === 'live' && livePhotos.length ? { text: livePhotos.length + ' 个实况' } : null,
            location ? { text: location } : null,
            allowRemark ? { text: '评论开启' } : { text: '评论关闭' },
            draft ? { text: '草稿模式' } : null,
            top ? { text: '置顶' } : null
        ]);

        renderList([
            { label: '发布类型', value: typeLabel(type) },
            { label: '分类', value: selectedSortText() },
            { label: '标签', value: tags || '未设置' },
            { label: '媒体状态', value: mediaText },
            { label: '定位', value: location || '未填写' },
            { label: '可见设置', value: (allowRemark ? '允许评论' : '关闭评论') + (draft ? ' / 草稿' : ' / 直接发布') + (top ? ' / 置顶' : '') }
        ]);

        if (previewTip) {
            previewTip.textContent = helperTip(type, photos, videos, musicLines);
        }
    }

    var inputs = [
        contentInput,
        photosField,
        liveCoverField,
        livePhotosField,
        videoField,
        videoPosterField,
        embedField,
        embedRatioField,
        musicField,
        musicTitleField,
        musicArtistField,
        musicCoverField,
        linkUrlField,
        linkTitleField,
        linkImageField,
        redpacketTotalInput,
        redpacketCountInput,
        redpacketModeInput,
        redpacketTitleInput,
        typeField,
        locationInput,
        sortField,
        tagsField,
        allowRemarkInput,
        draftInput,
        topInput
    ];
    inputs.forEach(function (el) {
        if (!el) return;
        el.addEventListener('input', updatePreview);
        el.addEventListener('change', updatePreview);
    });
    window.luminaUpdateLivePreview = updatePreview;
    updatePreview();
}
window.luminaInitLivePreview = luminaInitLivePreview;
document.addEventListener('DOMContentLoaded', luminaInitLivePreview);

(function () {
    function luminaAuthConfig() {
        var cfg = window.LUMINA_AUTH || {};
        var base = cfg.blogUrl || (window.LUMINA_BASE || './');
        if (base && base.charAt(base.length - 1) !== '/') {
            base += '/';
        }
        return {
            blogUrl: base,
            loginCode: !!cfg.loginCode,
            emailCode: !!cfg.emailCode,
            allowSignup: !!cfg.allowSignup
        };
    }

    function luminaAuthWrap() {
        var wrap = document.getElementById('sh-login');
        if (!wrap) {
            wrap = document.createElement('div');
            wrap.id = 'sh-login';
            wrap.className = 'sh-login lumina-auth';
            wrap.style.display = 'none';
            document.body.appendChild(wrap);
        } else if (!wrap.classList.contains('lumina-auth')) {
            wrap.classList.add('lumina-auth');
        }
        return wrap;
    }

    function luminaAuthCaptcha() {
        var cfg = luminaAuthConfig();
        if (!cfg.loginCode) return '';
        return [
            '<div class="lumina-auth-field lumina-auth-code">',
            '<input type="text" name="login_code" placeholder="验证码" autocomplete="off">',
            '<img src="' + cfg.blogUrl + 'include/lib/checkcode.php" alt="验证码" data-role="captcha">',
            '</div>'
        ].join('');
    }

    function luminaAuthHeader(title, desc) {
        return [
            '<div class="lumina-auth-head">',
            '<h3>' + title + '</h3>',
            desc ? '<p>' + desc + '</p>' : '',
            '</div>'
        ].join('');
    }

    function luminaAuthFormLogin() {
        var cfg = luminaAuthConfig();
        var links = [
            '<div class="lumina-auth-links">',
            '<a href="#" data-auth="reset">忘记密码</a>',
            cfg.allowSignup ? '<a href="#" data-auth="register">注册账号</a>' : '<span class="lumina-auth-muted">管理员未开启注册</span>',
            '</div>'
        ].join('');
        return [
            '<form class="lumina-auth-form" data-mode="login">',
            '<div class="lumina-auth-field">',
            '<label>账号或邮箱</label>',
            '<input type="text" name="user" autocomplete="username" placeholder="输入账号或邮箱">',
            '</div>',
            '<div class="lumina-auth-field">',
            '<label>密码</label>',
            '<input type="password" name="pw" autocomplete="current-password" placeholder="输入密码">',
            '</div>',
            luminaAuthCaptcha(),
            '<label class="lumina-auth-remember">',
            '<input type="checkbox" name="persist" value="1">7天内自动登录',
            '</label>',
            '<div class="lumina-auth-actions">',
            '<button type="submit" class="lumina-auth-submit">立即登录</button>',
            '</div>',
            '<div class="lumina-auth-msg" data-role="msg"></div>',
            links,
            '</form>'
        ].join('');
    }

    function luminaAuthFormRegister() {
        var cfg = luminaAuthConfig();
        var mailCode = '';
        if (cfg.emailCode) {
            mailCode = [
                '<div class="lumina-auth-field lumina-auth-code">',
                '<input type="text" name="mail_code" placeholder="邮箱验证码" autocomplete="off">',
                '<button type="button" class="lumina-auth-inline-btn" data-action="send-mail-code">发送邮箱验证码</button>',
                '</div>'
            ].join('');
        }
        return [
            '<form class="lumina-auth-form" data-mode="register">',
            '<div class="lumina-auth-field">',
            '<label>邮箱</label>',
            '<input type="email" name="mail" autocomplete="email" placeholder="输入邮箱">',
            '</div>',
            '<div class="lumina-auth-field">',
            '<label>密码</label>',
            '<input type="password" name="passwd" autocomplete="new-password" placeholder="设置密码">',
            '</div>',
            '<div class="lumina-auth-field">',
            '<label>重复密码</label>',
            '<input type="password" name="repasswd" autocomplete="new-password" placeholder="再次输入密码">',
            '</div>',
            luminaAuthCaptcha(),
            mailCode,
            '<div class="lumina-auth-actions">',
            '<button type="submit" class="lumina-auth-submit">立即注册</button>',
            '</div>',
            '<div class="lumina-auth-msg" data-role="msg"></div>',
            '<div class="lumina-auth-links">',
            '<a href="#" data-auth="login">已有账号？登录</a>',
            '</div>',
            '</form>'
        ].join('');
    }

    function luminaAuthFormReset(step) {
        if (step === 2) {
            return [
                '<form class="lumina-auth-form" data-mode="reset" data-step="2">',
                '<div class="lumina-auth-field">',
                '<label>邮箱验证码</label>',
                '<input type="text" name="mail_code" placeholder="输入验证码" autocomplete="off">',
                '</div>',
                '<div class="lumina-auth-field">',
                '<label>新密码</label>',
                '<input type="password" name="passwd" autocomplete="new-password" placeholder="输入新密码">',
                '</div>',
                '<div class="lumina-auth-field">',
                '<label>重复新密码</label>',
                '<input type="password" name="repasswd" autocomplete="new-password" placeholder="再次输入新密码">',
                '</div>',
                '<div class="lumina-auth-actions">',
                '<button type="submit" class="lumina-auth-submit">修改密码</button>',
                '</div>',
                '<div class="lumina-auth-msg" data-role="msg"></div>',
                '<div class="lumina-auth-links">',
                '<a href="#" data-auth="login">返回登录</a>',
                '</div>',
                '</form>'
            ].join('');
        }
        return [
            '<form class="lumina-auth-form" data-mode="reset" data-step="1">',
            '<div class="lumina-auth-field">',
            '<label>邮箱</label>',
            '<input type="email" name="mail" autocomplete="email" placeholder="输入注册邮箱">',
            '</div>',
            luminaAuthCaptcha(),
            '<div class="lumina-auth-actions">',
            '<button type="submit" class="lumina-auth-submit">发送邮箱验证码</button>',
            '</div>',
            '<div class="lumina-auth-msg" data-role="msg"></div>',
            '<div class="lumina-auth-links">',
            '<a href="#" data-auth="login">返回登录</a>',
            '</div>',
            '</form>'
        ].join('');
    }

    function luminaAuthRender(mode, step) {
        var wrap = luminaAuthWrap();
        var title = '登录';
        var desc = '欢迎回来';
        var body = '';
        if (mode === 'register') {
            title = '注册';
            desc = '创建新的账号';
            body = luminaAuthFormRegister();
        } else if (mode === 'reset') {
            title = '找回密码';
            desc = step === 2 ? '设置新密码' : '验证邮箱';
            body = luminaAuthFormReset(step);
        } else {
            body = luminaAuthFormLogin();
        }
        wrap.innerHTML = [
            '<div class="lumina-auth-card" role="dialog" aria-modal="true">',
            '<button type="button" class="lumina-auth-close" data-action="close" aria-label="关闭"></button>',
            luminaAuthHeader(title, desc),
            body,
            '</div>'
        ].join('');
        return wrap;
    }

    function luminaAuthShowMsg(form, msg, isError) {
        var node = form ? form.querySelector('[data-role="msg"]') : null;
        if (node) {
            node.textContent = msg || '';
            node.classList.toggle('is-error', !!isError);
            node.style.display = msg ? 'block' : 'none';
        }
        if (msg) {
            if (isError && typeof window.warnpop === 'function') {
                window.warnpop(msg);
            } else if (!isError && typeof window.successpop === 'function') {
                window.successpop(msg);
            }
        }
    }

    function luminaAuthRefreshCaptcha(wrap) {
        if (!wrap) return;
        var imgs = wrap.querySelectorAll('[data-role="captcha"]');
        if (!imgs.length) return;
        var cfg = luminaAuthConfig();
        imgs.forEach(function (img) {
            img.src = cfg.blogUrl + 'include/lib/checkcode.php?r=' + Math.random();
        });
    }

    function luminaAuthRequest(action, form) {
        var cfg = luminaAuthConfig();
        var fd = new FormData(form);
        fd.append('resp', 'json');
        return fetch(cfg.blogUrl + 'admin/account.php?action=' + action, {
            method: 'POST',
            body: fd,
            credentials: 'same-origin'
        }).then(function (res) {
            return res.json();
        });
    }

    function luminaAuthBind(wrap) {
        if (wrap.dataset.luminaAuthBound !== '1') {
            wrap.addEventListener('click', function (e) {
                if (e.target === wrap) {
                    luminaAuthClose();
                }
            });
            wrap.dataset.luminaAuthBound = '1';
        }
        var closeBtn = wrap.querySelector('[data-action="close"]');
        if (closeBtn) {
            closeBtn.addEventListener('click', function () {
                luminaAuthClose();
            });
        }
        var captchas = wrap.querySelectorAll('[data-role="captcha"]');
        captchas.forEach(function (captcha) {
            captcha.addEventListener('click', function () {
                luminaAuthRefreshCaptcha(wrap);
            });
        });
        var switchLinks = wrap.querySelectorAll('[data-auth]');
        switchLinks.forEach(function (link) {
            link.addEventListener('click', function (e) {
                e.preventDefault();
                var mode = link.getAttribute('data-auth');
                luminaAuthOpen(mode);
            });
        });
        var form = wrap.querySelector('.lumina-auth-form');
        if (!form) return;
        var sendBtn = form.querySelector('[data-action="send-mail-code"]');
        if (sendBtn) {
            sendBtn.addEventListener('click', function () {
                if (sendBtn.disabled) return;
                luminaAuthShowMsg(form, '', false);
                luminaAuthRequest('send_email_code', form).then(function (res) {
                    if (res && res.code === 0) {
                        luminaAuthShowMsg(form, '验证码已发送', false);
                        var count = 60;
                        sendBtn.disabled = true;
                        var timer = setInterval(function () {
                            sendBtn.textContent = '重新发送(' + count + ')';
                            count -= 1;
                            if (count < 0) {
                                clearInterval(timer);
                                sendBtn.textContent = '发送邮箱验证码';
                                sendBtn.disabled = false;
                            }
                        }, 1000);
                    } else {
                        luminaAuthShowMsg(form, (res && res.msg) ? res.msg : '发送失败', true);
                        luminaAuthRefreshCaptcha(wrap);
                    }
                }).catch(function () {
                    luminaAuthShowMsg(form, '发送失败', true);
                    luminaAuthRefreshCaptcha(wrap);
                });
            });
        }
        form.addEventListener('submit', function (e) {
            e.preventDefault();
            var mode = form.getAttribute('data-mode') || 'login';
            var step = form.getAttribute('data-step') || '1';
            var submitBtn = form.querySelector('.lumina-auth-submit');
            if (submitBtn) {
                submitBtn.disabled = true;
                submitBtn.textContent = '处理中...';
            }
            luminaAuthShowMsg(form, '', false);
            var action = mode === 'register' ? 'dosignup' : (mode === 'reset' ? (step === '2' ? 'doreset2' : 'doreset') : 'dosignin');
            luminaAuthRequest(action, form).then(function (res) {
                if (submitBtn) {
                    submitBtn.disabled = false;
                    submitBtn.textContent = mode === 'register' ? '立即注册' : (mode === 'reset' ? (step === '2' ? '修改密码' : '发送邮箱验证码') : '立即登录');
                }
                if (res && res.code === 0) {
                    if (mode === 'login') {
                        location.reload();
                        return;
                    }
                    if (mode === 'register') {
                        luminaAuthShowMsg(form, '注册成功，请登录', false);
                        luminaAuthOpen('login');
                        return;
                    }
                    if (mode === 'reset' && step === '1') {
                        luminaAuthOpen('reset', 2);
                        luminaAuthShowMsg(wrap.querySelector('.lumina-auth-form'), '验证码已发送', false);
                        return;
                    }
                    if (mode === 'reset' && step === '2') {
                        luminaAuthShowMsg(form, '密码已修改，请登录', false);
                        luminaAuthOpen('login');
                        return;
                    }
                }
                luminaAuthShowMsg(form, (res && res.msg) ? res.msg : '操作失败', true);
                luminaAuthRefreshCaptcha(wrap);
            }).catch(function () {
                if (submitBtn) {
                    submitBtn.disabled = false;
                    submitBtn.textContent = mode === 'register' ? '立即注册' : (mode === 'reset' ? (step === '2' ? '修改密码' : '发送邮箱验证码') : '立即登录');
                }
                luminaAuthShowMsg(form, '请求失败，请稍后再试', true);
                luminaAuthRefreshCaptcha(wrap);
            });
        });
    }

    function luminaAuthOpen(mode, step) {
        var cfg = luminaAuthConfig();
        if (window.LUMINA && window.LUMINA.isLogin) return;
        if (mode === 'register' && !cfg.allowSignup) {
            if (typeof window.warnpop === 'function') {
                window.warnpop('管理员未开启注册');
            }
            return;
        }
        var wrap = luminaAuthRender(mode, step || 1);
        wrap.style.display = 'flex';
        document.body.classList.add('lumina-auth-open');
        luminaAuthBind(wrap);
    }

    function luminaAuthClose() {
        var wrap = document.getElementById('sh-login');
        if (!wrap) return;
        wrap.style.display = 'none';
        wrap.innerHTML = '';
        document.body.classList.remove('lumina-auth-open');
    }

    document.addEventListener('click', function (e) {
        var link = e.target.closest('a');
        if (!link) return;
        var href = link.getAttribute('href') || '';
        if (href.indexOf('admin/account.php?action=signin') !== -1) {
            e.preventDefault();
            luminaAuthOpen('login');
        } else if (href.indexOf('admin/account.php?action=signup') !== -1) {
            e.preventDefault();
            luminaAuthOpen('register');
        }
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') luminaAuthClose();
    });

    window.kqlogin = function () {
        luminaAuthOpen('login');
    };
    window.gblogin = function () {
        luminaAuthClose();
    };

    document.addEventListener('DOMContentLoaded', function () {
        var wrap = luminaAuthRender('login', 1);
        wrap.style.display = 'none';
        luminaAuthBind(wrap);
    });
})();

(function () {
    var searchWrap = document.getElementById('lumina-search');
    var searchInput = searchWrap ? document.getElementById('lumina-search-input') : null;
    var searchForm = searchWrap ? document.getElementById('lumina-search-form') : null;
    var menu = document.getElementById('sh-menu');
    var dayBtn = document.getElementById('day');
    var dayIcon = document.getElementById('day-i');

    function setSearchState(active) {
        if (!searchWrap) return;
        searchWrap.classList.toggle('is-active', !!active);
        searchWrap.setAttribute('aria-hidden', active ? 'false' : 'true');
        if (document.body) {
            document.body.classList.toggle('lumina-search-open', !!active);
        }
    }

    function openSearch() {
        if (!searchWrap) return;
        setSearchState(true);
        if (searchInput) {
            searchInput.focus();
            if (searchInput.value) {
                var len = searchInput.value.length;
                searchInput.setSelectionRange(len, len);
            }
        }
    }

    function closeSearch() {
        if (!searchWrap) return;
        setSearchState(false);
    }

    window.luminaOpenSearch = openSearch;
    window.luminaCloseSearch = closeSearch;

    function findActionBtn(target) {
        if (!target) return null;
        if (target.closest) {
            return target.closest('[data-action]');
        }
        var node = target;
        while (node && node !== document) {
            if (node.getAttribute && node.getAttribute('data-action')) {
                return node;
            }
            node = node.parentNode;
        }
        return null;
    }

    document.addEventListener('click', function (e) {
        var actionBtn = findActionBtn(e.target);
        if (actionBtn) {
            var action = actionBtn.getAttribute('data-action');
            if (action === 'search') {
                e.preventDefault();
                openSearch();
                return;
            }
            if (action === 'search-close') {
                e.preventDefault();
                closeSearch();
                return;
            }
            if (action === 'backtop') {
                e.preventDefault();
                window.scrollTo({ top: 0, behavior: 'smooth' });
                return;
            }
        }
        if (searchWrap && e.target === searchWrap) {
            closeSearch();
        }
    });

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
            closeSearch();
        }
    });

    if (searchForm) {
        searchForm.addEventListener('submit', function () {
            closeSearch();
        });
    }

    function getCookie(name) {
        var cookie = document.cookie;
        if (!cookie) return '';
        var items = cookie.split('; ');
        for (var i = 0; i < items.length; i++) {
            var part = items[i];
            if (!part) continue;
            var eq = part.indexOf('=');
            var key = eq >= 0 ? part.slice(0, eq) : part;
            if (decodeURIComponent(key) === name) {
                var val = eq >= 0 ? part.slice(eq + 1) : '';
                return decodeURIComponent(val);
            }
        }
        return '';
    }

    function applyTheme(isDark) {
        var body = document.body;
        if (!body) return;
        body.classList.toggle('dark-theme', !!isDark);
        if (dayBtn) {
            dayBtn.setAttribute('lang', isDark ? '0' : '1');
        }
        if (dayIcon) {
            dayIcon.className = 'iconfont ' + (isDark ? 'icon-yueliang' : 'icon-ai250');
        }
        document.cookie = 'dark_theme=' + (isDark ? 'dark-theme' : 'root') + '; path=/';
        try {
            window.localStorage.setItem('lumina_theme_mode', isDark ? 'dark' : 'light');
        } catch (err) {}
    }

    function toggleTheme() {
        var body = document.body;
        if (!body) return false;
        var nextDark = !body.classList.contains('dark-theme');
        applyTheme(nextDark);
        return false;
    }

    window.luminaApplyTheme = applyTheme;
    window.luminaToggleTheme = toggleTheme;

    function applyStoredTheme() {
        var storedTheme = '';
        try {
            storedTheme = window.localStorage.getItem('lumina_theme_mode') || '';
        } catch (err) {}
        var cookieTheme = getCookie('dark_theme');
        if (storedTheme === 'dark' || cookieTheme === 'dark-theme') {
            applyTheme(true);
            return true;
        }
        if (storedTheme === 'light' || cookieTheme === 'root') {
            applyTheme(false);
            return true;
        }
        if (dayBtn) {
            var isDark = document.body && document.body.classList.contains('dark-theme');
            dayBtn.setAttribute('lang', isDark ? '0' : '1');
            if (dayIcon) {
                dayIcon.className = 'iconfont ' + (isDark ? 'icon-yueliang' : 'icon-ai250');
            }
        }
        return false;
    }
    window.luminaApplyStoredTheme = applyStoredTheme;

    applyStoredTheme();
    if (menu) {
        var lastMenuY = window.pageYOffset || document.documentElement.scrollTop || document.body.scrollTop || 0;
        var menuTicking = false;

        function setMenuVisible(visible) {
            menu.classList.toggle('is-visible', !!visible);
        }

        function updateMenuVisibility() {
            var y = window.pageYOffset || document.documentElement.scrollTop || document.body.scrollTop || 0;
            var delta = y - lastMenuY;
            var nearTop = y < 120;
            var doc = document.documentElement;
            var maxScroll = Math.max(
                doc ? doc.scrollHeight : 0,
                document.body ? document.body.scrollHeight : 0
            );
            var nearBottom = (window.innerHeight + y) >= (maxScroll - 24);

            if (nearTop || nearBottom) {
                setMenuVisible(true);
            } else if (Math.abs(delta) > 8) {
                setMenuVisible(delta < 0);
            }

            lastMenuY = y;
            menuTicking = false;
        }

        function handleMenuScroll() {
            if (menuTicking) return;
            menuTicking = true;
            window.requestAnimationFrame(updateMenuVisibility);
        }

        window.addEventListener('scroll', handleMenuScroll, { passive: true });
        window.addEventListener('resize', updateMenuVisibility);
        updateMenuVisibility();
    }
})();

(function () {
    var cfg = window.LUMINA || {};
    if (!cfg.ajaxProgressEnabled) {
        window.luminaAjaxProgressStart = function () {};
        window.luminaAjaxProgressDone = function () {};
        return;
    }

    var progressEl = null;
    var barEl = null;
    var timer = null;
    var hideTimer = null;
    var startedAt = 0;

    function ensureProgress() {
        if (progressEl && barEl) {
            return;
        }
        progressEl = document.querySelector('.lumina-ajax-progress');
        if (!progressEl) {
            progressEl = document.createElement('div');
            progressEl.className = 'lumina-ajax-progress';
            progressEl.setAttribute('aria-hidden', 'true');
            barEl = document.createElement('span');
            barEl.className = 'lumina-ajax-progress-bar';
            progressEl.appendChild(barEl);
            document.body.appendChild(progressEl);
        } else {
            barEl = progressEl.querySelector('.lumina-ajax-progress-bar');
            if (!barEl) {
                barEl = document.createElement('span');
                barEl.className = 'lumina-ajax-progress-bar';
                progressEl.appendChild(barEl);
            }
        }
    }

    function setWidth(value) {
        ensureProgress();
        barEl.style.width = value + '%';
    }

    window.luminaAjaxProgressStart = function () {
        if (!document.body) return;
        ensureProgress();
        if (hideTimer) {
            clearTimeout(hideTimer);
            hideTimer = null;
        }
        progressEl.classList.add('is-active');
        startedAt = Date.now();
        setWidth(18);
        var width = 18;
        if (timer) {
            clearInterval(timer);
        }
        timer = setInterval(function () {
            width = Math.min(88, width + Math.max(2, (88 - width) * 0.16));
            setWidth(width);
        }, 220);
    };

    window.luminaAjaxProgressDone = function () {
        ensureProgress();
        if (timer) {
            clearInterval(timer);
            timer = null;
        }
        setWidth(100);
        var elapsed = startedAt ? (Date.now() - startedAt) : 0;
        var hideDelay = Math.max(180, 420 - elapsed);
        hideTimer = setTimeout(function () {
            if (!progressEl || !barEl) return;
            progressEl.classList.remove('is-active');
            barEl.style.width = '0';
            startedAt = 0;
        }, hideDelay);
    };
})();

(function () {
    var cfg = window.LUMINA || {};
    if (!cfg.ajaxNavEnabled) {
        return;
    }
    if (!window.history || !window.history.pushState || !window.DOMParser || !window.fetch) {
        return;
    }

    var requestId = 0;

    function getUrl(raw) {
        try {
            return new URL(raw, window.location.href);
        } catch (err) {
            return null;
        }
    }

    function isModifiedClick(e) {
        return !!(e.metaKey || e.ctrlKey || e.shiftKey || e.altKey || e.button !== 0);
    }

    function isQuickNavLink(link, e) {
        if (!link || isModifiedClick(e) || !link.closest('.lumina-quick-nav')) {
            return false;
        }
        var rawHref = link.getAttribute('href') || '';
        if (!rawHref || rawHref.indexOf('#') === 0 || /^javascript:/i.test(rawHref)) {
            return false;
        }
        var url = getUrl(rawHref);
        if (!url || url.origin !== window.location.origin) {
            return false;
        }
        if (url.href === window.location.href || (url.pathname === window.location.pathname && url.search === window.location.search)) {
            return false;
        }
        return true;
    }

    function replaceSelector(doc, selector) {
        var current = document.querySelector(selector);
        var next = doc.querySelector(selector);
        if (current && next && current.parentNode) {
            current.parentNode.replaceChild(next, current);
            return true;
        }
        if (current && !next && current.parentNode) {
            current.parentNode.removeChild(current);
            return true;
        }
        if (!current && next && selector === '.lumina-quick-nav') {
            var main = document.querySelector('.sh-main');
            var list = document.getElementById('sh-nrbk');
            if (main && list) {
                main.insertBefore(next, list);
                return true;
            }
        }
        return !current && !next;
    }

    function applyDoc(doc) {
        var currentList = document.getElementById('sh-nrbk');
        var nextList = doc.getElementById('sh-nrbk');
        if (!currentList || !nextList || !currentList.parentNode) {
            return false;
        }

        replaceSelector(doc, '.lumina-quick-nav');
        if (typeof window.luminaDisposePageContent === 'function') {
            window.luminaDisposePageContent(currentList);
        }
        currentList.parentNode.replaceChild(nextList, currentList);
        replaceSelector(doc, '#lumina-page-nav');
        replaceSelector(doc, '.lumina-page-nav');
        replaceSelector(doc, '.lumina-list-more');

        if (doc.title) {
            document.title = doc.title;
        }
        if (typeof window.luminaRefreshDynamicContent === 'function') {
            window.luminaRefreshDynamicContent();
        }
        return true;
    }

    function setLoading(loading) {
        if (!document.body) return;
        document.body.classList.toggle('lumina-quick-nav-loading', !!loading);
        var items = document.querySelectorAll('.lumina-quick-nav-item');
        luminaEach(items, function (item) {
            item.classList.toggle('is-disabled', !!loading);
            item.setAttribute('aria-disabled', loading ? 'true' : 'false');
        });
        if (loading && typeof window.luminaAjaxProgressStart === 'function') {
            window.luminaAjaxProgressStart();
        } else if (!loading && typeof window.luminaAjaxProgressDone === 'function') {
            window.luminaAjaxProgressDone();
        }
    }

    function scrollToList() {
        var list = document.getElementById('sh-nrbk');
        if (!list || !list.getBoundingClientRect) return;
        var top = list.getBoundingClientRect().top + window.pageYOffset - 76;
        window.scrollTo({ top: Math.max(0, top), behavior: 'smooth' });
    }

    function loadQuickNav(url, opts) {
        opts = opts || {};
        if (opts.push !== false) {
            saveListSnapshotIfAvailable();
        }
        var fetchId = ++requestId;
        setLoading(true);

        return fetch(url, { credentials: 'same-origin' })
            .then(function (res) {
                return res.text();
            })
            .then(function (html) {
                if (fetchId !== requestId) {
                    return false;
                }
                var doc = new DOMParser().parseFromString(html, 'text/html');
                if (!applyDoc(doc)) {
                    window.location.href = url;
                    return false;
                }
                if (opts.push !== false) {
                    window.history.pushState({ luminaQuickNav: true, url: url }, '', url);
                }
                if (opts.scroll !== false) {
                    scrollToList();
                }
                if (opts.restoreListSnapshot) {
                    restoreListSnapshotIfAvailable({ force: true, scroll: true, url: url });
                }
                return true;
            })
            .catch(function () {
                if (!opts.silent) {
                    window.location.href = url;
                }
                return false;
            })
            .finally(function () {
                if (fetchId === requestId) {
                    setLoading(false);
                }
            });
    }

    document.addEventListener('click', function (e) {
        var link = e.target && e.target.closest ? e.target.closest('a') : null;
        if (!isQuickNavLink(link, e)) {
            return;
        }
        e.preventDefault();
        e.stopPropagation();
        if (e.stopImmediatePropagation) {
            e.stopImmediatePropagation();
        }
        loadQuickNav(link.href, { push: true, scroll: true });
    }, true);

    window.addEventListener('popstate', function (e) {
        var state = e.state || {};
        if (!state.luminaQuickNav) {
            return;
        }
        if (e.stopImmediatePropagation) {
            e.stopImmediatePropagation();
        }
        loadQuickNav(window.location.href, {
            push: false,
            scroll: false,
            silent: true,
            restoreListSnapshot: true
        });
    }, true);

    if (document.querySelector('.lumina-quick-nav') && (!window.history.state || (!window.history.state.luminaAjax && !window.history.state.luminaQuickNav))) {
        window.history.replaceState({ luminaQuickNav: true, url: window.location.href }, '', window.location.href);
    }
})();

(function () {
    var cfg = window.LUMINA || {};
    var currentScript = cfg.pageScript || 'common';
    var currentIdentity = cfg.pageIdentity || 'common';
    var ajaxEnabled = !!cfg.ajaxNavEnabled;
    var allowScriptMap = {
        common: true,
        index: true,
        home: true,
        page: true,
        view: true
    };
    var availableScriptMap = {
        common: true
    };
    var scriptPathMap = {
        index: ['assets/js/index.js'],
        home: ['assets/js/index.js', 'assets/js/home.js'],
        view: ['assets/js/index.js', 'assets/js/view.js'],
        page: []
    };
    var requestId = 0;
    var useSwupNav = false;
    var ajaxNavStatus = {
        enabled: ajaxEnabled,
        mode: ajaxEnabled ? 'booting' : 'disabled',
        reason: '',
        currentScript: currentScript,
        currentIdentity: currentIdentity,
        swupAvailable: !!window.Swup,
        containerCount: document.querySelectorAll('[data-lumina-pjax-container]').length
    };

    function saveListSnapshotIfAvailable() {
        if (typeof window.luminaSaveListSnapshot !== 'function') {
            return false;
        }
        try {
            return window.luminaSaveListSnapshot();
        } catch (err) {
            return false;
        }
    }

    function restoreListSnapshotIfAvailable(options) {
        if (typeof window.luminaRestoreListSnapshot !== 'function') {
            return false;
        }
        try {
            return window.luminaRestoreListSnapshot(options || {});
        } catch (err) {
            return false;
        }
    }

    function updateAjaxNavStatus(patch) {
        patch = patch || {};
        for (var key in patch) {
            if (Object.prototype.hasOwnProperty.call(patch, key)) {
                ajaxNavStatus[key] = patch[key];
            }
        }
        ajaxNavStatus.swupAvailable = !!window.Swup;
        ajaxNavStatus.containerCount = document.querySelectorAll('[data-lumina-pjax-container]').length;
        ajaxNavStatus.externalOverlays = {
            viewSet: !!document.getElementById('sh-view-set'),
            news: !!document.getElementById('sh-news'),
            link: !!document.getElementById('sh-link')
        };
        if (window.LUMINA) {
            window.LUMINA.ajaxNavStatus = ajaxNavStatus;
        }
        window.luminaAjaxNavStatus = function () {
            return ajaxNavStatus;
        };
        if (document.documentElement) {
            document.documentElement.setAttribute('data-lumina-ajax-nav', ajaxNavStatus.mode);
        }
    }

    updateAjaxNavStatus();

    if (scriptPathMap.hasOwnProperty(currentScript)) {
        availableScriptMap[currentScript] = true;
        if (currentScript === 'home' || currentScript === 'view') {
            availableScriptMap.index = true;
        }
    }

    if (!ajaxEnabled) {
        updateAjaxNavStatus({ mode: 'disabled', reason: 'option-disabled' });
        return;
    }
    if (!allowScriptMap[currentScript]) {
        updateAjaxNavStatus({ mode: 'disabled', reason: 'unsupported-current-script' });
        return;
    }
    if (!window.history || !window.history.pushState || !window.DOMParser || !window.fetch) {
        updateAjaxNavStatus({ mode: 'disabled', reason: 'browser-unsupported' });
        return;
    }

    function isModifiedClick(e) {
        return !!(e.metaKey || e.ctrlKey || e.shiftKey || e.altKey || e.button !== 0);
    }

    function getUrl(raw) {
        try {
            return new URL(raw, window.location.href);
        } catch (err) {
            return null;
        }
    }

    function isAjaxCandidateLink(link, e) {
        if (!link || isModifiedClick(e)) {
            return false;
        }
        if (link.closest('.lumina-quick-nav')) {
            return false;
        }
        if (link.target === '_blank' || link.hasAttribute('download')) {
            return false;
        }
        if (link.hasAttribute('data-fancybox') || link.closest('[data-fancybox], .sh-content-right-img, .sh-video, .lumina-live-photo, .lumina-music-card, .lumina-link-card, .lumina-redpacket-card')) {
            return false;
        }
        var rawHref = link.getAttribute('href') || '';
        if (!rawHref || rawHref.indexOf('#') === 0 || /^javascript:/i.test(rawHref)) {
            return false;
        }
        var url = getUrl(rawHref);
        if (!url || url.origin !== window.location.origin) {
            return false;
        }
        if (url.hash) {
            return false;
        }
        if (url.href === window.location.href || (url.pathname === window.location.pathname && url.search === window.location.search)) {
            return false;
        }
        if (/\/admin\/|\/user(\/|$)|\/index\.php\/user|account\.php/i.test(url.pathname + url.search)) {
            return false;
        }
        if (/[?&](action|plugin)=/i.test(url.search)) {
            return false;
        }
        if (link.closest('.sh-zanp-pl-ku, .sh-pinglunkuang, .sh-news, .sh-link, .sh-view-set-wk, .lumina-auth-shell')) {
            return false;
        }
        if (
            link.closest('[data-lumina-pjax-container]') ||
            link.closest('.sh-main') ||
            link.closest('.lumina-aside') ||
            link.closest('.lumina-page-nav') ||
            link.closest('#lumina-page-nav') ||
            link.closest('.lumina-sidecard') ||
            link.closest('.lumina-side-footer') ||
            link.closest('.sh-main-head-headimg') ||
            link.closest('.sh-main-head-top-center') ||
            link.closest('.lumina-site-logo-link') ||
            link.closest('.lumina-custom-float-btn') ||
            link.closest('#sh-menu') ||
            link.closest('.sh-copyright') ||
            link.closest('.sh-icp')
        ) {
            return true;
        }
        return false;
    }

    function getAjaxTargetFromEvent(e) {
        var target = e.target;
        if (!target || !target.closest) {
            return null;
        }
        var link = target.closest('a');
        if (link) {
            return link;
        }
        var card = target.closest('[data-lumina-ajax-href]');
        if (!card) {
            return null;
        }
        if (target.closest('a, button, input, textarea, select, [data-fancybox], .sh-content-right-img, .sh-video, .lumina-live-photo, .lumina-music-card, .lumina-redpacket-card, .sh-content-right-time-right')) {
            return null;
        }
        return {
            href: card.getAttribute('data-lumina-ajax-href') || '',
            target: '',
            hasAttribute: function () { return false; },
            getAttribute: function (name) {
                return name === 'href' ? (card.getAttribute('data-lumina-ajax-href') || '') : '';
            },
            closest: function (selector) {
                if (selector === '.sh-main') {
                    return card.closest(selector);
                }
                return card.closest(selector);
            }
        };
    }

    document.addEventListener('click', function (e) {
        var link = getAjaxTargetFromEvent(e);
        if (!isAjaxCandidateLink(link, e)) {
            return;
        }
        updateAjaxNavStatus({
            lastIntentUrl: link.href || '',
            lastIntentAt: Date.now()
        });
    }, true);

    function setAjaxLoading(loading) {
        if (!document.body) return;
        document.body.classList.toggle('lumina-ajax-loading', !!loading);
    }

    function syncSearchInputFromUrl(url) {
        var input = document.getElementById('lumina-search-input');
        if (!input || !url) return;
        input.value = url.searchParams.get('keyword') || '';
    }

    function parsePageScript(doc) {
        var meta = doc.querySelector('meta[name="lumina-page-script"]');
        return meta ? (meta.getAttribute('content') || 'common') : 'common';
    }

    function parsePageIdentity(doc) {
        var meta = doc.querySelector('meta[name="lumina-page-identity"]');
        return meta ? (meta.getAttribute('content') || 'common') : 'common';
    }

    function canSwapToScript(script) {
        script = script || 'common';
        return !!availableScriptMap[script];
    }

    function isSupportedPageScript(script) {
        script = script || 'common';
        return !!availableScriptMap[script] || scriptPathMap.hasOwnProperty(script);
    }

    function scriptUrl(path) {
        var base = (cfg.templateUrl || '').replace(/\/?$/, '/');
        return base + path;
    }

    function loadOneScript(path) {
        var src = scriptUrl(path);
        var existing = document.querySelector('script[data-lumina-dynamic-script="' + path + '"]');
        if (!existing) {
            var scripts = document.querySelectorAll('script[src]');
            for (var i = 0; i < scripts.length; i++) {
                var loadedSrc = scripts[i].getAttribute('src') || '';
                var loadedUrl = getUrl(loadedSrc);
                var loadedPath = loadedUrl ? loadedUrl.pathname.replace(/\\/g, '/') : loadedSrc.split('?')[0];
                if (loadedPath && loadedPath.slice(-path.length) === path) {
                    existing = scripts[i];
                    break;
                }
            }
        }
        if (existing) {
            return Promise.resolve();
        }
        return new Promise(function (resolve, reject) {
            var script = document.createElement('script');
            script.src = src;
            script.async = false;
            script.dataset.luminaDynamicScript = path;
            script.onload = function () {
                resolve();
            };
            script.onerror = function () {
                reject(new Error('failed to load ' + path));
            };
            document.body.appendChild(script);
        });
    }

    function ensurePageScripts(script) {
        script = script || 'common';
        if (availableScriptMap[script]) {
            return Promise.resolve();
        }
        var paths = scriptPathMap[script];
        if (!paths) {
            return Promise.reject(new Error('unsupported page script ' + script));
        }
        var chain = Promise.resolve();
        paths.forEach(function (path) {
            chain = chain.then(function () {
                return loadOneScript(path);
            });
        });
        return chain.then(function () {
            availableScriptMap[script] = true;
            if (script === 'home' || script === 'view') {
                availableScriptMap.index = true;
            }
            if (typeof window.luminaInstallScrollBridge === 'function') {
                window.luminaInstallScrollBridge();
            }
        });
    }

    function syncBodyClass(doc) {
        if (doc.body && document.body) {
            document.body.className = doc.body.className || '';
            if (typeof window.luminaApplyStoredTheme === 'function') {
                window.luminaApplyStoredTheme();
            }
        }
    }

    function refreshAfterPageChange(nextScript, nextIdentity, url) {
        currentScript = nextScript || 'common';
        currentIdentity = nextIdentity || 'common';
        if (window.LUMINA) {
            window.LUMINA.pageScript = currentScript;
            window.LUMINA.pageIdentity = currentIdentity;
        }
        syncSearchInputFromUrl(getUrl(url) || new URL(window.location.href));
        if (typeof window.luminaCloseSearch === 'function') {
            window.luminaCloseSearch();
        }
        if (typeof window.luminaRefreshDynamicContent === 'function') {
            window.luminaRefreshDynamicContent();
        }
    }

    function initSwupNav() {
        if (!useSwupNav) {
            updateAjaxNavStatus({ mode: 'fetch-fallback', reason: 'swup-disabled-fixed-shell' });
            return false;
        }
        if (!window.Swup) {
            updateAjaxNavStatus({ mode: 'fetch-fallback', reason: 'swup-missing' });
            return false;
        }
        if (!document.querySelector('[data-lumina-pjax-container]')) {
            updateAjaxNavStatus({ mode: 'fetch-fallback', reason: 'container-missing' });
            return false;
        }
        var swup = null;
        try {
            swup = new window.Swup({
                containers: ['[data-lumina-pjax-container]'],
                animationSelector: '[data-lumina-pjax-container]',
                cache: true,
                ignoreVisit: function (url, ctx) {
                    var link = ctx && ctx.el ? ctx.el : null;
                    var event = ctx && ctx.event ? ctx.event : { button: 0 };
                    if (link && link.hasAttribute && link.hasAttribute('data-lumina-ajax-href')) {
                        return false;
                    }
                    return !isAjaxCandidateLink(link, event);
                }
            });
        } catch (err) {
            updateAjaxNavStatus({ mode: 'fetch-fallback', reason: 'swup-init-error', error: err && err.message ? err.message : String(err) });
            if (window.console && window.console.warn) {
                window.console.warn('[Lumina] Swup init failed, fallback ajax enabled.', err);
            }
            return false;
        }

        if (window.LUMINA) {
            window.LUMINA.swup = swup;
        }
        updateAjaxNavStatus({ mode: 'swup', reason: 'initialized' });

        function startSwupProgress() {
            setAjaxLoading(true);
            if (typeof window.luminaAjaxProgressStart === 'function') {
                window.luminaAjaxProgressStart();
            }
        }

        function doneSwupProgress() {
            setAjaxLoading(false);
            if (typeof window.luminaAjaxProgressDone === 'function') {
                window.luminaAjaxProgressDone();
            }
        }

        swup.hooks.on('link:click', function () {
            saveListSnapshotIfAvailable();
            startSwupProgress();
        });
        swup.hooks.on('history:popstate', startSwupProgress);
        swup.hooks.on('visit:start', function () {
            saveListSnapshotIfAvailable();
            startSwupProgress();
        });

        swup.hooks.on('content:replace', function (visit) {
            var doc = visit && visit.to ? visit.to.document : null;
            if (!doc) {
                return;
            }
            syncBodyClass(doc);
            var nextScript = parsePageScript(doc);
            var nextIdentity = parsePageIdentity(doc);
            return ensurePageScripts(nextScript).then(function () {
                refreshAfterPageChange(nextScript, nextIdentity, window.location.href);
                if (visit && visit.history && visit.history.popstate) {
                    restoreListSnapshotIfAvailable({ force: true, scroll: true });
                }
                updateAjaxNavStatus({
                    mode: 'swup',
                    reason: 'navigated',
                    currentScript: currentScript,
                    currentIdentity: currentIdentity
                });
            });
        });

        swup.hooks.on('visit:end', doneSwupProgress);
        swup.hooks.on('visit:abort', doneSwupProgress);
        swup.hooks.on('fetch:error', doneSwupProgress);
        swup.hooks.on('fetch:timeout', doneSwupProgress);

        window.luminaAjaxCardClick = function (card, e) {
            if (!card) {
                return true;
            }
            var target = e && e.target;
            if (target && target.closest && target.closest('a, button, input, textarea, select, [data-fancybox], .sh-content-right-img, .sh-video, .lumina-live-photo, .lumina-music-card, .lumina-redpacket-card, .sh-content-right-time-right')) {
                return true;
            }
            var href = card.getAttribute('data-lumina-ajax-href') || '';
            if (!href) {
                return true;
            }
            var pseudoLink = {
                href: getUrl(href) ? getUrl(href).toString() : href,
                target: '',
                hasAttribute: function () { return false; },
                getAttribute: function (name) {
                    return name === 'href' ? href : '';
                },
                closest: function (selector) {
                    return card.closest(selector);
                }
            };
            if (!isAjaxCandidateLink(pseudoLink, e || { button: 0 })) {
                window.location.href = href;
                return false;
            }
            if (e && typeof e.preventDefault === 'function') {
                e.preventDefault();
            }
            if (e && typeof e.stopPropagation === 'function') {
                e.stopPropagation();
            }
            startSwupProgress();
            swup.navigate(pseudoLink.href, {}, { el: card, event: e || null });
            return false;
        };

        window.luminaAjaxVisit = function (url, opts) {
            opts = opts || {};
            var parsed = getUrl(url);
            if (!parsed) {
                window.location.href = url;
                return false;
            }
            var pseudoLink = {
                href: parsed.toString(),
                target: '',
                hasAttribute: function () { return false; },
                getAttribute: function (name) {
                    return name === 'href' ? parsed.toString() : '';
                },
                closest: function (selector) {
                    var main = document.querySelector('.sh-main') || document.querySelector('[data-lumina-pjax-container]');
                    if (!main || !main.closest) {
                        return null;
                    }
                    if (selector === '.sh-main' && main.classList && main.classList.contains('sh-main')) {
                        return main;
                    }
                    return main.closest(selector);
                }
            };
            if (!isAjaxCandidateLink(pseudoLink, { button: 0 })) {
                window.location.href = parsed.toString();
                return false;
            }
            if (opts.push !== false) {
                saveListSnapshotIfAvailable();
            }
            startSwupProgress();
            swup.navigate(pseudoLink.href, {}, { el: pseudoLink, event: { button: 0 } });
            return false;
        };

        return true;
    }

    if (initSwupNav()) {
        return;
    }
    updateAjaxNavStatus({ mode: 'fetch-fallback', reason: ajaxNavStatus.reason || 'swup-unavailable' });

    function syncExternalNode(doc, nextContent, selector) {
        if (nextContent && nextContent.querySelector && nextContent.querySelector(selector)) {
            return;
        }

        var current = document.querySelector(selector);
        var next = doc.querySelector(selector);

        if (current && next && current.parentNode) {
            current.parentNode.replaceChild(next, current);
            return;
        }
        if (current && !next && current.parentNode) {
            current.parentNode.removeChild(current);
            return;
        }
        if (!current && next && document.body) {
            document.body.appendChild(next);
        }
    }

    function syncExternalOverlays(doc, nextContent) {
        ['#sh-view-set', '#sh-news', '#sh-link'].forEach(function (selector) {
            syncExternalNode(doc, nextContent, selector);
        });
    }

    function replaceContentFromDoc(doc, url) {
        var nextContent = doc.querySelector('[data-lumina-pjax-container]') || doc.querySelector('.centent');
        var currentContent = document.querySelector('[data-lumina-pjax-container]') || document.querySelector('.centent');
        if (!nextContent || !currentContent || !currentContent.parentNode) {
            return false;
        }
        if (typeof window.luminaCloseTransientPageUi === 'function') {
            window.luminaCloseTransientPageUi();
        }
        if (typeof window.luminaDisposePageContent === 'function') {
            window.luminaDisposePageContent(currentContent);
        }
        currentContent.parentNode.replaceChild(nextContent, currentContent);
        syncExternalOverlays(doc, nextContent);
        syncBodyClass(doc);
        if (doc.title) {
            document.title = doc.title;
        }
        syncSearchInputFromUrl(getUrl(url) || new URL(window.location.href));
        if (typeof window.luminaCloseSearch === 'function') {
            window.luminaCloseSearch();
        }
        return true;
    }

    function fetchAndSwap(url, opts) {
        opts = opts || {};
        if (opts.push !== false) {
            saveListSnapshotIfAvailable();
        }
        var fetchId = ++requestId;
        setAjaxLoading(true);
        if (typeof window.luminaAjaxProgressStart === 'function') {
            window.luminaAjaxProgressStart();
        }

        return fetch(url, {
            credentials: 'same-origin',
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        }).then(function (res) {
            return res.text();
        }).then(function (html) {
            if (fetchId !== requestId) {
                return false;
            }
            var parser = new DOMParser();
            var doc = parser.parseFromString(html, 'text/html');
            var nextScript = parsePageScript(doc);
            var nextIdentity = parsePageIdentity(doc);
            if (!isSupportedPageScript(nextScript)) {
                window.location.href = url;
                return false;
            }
            var ok = replaceContentFromDoc(doc, url);
            if (!ok) {
                window.location.href = url;
                return false;
            }
            return ensurePageScripts(nextScript).then(function () {
                currentScript = nextScript;
                currentIdentity = nextIdentity;
                if (window.LUMINA) {
                    window.LUMINA.pageScript = currentScript;
                    window.LUMINA.pageIdentity = currentIdentity;
                }
                updateAjaxNavStatus({
                    mode: 'fetch-fallback',
                    reason: 'navigated',
                    currentScript: currentScript,
                    currentIdentity: currentIdentity
                });
                if (opts.push !== false) {
                    window.history.pushState({ luminaAjax: true, url: url, script: currentScript, identity: currentIdentity }, '', url);
                }
                // Position the viewport BEFORE re-initializing dynamic content.
                // loaddemand() builds an IntersectionObserver against the current
                // scroll offset; if we scroll afterwards, images that end up on
                // screen were observed as off-screen and never get their data-src
                // promoted. Restore/scroll first, then refresh.
                if (opts.restoreListSnapshot) {
                    restoreListSnapshotIfAvailable({ force: true, scroll: true });
                } else if (opts.scroll !== false) {
                    window.scrollTo(0, 0);
                }
                if (typeof window.luminaRefreshDynamicContent === 'function') {
                    window.luminaRefreshDynamicContent();
                }
                return true;
            }).catch(function () {
                window.location.href = url;
                return false;
            });
        }).catch(function () {
            if (!opts.silent) {
                window.location.href = url;
            }
            return false;
        }).finally(function () {
            if (fetchId === requestId) {
                setAjaxLoading(false);
                if (typeof window.luminaAjaxProgressDone === 'function') {
                    window.luminaAjaxProgressDone();
                }
            }
        });
    }

    window.luminaAjaxVisit = fetchAndSwap;

    window.luminaAjaxCardClick = function (card, e) {
        if (!card) {
            return true;
        }
        var target = e && e.target;
        if (target && target.closest && target.closest('a, button, input, textarea, select, [data-fancybox], .sh-content-right-img, .sh-video, .lumina-live-photo, .lumina-music-card, .lumina-redpacket-card, .sh-content-right-time-right')) {
            return true;
        }
        var href = card.getAttribute('data-lumina-ajax-href') || '';
        if (!href) {
            return true;
        }
        var pseudoLink = {
            href: getUrl(href) ? getUrl(href).toString() : href,
            target: '',
            hasAttribute: function () { return false; },
            getAttribute: function (name) {
                return name === 'href' ? href : '';
            },
            closest: function (selector) {
                return card.closest(selector);
            }
        };
        if (!isAjaxCandidateLink(pseudoLink, e || {})) {
            window.location.href = href;
            return false;
        }
        if (e && typeof e.preventDefault === 'function') {
            e.preventDefault();
        }
        if (e && typeof e.stopPropagation === 'function') {
            e.stopPropagation();
        }
        fetchAndSwap(pseudoLink.href, { push: true, scroll: true });
        return false;
    };

    document.addEventListener('click', function (e) {
        var link = getAjaxTargetFromEvent(e);
        if (!isAjaxCandidateLink(link, e)) {
            return;
        }
        e.preventDefault();
        fetchAndSwap(link.href, { push: true, scroll: true });
    });

    document.addEventListener('submit', function (e) {
        var form = e.target;
        if (!form || form.id !== 'lumina-search-form') {
            return;
        }
        if (!allowScriptMap[currentScript]) {
            return;
        }
        e.preventDefault();
        var action = form.getAttribute('action') || window.location.href;
        var url = getUrl(action);
        if (!url) {
            form.submit();
            return;
        }
        var fd = new FormData(form);
        var keyword = fd.get('keyword');
        if (keyword && String(keyword).trim() !== '') {
            url.searchParams.set('keyword', String(keyword).trim());
        } else {
            url.searchParams.delete('keyword');
        }
        fetchAndSwap(url.toString(), { push: true, scroll: true });
    });

    window.addEventListener('popstate', function (e) {
        var state = e.state || {};
        if (!state.luminaAjax) {
            return;
        }
        fetchAndSwap(window.location.href, { push: false, scroll: false, silent: true, restoreListSnapshot: true });
    });

    if (!window.history.state || (!window.history.state.luminaAjax && !window.history.state.luminaQuickNav)) {
        window.history.replaceState({ luminaAjax: true, url: window.location.href, script: currentScript, identity: currentIdentity }, '', window.location.href);
    }
})();

window.luminaNavigateBack = window.luminaNavigateBack || function (el, e) {
    var href = el && el.getAttribute ? (el.getAttribute('data-lumina-back-url') || '') : '';
    if (!href) {
        return true;
    }
    if (e && typeof e.preventDefault === 'function') {
        e.preventDefault();
    }
    if (e && typeof e.stopPropagation === 'function') {
        e.stopPropagation();
    }
    if (typeof window.luminaAjaxVisit === 'function') {
        window.luminaAjaxVisit(href, { push: true, scroll: true });
        return false;
    }
    window.location.href = href;
    return false;
};
