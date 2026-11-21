/**
 * サポートの固定パネル
 *
 * 画面の組み立てはPHPが返す。JSは差し替えと送信だけ。
 * 状態からラベルを組み立てる判断をJSに置くと、同じ判断が2か所に分かれて必ずずれる。
 */
(function () {
    'use strict';

    var cfg = window.hbSupportSidebar;

    if (!cfg) { return; }

    var panel  = document.getElementById('hb-support-panel');
    var toggle = document.getElementById('hb-support-toggle');

    if (!panel || !toggle) { return; }

    var body = panel.querySelector('.hb-body');
    var foot = panel.querySelector('.hb-foot');

    // 開閉はこの端末に覚えさせる。サーバーに持たせるほどの物ではない。
    var OPEN_KEY = 'hb-support-open';

    function open(state) {
        panel.classList.toggle('is-open', state);
        // 開いている間はつまみを隠す。パネルの裏に回って押せなくなるため（閉じるのは ✕）。
        document.body.classList.toggle('hb-support-open', state);
        toggle.setAttribute('aria-expanded', state ? 'true' : 'false');

        try { window.localStorage.setItem(OPEN_KEY, state ? '1' : '0'); } catch (e) {}

        if (state && !body.dataset.loaded) { load({ op: 'list' }); }
    }

    /** PHPに画面を作らせて差し替える。 */
    function load(params, done) {
        body.innerHTML = '<p class="description">読み込んでいます…</p>';

        var data = new URLSearchParams(params);
        data.append('action', 'hb_support_sidebar');
        data.append('nonce', cfg.nonce);

        fetch(cfg.url, { method: 'POST', body: data, credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                if (!res || !res.success) {
                    body.innerHTML = '<p>いま接続できません。しばらくしてからお試しください。</p>';
                    foot.innerHTML = '';
                    return;
                }

                body.dataset.loaded = '1';
                body.innerHTML = res.data.body || '';
                foot.innerHTML = res.data.foot || '';
                body.scrollTop = res.data.scroll_bottom ? body.scrollHeight : 0;

                if (typeof res.data.unread === 'number') { badge(res.data.unread); }
                if (done) { done(); }
            })
            .catch(function () {
                body.innerHTML = '<p>いま接続できません。しばらくしてからお試しください。</p>';
                foot.innerHTML = '';
            });
    }

    function badge(count) {
        var el = toggle.querySelector('.hb-count');

        if (!el) { return; }

        el.textContent = count;
        el.style.display = count > 0 ? '' : 'none';
    }

    toggle.addEventListener('click', function () {
        open(!panel.classList.contains('is-open'));
    });

    panel.addEventListener('click', function (event) {
        var close = event.target.closest('[data-hb-close]');

        if (close) { event.preventDefault(); open(false); return; }

        var link = event.target.closest('[data-hb-open]');

        if (link) {
            event.preventDefault();
            load({ op: 'thread', ticket: link.getAttribute('data-hb-open') });
            return;
        }

        var back = event.target.closest('[data-hb-back]');

        if (back) { event.preventDefault(); load({ op: 'list' }); }
    });

    // 送信はパネルの中のフォームから。画面は再読込しない。
    panel.addEventListener('submit', function (event) {
        var form = event.target.closest('form[data-hb-form]');

        if (!form) { return; }

        event.preventDefault();

        var params = {};

        new FormData(form).forEach(function (value, key) { params[key] = value; });

        params.op = form.getAttribute('data-hb-form');

        form.querySelectorAll('button').forEach(function (b) { b.disabled = true; });

        load(params);
    });

    try {
        if (window.localStorage.getItem(OPEN_KEY) === '1') { open(true); }
    } catch (e) {}
}());
